<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleAssistantGet($db);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Metoda nu este acceptata.', 405);
}

apiVerifyCsrf();
$input = apiInput();
match ((string) ($input['action'] ?? '')) {
    'add_operation' => assistantAddOperation($db, $input),
    default => apiError('Actiune necunoscuta.'),
};

function handleAssistantGet(PDO $db): never
{
    $action = (string) ($_GET['action'] ?? 'capabilities');
    match ($action) {
        'capabilities' => assistantCapabilities($db),
        'guards' => assistantGuards($db),
        'schedule' => assistantSchedule($db),
        'best_guard_days' => assistantBestGuardDays($db),
        default => apiError('Actiune necunoscuta.'),
    };
}

function assistantCapabilities(PDO $db): never
{
    global $apiPermissions, $apiUser;
    apiRequirePermission('guards.view', 'progop.view');
    apiSuccess([
        'user' => [
            'id'=>(int) $apiUser['id'],
            'person_id'=>$apiUser['person_id'] === null ? null : (int) $apiUser['person_id'],
            'name'=>$apiUser['display_name'],
            'specialty'=>$apiUser['specialty'],
        ],
        'permissions'=>$apiPermissions,
        'rooms'=>$db->query('SELECT id,name,display_order FROM operation_rooms WHERE active=1 ORDER BY display_order,name')->fetchAll(),
        'operation_types'=>$db->query('SELECT id,name FROM operation_types WHERE active=1 ORDER BY name')->fetchAll(),
        'doctors'=>$db->query('SELECT id,display_name,professional_category FROM people WHERE active=1 AND specialty="OG" ORDER BY last_name,first_name')->fetchAll(),
        'specialties'=>['OG','ATI','NN'],
        'guard_lines'=>assistantGuardLines($db),
    ]);
}

function assistantGuards(PDO $db): never
{
    apiRequirePermission('guards.view');
    [$from, $to] = assistantDateRange();
    $specialty = strtoupper(trim((string) ($_GET['specialty'] ?? '')));
    if ($specialty !== '' && !in_array($specialty, ['OG','ATI','NN'], true)) apiError('Specialitatea nu este valida.');
    $personId = (int) ($_GET['person_id'] ?? 0);
    $sql =
        'SELECT ga.id,ga.guard_date,ga.specialty,ga.line_no,ga.assignment_state,
                ga.original_person_id,original.display_name original_name,
                ga.current_person_id,current.display_name current_name
         FROM guard_assignments ga
         JOIN guard_plan_versions gv ON gv.id=ga.version_id
         JOIN guard_plans gp ON gp.current_version_id=gv.id
         JOIN people original ON original.id=ga.original_person_id
         JOIN people current ON current.id=ga.current_person_id
         WHERE ga.guard_date BETWEEN :date_from AND :date_to';
    $params = ['date_from'=>$from,'date_to'=>$to];
    if ($specialty !== '') { $sql .= ' AND ga.specialty=:specialty'; $params['specialty']=$specialty; }
    if ($personId > 0) { $sql .= ' AND ga.current_person_id=:person_id'; $params['person_id']=$personId; }
    $sql .= ' ORDER BY ga.guard_date,FIELD(ga.specialty,"OG","ATI","NN"),ga.line_no';
    $statement = $db->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    apiSuccess(['date_from'=>$from,'date_to'=>$to,'count'=>count($rows),'guards'=>$rows]);
}

function assistantSchedule(PDO $db): never
{
    apiRequirePermission('progop.view');
    [$from, $to] = assistantDateRange();
    $statement = $db->prepare(
        'SELECT o.id,o.scheduled_date,r.id room_id,r.name room_name,o.display_order,o.patient_name,o.diagnostic,
                ot.id operation_type_id,ot.name operation_type,p.id doctor_id,p.display_name doctor_name,
                o.status,o.custom_notes,o.is_extra,o.extra_reason,o.created_at,o.updated_at
         FROM operations o
         JOIN operation_rooms r ON r.id=o.room_id
         LEFT JOIN operation_types ot ON ot.id=o.operation_type_id
         JOIN people p ON p.id=o.scheduled_doctor_id
         WHERE o.scheduled_date BETWEEN :date_from AND :date_to AND o.status<>"deleted"
         ORDER BY o.scheduled_date,r.display_order,o.display_order,o.id'
    );
    $statement->execute(['date_from'=>$from,'date_to'=>$to]);
    $rows = $statement->fetchAll();
    apiSuccess(['date_from'=>$from,'date_to'=>$to,'count'=>count($rows),'operations'=>$rows]);
}

function assistantBestGuardDays(PDO $db): never
{
    global $apiUser;
    assistantRequireAll(['guards.view','progop.view']);
    [$from, $to] = assistantDateRange();
    $personId = (int) ($_GET['person_id'] ?? ($apiUser['person_id'] ?? 0));
    if ($personId <= 0) apiError('Specifică person_id sau foloseste un cont asociat unui medic.');
    $person = $db->prepare('SELECT id,display_name FROM people WHERE id=:id');
    $person->execute(['id'=>$personId]);
    $doctor = $person->fetch();
    if (!$doctor) apiError('Medicul nu a fost gasit.', 404);
    $statement = $db->prepare(
        'SELECT guard_days.guard_date,
                GROUP_CONCAT(DISTINCT CONCAT(guard_days.specialty," L",guard_days.line_no) ORDER BY guard_days.specialty,guard_days.line_no SEPARATOR ", ") guards,
                COUNT(DISTINCT o.id) operation_count,
                COUNT(DISTINCT CASE WHEN o.scheduled_doctor_id=:my_operations_person THEN o.id END) my_operation_count,
                GROUP_CONCAT(DISTINCT CASE WHEN o.id IS NOT NULL THEN r.name END ORDER BY r.display_order SEPARATOR ", ") rooms
         FROM (
             SELECT ga.guard_date,ga.specialty,ga.line_no
             FROM guard_assignments ga
             JOIN guard_plan_versions gv ON gv.id=ga.version_id
             JOIN guard_plans gp ON gp.current_version_id=gv.id
             WHERE ga.current_person_id=:guard_person AND ga.guard_date BETWEEN :date_from AND :date_to
         ) guard_days
         LEFT JOIN operations o ON o.scheduled_date=guard_days.guard_date AND o.status IN ("scheduled","completed")
         LEFT JOIN operation_rooms r ON r.id=o.room_id
         GROUP BY guard_days.guard_date
         ORDER BY operation_count ASC,guard_days.guard_date ASC'
    );
    $statement->execute(['my_operations_person'=>$personId,'guard_person'=>$personId,'date_from'=>$from,'date_to'=>$to]);
    $days = $statement->fetchAll();
    apiSuccess([
        'person'=>['id'=>(int)$doctor['id'],'name'=>$doctor['display_name']],
        'date_from'=>$from,'date_to'=>$to,'guard_day_count'=>count($days),
        'best_day'=>$days[0] ?? null,'days'=>$days,
    ]);
}

function assistantAddOperation(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('progop.create', 'progop.manage_all');
    $date = validDate((string) ($input['date'] ?? ''));
    $patientName = trim((string) ($input['patient_name'] ?? ''));
    if ($patientName === '') apiError('Numele sau identificatorul pacientului este obligatoriu.');
    $room = assistantResolveNamedEntity($db, 'operation_rooms', $input['room'] ?? $input['room_id'] ?? null, true);
    $doctorValue = $input['doctor'] ?? $input['doctor_id'] ?? ($apiUser['person_id'] ?? null);
    $doctor = assistantResolveDoctor($db, $doctorValue);
    $type = assistantResolveNamedEntity($db, 'operation_types', $input['operation_type'] ?? $input['operation_type_id'] ?? null, false);
    $blocked = $db->prepare('SELECT reason FROM room_blocks WHERE block_date=:date AND room_id=:room_id AND removed_at IS NULL LIMIT 1');
    $blocked->execute(['date'=>$date,'room_id'=>$room['id']]);
    $reason = $blocked->fetchColumn();
    if ($reason !== false) apiError('Sala este blocata in ziua selectata' . ($reason ? ': ' . $reason : '.') , 409);

    $data = [
        'date'=>$date,'room_id'=>(int)$room['id'],'room_name'=>$room['name'],'doctor_id'=>(int)$doctor['id'],'doctor_name'=>$doctor['display_name'],
        'operation_type_id'=>(int)$type['id'],'operation_type'=>$type['name'],'patient_name'=>mb_substr($patientName,0,220),
        'diagnostic'=>trim((string)($input['diagnostic'] ?? '')),'notes'=>trim((string)($input['notes'] ?? '')),
        'is_extra'=>!empty($input['is_extra']) ? 1 : 0,'extra_reason'=>trim((string)($input['extra_reason'] ?? '')),
    ];
    $statement = $db->prepare(
        'INSERT INTO operations(scheduled_date,room_id,display_order,patient_name,diagnostic,operation_type_id,scheduled_doctor_id,created_by,custom_notes,is_extra,extra_reason)
         VALUES(:scheduled_date,:room_id,
            (SELECT COALESCE(MAX(existing.display_order),0)+1 FROM operations existing WHERE existing.scheduled_date=:order_date AND existing.room_id=:order_room AND existing.status<>"deleted"),
            :patient_name,:diagnostic,:operation_type_id,:doctor_id,:created_by,:notes,:is_extra,:extra_reason)'
    );
    $statement->execute([
        'scheduled_date'=>$date,'room_id'=>$room['id'],'order_date'=>$date,'order_room'=>$room['id'],'patient_name'=>$data['patient_name'],
        'diagnostic'=>$data['diagnostic'],'operation_type_id'=>$type['id'],'doctor_id'=>$doctor['id'],'created_by'=>$apiUser['id'],
        'notes'=>$data['notes'],'is_extra'=>$data['is_extra'],'extra_reason'=>$data['extra_reason'],
    ]);
    $operationId = (int) $db->lastInsertId();
    $db->prepare('INSERT INTO operation_events(operation_id,event_type,performed_by,before_data,after_data) VALUES(:operation_id,"created",:user_id,NULL,:after_data)')
        ->execute(['operation_id'=>$operationId,'user_id'=>$apiUser['id'],'after_data'=>json_encode($data,JSON_UNESCAPED_UNICODE)]);
    apiAudit($db, 'progop.operation_created_via_assistant', 'operation', $operationId, null, $data);
    apiSuccess(['message'=>'Operatia a fost adaugata.','operation_id'=>$operationId,'operation'=>$data]);
}

function assistantDateRange(): array
{
    $from = validDate((string) ($_GET['date_from'] ?? date('Y-m-d')));
    $to = validDate((string) ($_GET['date_to'] ?? (new DateTimeImmutable($from))->modify('+90 days')->format('Y-m-d')));
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    if ($end < $start || $end > $start->modify('+366 days')) apiError('Intervalul trebuie sa fie cronologic si de maximum 366 de zile.');
    return [$from,$to];
}

function assistantRequireAll(array $permissions): void
{
    global $apiPermissions;
    foreach ($permissions as $permission) if (!in_array($permission,$apiPermissions,true)) apiError('Tokenul nu are toate drepturile necesare.',403);
}

function assistantResolveNamedEntity(PDO $db, string $table, mixed $value, bool $room): array
{
    if (!in_array($table,['operation_rooms','operation_types'],true)) apiError('Configuratie interna invalida.',500);
    if ($value === null || trim((string)$value) === '') apiError($room ? 'Sala este obligatorie.' : 'Tipul operatiei este obligatoriu.');
    $column = $room ? 'display_order' : 'name';
    $statement = ctype_digit((string)$value)
        ? $db->prepare("SELECT id,name FROM $table WHERE id=:value AND active=1")
        : $db->prepare("SELECT id,name FROM $table WHERE LOWER(name)=LOWER(:value) AND active=1 ORDER BY $column LIMIT 1");
    $statement->execute(['value'=>$value]);
    $row = $statement->fetch();
    if (!$row) apiError(($room ? 'Sala' : 'Tipul operatiei') . ' nu a fost gasit.',404);
    return $row;
}

function assistantResolveDoctor(PDO $db, mixed $value): array
{
    if ($value === null || trim((string)$value) === '') apiError('Medicul operator este obligatoriu.');
    $statement = ctype_digit((string)$value)
        ? $db->prepare('SELECT id,display_name FROM people WHERE id=:value AND active=1 AND specialty="OG"')
        : $db->prepare('SELECT id,display_name FROM people WHERE LOWER(display_name)=LOWER(:value) AND active=1 AND specialty="OG" LIMIT 1');
    $statement->execute(['value'=>$value]);
    $row = $statement->fetch();
    if (!$row) apiError('Medicul OG nu a fost gasit.',404);
    return $row;
}

function assistantGuardLines(PDO $db): array
{
    $result=[];
    foreach ($db->query('SELECT specialty,line_count,line_labels,line1_protected FROM guard_specialty_settings ORDER BY FIELD(specialty,"OG","ATI","NN")') as $row) {
        $result[$row['specialty']]=['line_count'=>(int)$row['line_count'],'labels'=>json_decode((string)$row['line_labels'],true) ?: [],'line1_protected'=>(bool)$row['line1_protected']];
    }
    return $result;
}
