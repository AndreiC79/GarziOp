<?php

declare(strict_types=1);

final class Statistics
{
    public static function period(int $year, ?int $month): array
    {
        if ($year < 2020 || $year > 2100) throw new DomainException('Anul selectat nu este valid.');
        if ($month !== null && ($month < 1 || $month > 12)) throw new DomainException('Luna selectata nu este valida.');
        $start = $month === null ? sprintf('%04d-01-01', $year) : sprintf('%04d-%02d-01', $year, $month);
        $end = $month === null
            ? sprintf('%04d-12-31', $year)
            : (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $months = [1=>'Ianuarie',2=>'Februarie',3=>'Martie',4=>'Aprilie',5=>'Mai',6=>'Iunie',7=>'Iulie',8=>'August',9=>'Septembrie',10=>'Octombrie',11=>'Noiembrie',12=>'Decembrie'];
        return [
            'year' => $year,
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'period' => $month === null ? 'annual' : 'monthly',
            'label' => $month === null ? (string)$year : $months[$month] . ' ' . $year,
        ];
    }

    public static function canGenerate(PDO $db, int $userId): bool
    {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id
             WHERE ur.user_id=:user_id AND r.code IN ("platform_admin","super_admin")'
        );
        $statement->execute(['user_id'=>$userId]);
        return (int)$statement->fetchColumn() > 0;
    }

    public static function guards(PDO $db, array $period, ?string $specialty = null, ?int $personId = null): array
    {
        if ($specialty !== null && !in_array($specialty, ['OG','ATI','NN'], true)) throw new DomainException('Specialitatea nu este valida.');
        $where = ['ga.guard_date BETWEEN :start AND :end'];
        $params = ['start'=>$period['start'], 'end'=>$period['end']];
        if ($specialty !== null) { $where[] = 'ga.specialty=:specialty'; $params['specialty'] = $specialty; }
        if ($personId !== null) { $where[] = 'ga.current_person_id=:person_id'; $params['person_id'] = $personId; }
        $statement = $db->prepare(
            'SELECT ga.id,ga.guard_date,ga.specialty,ga.line_no,ga.assignment_state,
                    ga.original_person_id,ga.current_person_id,
                    original.display_name original_name,current.display_name current_name,
                    current.first_name,current.last_name,current.professional_category,
                    h.name holiday_name
             FROM guard_assignments ga
             JOIN guard_plan_versions gv ON gv.id=ga.version_id
             JOIN guard_plans gp ON gp.current_version_id=gv.id
             JOIN people original ON original.id=ga.original_person_id
             JOIN people current ON current.id=ga.current_person_id
             LEFT JOIN holidays h ON h.holiday_date=ga.guard_date
             WHERE '.implode(' AND ', $where).'
             ORDER BY ga.guard_date,FIELD(ga.specialty,"OG","ATI","NN"),ga.line_no'
        );
        $statement->execute($params);
        $assignments = $statement->fetchAll();

        $summary = ['total'=>0,'performed'=>0,'scheduled'=>0,'weekend'=>0,'holidays'=>0,'initial'=>0,'changed'=>0,'gaps'=>null,'coverage'=>null,'consecutive'=>0];
        $people = [];
        $months = [];
        $personDates = [];
        $today = date('Y-m-d');
        foreach ($assignments as &$assignment) {
            $performed = (string)$assignment['guard_date'] <= $today;
            $weekend = (int)(new DateTimeImmutable((string)$assignment['guard_date']))->format('N') >= 6;
            $holiday = trim((string)($assignment['holiday_name'] ?? '')) !== '';
            $changed = (int)$assignment['original_person_id'] !== (int)$assignment['current_person_id'] || (string)$assignment['assignment_state'] !== 'initial';
            $assignment['period_status'] = $performed ? 'Efectuata' : 'Programata';
            $assignment['day_type'] = $holiday ? 'Sarbatoare' : ($weekend ? 'Weekend' : 'Zi lucratoare');
            $summary['total']++;
            $summary[$performed ? 'performed' : 'scheduled']++;
            if ($weekend) $summary['weekend']++;
            if ($holiday) $summary['holidays']++;
            $summary[$changed ? 'changed' : 'initial']++;
            $personIdKey = (int)$assignment['current_person_id'];
            if (!isset($people[$personIdKey])) {
                $people[$personIdKey] = [
                    'person_id'=>$personIdKey,'name'=>$assignment['current_name'],'specialty'=>$assignment['specialty'],
                    'category'=>$assignment['professional_category'],'total'=>0,'performed'=>0,'scheduled'=>0,
                    'weekend'=>0,'holidays'=>0,'initial'=>0,'changed'=>0,'exchanges'=>0,'given'=>0,'taken'=>0,'lines'=>[],
                ];
            }
            $row =& $people[$personIdKey];
            $row['total']++;
            $row[$performed ? 'performed' : 'scheduled']++;
            if ($weekend) $row['weekend']++;
            if ($holiday) $row['holidays']++;
            $row[$changed ? 'changed' : 'initial']++;
            $lineKey = $assignment['specialty'].' L'.(int)$assignment['line_no'];
            $row['lines'][$lineKey] = ($row['lines'][$lineKey] ?? 0) + 1;
            unset($row);
            $monthKey = substr((string)$assignment['guard_date'], 0, 7);
            $months[$monthKey] ??= ['month'=>$monthKey,'total'=>0,'performed'=>0,'scheduled'=>0];
            $months[$monthKey]['total']++;
            $months[$monthKey][$performed ? 'performed' : 'scheduled']++;
            $personDates[$personIdKey][(string)$assignment['guard_date']] = true;
        }
        unset($assignment);

        foreach ($personDates as $dates) {
            $sorted = array_keys($dates);
            sort($sorted);
            for ($i=1,$count=count($sorted); $i<$count; $i++) {
                if ((new DateTimeImmutable($sorted[$i-1]))->modify('+1 day')->format('Y-m-d') === $sorted[$i]) $summary['consecutive']++;
            }
        }

        if ($personId === null) {
            $settingsSql = 'SELECT specialty,line_count FROM guard_specialty_settings';
            $settingsParams = [];
            if ($specialty !== null) { $settingsSql .= ' WHERE specialty=:specialty'; $settingsParams['specialty']=$specialty; }
            $settingsStatement = $db->prepare($settingsSql);
            $settingsStatement->execute($settingsParams);
            $linesPerDay = 0;
            foreach ($settingsStatement as $setting) $linesPerDay += (int)$setting['line_count'];
            $days = (int)(new DateTimeImmutable($period['start']))->diff(new DateTimeImmutable($period['end']))->days + 1;
            $expected = $days * $linesPerDay;
            $summary['gaps'] = max(0, $expected - $summary['total']);
            $summary['coverage'] = $expected > 0 ? round(min(100, $summary['total'] * 100 / $expected), 1) : 0;
        }

        $changeWhere = ['ga.guard_date BETWEEN :change_start AND :change_end'];
        $changeParams = ['change_start'=>$period['start'], 'change_end'=>$period['end']];
        if ($specialty !== null) { $changeWhere[]='ga.specialty=:change_specialty';$changeParams['change_specialty']=$specialty; }
        if ($personId !== null) { $changeWhere[]='(gci.from_person_id=:change_person_a OR gci.to_person_id=:change_person_b)';$changeParams['change_person_a']=$personId;$changeParams['change_person_b']=$personId; }
        $changeStatement = $db->prepare(
            'SELECT gc.id change_id,gc.change_type,gc.created_at,gc.reason,ga.guard_date,ga.specialty,ga.line_no,
                    gci.from_person_id,gci.to_person_id,from_p.display_name from_name,to_p.display_name to_name,
                    COALESCE(actor.display_name,u.username) initiated_by_name
             FROM guard_change_items gci
             JOIN guard_changes gc ON gc.id=gci.change_id
             JOIN guard_assignments ga ON ga.id=gci.assignment_id
             JOIN guard_plan_versions gv ON gv.id=ga.version_id
             JOIN guard_plans gp ON gp.current_version_id=gv.id
             JOIN people from_p ON from_p.id=gci.from_person_id
             JOIN people to_p ON to_p.id=gci.to_person_id
             JOIN users u ON u.id=gc.initiated_by LEFT JOIN people actor ON actor.id=u.person_id
             WHERE '.implode(' AND ', $changeWhere).'
             ORDER BY ga.guard_date,gc.created_at'
        );
        $changeStatement->execute($changeParams);
        $changes = $changeStatement->fetchAll();
        $changeSeen = [];
        foreach ($changes as $change) {
            foreach ([(int)$change['from_person_id'],(int)$change['to_person_id']] as $involvedId) {
                if (!isset($people[$involvedId])) continue;
                $uniqueKey = $change['change_id'].':'.$involvedId;
                if ($change['change_type'] === 'exchange' && !isset($changeSeen[$uniqueKey])) $people[$involvedId]['exchanges']++;
                $changeSeen[$uniqueKey] = true;
            }
            if ($change['change_type'] === 'transfer') {
                if (isset($people[(int)$change['from_person_id']])) $people[(int)$change['from_person_id']]['given']++;
                if (isset($people[(int)$change['to_person_id']])) $people[(int)$change['to_person_id']]['taken']++;
            }
        }

        uasort($people, static fn(array $a,array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
        ksort($months);
        return [
            'kind'=>'guards','period'=>$period,'filters'=>['specialty'=>$specialty,'person_id'=>$personId],
            'summary'=>$summary,'by_person'=>array_values($people),'by_month'=>array_values($months),
            'assignments'=>$assignments,'changes'=>$changes,
        ];
    }

    public static function operations(PDO $db, array $period, ?int $personId = null): array
    {
        $where = ['o.scheduled_date BETWEEN :start AND :end'];
        $params = ['start'=>$period['start'],'end'=>$period['end']];
        if ($personId !== null) { $where[]='o.scheduled_doctor_id=:person_id';$params['person_id']=$personId; }
        $statement = $db->prepare(
            'SELECT o.id,o.scheduled_date,o.status,o.is_extra,o.created_at,o.updated_at,
                    p.id person_id,p.display_name doctor_name,p.professional_category,
                    COALESCE(ot.name,"Tip nespecificat") operation_type,r.name room_name,
                    COALESCE(creator_person.display_name,creator.username) created_by_name
             FROM operations o JOIN people p ON p.id=o.scheduled_doctor_id
             JOIN operation_rooms r ON r.id=o.room_id LEFT JOIN operation_types ot ON ot.id=o.operation_type_id
             JOIN users creator ON creator.id=o.created_by LEFT JOIN people creator_person ON creator_person.id=creator.person_id
             WHERE '.implode(' AND ', $where).'
             ORDER BY o.scheduled_date,p.last_name,p.first_name,o.id'
        );
        $statement->execute($params);
        $operations = $statement->fetchAll();

        $guardStatement = $db->prepare(
            'SELECT DISTINCT ga.current_person_id,ga.guard_date FROM guard_assignments ga
             JOIN guard_plan_versions gv ON gv.id=ga.version_id JOIN guard_plans gp ON gp.current_version_id=gv.id
             WHERE ga.guard_date BETWEEN :start AND :end'
        );
        $guardStatement->execute(['start'=>$period['start'],'end'=>$period['end']]);
        $guardDays = [];
        foreach ($guardStatement as $guard) $guardDays[(int)$guard['current_person_id'].':'.$guard['guard_date']] = true;

        $summary = ['total'=>0,'completed'=>0,'future'=>0,'unconfirmed'=>0,'cancelled'=>0,'deleted'=>0,'extra'=>0,'guard_day'=>0,'average_lead_days'=>0.0,'confirmation_rate'=>0.0];
        $people=[];$types=[];$rooms=[];$months=[];$weekdays=[];$creators=[];$unconfirmed=[];$extras=[];$leadDays=[];
        $today=date('Y-m-d');
        $weekdayLabels=[1=>'Luni',2=>'Marti',3=>'Miercuri',4=>'Joi',5=>'Vineri',6=>'Sambata',7=>'Duminica'];
        foreach ($operations as $operation) {
            $status=(string)$operation['status'];
            if ($status !== 'deleted') $summary['total']++;
            if (isset($summary[$status])) $summary[$status]++;
            $isFuture=$status==='scheduled' && (string)$operation['scheduled_date']>$today;
            $isUnconfirmed=$status==='scheduled' && (string)$operation['scheduled_date']<=$today;
            if($isFuture)$summary['future']++;
            if($isUnconfirmed){$summary['unconfirmed']++;$unconfirmed[]=['date'=>$operation['scheduled_date'],'doctor'=>$operation['doctor_name'],'type'=>$operation['operation_type'],'room'=>$operation['room_name'],'created_by'=>$operation['created_by_name']];}
            if((int)$operation['is_extra']===1 && $status!=='deleted'){
                $summary['extra']++;
                $extras[]=['date'=>$operation['scheduled_date'],'doctor'=>$operation['doctor_name'],'type'=>$operation['operation_type'],'room'=>$operation['room_name'],'status'=>$status,'created_by'=>$operation['created_by_name']];
            }
            $onGuardDay=$status==='completed'&&isset($guardDays[(int)$operation['person_id'].':'.$operation['scheduled_date']]);
            if($onGuardDay)$summary['guard_day']++;
            if($status!=='deleted')$leadDays[]=(int)(new DateTimeImmutable(substr((string)$operation['created_at'],0,10)))->diff(new DateTimeImmutable((string)$operation['scheduled_date']))->format('%r%a');

            $personKey=(int)$operation['person_id'];
            $people[$personKey]??=['person_id'=>$personKey,'name'=>$operation['doctor_name'],'category'=>$operation['professional_category'],'total'=>0,'completed'=>0,'future'=>0,'unconfirmed'=>0,'cancelled'=>0,'deleted'=>0,'extra'=>0,'guard_day'=>0,'types'=>[]];
            $person=&$people[$personKey];
            if($status!=='deleted')$person['total']++;
            if(isset($person[$status]))$person[$status]++;
            if($isFuture)$person['future']++;
            if($isUnconfirmed)$person['unconfirmed']++;
            if((int)$operation['is_extra']===1&&$status!=='deleted')$person['extra']++;
            if($onGuardDay)$person['guard_day']++;
            if($status==='completed')$person['types'][$operation['operation_type']]=($person['types'][$operation['operation_type']]??0)+1;
            unset($person);

            $typeKey=(string)$operation['operation_type'];
            $types[$typeKey]??=['name'=>$typeKey,'total'=>0,'completed'=>0,'unconfirmed'=>0,'cancelled'=>0];
            if($status!=='deleted')$types[$typeKey]['total']++;
            if(isset($types[$typeKey][$status]))$types[$typeKey][$status]++;
            if($isUnconfirmed)$types[$typeKey]['unconfirmed']++;
            $roomKey=(string)$operation['room_name'];
            $rooms[$roomKey]??=['name'=>$roomKey,'total'=>0,'completed'=>0,'extra'=>0];
            if($status!=='deleted')$rooms[$roomKey]['total']++;
            if($status==='completed')$rooms[$roomKey]['completed']++;
            if((int)$operation['is_extra']===1&&$status!=='deleted')$rooms[$roomKey]['extra']++;
            $monthKey=substr((string)$operation['scheduled_date'],0,7);
            $months[$monthKey]??=['month'=>$monthKey,'total'=>0,'completed'=>0,'unconfirmed'=>0,'future'=>0];
            if($status!=='deleted')$months[$monthKey]['total']++;
            if($status==='completed')$months[$monthKey]['completed']++;
            if($isUnconfirmed)$months[$monthKey]['unconfirmed']++;
            if($isFuture)$months[$monthKey]['future']++;
            $weekday=(int)(new DateTimeImmutable((string)$operation['scheduled_date']))->format('N');
            $weekdays[$weekday]??=['weekday'=>$weekdayLabels[$weekday],'total'=>0,'completed'=>0];
            if($status!=='deleted')$weekdays[$weekday]['total']++;
            if($status==='completed')$weekdays[$weekday]['completed']++;
            $creatorKey=(string)$operation['created_by_name'];
            $creators[$creatorKey]??=['name'=>$creatorKey,'created'=>0];
            $creators[$creatorKey]['created']++;
        }
        $summary['average_lead_days']=$leadDays?round(array_sum($leadDays)/count($leadDays),1):0;
        $pastEligible=$summary['completed']+$summary['unconfirmed'];
        $summary['confirmation_rate']=$pastEligible>0?round($summary['completed']*100/$pastEligible,1):100;
        foreach($people as &$person){arsort($person['types']);$person['main_type']=array_key_first($person['types'])??'-';}unset($person);
        uasort($people,static fn(array$a,array$b):int=>strcasecmp((string)$a['name'],(string)$b['name']));
        uasort($types,static fn(array$a,array$b):int=>$b['completed']<=>$a['completed']?:strcasecmp($a['name'],$b['name']));
        uasort($rooms,static fn(array$a,array$b):int=>strcasecmp($a['name'],$b['name']));
        uasort($creators,static fn(array$a,array$b):int=>$b['created']<=>$a['created']?:strcasecmp($a['name'],$b['name']));
        ksort($months);ksort($weekdays);

        $eventWhere=['o.scheduled_date BETWEEN :event_start AND :event_end'];
        $eventParams=['event_start'=>$period['start'],'event_end'=>$period['end']];
        if($personId!==null){$eventWhere[]='o.scheduled_doctor_id=:event_person';$eventParams['event_person']=$personId;}
        $eventStatement=$db->prepare(
            'SELECT oe.event_type,COUNT(*) event_count,COALESCE(p.display_name,u.username) user_name
             FROM operation_events oe JOIN operations o ON o.id=oe.operation_id JOIN users u ON u.id=oe.performed_by
             LEFT JOIN people p ON p.id=u.person_id WHERE '.implode(' AND ',$eventWhere).'
             GROUP BY oe.event_type,u.id,p.display_name,u.username ORDER BY user_name,oe.event_type'
        );
        $eventStatement->execute($eventParams);
        $activity=[];
        foreach($eventStatement as $event){$key=(string)$event['user_name'];$activity[$key]??=['name'=>$key,'created'=>0,'updated'=>0,'moved'=>0,'reordered'=>0,'completed'=>0,'cancelled'=>0,'deleted'=>0,'restored'=>0,'total'=>0];$type=(string)$event['event_type'];$count=(int)$event['event_count'];if(isset($activity[$key][$type]))$activity[$key][$type]+=$count;$activity[$key]['total']+=$count;}
        uasort($activity,static fn(array$a,array$b):int=>$b['total']<=>$a['total']);
        return [
            'kind'=>'progop','period'=>$period,'filters'=>['person_id'=>$personId],'summary'=>$summary,
            'by_person'=>array_values($people),'by_type'=>array_values($types),'by_room'=>array_values($rooms),
            'by_month'=>array_values($months),'by_weekday'=>array_values($weekdays),'by_creator'=>array_values($creators),
            'activity'=>array_values($activity),'unconfirmed'=>$unconfirmed,'extras'=>$extras,
        ];
    }

    public static function dailyActivity(PDO $db, string $date): array
    {
        $statement = $db->prepare(
            'SELECT o.is_extra,p.id person_id,p.display_name doctor_name,
                    COALESCE(ot.name,"Tip nespecificat") operation_type,r.name room_name
             FROM operations o JOIN people p ON p.id=o.scheduled_doctor_id
             JOIN operation_rooms r ON r.id=o.room_id LEFT JOIN operation_types ot ON ot.id=o.operation_type_id
             WHERE o.scheduled_date=:date AND o.status="completed"
             ORDER BY p.last_name,p.first_name,ot.name,o.id'
        );
        $statement->execute(['date'=>$date]);
        $people=[];$types=[];$rooms=[];$total=0;$extra=0;
        foreach ($statement as $operation) {
            $total++;
            if ((int)$operation['is_extra'] === 1) $extra++;
            $personId=(int)$operation['person_id'];
            $people[$personId]??=['name'=>$operation['doctor_name'],'completed'=>0,'extra'=>0,'types'=>[]];
            $people[$personId]['completed']++;
            if ((int)$operation['is_extra'] === 1) $people[$personId]['extra']++;
            $type=(string)$operation['operation_type'];
            $people[$personId]['types'][$type]=($people[$personId]['types'][$type]??0)+1;
            $types[$type]=($types[$type]??0)+1;
            $room=(string)$operation['room_name'];
            $rooms[$room]=($rooms[$room]??0)+1;
        }
        foreach ($people as &$person) {
            arsort($person['types']);
            $person['type_breakdown']=implode(', ', array_map(static fn(string $name,int $count): string => $name.' ('.$count.')', array_keys($person['types']), array_values($person['types'])));
        }
        unset($person);
        uasort($people, static fn(array $a,array $b): int => $b['completed']<=>$a['completed'] ?: strcasecmp((string)$a['name'],(string)$b['name']));
        arsort($types);arsort($rooms);
        return [
            'kind'=>'daily_activity',
            'period'=>['start'=>$date,'end'=>$date,'label'=>(new DateTimeImmutable($date))->format('d.m.Y'),'period'=>'daily'],
            'summary'=>['completed'=>$total,'extra'=>$extra,'doctors'=>count($people),'types'=>count($types)],
            'by_person'=>array_values($people),
            'by_type'=>array_map(static fn(string $name,int $count): array => ['name'=>$name,'completed'=>$count], array_keys($types), array_values($types)),
            'by_room'=>array_map(static fn(string $name,int $count): array => ['name'=>$name,'completed'=>$count], array_keys($rooms), array_values($rooms)),
        ];
    }

}
