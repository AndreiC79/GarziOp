<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
apiRequirePermission('progop.view');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        handleLegacyGet($db);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') legacyError('Metoda nu este acceptata.', 405);
    apiVerifyCsrf();
    handleLegacyPost($db, apiInput());
} catch (DomainException $error) {
    if ($db->inTransaction()) $db->rollBack();
    legacyError($error->getMessage(), 409);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    apiLogException($error, 'progop-legacy');
    legacyError('Operatiunea nu a putut fi finalizata.', 500);
}

function handleLegacyGet(PDO $db): never
{
    $action = (string) ($_GET['action'] ?? 'load');
    if (legacyCurrentUserHasRole('operating_room_dashboard') && $action !== 'overview') {
        legacyError('Contul Panou bloc operator are acces exclusiv la Overview.', 403);
    }
    if ($action === 'download') downloadLegacyExport((string) ($_GET['file'] ?? ''));
    if ($action === 'load') {
        $month = validMonth((string) ($_GET['month'] ?? date('Y-m')));
        $previous = (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
        $next = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m');
        legacySuccess(legacyPayload($db, [$month, $previous, $next]));
    }
    if ($action === 'overview') {
        $date = validDate((string) ($_GET['date'] ?? date('Y-m-d')));
        legacyAssertDashboardDate($date);
        $mode = !legacyCurrentUserHasRole('operating_room_dashboard') && ($_GET['mode'] ?? 'day') === 'week' ? 'week' : 'day';
        $start = $date;
        $end = $date;
        if ($mode === 'week') {
            $point = new DateTimeImmutable($date);
            $start = $point->modify('monday this week')->format('Y-m-d');
            $end = $point->modify('sunday this week')->format('Y-m-d');
        }
        $dates = dateRange($start, $end);
        legacySuccess([
            'meta' => ['updatedAt' => date(DATE_ATOM), 'baseDate' => $date, 'mode' => $mode],
            'settings' => legacySettings($db),
            'capabilities' => legacyCapabilities(),
            'days' => legacyDays($db, $start, $end),
            'guardsByDate' => legacyGuards($db, $start, $end),
            'atiAssignmentsByDate' => legacyAtiAssignments($start, $end),
            'range' => ['start' => $start, 'end' => $end, 'dates' => $dates],
        ]);
    }
    if ($action === 'export') generateLegacyExport($db, $_GET);
    if ($action === 'searchPatients') legacySearchPatients($db, (string)($_GET['q'] ?? ''));
    if ($action === 'unassignedOperations') legacyUnassignedOperations($db);
    legacyError('Actiune necunoscuta.');
}

function legacyUnassignedOperations(PDO $db): never
{
    if (!legacyCanAssignRooms()) legacyError('Nu ai dreptul de repartizare pe sali.',403);
    $rows=$db->query('SELECT o.id,o.scheduled_date,o.patient_name,o.diagnostic,o.custom_notes,o.created_at,o.is_extra,o.extra_reason,ot.name operation_type,p.display_name doctor_name FROM operations o LEFT JOIN operation_types ot ON ot.id=o.operation_type_id JOIN people p ON p.id=o.scheduled_doctor_id WHERE o.status<>"deleted" AND o.display_order=0 AND o.scheduled_date>=CURRENT_DATE() ORDER BY o.scheduled_date,o.created_at,o.id')->fetchAll();
    foreach($rows as &$row){$approval=legacyExtraApprovalMeta((bool)$row['is_extra'],(string)($row['extra_reason']??''));$row['approval_label']=$approval['label'];}unset($row);
    legacySuccess(['operations'=>$rows,'rooms'=>legacyRooms($db)]);
}

function legacySearchPatients(PDO $db, string $query): never
{
    $query = trim($query);
    if (mb_strlen($query) < 2) legacySuccess(['results'=>[]]);
    $statement = $db->prepare(
        'SELECT o.id,o.scheduled_date,o.patient_name,o.diagnostic,o.custom_notes,o.status,o.is_extra,
                r.name room_name,ot.name operation_type,p.display_name doctor_name
         FROM operations o JOIN operation_rooms r ON r.id=o.room_id
         LEFT JOIN operation_types ot ON ot.id=o.operation_type_id JOIN people p ON p.id=o.scheduled_doctor_id
         WHERE o.status<>"deleted" AND (o.patient_name LIKE :query_patient OR o.diagnostic LIKE :query_diagnostic OR o.custom_notes LIKE :query_notes)
         ORDER BY (o.scheduled_date>=CURRENT_DATE()) DESC,o.scheduled_date ASC,o.created_at ASC LIMIT 100'
    );
    $like = '%'.$query.'%';
    $statement->execute(['query_patient'=>$like,'query_diagnostic'=>$like,'query_notes'=>$like]);
    legacySuccess(['results'=>$statement->fetchAll()]);
}

function handleLegacyPost(PDO $db, array $input): never
{
    $action = (string) ($input['action'] ?? '');
    if (legacyCurrentUserHasRole('operating_room_dashboard') && !in_array($action, [
        'addEmergencyIntervention', 'moveIntervention', 'toggleCompleted', 'setDashboardStatus', 'setEstimatedTime'
    ], true)) {
        legacyError('Actiunea nu este disponibila contului Panou bloc operator.', 403);
    }
    match ($action) {
        'addIntervention' => legacyAddIntervention($db, $input),
        'addEmergencyIntervention' => legacyAddEmergencyIntervention($db, $input),
        'addSurgeryRequest' => legacyAddSurgeryRequest($db, $input),
        'editSurgeryRequest' => legacyEditSurgeryRequest($db, $input),
        'assignSurgeryRoom' => legacyAssignSurgeryRoom($db, $input),
        'unassignSurgeryRoom' => legacyUnassignSurgeryRoom($db, $input),
        'editIntervention' => legacyEditIntervention($db, $input),
        'deleteIntervention' => legacyDeleteIntervention($db, $input),
        'moveIntervention' => legacyMoveIntervention($db, $input),
        'reorderIntervention' => legacyReorderIntervention($db, $input),
        'toggleCompleted' => legacyToggleCompleted($db, $input),
        'setDashboardStatus' => legacySetDashboardStatus($db, $input),
        'setEstimatedTime' => legacySetEstimatedTime($db, $input),
        'reviewExtraOperation' => legacyReviewExtraOperation($db, $input),
        'blockRoom' => legacyBlockRoom($db, $input),
        'unblockRoom' => legacyUnblockRoom($db, $input),
        'addDoctor' => legacyAddDoctor($db, $input),
        'updateDoctor' => legacyUpdateDoctor($db, $input),
        'deleteDoctor' => legacyDeleteDoctor($db, $input),
        'addOperationType' => legacyAddOperationType($db, $input),
        'updateOperationType' => legacyUpdateOperationType($db, $input),
        'deleteOperationType' => legacyDeleteOperationType($db, $input),
        'backup' => legacyBackup($db),
        'saveMyResidentStage' => legacySaveMyResidentStage($db, $input),
        'saveAtiWeek' => legacySaveAtiWeek($db, $input),
        default => legacyError('Actiune necunoscuta.'),
    };
}

function legacyPayload(PDO $db, array $months): array
{
    global $apiPermissions, $apiUser;
    $result = [];
    foreach (array_unique($months) as $month) {
        $start = $month . '-01';
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $result[$month] = ['meta' => ['month' => $month, 'updatedAt' => date(DATE_ATOM)], 'schedule' => legacyDays($db, $start, $end), 'moveHistory' => []];
    }
    $first = min($months) . '-01';
    $lastMonth = max($months);
    $last = (new DateTimeImmutable($lastMonth . '-01'))->modify('last day of this month')->format('Y-m-d');
    return [
        'meta' => ['updatedAt' => date(DATE_ATOM)],
        'settings' => legacySettings($db),
        'months' => $result,
        'guardsByDate' => legacyGuards($db, $first, $last),
        'atiAssignmentsByDate' => legacyAtiAssignments($first, $last),
        'capabilities' => legacyCapabilities(),
    ];
}

function legacyCapabilities(): array
{
    global $apiPermissions, $apiUser;
    $canCreate = in_array('progop.create', $apiPermissions, true) || in_array('progop.manage_all', $apiPermissions, true);
    return [
        'currentUserId' => (int)$apiUser['id'],
        'currentPersonId' => $apiUser['person_id'] === null ? 0 : (int)$apiUser['person_id'],
        'currentPersonName' => (string)($apiUser['display_name'] ?? ''),
        'canCreate' => $canCreate,
        'canEditOwn' => in_array('progop.edit_own', $apiPermissions, true) || $canCreate,
        'canManageAll' => in_array('progop.manage_all', $apiPermissions, true),
        'canAssignRooms' => legacyCanAssignRooms(),
        'canManageSettings' => in_array('progop.manage_settings', $apiPermissions, true),
        'canScheduleOwn' => $canCreate && !empty($apiUser['person_id']) && (string)($apiUser['specialty'] ?? '') === 'OG',
        'canReviewExtra' => in_array(strtolower((string)$apiUser['username']), ['cucu.andrei', 'traian.maier'], true),
        'currentIsResident' => legacyCurrentUserIsResident(),
        'isOperatingRoomDashboard' => legacyCurrentUserHasRole('operating_room_dashboard'),
        'isSuperAdmin' => legacyCurrentUserHasRole('super_admin'),
    ];
}

function legacyCurrentUserHasRole(string $role): bool
{
    global $db, $apiUser;
    return Auth::hasRole($db, (int)$apiUser['id'], $role);
}

function legacyAssertDashboardDate(string $date): void
{
    if (!legacyCurrentUserHasRole('operating_room_dashboard')) return;
    $point = new DateTimeImmutable($date);
    $today = new DateTimeImmutable('today');
    if ($point < $today->modify('-1 day') || $point > $today->modify('+1 day')) {
        legacyError('Panoul bloc operator permite actiuni numai pentru ieri, astazi si maine.', 403);
    }
}

function legacyCurrentUserIsResident(): bool
{
    global $db, $apiUser;
    if (empty($apiUser['person_id'])) return false;
    $statement=$db->prepare('SELECT professional_category FROM people WHERE id=:id');$statement->execute(['id'=>$apiUser['person_id']]);
    return legacyIsResident((string)$statement->fetchColumn());
}

function legacyCanAssignRooms(): bool
{
    global $apiPermissions, $apiUser, $db;
    if (in_array('progop.manage_all', $apiPermissions, true)) return true;
    if (in_array((string)($apiUser['specialty'] ?? ''), ['ATI', 'ASISTENTA'], true)) return true;
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id
         WHERE ur.user_id=:user_id AND r.code IN ("doctor_ati","assistant_editor","assistant_viewer")'
    );
    $statement->execute(['user_id'=>(int)$apiUser['id']]);
    return (int)$statement->fetchColumn() > 0;
}

function legacySettings(PDO $db): array
{
    $rooms = legacyRooms($db);
    $doctors = $db->query('SELECT id,display_name,professional_category FROM people WHERE active=1 AND specialty="OG" ORDER BY last_name, first_name')->fetchAll();
    $atiDoctors = $db->query('SELECT id,display_name FROM people WHERE active=1 AND specialty="ATI" ORDER BY last_name,first_name')->fetchAll();
    return [
        'rooms' => $rooms,
        'doctors' => array_column($doctors, 'display_name'),
        'doctorOptions' => array_map(static fn(array $doctor): array => [
            'id' => (int)$doctor['id'],
            'name' => (string)$doctor['display_name'],
            'resident' => legacyIsResident((string)($doctor['professional_category'] ?? '')),
        ], $doctors),
        'atiDoctorOptions' => array_map(static fn(array $doctor): array => ['id'=>(int)$doctor['id'],'name'=>(string)$doctor['display_name']], $atiDoctors),
        'operationTypes' => $db->query('SELECT name FROM operation_types WHERE active=1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN),
        'residentStages' => legacyResidentStages(),
    ];
}

function legacyDays(PDO $db, string $start, string $end): array
{
    $rooms = legacyRooms($db);
    $days = [];
    foreach (dateRange($start, $end) as $date) {
        $days[$date] = ['rooms' => [], 'pendingInterventions' => [], 'extraInterventions' => []];
        foreach ($rooms as $room) $days[$date]['rooms'][$room] = ['blocked' => false, 'blockReason' => '', 'interventions' => []];
    }
    $blocks = $db->prepare('SELECT rb.block_date,r.name,rb.reason FROM room_blocks rb JOIN operation_rooms r ON r.id=rb.room_id WHERE rb.block_date BETWEEN :a AND :b AND rb.removed_at IS NULL');
    $blocks->execute(['a' => $start, 'b' => $end]);
    foreach ($blocks as $block) {
        if (!isset($days[$block['block_date']]['rooms'][$block['name']])) continue;
        $days[$block['block_date']]['rooms'][$block['name']]['blocked'] = true;
        $days[$block['block_date']]['rooms'][$block['name']]['blockReason'] = (string) $block['reason'];
    }
    $dashboardState = legacyDashboardOperationState();
    $operations = $db->prepare(
        'SELECT o.*,r.name room_name,ot.name operation_type,p.display_name doctor_name
         FROM operations o JOIN operation_rooms r ON r.id=o.room_id
         LEFT JOIN operation_types ot ON ot.id=o.operation_type_id JOIN people p ON p.id=o.scheduled_doctor_id
         WHERE o.scheduled_date BETWEEN :a AND :b AND o.status<>"deleted"
         ORDER BY o.scheduled_date,r.display_order,o.display_order,o.id'
    );
    $operations->execute(['a' => $start, 'b' => $end]);
    foreach ($operations as $operation) {
        if (!isset($days[$operation['scheduled_date']]['rooms'][$operation['room_name']])) continue;
        $approval = legacyExtraApprovalMeta((bool)$operation['is_extra'], (string)($operation['extra_reason'] ?? ''));
        $savedDashboardState = is_array($dashboardState['operations'][(string)$operation['id']] ?? null)
            ? $dashboardState['operations'][(string)$operation['id']]
            : [];
        $operatingStatus = $operation['status'] === 'completed'
            ? 'completed'
            : (string)($savedDashboardState['status'] ?? 'scheduled');
        if (!in_array($operatingStatus, ['scheduled', 'in_room', 'in_progress', 'completed'], true)) $operatingStatus = 'scheduled';
        $item = [
            'id' => (string) $operation['id'], 'patientName' => $operation['patient_name'], 'diagnostic' => $operation['diagnostic'] ?? '',
            'operationType' => $operation['operation_type'] ?? '', 'doctor' => $operation['doctor_name'], 'doctorId' => (int) $operation['scheduled_doctor_id'], 'customNotes' => $operation['custom_notes'] ?? '',
            'completed' => $operation['status'] === 'completed', 'completedAt' => $operation['completed_at'] ?? '',
            'extra' => (bool) $operation['is_extra'], 'extraReason' => $approval['reason'],
            'urgent' => (bool)$operation['is_extra'] && stripos((string)($operation['extra_reason'] ?? ''), 'urgenta') !== false,
            'approvalState' => $approval['state'], 'approvalLabel' => $approval['label'],
            'createdAt' => $operation['created_at'], 'updatedAt' => $operation['updated_at'], 'createdBy' => (int) $operation['created_by'],
            'roomName' => $operation['room_name'], 'displayOrder' => (int)$operation['display_order'],
            'operatingStatus' => $operatingStatus,
            'estimatedTime' => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)($savedDashboardState['estimated_time'] ?? ''))
                ? (string)$savedDashboardState['estimated_time'] : '',
        ];
        if ((bool)$operation['is_extra'] && (int)$operation['display_order'] === 0) {
            $days[$operation['scheduled_date']]['extraInterventions'][] = $item;
        } elseif ((int)$operation['display_order'] === 0) {
            $days[$operation['scheduled_date']]['pendingInterventions'][] = $item;
        } else {
            $days[$operation['scheduled_date']]['rooms'][$operation['room_name']]['interventions'][] = $item;
        }
    }
    foreach ($days as &$day) {
        usort($day['pendingInterventions'], 'legacyChronologySort');
        usort($day['extraInterventions'], 'legacyChronologySort');
    }
    unset($day);
    return $days;
}

function legacyChronologySort(array $left, array $right): int
{
    return [$left['createdAt'], (int)$left['id']] <=> [$right['createdAt'], (int)$right['id']];
}

function legacyGuards(PDO $db, string $start, string $end): array
{
    $result = array_fill_keys(dateRange($start, $end), []);
    $statement = $db->prepare(
        'SELECT ga.guard_date,ga.specialty,ga.line_no,p.id person_id,p.display_name
         FROM guard_assignments ga JOIN guard_plan_versions gv ON gv.id=ga.version_id
         JOIN guard_plans gp ON gp.current_version_id=gv.id JOIN people p ON p.id=ga.current_person_id
         WHERE ga.guard_date BETWEEN :a AND :b ORDER BY ga.guard_date,FIELD(ga.specialty,"ATI","OG","NN"),ga.line_no'
    );
    $statement->execute(['a' => $start, 'b' => $end]);
    foreach ($statement as $guard) {
        $weekday = (int) (new DateTimeImmutable($guard['guard_date']))->format('N');
        $result[$guard['guard_date']][] = ['personId' => (int) $guard['person_id'], 'personName' => $guard['display_name'], 'line' => (int) $guard['line_no'], 'specialty' => $guard['specialty'], 'weekend' => $weekday >= 6];
    }
    return $result;
}

function legacyAtiAssignmentsPath(): string
{
    return dirname(__DIR__, 2) . '/storage/config/ati_room_assignments.json';
}

function legacyAllAtiAssignments(): array
{
    $path = legacyAtiAssignmentsPath();
    if (!is_file($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded['assignments'] ?? null) ? $decoded['assignments'] : [];
}

function legacyAtiAssignments(string $start, string $end): array
{
    $all = legacyAllAtiAssignments();
    $result = [];
    foreach (dateRange($start, $end) as $date) $result[$date] = is_array($all[$date] ?? null) ? $all[$date] : [];
    return $result;
}

function legacySaveAtiWeek(PDO $db, array $input): never
{
    global $apiUser;
    if (!legacyCanAssignRooms()) legacyError('Nu ai dreptul de repartizare ATI pe sali.', 403);
    $weekStart = validDate((string)($input['weekStart'] ?? ''));
    $point = new DateTimeImmutable($weekStart);
    if ($point->format('N') !== '1') throw new DomainException('Saptamana trebuie sa inceapa luni.');
    $incoming = is_array($input['assignments'] ?? null) ? $input['assignments'] : [];
    $rooms = legacyRooms($db);
    $validRooms = array_fill_keys($rooms, true);
    $atiIds = array_map('intval', $db->query('SELECT id FROM people WHERE active=1 AND specialty="ATI"')->fetchAll(PDO::FETCH_COLUMN));
    $validDoctors = array_fill_keys($atiIds, true);
    $all = legacyAllAtiAssignments();
    $saved = [];
    foreach (dateRange($weekStart, $point->modify('+6 days')->format('Y-m-d')) as $date) {
        $day = is_array($incoming[$date] ?? null) ? $incoming[$date] : [];
        $cleanDay = [];
        foreach ($day as $room => $doctorIds) {
            if (!isset($validRooms[$room])) continue;
            $cleanIds = [];
            foreach (array_slice(array_values(array_unique(array_map('intval', is_array($doctorIds) ? $doctorIds : []))), 0, 2) as $doctorId) {
                if ($doctorId > 0 && isset($validDoctors[$doctorId])) $cleanIds[] = $doctorId;
            }
            if ($cleanIds) $cleanDay[$room] = $cleanIds;
        }
        if ($cleanDay) $all[$date] = $saved[$date] = $cleanDay;
        else { unset($all[$date]); $saved[$date] = []; }
    }
    ksort($all);
    $directory = dirname(legacyAtiAssignmentsPath());
    if (!is_dir($directory)) mkdir($directory, 0775, true);
    $payload = ['format_version'=>1,'updated_at'=>date(DATE_ATOM),'updated_by'=>(int)$apiUser['id'],'assignments'=>$all];
    if (file_put_contents(legacyAtiAssignmentsPath(), json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new DomainException('Planificarea ATI nu a putut fi salvata.');
    }
    apiAudit($db, 'progop.ati_assignments_saved', 'ati_schedule', null, null, ['week_start'=>$weekStart,'assignments'=>$saved]);
    legacyMutationSuccess($db, array_keys($saved), 'Repartizarea ATI a fost salvata.');
}

function legacyAddIntervention(PDO $db, array $input): never
{
    global $apiUser;
    requireLegacyPermission('progop.create', 'progop.manage_all');
    [$date, $roomId, $data] = legacyInterventionInput($db, $input);
    legacyAssertDashboardDate($date);
    assertLegacyRoomAvailable($db, $date, $roomId);
    $statement = $db->prepare('INSERT INTO operations (scheduled_date,room_id,display_order,patient_name,diagnostic,operation_type_id,scheduled_doctor_id,created_by,custom_notes,is_extra,extra_reason) VALUES (:date,:room,(SELECT COALESCE(MAX(x.display_order),0)+1 FROM operations x WHERE x.scheduled_date=:order_date AND x.room_id=:order_room AND x.status<>"deleted"),:patient,:diagnostic,:type,:doctor,:user,:notes,:extra,:extra_reason)');
    $extra = legacyIsCurrentWeek($date) || new DateTimeImmutable($date) < new DateTimeImmutable('today') ? 1 : 0;
    $needsApproval = $extra && legacyIsCurrentWeek($date);
    $extraReason = $extra ? ($needsApproval ? legacyExtraApprovalReason('pending', 'Programare in saptamana curenta') : 'Adaugata ulterior') : '';
    $statement->execute(['date'=>$date,'room'=>$roomId,'order_date'=>$date,'order_room'=>$roomId,'patient'=>$data['patient'],'diagnostic'=>$data['diagnostic'],'type'=>$data['type_id'],'doctor'=>$data['doctor_id'],'user'=>$apiUser['id'],'notes'=>$data['notes'],'extra'=>$extra,'extra_reason'=>$extraReason]);
    $id = (int) $db->lastInsertId();
    legacyOperationAudit($db, $id, 'created', null, $data);
    if ($needsApproval) legacyRequestExtraApproval($db, $id, $date, $data['patient'], (int)$data['doctor_id']);
    legacyMutationSuccess($db, [$date]);
}

function legacyAddEmergencyIntervention(PDO $db, array $input): never
{
    global $apiUser;
    requireLegacyPermission('progop.create', 'progop.manage_all');
    [$date, $roomId, $data] = legacyInterventionInput($db, $input);
    if ($date !== date('Y-m-d')) {
        throw new DomainException('Urgentele din Overview pot fi adaugate numai pentru ziua curenta.');
    }
    assertLegacyRoomAvailable($db, $date, $roomId);
    $statement = $db->prepare(
        'INSERT INTO operations
         (scheduled_date,room_id,display_order,patient_name,diagnostic,operation_type_id,scheduled_doctor_id,created_by,custom_notes,is_extra,extra_reason)
         VALUES
         (:date,:room,(SELECT COALESCE(MAX(x.display_order),0)+1 FROM operations x WHERE x.scheduled_date=:order_date AND x.room_id=:order_room AND x.status<>"deleted"),
          :patient,:diagnostic,:type,:doctor,:user,:notes,1,:extra_reason)'
    );
    $statement->execute([
        'date'=>$date,'room'=>$roomId,'order_date'=>$date,'order_room'=>$roomId,
        'patient'=>$data['patient'],'diagnostic'=>$data['diagnostic'],'type'=>$data['type_id'],
        'doctor'=>$data['doctor_id'],'user'=>$apiUser['id'],'notes'=>$data['notes'],
        'extra_reason'=>legacyExtraApprovalReason('pending', 'Urgenta adaugata din Overview'),
    ]);
    $id = (int)$db->lastInsertId();
    $estimatedTime = trim((string)($input['estimatedTime'] ?? ''));
    if ($estimatedTime !== '') {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $estimatedTime)) throw new DomainException('Ora estimata nu este valida.');
        legacyUpdateDashboardOperationState($id, ['estimated_time'=>$estimatedTime]);
    }
    legacyOperationAudit($db, $id, 'created', null, [
        'workflow'=>'overview_emergency','date'=>$date,'room_id'=>$roomId,
        'patient'=>$data['patient'],'doctor_id'=>$data['doctor_id'],'operation_type_id'=>$data['type_id'],
        'diagnostic'=>$data['diagnostic'],'notes'=>$data['notes'],'extra'=>true,
        'extra_reason'=>'Urgenta adaugata din Overview',
    ]);
    legacyRequestExtraApproval($db, $id, $date, $data['patient'], (int)$data['doctor_id']);
    legacyMutationSuccess($db, [$date], 'Urgenta a fost adaugata si trimisa spre aprobare.');
}

function legacyAddSurgeryRequest(PDO $db, array $input): never
{
    global $apiUser;
    requireLegacyPermission('progop.create', 'progop.manage_all');
    if (empty($apiUser['person_id']) || (string)($apiUser['specialty'] ?? '') !== 'OG') {
        legacyError('Doar un medic OG isi poate adauga o programare proprie.', 403);
    }
    [$date, $patient, $diagnostic, $notes, $typeId, $typeName, $doctor] = legacySurgeryRequestInput($db, $input);
    $roomId = legacyRoomId($db, legacyRooms($db)[0]);
    $extra = legacyIsCurrentWeek($date);
    $statement = $db->prepare(
        'INSERT INTO operations
         (scheduled_date,room_id,display_order,patient_name,diagnostic,operation_type_id,scheduled_doctor_id,created_by,custom_notes,is_extra,extra_reason)
         VALUES(:date,:room,0,:patient,:diagnostic,:type,:doctor,:user,:notes,:extra,:extra_reason)'
    );
    $statement->execute([
        'date'=>$date,'room'=>$roomId,'patient'=>$patient,'diagnostic'=>$diagnostic,'notes'=>$notes,'type'=>$typeId,
        'doctor'=>$doctor['id'],'user'=>$apiUser['id'],'extra'=>$extra?1:0,
        'extra_reason'=>$extra?legacyExtraApprovalReason('pending', 'Programare in saptamana curenta'):null,
    ]);
    $id = (int)$db->lastInsertId();
    legacyOperationAudit($db, $id, 'created', null, [
        'workflow'=>'surgery_request','date'=>$date,'patient'=>$patient,'operation_type'=>$typeName,
        'doctor_id'=>(int)$doctor['id'],'doctor_name'=>(string)$doctor['display_name'],'diagnostic'=>$diagnostic,'notes'=>$notes,
        'scheduled_by'=>(string)($apiUser['display_name'] ?? $apiUser['username']),
        'after_14_confirmed'=>(bool)($doctor['after14_confirmed'] ?? false),
        'after_14_confirmed'=>(bool)($doctor['after14_confirmed'] ?? false),
        'extra'=>$extra,'room_status'=>$extra?'extra':'pending',
    ]);
    if ($extra) legacyRequestExtraApproval($db, $id, $date, $patient, (int)$doctor['id']);
    legacyMutationSuccess($db, [$date], $extra
        ? 'Programarea EXTRA a fost salvata si trimisa spre aprobare.'
        : 'Programarea a fost salvata si asteapta repartizarea pe sala.');
}

function legacyEditSurgeryRequest(PDO $db, array $input): never
{
    $id = (int)($input['interventionId'] ?? 0);
    $before = requireLegacyEditableOperation($db, $id);
    if ((int)$before['display_order'] !== 0 && !(bool)$before['is_extra']) {
        throw new DomainException('Programarea a fost deja repartizata. Modificarea se face din sala alocata.');
    }
    [$date, $patient, $diagnostic, $notes, $typeId, $typeName, $doctor] = legacySurgeryRequestInput($db, $input);
    $extra = legacyIsCurrentWeek($date);
    $db->prepare(
        'UPDATE operations SET scheduled_date=:date,patient_name=:patient,diagnostic=:diagnostic,custom_notes=:notes,operation_type_id=:type,scheduled_doctor_id=:doctor,
         display_order=0,is_extra=:extra,extra_reason=:extra_reason WHERE id=:id'
    )->execute([
        'date'=>$date,'patient'=>$patient,'diagnostic'=>$diagnostic,'notes'=>$notes,'type'=>$typeId,'doctor'=>$doctor['id'],'extra'=>$extra?1:0,
        'extra_reason'=>$extra?legacyExtraApprovalReason('pending', 'Programare in saptamana curenta'):null,'id'=>$id,
    ]);
    legacyOperationAudit($db, $id, 'updated', $before, [
        'workflow'=>'surgery_request','date'=>$date,'patient'=>$patient,'operation_type'=>$typeName,
        'doctor_id'=>(int)$doctor['id'],'doctor_name'=>(string)$doctor['display_name'],'diagnostic'=>$diagnostic,'notes'=>$notes,
        'extra'=>$extra,'room_status'=>$extra?'extra':'pending',
    ]);
    if ($extra) legacyRequestExtraApproval($db, $id, $date, $patient, (int)$doctor['id']);
    legacyMutationSuccess($db, [(string)$before['scheduled_date'],$date], 'Programarea a fost actualizata.');
}

function legacyAssignSurgeryRoom(PDO $db, array $input): never
{
    if (!legacyCanAssignRooms()) legacyError('Numai asistentele, medicii ATI si conturile cu control complet pot repartiza operatii pe sali.', 403);
    $id = (int)($input['interventionId'] ?? 0);
    $before = legacyOperationForRoomAssignment($db, $id);
    $roomName = (string)($input['room'] ?? '');
    $roomId = legacyRoomId($db, $roomName);
    assertLegacyRoomAvailable($db, (string)$before['scheduled_date'], $roomId);
    $order = $db->prepare('SELECT COALESCE(MAX(display_order),0)+1 FROM operations WHERE scheduled_date=:date AND room_id=:room AND status<>"deleted"');
    $order->execute(['date'=>$before['scheduled_date'],'room'=>$roomId]);
    $displayOrder = max(1, (int)$order->fetchColumn());
    $db->prepare('UPDATE operations SET room_id=:room,display_order=:display_order WHERE id=:id')
        ->execute(['room'=>$roomId,'display_order'=>$displayOrder,'id'=>$id]);
    legacyOperationAudit($db, $id, 'moved', $before, ['workflow'=>'room_assignment','room'=>$roomName,'display_order'=>$displayOrder]);
    legacyMutationSuccess($db, [(string)$before['scheduled_date']], 'Programarea a fost repartizata in '.$roomName.'.');
}

function legacyUnassignSurgeryRoom(PDO $db, array $input): never
{
    if (!legacyCanAssignRooms()) legacyError('Nu ai dreptul sa retrimiti operatia la repartizare.', 403);
    $id = (int)($input['interventionId'] ?? 0);
    $before = legacyOperationForRoomAssignment($db, $id);
    $db->prepare('UPDATE operations SET display_order=0 WHERE id=:id')->execute(['id'=>$id]);
    legacyNormalizeRoomOrder($db, (string)$before['scheduled_date'], (int)$before['room_id']);
    legacyOperationAudit($db, $id, 'moved', $before, ['workflow'=>'room_unassignment','display_order'=>0]);
    legacyMutationSuccess($db, [(string)$before['scheduled_date']], 'Programarea a revenit in lista de repartizat.');
}

function legacyOperationForRoomAssignment(PDO $db, int $id): array
{
    $statement = $db->prepare('SELECT * FROM operations WHERE id=:id AND status<>"deleted"');
    $statement->execute(['id'=>$id]);
    $operation = $statement->fetch();
    if (!$operation) throw new DomainException('Interventia nu a fost gasita.');
    return $operation;
}

function legacyEditIntervention(PDO $db, array $input): never
{
    $id = (int) ($input['interventionId'] ?? 0);
    $before = requireLegacyEditableOperation($db, $id);
    [$date, $roomId, $data] = legacyInterventionInput($db, $input);
    $db->prepare('UPDATE operations SET patient_name=:patient,diagnostic=:diagnostic,operation_type_id=:type,scheduled_doctor_id=:doctor,custom_notes=:notes WHERE id=:id')->execute(['patient'=>$data['patient'],'diagnostic'=>$data['diagnostic'],'type'=>$data['type_id'],'doctor'=>$data['doctor_id'],'notes'=>$data['notes'],'id'=>$id]);
    legacyOperationAudit($db, $id, 'updated', $before, $data);
    legacyMutationSuccess($db, [$date]);
}

function legacyDeleteIntervention(PDO $db, array $input): never
{
    $id = (int) ($input['interventionId'] ?? 0);
    $before = requireLegacyEditableOperation($db, $id);
    $db->prepare('UPDATE operations SET status="deleted",deleted_at=NOW() WHERE id=:id')->execute(['id'=>$id]);
    if ((int)$before['display_order'] > 0) legacyNormalizeRoomOrder($db, (string)$before['scheduled_date'], (int)$before['room_id']);
    legacyOperationAudit($db, $id, 'deleted', $before, ['status'=>'deleted']);
    legacyMutationSuccess($db, [(string) $before['scheduled_date']]);
}

function legacyMoveIntervention(PDO $db, array $input): never
{
    $id = (int) ($input['interventionId'] ?? 0);
    $before = requireLegacyEditableOperation($db, $id);
    $date = validDate((string) ($input['toDate'] ?? ''));
    legacyAssertDashboardDate($date);
    $roomId = legacyRoomId($db, (string) ($input['toRoom'] ?? ''));
    assertLegacyRoomAvailable($db, $date, $roomId);
    $position = max(1, (int) ($input['position'] ?? 9999));
    $db->beginTransaction();
    $db->prepare('UPDATE operations SET display_order=display_order-1 WHERE scheduled_date=:date AND room_id=:room AND display_order>:position AND status<>"deleted"')
        ->execute(['date'=>$before['scheduled_date'],'room'=>$before['room_id'],'position'=>$before['display_order']]);
    if ((string)$before['scheduled_date'] === $date && (int)$before['room_id'] === $roomId && $position > (int)$before['display_order']) $position--;
    $count=$db->prepare('SELECT COUNT(*) FROM operations WHERE scheduled_date=:date AND room_id=:room AND status<>"deleted" AND id<>:id');
    $count->execute(['date'=>$date,'room'=>$roomId,'id'=>$id]);$position=min($position,(int)$count->fetchColumn()+1);
    $db->prepare('UPDATE operations SET display_order=display_order+1 WHERE scheduled_date=:date AND room_id=:room AND display_order>=:position AND status<>"deleted"')->execute(['date'=>$date,'room'=>$roomId,'position'=>$position]);
    $db->prepare('UPDATE operations SET scheduled_date=:date,room_id=:room,display_order=:position WHERE id=:id')->execute(['date'=>$date,'room'=>$roomId,'position'=>$position,'id'=>$id]);
    legacyNormalizeRoomOrder($db, (string)$before['scheduled_date'], (int)$before['room_id']);
    legacyNormalizeRoomOrder($db, $date, $roomId);
    legacyOperationAudit($db, $id, 'moved', $before, ['date'=>$date,'room_id'=>$roomId,'position'=>$position]);
    $db->commit();
    legacyMutationSuccess($db, [(string)$before['scheduled_date'],$date]);
}

function legacyReorderIntervention(PDO $db, array $input): never
{
    $id = (int) ($input['interventionId'] ?? 0);
    $before = requireLegacyEditableOperation($db, $id);
    $direction = (string) ($input['direction'] ?? '');
    $operator = $direction === 'up' ? '<' : '>';
    $order = $direction === 'up' ? 'DESC' : 'ASC';
    $neighbor = $db->prepare("SELECT id,display_order FROM operations WHERE scheduled_date=:date AND room_id=:room AND status<>\"deleted\" AND display_order $operator :position ORDER BY display_order $order LIMIT 1");
    $neighbor->execute(['date'=>$before['scheduled_date'],'room'=>$before['room_id'],'position'=>$before['display_order']]);
    $other = $neighbor->fetch();
    if ($other) {
        $db->beginTransaction();
        $db->prepare('UPDATE operations SET display_order=:position WHERE id=:id')->execute(['position'=>$other['display_order'],'id'=>$id]);
        $db->prepare('UPDATE operations SET display_order=:position WHERE id=:id')->execute(['position'=>$before['display_order'],'id'=>$other['id']]);
        legacyOperationAudit($db, $id, 'reordered', $before, ['display_order'=>$other['display_order']]);
        $db->commit();
    }
    legacyMutationSuccess($db, [(string)$before['scheduled_date']]);
}

function legacyNormalizeRoomOrder(PDO $db, string $date, int $roomId): void
{
    $statement = $db->prepare(
        'SELECT id FROM operations
         WHERE scheduled_date=:date AND room_id=:room AND status<>"deleted" AND display_order>0
         ORDER BY display_order,id'
    );
    $statement->execute(['date'=>$date,'room'=>$roomId]);
    $update = $db->prepare('UPDATE operations SET display_order=:position WHERE id=:id');
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $index => $operationId) {
        $update->execute(['position'=>$index + 1,'id'=>(int)$operationId]);
    }
}

function legacyMoveOperationToRoomEdge(PDO $db, array $operation, string $edge): void
{
    if ((int)($operation['display_order'] ?? 0) <= 0 || (int)($operation['room_id'] ?? 0) <= 0) return;
    if ($edge === 'start') {
        $db->prepare(
            'UPDATE operations SET display_order=display_order+1
             WHERE scheduled_date=:date AND room_id=:room AND id<>:id AND status<>"deleted" AND display_order>0'
        )->execute(['date'=>$operation['scheduled_date'],'room'=>$operation['room_id'],'id'=>$operation['id']]);
        $db->prepare('UPDATE operations SET display_order=1 WHERE id=:id')->execute(['id'=>$operation['id']]);
    } else {
        $statement = $db->prepare(
            'SELECT COALESCE(MAX(display_order),0)+1 FROM operations
             WHERE scheduled_date=:date AND room_id=:room AND id<>:id AND status<>"deleted" AND display_order>0'
        );
        $statement->execute(['date'=>$operation['scheduled_date'],'room'=>$operation['room_id'],'id'=>$operation['id']]);
        $db->prepare('UPDATE operations SET display_order=:position WHERE id=:id')->execute([
            'position'=>(int)$statement->fetchColumn(),
            'id'=>$operation['id'],
        ]);
    }
    legacyNormalizeRoomOrder($db, (string)$operation['scheduled_date'], (int)$operation['room_id']);
}

function legacyToggleCompleted(PDO $db, array $input): never
{
    $id=(int)($input['interventionId']??0); $before=requireLegacyEditableOperation($db,$id,true); $completed=!empty($input['completed']);
    legacyAssertDashboardDate((string)$before['scheduled_date']);
    if ((string)$before['status']==='completed' && !$completed && !legacyCurrentUserHasRole('super_admin') && !legacyCurrentUserHasRole('operating_room_dashboard')) {
        legacyError('Numai Superuserul poate modifica o operatie efectuata.', 403);
    }
    $db->beginTransaction();
    $db->prepare('UPDATE operations SET status=:status,completed_at=:at WHERE id=:id')->execute(['status'=>$completed?'completed':'scheduled','at'=>$completed?date('Y-m-d H:i:s'):null,'id'=>$id]);
    if ($completed) legacyMoveOperationToRoomEdge($db, $before, 'end');
    legacyOperationAudit($db,$id,'completed',$before,['completed'=>$completed]);
    $db->commit();
    legacyUpdateDashboardOperationState($id, ['status'=>$completed?'completed':'scheduled']);
    legacyMutationSuccess($db,[(string)$before['scheduled_date']]);
}

function legacySetDashboardStatus(PDO $db, array $input): never
{
    $id = (int)($input['interventionId'] ?? 0);
    $status = (string)($input['status'] ?? '');
    if (!in_array($status, ['scheduled', 'in_room', 'in_progress', 'completed'], true)) {
        throw new DomainException('Starea operatiei nu este valida.');
    }
    $before = requireLegacyEditableOperation($db, $id, true);
    legacyAssertDashboardDate((string)$before['scheduled_date']);
    if ((string)$before['status'] === 'completed' && $status !== 'completed'
        && !legacyCurrentUserHasRole('super_admin') && !legacyCurrentUserHasRole('operating_room_dashboard')) {
        legacyError('Numai Superuserul poate modifica o operatie efectuata.', 403);
    }
    $completed = $status === 'completed';
    $db->beginTransaction();
    $db->prepare('UPDATE operations SET status=:status,completed_at=:completed_at WHERE id=:id')->execute([
        'status'=>$completed?'completed':'scheduled',
        'completed_at'=>$completed?date('Y-m-d H:i:s'):null,
        'id'=>$id,
    ]);
    if (in_array($status, ['in_room','in_progress'], true)) legacyMoveOperationToRoomEdge($db, $before, 'start');
    if ($completed) legacyMoveOperationToRoomEdge($db, $before, 'end');
    legacyOperationAudit($db, $id, 'completed', $before, ['operating_status'=>$status,'completed'=>$completed]);
    $db->commit();
    legacyUpdateDashboardOperationState($id, ['status'=>$status]);
    legacyMutationSuccess($db, [(string)$before['scheduled_date']], 'Starea operatiei a fost actualizata.');
}

function legacySetEstimatedTime(PDO $db, array $input): never
{
    $id = (int)($input['interventionId'] ?? 0);
    $estimatedTime = trim((string)($input['estimatedTime'] ?? ''));
    if ($estimatedTime !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $estimatedTime)) {
        throw new DomainException('Ora estimata nu este valida.');
    }
    $operation = requireLegacyEditableOperation($db, $id);
    legacyAssertDashboardDate((string)$operation['scheduled_date']);
    legacyUpdateDashboardOperationState($id, ['estimated_time'=>$estimatedTime]);
    apiAudit($db, 'progop.estimated_time_updated', 'operation', $id, null, ['estimated_time'=>$estimatedTime]);
    legacyMutationSuccess($db, [(string)$operation['scheduled_date']], 'Ora estimata a fost actualizata.');
}

function legacyDashboardOperationStatePath(): string
{
    return dirname(__DIR__, 2) . '/storage/config/dashboard_operation_state.json';
}

function legacyDashboardOperationState(): array
{
    $path = legacyDashboardOperationStatePath();
    if (!is_file($path)) return ['format_version'=>1,'operations'=>[]];
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) return ['format_version'=>1,'operations'=>[]];
    if (!is_array($decoded['operations'] ?? null)) $decoded['operations'] = [];
    return $decoded;
}

function legacyUpdateDashboardOperationState(int $operationId, array $changes): void
{
    global $apiUser;
    $state = legacyDashboardOperationState();
    $key = (string)$operationId;
    $current = is_array($state['operations'][$key] ?? null) ? $state['operations'][$key] : [];
    $next = array_merge($current, $changes, ['updated_at'=>date(DATE_ATOM),'updated_by'=>(int)$apiUser['id']]);
    if (($next['status'] ?? 'scheduled') === 'scheduled' && ($next['estimated_time'] ?? '') === '') {
        unset($state['operations'][$key]);
    } else {
        $state['operations'][$key] = $next;
    }
    $state['format_version'] = 1;
    $state['updated_at'] = date(DATE_ATOM);
    $directory = dirname(legacyDashboardOperationStatePath());
    if (!is_dir($directory)) mkdir($directory, 0775, true);
    if (file_put_contents(legacyDashboardOperationStatePath(), json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new DomainException('Starea panoului nu a putut fi salvata.');
    }
}

function legacyReviewExtraOperation(PDO $db, array $input): never
{
    global $apiUser;
    $username = strtolower((string)($apiUser['username'] ?? ''));
    if (!in_array($username, ['cucu.andrei', 'traian.maier'], true)) {
        legacyError('Doar directorii medicali desemnati pot aproba operatiile EXTRA.', 403);
    }
    $operationId = (int)($input['operationId'] ?? 0);
    $approved = !empty($input['approved']);
    $rejectionReason = trim((string)($input['rejectionReason'] ?? ''));
    if (!$approved && $rejectionReason === '') throw new DomainException('Motivul refuzului este obligatoriu.');
    $statement = $db->prepare(
        'SELECT o.*,p.display_name doctor_name FROM operations o JOIN people p ON p.id=o.scheduled_doctor_id
         WHERE o.id=:id AND o.status<>"deleted" AND o.is_extra=1'
    );
    $statement->execute(['id'=>$operationId]);
    $operation = $statement->fetch();
    if (!$operation) throw new DomainException('Operatia EXTRA nu a fost gasita.');

    $meta = legacyExtraApprovalMeta(true, (string)($operation['extra_reason'] ?? ''));
    $state = $approved ? 'approved' : 'rejected';
    $reason = legacyExtraApprovalReason($state, $approved ? ($meta['reason'] ?: 'Programare in saptamana curenta') : $rejectionReason);
    $db->prepare('UPDATE operations SET extra_reason=:reason WHERE id=:id')->execute(['reason'=>$reason,'id'=>$operationId]);
    apiAudit($db, $approved ? 'progop.extra_approved' : 'progop.extra_rejected', 'operation', $operationId,
        ['approval_state'=>$meta['state']], ['approval_state'=>$state,'patient_name'=>$operation['patient_name'],'scheduled_date'=>$operation['scheduled_date'],'rejection_reason'=>$approved ? null : $rejectionReason]);

    $recipients = $db->prepare(
        'SELECT DISTINCT u.id FROM users u WHERE u.status="active" AND (u.id=:creator OR u.person_id=:doctor) AND u.id<>:actor'
    );
    $recipients->execute(['creator'=>$operation['created_by'],'doctor'=>$operation['scheduled_doctor_id'],'actor'=>$apiUser['id']]);
    $title = $approved ? 'Operatie EXTRA aprobata' : 'Operatie EXTRA neaprobata';
    $message = sprintf('%s din %s, pentru %s, a fost %s de %s.', (string)$operation['patient_name'],
        (new DateTimeImmutable((string)$operation['scheduled_date']))->format('d.m.Y'), (string)$operation['doctor_name'],
        $approved ? 'aprobata' : 'respinsa', (string)($apiUser['display_name'] ?? $apiUser['username']));
    if (!$approved) $message .= ' Motiv: ' . $rejectionReason . '.';
    $insert = $db->prepare('INSERT INTO notifications(user_id,notification_type,title,message,entity_type,entity_id) VALUES(:user_id,:type,:title,:message,"operation",:entity_id)');
    foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        $insert->execute(['user_id'=>$userId,'type'=>'extra_approval_result','title'=>$title,'message'=>$message,'entity_id'=>$operationId]);
    }
    legacyMutationSuccess($db, [(string)$operation['scheduled_date']], $approved ? 'Operatia EXTRA a fost aprobata.' : 'Operatia EXTRA a fost marcata ca neaprobata.');
}

function legacyBlockRoom(PDO $db,array $input): never
{
    global $apiUser; requireLegacyPermission('progop.manage_all'); $date=validDate((string)($input['date']??'')); $room=legacyRoomId($db,(string)($input['room']??'')); $reason=trim((string)($input['reason']??''));
    $db->prepare('INSERT INTO room_blocks(room_id,block_date,reason,created_by) VALUES(:room,:date,:reason,:user)')->execute(['room'=>$room,'date'=>$date,'reason'=>$reason,'user'=>$apiUser['id']]);
    apiAudit($db,'progop.room_blocked','room_block',(int)$db->lastInsertId(),null,['date'=>$date,'room_id'=>$room,'reason'=>$reason]); legacyMutationSuccess($db,[$date]);
}

function legacyUnblockRoom(PDO $db,array $input): never
{
    global $apiUser; requireLegacyPermission('progop.manage_all'); $date=validDate((string)($input['date']??'')); $room=legacyRoomId($db,(string)($input['room']??''));
    $statement=$db->prepare('SELECT * FROM room_blocks WHERE block_date=:date AND room_id=:room AND removed_at IS NULL ORDER BY id DESC LIMIT 1'); $statement->execute(['date'=>$date,'room'=>$room]); $before=$statement->fetch();
    if($before){$db->prepare('UPDATE room_blocks SET removed_by=:user,removed_at=NOW() WHERE id=:id')->execute(['user'=>$apiUser['id'],'id'=>$before['id']]); apiAudit($db,'progop.room_unblocked','room_block',(int)$before['id'],$before,null);} legacyMutationSuccess($db,[$date]);
}

function legacyAddDoctor(PDO $db,array $input): never
{
    requireLegacyPermission('progop.manage_settings'); $name=legacyRequired((string)($input['value']??''),'Numele medicului este obligatoriu.'); [$first,$last]=splitLegacyName($name);
    if (legacyDuplicatePersonId($db, $name) !== null) throw new DomainException('Exista deja un medic activ cu acest nume. Foloseste administrarea personalului.');
    $db->prepare('INSERT INTO people(first_name,last_name,display_name,specialty,professional_category,guard_eligible) VALUES(:first,:last,:display,"OG","Medic specialist",0)')->execute(['first'=>$first,'last'=>$last,'display'=>$name]); apiAudit($db,'admin.person_created','person',(int)$db->lastInsertId(),null,['display_name'=>$name]); legacyMutationSuccess($db,[]);
}

function legacyUpdateDoctor(PDO $db,array $input): never
{
    requireLegacyPermission('progop.manage_settings'); $old=legacyRequired((string)($input['oldValue']??''),'Lipseste medicul.'); $new=legacyRequired((string)($input['newValue']??''),'Numele nou este obligatoriu.'); $person=legacyPersonByName($db,$old); [$first,$last]=splitLegacyName($new);
    $duplicateId = legacyDuplicatePersonId($db, $new);
    if ($duplicateId !== null && $duplicateId !== (int) $person['id']) throw new DomainException('Exista deja un medic activ cu acest nume.');
    $db->prepare('UPDATE people SET first_name=:first,last_name=:last,display_name=:display WHERE id=:id')->execute(['first'=>$first,'last'=>$last,'display'=>$new,'id'=>$person['id']]); apiAudit($db,'admin.person_updated','person',(int)$person['id'],$person,['display_name'=>$new]); legacyMutationSuccess($db,[]);
}

function legacyDeleteDoctor(PDO $db,array $input): never
{
    requireLegacyPermission('progop.manage_settings'); $person=legacyPersonByName($db,legacyRequired((string)($input['value']??''),'Lipseste medicul.')); $used=$db->prepare('SELECT COUNT(*) FROM operations WHERE scheduled_doctor_id=:id AND status<>"deleted"');$used->execute(['id'=>$person['id']]);if((int)$used->fetchColumn()>0)throw new DomainException('Medicul este folosit in programari si nu poate fi sters.');$db->prepare('UPDATE people SET active=0 WHERE id=:id')->execute(['id'=>$person['id']]);apiAudit($db,'admin.person_disabled','person',(int)$person['id'],$person,['active'=>0]);legacyMutationSuccess($db,[]);
}

function legacyAddOperationType(PDO $db,array $input): never { requireLegacyPermission('progop.manage_settings');$name=legacyRequired((string)($input['value']??''),'Tipul este obligatoriu.');$db->prepare('INSERT INTO operation_types(name) VALUES(:name)')->execute(['name'=>$name]);apiAudit($db,'progop.type_created','operation_type',(int)$db->lastInsertId(),null,['name'=>$name]);legacyMutationSuccess($db,[]); }
function legacyUpdateOperationType(PDO $db,array $input): never { requireLegacyPermission('progop.manage_settings');$old=legacyRequired((string)($input['oldValue']??''),'Lipseste tipul.');$new=legacyRequired((string)($input['newValue']??''),'Noul tip este obligatoriu.');$statement=$db->prepare('SELECT * FROM operation_types WHERE name=:name AND active=1');$statement->execute(['name'=>$old]);$before=$statement->fetch();if(!$before)throw new DomainException('Tipul nu exista.');$db->prepare('UPDATE operation_types SET name=:name WHERE id=:id')->execute(['name'=>$new,'id'=>$before['id']]);apiAudit($db,'progop.type_updated','operation_type',(int)$before['id'],$before,['name'=>$new]);legacyMutationSuccess($db,[]); }
function legacyDeleteOperationType(PDO $db,array $input): never { requireLegacyPermission('progop.manage_settings');$name=legacyRequired((string)($input['value']??''),'Lipseste tipul.');$statement=$db->prepare('SELECT * FROM operation_types WHERE name=:name AND active=1');$statement->execute(['name'=>$name]);$before=$statement->fetch();if(!$before)throw new DomainException('Tipul nu exista.');$used=$db->prepare('SELECT COUNT(*) FROM operations WHERE operation_type_id=:id AND status<>"deleted"');$used->execute(['id'=>$before['id']]);if((int)$used->fetchColumn()>0)throw new DomainException('Tipul este folosit si nu poate fi sters.');$db->prepare('UPDATE operation_types SET active=0 WHERE id=:id')->execute(['id'=>$before['id']]);apiAudit($db,'progop.type_disabled','operation_type',(int)$before['id'],$before,['active'=>0]);legacyMutationSuccess($db,[]); }

function legacyBackup(PDO $db): never
{
    requireLegacyPermission('progop.manage_all');
    global $apiUser; $directory=dirname(__DIR__,2).'/storage/backups';if(!is_dir($directory))mkdir($directory,0775,true);$file='backup_sql_'.date('Ymd_His').'.json';
    $guardLinesPath=dirname(__DIR__,2).'/storage/config/guard_monthly_lines.json';$guardMonthlyLines=is_file($guardLinesPath)?json_decode((string)file_get_contents($guardLinesPath),true):[];
    $data=['created_at'=>date(DATE_ATOM),'people'=>$db->query('SELECT * FROM people')->fetchAll(),'operations'=>$db->query('SELECT * FROM operations')->fetchAll(),'blocks'=>$db->query('SELECT * FROM room_blocks')->fetchAll(),'types'=>$db->query('SELECT * FROM operation_types')->fetchAll(),'ati_room_assignments'=>legacyAllAtiAssignments(),'resident_stages'=>legacyResidentStages(),'guard_monthly_lines'=>$guardMonthlyLines];
    file_put_contents($directory.'/'.$file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));apiAudit($db,'progop.backup_created','backup',null,null,['file'=>$file]);legacyMutationSuccess($db,[],'Backup SQL creat cu succes.');
}

function legacyInterventionInput(PDO $db,array $input): array
{
    $date=validDate((string)($input['date']??''));$room=legacyRoomId($db,(string)($input['room']??''));$item=is_array($input['intervention']??null)?$input['intervention']:[];
    $patient=legacyRequired((string)($item['patientName']??''),'Numele pacientului este obligatoriu.');$doctor=legacyPersonByName($db,legacyRequired((string)($item['doctor']??''),'Medicul este obligatoriu.'));
    $typeName=legacyRequired((string)($item['operationType']??''),'Tipul operatiei este obligatoriu.');$type=$db->prepare('SELECT id FROM operation_types WHERE name=:name AND active=1');$type->execute(['name'=>$typeName]);$typeId=(int)$type->fetchColumn();if(!$typeId)throw new DomainException('Tipul operatiei nu este activ.');
    return [$date,$room,['patient'=>mb_substr($patient,0,220),'diagnostic'=>trim((string)($item['diagnostic']??'')),'notes'=>trim((string)($item['customNotes']??'')),'doctor_id'=>(int)$doctor['id'],'type_id'=>$typeId]];
}

function legacySurgeryRequestInput(PDO $db, array $input): array
{
    $date = validDate((string)($input['date'] ?? ''));
    if ($date < date('Y-m-d')) throw new DomainException('Programarea nu poate fi facuta pentru o zi din trecut.');
    $item = is_array($input['intervention'] ?? null) ? $input['intervention'] : [];
    $patient = legacyRequired((string)($item['patientName'] ?? ''), 'Numele pacientei este obligatoriu.');
    $diagnostic = mb_substr(trim((string)($item['diagnostic'] ?? '')), 0, 2000);
    $notes = mb_substr(trim((string)($item['customNotes'] ?? '')), 0, 4000);
    $doctorId = (int)($item['doctorId'] ?? 0);
    $doctorStatement = $db->prepare('SELECT id,display_name,professional_category FROM people WHERE id=:id AND active=1 AND specialty="OG"');
    $doctorStatement->execute(['id'=>$doctorId]);
    $doctor = $doctorStatement->fetch();
    if (!$doctor) throw new DomainException('Selecteaza un medic OG activ.');
    $after14Confirmed = !empty($item['after14Confirmed']);
    legacyValidateResidentStage($db, $doctor, $date, $after14Confirmed);
    $doctor['after14_confirmed'] = $after14Confirmed;
    $typeName = legacyRequired((string)($item['operationType'] ?? ''), 'Tipul operatiei este obligatoriu.');
    $type = $db->prepare('SELECT id,name FROM operation_types WHERE name=:name AND active=1');
    $type->execute(['name'=>$typeName]);
    $typeRow = $type->fetch();
    if (!$typeRow) throw new DomainException('Tipul operatiei nu este activ.');
    return [$date, mb_substr($patient, 0, 220), $diagnostic, $notes, (int)$typeRow['id'], (string)$typeRow['name'], $doctor];
}

function legacyIsResident(string $category): bool
{
    return str_contains(mb_strtolower($category), 'rezident');
}

function legacyResidentStages(): array
{
    $path = dirname(__DIR__, 2) . '/storage/config/resident_stages.json';
    if (!is_file($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded['stages'] ?? null) ? array_values($decoded['stages']) : [];
}

function legacyValidateResidentStage(PDO $db, array $doctor, string $date, bool $after14Confirmed): void
{
    if (!legacyIsResident((string)($doctor['professional_category'] ?? ''))) return;
    $external = false;
    foreach (legacyResidentStages() as $stage) {
        if (!is_array($stage) || empty($stage['start_date']) || empty($stage['end_date'])) continue;
        $matches = (!empty($stage['person_id']) && (int)$stage['person_id'] === (int)$doctor['id'])
            || (!empty($stage['display_name']) && mb_strtolower(trim((string)$stage['display_name'])) === mb_strtolower(trim((string)$doctor['display_name'])));
        if ($matches && $date >= (string)$stage['start_date'] && $date <= (string)$stage['end_date']) { $external = true; break; }
    }
    if (!$external) return;
    $guard = $db->prepare(
        'SELECT COUNT(*) FROM guard_assignments ga JOIN guard_plan_versions gv ON gv.id=ga.version_id
         JOIN guard_plans gp ON gp.current_version_id=gv.id
         WHERE ga.guard_date=:date AND ga.current_person_id=:person_id'
    );
    $guard->execute(['date'=>$date, 'person_id'=>(int)$doctor['id']]);
    if ((int)$guard->fetchColumn() > 0) return;
    if (!$after14Confirmed) throw new DomainException('Rezidentul este in stagiu extern la data operatiei. Confirma programarea dupa ora 14:00 sau alege o zi in care este de garda.');
}

function legacySaveMyResidentStage(PDO $db, array $input): never
{
    global $apiUser;
    if (!legacyCurrentUserIsResident() || empty($apiUser['person_id'])) legacyError('Configurarea stagiului este disponibila numai conturilor de medic rezident.', 403);
    $start=validDate((string)($input['startDate']??''));$end=validDate((string)($input['endDate']??''));if($end<$start)throw new DomainException('Data de final nu poate fi inaintea datei de inceput.');
    $person=$db->prepare('SELECT id,display_name FROM people WHERE id=:id');$person->execute(['id'=>$apiUser['person_id']]);$person=$person->fetch();
    $path=dirname(__DIR__,2).'/storage/config/resident_stages.json';$directory=dirname($path);if(!is_dir($directory))mkdir($directory,0775,true);
    $before=legacyResidentStages();$stages=array_values(array_filter($before,static fn(array $stage):bool=>(int)($stage['person_id']??0)!==(int)$person['id']));
    $entry=['person_id'=>(int)$person['id'],'display_name'=>(string)$person['display_name'],'start_date'=>$start,'end_date'=>$end,'hospital'=>mb_substr(trim((string)($input['hospital']??'')),0,220),'notes'=>mb_substr(trim((string)($input['notes']??'')),0,500),'updated_by'=>(int)$apiUser['id'],'updated_at'=>date(DATE_ATOM)];$stages[]=$entry;
    $written=file_put_contents($path,json_encode(['format_version'=>1,'stages'=>$stages],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);if($written===false)throw new DomainException('Configurarea stagiului nu a putut fi salvata.');
    apiAudit($db,'resident.stage_updated','person',(int)$person['id'],$before,$entry);legacyMutationSuccess($db,[date('Y-m-d')],'Perioada de stagiu extern a fost salvata.');
}

function legacyExtraApprovalReason(string $state, string $reason): string
{
    $reason = trim(preg_replace('/^\[\[EXTRA_(?:PENDING|REJECTED)\]\]\s*/', '', $reason) ?? $reason);
    return match ($state) {
        'pending' => '[[EXTRA_PENDING]] ' . $reason,
        'rejected' => '[[EXTRA_REJECTED]] ' . $reason,
        default => $reason,
    };
}

function legacyExtraApprovalMeta(bool $isExtra, string $reason): array
{
    if (!$isExtra) return ['state'=>'none','label'=>'','reason'=>''];
    if (str_starts_with($reason, '[[EXTRA_PENDING]]')) {
        return ['state'=>'pending','label'=>'NEAPROBATA','reason'=>trim(substr($reason, strlen('[[EXTRA_PENDING]]')))];
    }
    if (str_starts_with($reason, '[[EXTRA_REJECTED]]')) {
        return ['state'=>'rejected','label'=>'NEAPROBATA','reason'=>trim(substr($reason, strlen('[[EXTRA_REJECTED]]')))];
    }
    return ['state'=>'approved','label'=>'EXTRA','reason'=>$reason];
}

function legacyRequestExtraApproval(PDO $db, int $operationId, string $date, string $patient, int $doctorId): void
{
    global $apiUser;
    $doctor = $db->prepare('SELECT display_name FROM people WHERE id=:id');
    $doctor->execute(['id'=>$doctorId]);
    $doctorName = (string)($doctor->fetchColumn() ?: 'medic nespecificat');
    $reviewers = $db->query('SELECT id FROM users WHERE status="active" AND username IN ("cucu.andrei","traian.maier")')->fetchAll(PDO::FETCH_COLUMN);
    $message = sprintf('Operatia EXTRA pentru %s, medic %s, din %s, a fost adaugata de %s si asteapta decizia.',
        $patient, $doctorName, (new DateTimeImmutable($date))->format('d.m.Y'), (string)($apiUser['display_name'] ?? $apiUser['username']));
    $insert = $db->prepare('INSERT INTO notifications(user_id,notification_type,title,message,entity_type,entity_id) VALUES(:user_id,"extra_approval_request","Operatie EXTRA de aprobat",:message,"operation",:entity_id)');
    foreach ($reviewers as $userId) {
        $insert->execute(['user_id'=>$userId,'message'=>$message,'entity_id'=>$operationId]);
    }
    apiAudit($db, 'progop.extra_approval_requested', 'operation', $operationId, null, ['scheduled_date'=>$date,'patient_name'=>$patient,'doctor_id'=>$doctorId]);
}

function legacyIsCurrentWeek(string $date): bool
{
    $today = new DateTimeImmutable('today');
    $start = $today->modify('monday this week');
    $end = $start->modify('+6 days');
    $point = new DateTimeImmutable($date);
    return $point >= $start && $point <= $end;
}

function requireLegacyEditableOperation(PDO $db,int $id,bool $allowCompletedTransition=false): array
{
    global $apiPermissions,$apiUser;$statement=$db->prepare('SELECT * FROM operations WHERE id=:id AND status<>"deleted"');$statement->execute(['id'=>$id]);$operation=$statement->fetch();if(!$operation)throw new DomainException('Interventia nu a fost gasita.');
    $canEditCreated=in_array('progop.edit_own',$apiPermissions,true)||in_array('progop.create',$apiPermissions,true);
    $allowed=in_array('progop.manage_all',$apiPermissions,true)||($canEditCreated&&(int)$operation['created_by']===(int)$apiUser['id']);if(!$allowed)legacyError('Poti modifica doar interventiile programate de tine.',403);
    legacyAssertDashboardDate((string)$operation['scheduled_date']);
    if ((string)$operation['status']==='completed' && !$allowCompletedTransition && !legacyCurrentUserHasRole('super_admin')) {
        legacyError('Operatiile efectuate pot fi modificate numai de Superuser.',403);
    }
    return $operation;
}

function legacyRooms(PDO $db): array
{
    $rooms = $db->query('SELECT name FROM operation_rooms WHERE active=1 ORDER BY display_order,id LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
    if (!$rooms) throw new DomainException('Nu exista nicio sala activa configurata.');
    if (count($rooms) > 4) throw new DomainException('Sunt active mai mult de patru sali. Pastreaza maximum patru sali active.');
    return array_map('strval', $rooms);
}

function legacyRoomId(PDO $db,string $name): int { if(!in_array($name,legacyRooms($db),true))throw new DomainException('Sala nu este valida.');$statement=$db->prepare('SELECT id FROM operation_rooms WHERE name=:name AND active=1');$statement->execute(['name'=>$name]);$id=(int)$statement->fetchColumn();if(!$id)throw new DomainException('Sala nu este activa.');return $id; }
function legacyDuplicatePersonId(PDO $db,string $name): ?int { $statement=$db->prepare('SELECT id FROM people WHERE active=1 AND specialty="OG" AND LOWER(TRIM(display_name))=LOWER(TRIM(:name)) ORDER BY id');$statement->execute(['name'=>$name]);$ids=array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));if(count($ids)>1)throw new DomainException('Exista mai multi medici activi cu acest nume. Corecteaza lista din Administrare.');return $ids[0]??null; }
function legacyPersonByName(PDO $db,string $name): array { $id=legacyDuplicatePersonId($db,$name);if($id===null)throw new DomainException('Medicul OG nu a fost gasit.');$statement=$db->prepare('SELECT * FROM people WHERE id=:id');$statement->execute(['id'=>$id]);return $statement->fetch(); }
function assertLegacyRoomAvailable(PDO $db,string $date,int $room): void { $statement=$db->prepare('SELECT COUNT(*) FROM room_blocks WHERE block_date=:date AND room_id=:room AND removed_at IS NULL');$statement->execute(['date'=>$date,'room'=>$room]);if((int)$statement->fetchColumn()>0)throw new DomainException('Sala este blocata in ziua selectata.'); }
function legacyRequired(string $value,string $message): string { $value=trim($value);if($value==='')throw new DomainException($message);return $value; }
function splitLegacyName(string $name): array { $parts=preg_split('/\s+/u',trim($name))?:[$name];if(count($parts)===1)return[$parts[0],$parts[0]];$first=array_shift($parts);return[$first,implode(' ',$parts)]; }

function legacyOperationAudit(PDO $db,int $id,string $event,mixed $before,mixed $after): void
{
    global $apiUser;$db->prepare('INSERT INTO operation_events(operation_id,event_type,performed_by,before_data,after_data) VALUES(:operation,:event,:user,:before,:after)')->execute(['operation'=>$id,'event'=>$event,'user'=>$apiUser['id'],'before'=>$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE),'after'=>$after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE)]);apiAudit($db,'progop.operation_'.$event,'operation',$id,$before,$after);
}

function legacyMutationSuccess(PDO $db,array $dates,string $message='Datele au fost salvate.'): never
{
    $months=[];foreach($dates as $date){if(preg_match('/^\d{4}-\d{2}/',$date,$match))$months[]=$match[0];}if(!$months)$months=[date('Y-m')];legacySuccess(legacyPayload($db,array_unique($months)),$message);
}

function requireLegacyPermission(string ...$permissions): void
{
    global $apiPermissions;foreach($permissions as $permission)if(in_array($permission,$apiPermissions,true))return;legacyError('Nu ai dreptul necesar pentru aceasta actiune.',403);
}

function dateRange(string $start,string $end): array { $result=[];$cursor=new DateTimeImmutable($start);$last=new DateTimeImmutable($end);while($cursor<=$last){$result[]=$cursor->format('Y-m-d');$cursor=$cursor->modify('+1 day');}return$result; }

function legacySuccess(array $data=[],?string $message=null): never { echo json_encode(['success'=>true,'data'=>$data,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
function legacyError(string $message,int $status=400): never { http_response_code($status);echo json_encode(['success'=>false,'data'=>null,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }

function generateLegacyExport(PDO $db,array $query): never
{
    requireLegacyPermission('progop.view');$mode=(string)($query['mode']??'day');$format='pdf';$date=validDate((string)($query['date']??date('Y-m-d')));$month=validMonth((string)($query['month']??substr($date,0,7)));
    [$start,$end]=legacyExportRange($db,$mode,$date,$month,(string)($query['startDate']??''),(string)($query['endDate']??''));$rows=legacyExportRows($db,$start,$end,(string)($query['room']??''),(string)($query['doctor']??''),$mode);
    $directory=dirname(__DIR__,2).'/storage/exports';if(!is_dir($directory))mkdir($directory,0775,true);$file='program_operatii_'.$mode.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(8)).'.'.$format;$path=$directory.'/'.$file;
    writeLegacyPdf($path,$rows,'Program operatii '.$start.' - '.$end,legacyRooms($db));
    legacySuccess(['file'=>'api/progop-legacy.php?action=download&file='.rawurlencode($file),'format'=>$format,'rows'=>count($rows)]);
}

function legacyExportRange(PDO $db,string $mode,string $date,string $month,string $from,string $to): array
{
    if($mode==='day')return[$date,$date];if($mode==='month'){ $start=$month.'-01';return[$start,(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d')];}
    if($mode==='nextWeek'){ $point=new DateTimeImmutable($date);$start=$point->modify('monday next week')->format('Y-m-d');return[$start,(new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d')];}
    if($mode==='range')return[validDate($from),validDate($to)];$range=$db->query('SELECT COALESCE(MIN(scheduled_date),CURRENT_DATE()) a,COALESCE(MAX(scheduled_date),CURRENT_DATE()) b FROM operations WHERE status<>"deleted"')->fetch();return[$range['a'],$range['b']];
}

function legacyExportRows(PDO $db,string $start,string $end,string $room,string $doctor,string $mode): array
{
    $sql='SELECT o.scheduled_date,r.name room_name,o.display_order,o.patient_name,o.diagnostic,ot.name operation_type,p.display_name doctor_name,o.custom_notes,o.status,o.is_extra,rb.reason block_reason FROM operations o JOIN operation_rooms r ON r.id=o.room_id LEFT JOIN operation_types ot ON ot.id=o.operation_type_id JOIN people p ON p.id=o.scheduled_doctor_id LEFT JOIN room_blocks rb ON rb.room_id=o.room_id AND rb.block_date=o.scheduled_date AND rb.removed_at IS NULL WHERE o.scheduled_date BETWEEN :a AND :b AND o.status<>"deleted"';$params=['a'=>$start,'b'=>$end];
    if($room!==''){$sql.=' AND r.name=:room';$params['room']=$room;}if($doctor!==''){$sql.=' AND p.display_name=:doctor';$params['doctor']=$doctor;}if($mode==='blocked')$sql.=' AND rb.id IS NOT NULL';$sql.=' ORDER BY o.scheduled_date,r.display_order,o.display_order,o.id';$statement=$db->prepare($sql);$statement->execute($params);return$statement->fetchAll();
}

function writeLegacyCsv(string $path,array $rows): void { $handle=fopen($path,'wb');fwrite($handle,"\xEF\xBB\xBF");fputcsv($handle,['Data','Sala','Nr.','Pacient','Diagnostic','Tip operatie','Medic','Observatii','Finalizata','Extra','Sala blocata'],';');foreach($rows as $row)fputcsv($handle,[$row['scheduled_date'],$row['room_name'],$row['display_order'],$row['patient_name'],$row['diagnostic'],$row['operation_type'],$row['doctor_name'],$row['custom_notes'],$row['status']==='completed'?'DA':'NU',$row['is_extra']?'DA':'NU',$row['block_reason']??''],';');fclose($handle); }

function writeLegacyXlsx(string $path,array $rows): void
{
    require_once dirname(__DIR__,2).'/app/lib/xlsxwriter.class.php';$writer=new XLSXWriter();$sheet='Program operatii';$types=['Data'=>'string','Sala'=>'string','Nr.'=>'integer','Pacient'=>'string','Diagnostic'=>'string','Tip operatie'=>'string','Medic'=>'string','Observatii'=>'string','Finalizata'=>'string','Extra'=>'string'];
    $writer->writeSheetHeader($sheet,$types,['widths'=>[12,10,6,24,28,20,22,30,10,8],'freeze_rows'=>2,'auto_filter'=>true,'fit_to_page'=>true,'page_setup'=>['orientation'=>'landscape','paperSize'=>9,'fitToWidth'=>1]]);$writer->writeSheetRow($sheet,['PROGRAM OPERATOR','','','','','','','','',''],['height'=>26,'font-style'=>'bold','font-size'=>14,'fill'=>'#DDEBF7']);$writer->markMergedCell($sheet,0,0,0,9);
    foreach($rows as $row)$writer->writeSheetRow($sheet,[$row['scheduled_date'],$row['room_name'],(int)$row['display_order'],$row['patient_name'],$row['diagnostic'],$row['operation_type'],$row['doctor_name'],$row['custom_notes'],$row['status']==='completed'?'DA':'NU',$row['is_extra']?'DA':'NU'],['height'=>20,'border'=>'all','border-style'=>'thin','valign'=>'center']);$writer->writeToFile($path);
}

function legacyRomanianDateTitle(string $date): string
{
    $point = new DateTimeImmutable($date);
    $weekdays = [1=>'Luni',2=>'Marți',3=>'Miercuri',4=>'Joi',5=>'Vineri',6=>'Sâmbătă',7=>'Duminică'];
    $months = [1=>'ianuarie',2=>'februarie',3=>'martie',4=>'aprilie',5=>'mai',6=>'iunie',7=>'iulie',8=>'august',9=>'septembrie',10=>'octombrie',11=>'noiembrie',12=>'decembrie'];
    return $weekdays[(int)$point->format('N')].' '.$point->format('j').' '.$months[(int)$point->format('n')].' '.$point->format('Y');
}

function writeLegacyPdf(string $path,array $rows,string $title,array $rooms): void
{
    require_once dirname(__DIR__, 2) . '/app/PdfDocument.php';
    $daysByRoom = [];
    foreach ($rows as $row) $daysByRoom[(string)$row['scheduled_date']][(string)$row['room_name']][] = $row;
    if (!$daysByRoom) $daysByRoom['Fara programari'] = [];
    $pdf = createUnicodePdf($title);
    $pageCount = count($daysByRoom);
    $pageIndex = 0;
    foreach ($daysByRoom as $date => $dayRooms) {
        $pageIndex++;
        $pdf->AddPage();
        $pdf->SetTextColor(22, 49, 63);
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->Cell(0, 7, 'PROGRAM OPERATOR', 0, 1, 'L');
        $dateTitle = $date === 'Fara programari' ? 'Fără programări' : legacyRomanianDateTitle($date);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->Cell(0, 5, $dateTitle . '  |  pagina ' . $pageIndex . ' / ' . $pageCount, 0, 1, 'L');
        $left = 8.0;
        $gap = 2.0;
        $columns = max(1, count($rooms));
        $columnWidth = ($pdf->getPageWidth() - 16 - $gap * ($columns - 1)) / $columns;
        $headerY = 23.0;
        $headerHeight = 8.0;
        $maxItems = 1;
        foreach ($rooms as $room) $maxItems = max($maxItems, count($dayRooms[$room] ?? []));
        $blockHeight = max(12.0, min(28.0, 166.0 / $maxItems));
        $fontSize = $blockHeight < 16 ? 6.2 : ($blockHeight < 22 ? 7.0 : 8.0);
        foreach ($rooms as $roomIndex => $room) {
            $x = $left + $roomIndex * ($columnWidth + $gap);
            $pdf->SetXY($x, $headerY);
            $pdf->SetFillColor(26, 64, 79);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->Cell($columnWidth, $headerHeight, $room, 1, 0, 'L', true);
            $items = $dayRooms[$room] ?? [];
            if (!$items) {
                $pdf->SetXY($x, $headerY + $headerHeight);
                $pdf->SetFillColor(250, 252, 251);
                $pdf->SetTextColor(92, 115, 124);
                $pdf->SetFont('dejavusans', '', 6.5);
                $pdf->Cell($columnWidth, 12, 'Fără operații programate', 1, 0, 'L', true);
                continue;
            }
            foreach ($items as $itemIndex => $item) {
                $y = $headerY + $headerHeight + $itemIndex * $blockHeight;
                $pdf->SetFillColor(...($itemIndex % 2 === 0 ? [255,255,255] : [241,246,247]));
                $pdf->SetTextColor(24, 50, 61);
                $patient = (int)$item['display_order'] . '. ' . (string)$item['patient_name'];
                $html = '<div style="color:#18323d;font-size:'.$fontSize.'pt;line-height:1.08"><b>' . htmlspecialchars($patient, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><br>'
                    . '<b>' . htmlspecialchars((string)($item['operation_type'] ?: 'Tip nespecificat'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b><br>'
                    . htmlspecialchars((string)$item['doctor_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $diagnostic = trim((string)$item['diagnostic']);
                $notes = trim((string)$item['custom_notes']);
                if ($diagnostic !== '') $html .= '<br><span style="font-size:'.max(5.3,$fontSize-.7).'pt">Dx: ' . htmlspecialchars(mb_strimwidth($diagnostic, 0, 110, '...'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
                if ($notes !== '') $html .= '<br><span style="font-size:'.max(5.3,$fontSize-.7).'pt">Obs: ' . htmlspecialchars(mb_strimwidth($notes, 0, 110, '...'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
                $html .= '</div>';
                $pdf->writeHTMLCell($columnWidth, $blockHeight, $x, $y, $html, 1, 0, true, true, 'L');
            }
        }
        $pdf->SetXY(8, 201);
        $pdf->SetTextColor(94, 112, 120);
        $pdf->SetFont('dejavusans', '', 5.5);
        $pdf->Cell(0, 4, 'Generat din platforma internă - ' . date('d.m.Y H:i'), 0, 0, 'L');
    }
    $pdf->Output($path, 'F');
    return;
}

/* Legacy ASCII PDF implementation retained only for reference during this update.

    $days=[];
    foreach($rows as$row)$days[(string)$row['scheduled_date']][(string)$row['room_name']][]=$row;
    if(!$days)$days['Fara programari']=[];
    $objects=[];$pageIds=[];$fontRegular=3;$fontBold=4;$pageIndex=0;$pageCount=count($days);
    foreach($days as$date=>$dayRooms){
        $pageId=5+$pageIndex*2;$contentId=$pageId+1;$pageIds[]=$pageId;$pageIndex++;
        $left=24.0;$right=24.0;$gap=8.0;$pageWidth=842.0;$pageHeight=595.0;$columnWidth=($pageWidth-$left-$right-$gap*3)/4;
        $stream="q 0.965 0.976 0.98 rg 0 0 842 595 re f Q\n";
        $stream.=legacyPdfText(24,566,'PROGRAM OPERATOR',15,true);
        $dateTitle=$date==='Fara programari'?$date:(new DateTimeImmutable($date))->format('d.m.Y');
        $stream.=legacyPdfText(24,548,$dateTitle.'  |  pagina '.$pageIndex.' / '.$pageCount,8,false);
        $stream.=legacyPdfText(818,566,'Maternitatea Giulesti',8,true,28,'right');
        $maxItems=1;foreach($rooms as$room)$maxItems=max($maxItems,count($dayRooms[$room]??[]));
        $availableHeight=472.0;$blockHeight=max(25.0,min(42.0,$availableHeight/$maxItems));
        foreach($rooms as$roomIndex=>$room){
            $x=$left+$roomIndex*($columnWidth+$gap);$headerY=516.0;
            $stream.="q 0.10 0.25 0.31 rg $x $headerY $columnWidth 26 re f Q\n";
            $stream.=legacyPdfText($x+8,$headerY+9,$room,10,true,22,'left',true);
            $items=$dayRooms[$room]??[];
            if(!$items){
                $stream.="q 0.88 G 0.5 w $x 482 $columnWidth 28 re S Q\n";
                $stream.=legacyPdfText($x+8,492,'Fara operatii programate',7,false,30);
                continue;
            }
            foreach($items as$itemIndex=>$item){
                $y=$headerY-($itemIndex+1)*$blockHeight;
                $fill=$itemIndex%2===0?'1 1 1':'0.94 0.96 0.965';
                $stream.="q $fill rg $x $y $columnWidth ".($blockHeight-2)." re f Q\n";
                $stream.="q 0.82 G 0.35 w $x $y $columnWidth ".($blockHeight-2)." re S Q\n";
                $fontMain=$blockHeight<31?5.4:6.4;$fontSmall=$blockHeight<31?4.8:5.5;$lineGap=$blockHeight<31?6.2:7.6;$textY=$y+$blockHeight-10;
                $patient=(int)$item['display_order'].'. '.(string)$item['patient_name'];
                $stream.=legacyPdfText($x+5,$textY,$patient,$fontMain,true,legacyPdfCharLimit($columnWidth,$fontMain));
                $stream.=legacyPdfText($x+5,$textY-$lineGap,(string)($item['operation_type']?:'Tip nespecificat'),$fontSmall,true,legacyPdfCharLimit($columnWidth,$fontSmall));
                $stream.=legacyPdfText($x+5,$textY-$lineGap*2,(string)$item['doctor_name'],$fontSmall,false,legacyPdfCharLimit($columnWidth,$fontSmall));
                if($blockHeight>=34){
                    $diagnostic=trim((string)$item['diagnostic']);
                    if($diagnostic!=='')$stream.=legacyPdfText($x+5,$textY-$lineGap*3,$diagnostic,5,false,legacyPdfCharLimit($columnWidth,5));
                }
                if($item['status']==='completed')$stream.=legacyPdfText($x+$columnWidth-5,$textY,'EFECTUATA',4.5,true,14,'right');
            }
        }
        $stream.=legacyPdfText(24,15,'Generat din platforma interna - '.date('d.m.Y H:i'),5.5,false);
        $objects[$pageId]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 $fontRegular 0 R /F2 $fontBold 0 R >> >> /Contents $contentId 0 R >>";
        $objects[$contentId]="<< /Length ".strlen($stream)." >>\nstream\n$stream\nendstream";
    }
    $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2]='<< /Type /Pages /Kids ['.implode(' ',array_map(fn($id)=>$id.' 0 R',$pageIds)).'] /Count '.count($pageIds).' >>';
    $objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as$id=>$object){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$object\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($id=1;$id<=$max;$id++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$id]??0);$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";file_put_contents($path,$pdf);
}

function legacyPdfText(float$x,float$y,string$text,float$size,bool$bold=false,int$limit=80,string$align='left',bool$white=false): string
{
    $text=trim(preg_replace('/\s+/u',' ',$text)??'');if(mb_strlen($text)>$limit)$text=mb_substr($text,0,max(1,$limit-1)).'.';
    $encoded=iconv('UTF-8','Windows-1252//TRANSLIT',$text)?:'';$encoded=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$encoded);
    if($align==='right')$x-=min($limit,strlen($encoded))*$size*.48;
    return 'BT /'.($bold?'F2':'F1').' '.number_format($size,1,'.','').' Tf '.($white?'1 1 1':'0.08 0.13 0.16').' rg '.number_format($x,1,'.','').' '.number_format($y,1,'.','')." Td ($encoded) Tj ET\n";
}

function legacyPdfCharLimit(float$columnWidth,float$fontSize): int { return max(12,(int)(($columnWidth-12)/($fontSize*.49))); }

function simpleTextPdf(string $path,array $lines): void
{
    $pages=array_chunk($lines,34);$objects=[];$pageIds=[];$fontId=3;foreach($pages as $pageIndex=>$pageLines){$pageId=4+$pageIndex*2;$contentId=$pageId+1;$pageIds[]=$pageId;$stream="BT\n/F1 9 Tf\n40 555 Td\n";foreach($pageLines as $index=>$line){$text=iconv('UTF-8','Windows-1252//TRANSLIT',(string)$line)?:'';$text=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$text);if($index>0)$stream.="0 -15 Td\n";$stream.='('.substr($text,0,150).") Tj\n";}$stream.="ET";$objects[$pageId]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 $fontId 0 R >> >> /Contents $contentId 0 R >>";$objects[$contentId]="<< /Length ".strlen($stream)." >>\nstream\n$stream\nendstream";}
    $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[2]='<< /Type /Pages /Kids ['.implode(' ',array_map(fn($id)=>$id.' 0 R',$pageIds)).'] /Count '.count($pageIds).' >>';$objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$object\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($id=1;$id<=$max;$id++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$id]??0);$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";file_put_contents($path,$pdf);
}

*/

function downloadLegacyExport(string $file): never { $name=basename($file);$path=dirname(__DIR__,2).'/storage/exports/'.$name;if($name!==$file||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf'||!is_file($path))legacyError('Fisierul PDF nu a fost gasit sau a expirat.',404);register_shutdown_function(static function()use($path):void{if(is_file($path))@unlink($path);});header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.preg_replace('/_[a-f0-9]{16}(?=\.)/','',$name).'"');header('Content-Length: '.filesize($path));readfile($path);exit; }
