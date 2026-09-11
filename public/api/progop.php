<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
apiRequirePermission('progop.view');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    loadProgOp($db);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Metoda nu este acceptata.', 405);
}

apiVerifyCsrf();
$input = apiInput();
$action = (string) ($input['action'] ?? '');

match ($action) {
    'add_operation' => addOperation($db, $input),
    'update_operation' => updateOperation($db, $input),
    'delete_operation' => deleteOperation($db, $input),
    'toggle_completed' => toggleCompleted($db, $input),
    'block_room' => blockRoom($db, $input),
    'unblock_room' => unblockRoom($db, $input),
    default => apiError('Actiune necunoscuta.'),
};

function loadProgOp(PDO $db): never
{
    global $apiPermissions, $apiUser;
    $month = validMonth((string) ($_GET['month'] ?? date('Y-m')));
    $start = $month . '-01';
    $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

    $statement = $db->prepare(
        'SELECT o.id, o.scheduled_date, o.room_id, r.name AS room_name, o.display_order,
                o.patient_name, o.diagnostic, o.operation_type_id, ot.name AS operation_type,
                o.scheduled_doctor_id, p.display_name AS doctor_name, o.created_by,
                creator.username AS created_by_name, o.status, o.custom_notes, o.is_extra,
                o.extra_reason, o.completed_at, o.created_at, o.updated_at
         FROM operations o
         INNER JOIN operation_rooms r ON r.id = o.room_id
         LEFT JOIN operation_types ot ON ot.id = o.operation_type_id
         INNER JOIN people p ON p.id = o.scheduled_doctor_id
         INNER JOIN users creator ON creator.id = o.created_by
         WHERE o.scheduled_date BETWEEN :date_from AND :date_to AND o.status <> "deleted"
         ORDER BY o.scheduled_date, r.display_order, o.display_order, o.id'
    );
    $statement->execute(['date_from' => $start, 'date_to' => $end]);

    $blockStatement = $db->prepare(
        'SELECT rb.id, rb.block_date, rb.room_id, r.name AS room_name, rb.reason
         FROM room_blocks rb INNER JOIN operation_rooms r ON r.id = rb.room_id
         WHERE rb.block_date BETWEEN :date_from AND :date_to AND rb.removed_at IS NULL'
    );
    $blockStatement->execute(['date_from' => $start, 'date_to' => $end]);

    $guardStatement = $db->prepare(
        'SELECT ga.guard_date, ga.specialty, ga.line_no, p.id AS person_id, p.display_name
         FROM guard_assignments ga
         INNER JOIN guard_plan_versions gv ON gv.id = ga.version_id
         INNER JOIN guard_plans gp ON gp.current_version_id = gv.id
         INNER JOIN people p ON p.id = ga.current_person_id
         WHERE ga.guard_date BETWEEN :date_from AND :date_to
         ORDER BY ga.guard_date, FIELD(ga.specialty, "ATI", "OG", "NN"), ga.line_no'
    );
    $guardStatement->execute(['date_from' => $start, 'date_to' => $end]);

    apiSuccess([
        'month' => $month,
        'rooms' => $db->query('SELECT id, name, display_order FROM operation_rooms WHERE active = 1 ORDER BY display_order, name')->fetchAll(),
        'operationTypes' => $db->query('SELECT id, name FROM operation_types WHERE active = 1 ORDER BY name')->fetchAll(),
        'doctors' => $db->query('SELECT id, display_name, specialty FROM people WHERE active = 1 AND specialty = "OG" ORDER BY last_name, first_name')->fetchAll(),
        'operations' => $statement->fetchAll(),
        'blocks' => $blockStatement->fetchAll(),
        'guards' => $guardStatement->fetchAll(),
        'currentUserId' => $apiUser['id'],
        'currentPersonId' => $apiUser['person_id'] ?? null,
        'canCreate' => in_array('progop.create', $apiPermissions, true) || in_array('progop.manage_all', $apiPermissions, true),
        'canManageAll' => in_array('progop.manage_all', $apiPermissions, true),
        'canEditOwn' => in_array('progop.edit_own', $apiPermissions, true),
    ]);
}

function addOperation(PDO $db, array $input): never
{
    global $apiPermissions, $apiUser;
    requireAnyProgOpPermission(['progop.create', 'progop.manage_all']);
    $data = validateOperationInput($db, $input);
    ensureRoomAvailable($db, $data['date'], $data['room_id']);

    $statement = $db->prepare(
        'INSERT INTO operations
         (scheduled_date, room_id, display_order, patient_name, diagnostic, operation_type_id,
          scheduled_doctor_id, created_by, custom_notes, is_extra, extra_reason)
         VALUES
         (:scheduled_date, :room_id,
          (SELECT COALESCE(MAX(existing.display_order), 0) + 1 FROM operations existing
           WHERE existing.scheduled_date = :order_date AND existing.room_id = :order_room AND existing.status <> "deleted"),
          :patient_name, :diagnostic, :operation_type_id, :scheduled_doctor_id, :created_by,
          :custom_notes, :is_extra, :extra_reason)'
    );
    $statement->execute([
        'scheduled_date' => $data['date'], 'room_id' => $data['room_id'],
        'order_date' => $data['date'], 'order_room' => $data['room_id'],
        'patient_name' => $data['patient_name'], 'diagnostic' => $data['diagnostic'],
        'operation_type_id' => $data['operation_type_id'], 'scheduled_doctor_id' => $data['doctor_id'],
        'created_by' => $apiUser['id'], 'custom_notes' => $data['notes'],
        'is_extra' => $data['is_extra'], 'extra_reason' => $data['extra_reason'],
    ]);
    $id = (int) $db->lastInsertId();
    recordOperationEvent($db, $id, 'created', null, $data);
    apiAudit($db, 'progop.operation_created', 'operation', $id, null, $data);
    apiSuccess(['id' => $id]);
}

function updateOperation(PDO $db, array $input): never
{
    $id = (int) ($input['id'] ?? 0);
    $before = requireEditableOperation($db, $id);
    $data = validateOperationInput($db, $input);
    if ((int) $before['room_id'] !== $data['room_id'] || $before['scheduled_date'] !== $data['date']) {
        ensureRoomAvailable($db, $data['date'], $data['room_id']);
    }

    $db->prepare(
        'UPDATE operations SET scheduled_date=:scheduled_date, room_id=:room_id, patient_name=:patient_name,
         diagnostic=:diagnostic, operation_type_id=:operation_type_id, scheduled_doctor_id=:scheduled_doctor_id,
         custom_notes=:custom_notes, is_extra=:is_extra, extra_reason=:extra_reason WHERE id=:id'
    )->execute([
        'scheduled_date' => $data['date'], 'room_id' => $data['room_id'], 'patient_name' => $data['patient_name'],
        'diagnostic' => $data['diagnostic'], 'operation_type_id' => $data['operation_type_id'],
        'scheduled_doctor_id' => $data['doctor_id'], 'custom_notes' => $data['notes'],
        'is_extra' => $data['is_extra'], 'extra_reason' => $data['extra_reason'], 'id' => $id,
    ]);
    recordOperationEvent($db, $id, 'updated', $before, $data);
    apiAudit($db, 'progop.operation_updated', 'operation', $id, $before, $data);
    apiSuccess();
}

function deleteOperation(PDO $db, array $input): never
{
    $id = (int) ($input['id'] ?? 0);
    $before = requireEditableOperation($db, $id);
    $db->prepare('UPDATE operations SET status="deleted", deleted_at=NOW() WHERE id=:id')->execute(['id' => $id]);
    recordOperationEvent($db, $id, 'deleted', $before, ['status' => 'deleted']);
    apiAudit($db, 'progop.operation_deleted', 'operation', $id, $before, ['status' => 'deleted']);
    apiSuccess();
}

function toggleCompleted(PDO $db, array $input): never
{
    $id = (int) ($input['id'] ?? 0);
    $before = requireEditableOperation($db, $id);
    $completed = !empty($input['completed']);
    $status = $completed ? 'completed' : 'scheduled';
    $db->prepare('UPDATE operations SET status=:status, completed_at=:completed_at WHERE id=:id')
        ->execute(['status' => $status, 'completed_at' => $completed ? date('Y-m-d H:i:s') : null, 'id' => $id]);
    recordOperationEvent($db, $id, 'completed', $before, ['status' => $status]);
    apiAudit($db, 'progop.operation_status', 'operation', $id, $before, ['status' => $status]);
    apiSuccess();
}

function blockRoom(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('progop.manage_all');
    $date = validDate((string) ($input['date'] ?? ''));
    $roomId = (int) ($input['room_id'] ?? 0);
    $reason = trim((string) ($input['reason'] ?? ''));
    $db->prepare(
        'INSERT INTO room_blocks (room_id, block_date, reason, created_by)
         VALUES (:room_id, :block_date, :reason, :created_by)'
    )->execute(['room_id' => $roomId, 'block_date' => $date, 'reason' => $reason, 'created_by' => $apiUser['id']]);
    $id = (int) $db->lastInsertId();
    apiAudit($db, 'progop.room_blocked', 'room_block', $id, null, ['date' => $date, 'room_id' => $roomId, 'reason' => $reason]);
    apiSuccess();
}

function unblockRoom(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('progop.manage_all');
    $blockId = (int) ($input['block_id'] ?? 0);
    $statement = $db->prepare('SELECT * FROM room_blocks WHERE id=:id AND removed_at IS NULL');
    $statement->execute(['id' => $blockId]);
    $before = $statement->fetch();
    if (!$before) {
        apiError('Blocarea nu a fost gasita.', 404);
    }
    $db->prepare('UPDATE room_blocks SET removed_by=:user_id, removed_at=NOW() WHERE id=:id')
        ->execute(['user_id' => $apiUser['id'], 'id' => $blockId]);
    apiAudit($db, 'progop.room_unblocked', 'room_block', $blockId, $before, null);
    apiSuccess();
}

function validateOperationInput(PDO $db, array $input): array
{
    $date = validDate((string) ($input['date'] ?? ''));
    $roomId = (int) ($input['room_id'] ?? 0);
    $doctorId = (int) ($input['doctor_id'] ?? 0);
    $typeId = (int) ($input['operation_type_id'] ?? 0);
    $patientName = trim((string) ($input['patient_name'] ?? ''));
    if ($patientName === '' || $roomId <= 0 || $doctorId <= 0 || $typeId <= 0) {
        apiError('Pacientul, sala, medicul si tipul operatiei sunt obligatorii.');
    }
    $exists = $db->prepare(
        'SELECT
          EXISTS(SELECT 1 FROM operation_rooms WHERE id=:room_id AND active=1) AS room_exists,
          EXISTS(SELECT 1 FROM people WHERE id=:doctor_id AND active=1 AND specialty="OG") AS doctor_exists,
          EXISTS(SELECT 1 FROM operation_types WHERE id=:type_id AND active=1) AS type_exists'
    );
    $exists->execute(['room_id' => $roomId, 'doctor_id' => $doctorId, 'type_id' => $typeId]);
    $valid = $exists->fetch();
    if (!$valid || !$valid['room_exists'] || !$valid['doctor_exists'] || !$valid['type_exists']) {
        apiError('Una dintre optiunile selectate nu mai este activa.');
    }
    return [
        'date' => $date, 'room_id' => $roomId, 'doctor_id' => $doctorId, 'operation_type_id' => $typeId,
        'patient_name' => mb_substr($patientName, 0, 220),
        'diagnostic' => trim((string) ($input['diagnostic'] ?? '')),
        'notes' => trim((string) ($input['notes'] ?? '')),
        'is_extra' => !empty($input['is_extra']) ? 1 : 0,
        'extra_reason' => trim((string) ($input['extra_reason'] ?? '')),
    ];
}

function requireAnyProgOpPermission(array $permissions): void
{
    global $apiPermissions;
    foreach ($permissions as $permission) {
        if (in_array($permission, $apiPermissions, true)) {
            return;
        }
    }
    apiError('Nu ai dreptul necesar pentru aceasta actiune.', 403);
}

function requireEditableOperation(PDO $db, int $id): array
{
    global $apiPermissions, $apiUser;
    $statement = $db->prepare('SELECT * FROM operations WHERE id=:id AND status <> "deleted"');
    $statement->execute(['id' => $id]);
    $operation = $statement->fetch();
    if (!$operation) {
        apiError('Interventia nu a fost gasita.', 404);
    }
    $canManageAll = in_array('progop.manage_all', $apiPermissions, true);
    $canEditOwn = in_array('progop.edit_own', $apiPermissions, true) && (int) $operation['created_by'] === (int) $apiUser['id'];
    if (!$canManageAll && !$canEditOwn) {
        apiError('Poti modifica numai interventiile programate de tine.', 403);
    }
    return $operation;
}

function ensureRoomAvailable(PDO $db, string $date, int $roomId): void
{
    $statement = $db->prepare(
        'SELECT id FROM room_blocks WHERE block_date=:block_date AND room_id=:room_id AND removed_at IS NULL LIMIT 1'
    );
    $statement->execute(['block_date' => $date, 'room_id' => $roomId]);
    if ($statement->fetchColumn()) {
        apiError('Sala este blocata in ziua selectata.');
    }
}

function recordOperationEvent(PDO $db, int $operationId, string $eventType, mixed $before, mixed $after): void
{
    global $apiUser;
    $db->prepare(
        'INSERT INTO operation_events (operation_id, event_type, performed_by, before_data, after_data)
         VALUES (:operation_id, :event_type, :performed_by, :before_data, :after_data)'
    )->execute([
        'operation_id' => $operationId, 'event_type' => $eventType, 'performed_by' => $apiUser['id'],
        'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        'after_data' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
    ]);
}
