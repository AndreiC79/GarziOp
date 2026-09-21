<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
try {
    apiRequirePermission('progop.view');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        listAtiRoomAssignments($db);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiError('Metoda nu este acceptata.', 405);
    }
    apiVerifyCsrf();
    apiRequirePermission('progop.manage_all');
    updateAtiRoomAssignments($db, apiInput());
} catch (Throwable $error) {
    apiLogException($error, 'ati-room-assignments');
    apiError('Repartizarea ATI nu a putut fi salvata.', 500);
}

function listAtiRoomAssignments(PDO $db): never
{
    [$from, $to] = atiAssignmentDateRange();
    apiSuccess([
        'date_from' => $from,
        'date_to' => $to,
        'rooms' => atiAssignmentRooms($db),
        'ati_doctors' => atiAssignmentDoctors($db),
        'assignments' => AtiRoomAssignments::between($from, $to),
    ]);
}

function updateAtiRoomAssignments(PDO $db, array $input): never
{
    global $apiUser;

    $updates = $input['assignments'] ?? null;
    if (!is_array($updates) || !array_is_list($updates) || count($updates) < 1 || count($updates) > 100) {
        apiError('Trimite intre 1 si 100 de repartizari ATI in campul assignments.');
    }

    $rooms = atiAssignmentRooms($db);
    $doctors = atiAssignmentDoctors($db);
    $roomById = [];
    foreach ($rooms as $room) {
        $roomById[(int)$room['id']] = $room;
    }
    $doctorById = [];
    foreach ($doctors as $doctor) {
        $doctorId = (int)$doctor['id'];
        $doctorById[$doctorId] = $doctor;
    }

    $all = AtiRoomAssignments::all();
    $before = [];
    $after = [];
    $normalized = [];
    $planned = [];

    foreach ($updates as $index => $update) {
        if (!is_array($update)) apiError('Repartizarea ATI de la pozitia ' . ($index + 1) . ' nu este valida.');
        if (array_diff(array_keys($update), ['date','room_id','person_id'])) {
            apiError('Repartizarea ATI de la pozitia ' . ($index + 1) . ' contine campuri necunoscute.');
        }
        $date = validDate((string)($update['date'] ?? ''));
        $roomId = $update['room_id'] ?? null;
        $personId = $update['person_id'] ?? null;
        if (!is_int($roomId) || $roomId <= 0) apiError('room_id trebuie sa fie un numar intreg pozitiv.');
        if (!is_int($personId) || $personId <= 0) apiError('person_id trebuie sa fie un numar intreg pozitiv.');
        $room = $roomById[$roomId] ?? null;
        if (!$room) apiError('Sala nu a fost gasita sau nu este activa.', 404);
        $doctor = $doctorById[$personId] ?? null;
        if (!$doctor) apiError('Medicul ATI nu a fost gasit sau nu este activ.', 404);
        $roomName = (string)$room['name'];
        $doctorIds = $planned[$date][$roomName] ?? [];
        if (in_array($personId, $doctorIds, true)) apiError('Acelasi medic ATI nu poate fi repartizat de doua ori in aceeasi sala si zi.');
        if (count($doctorIds) >= 2) apiError('Pot fi repartizati maximum doi medici ATI in aceeasi sala si zi.');
        $planned[$date][$roomName][] = $personId;
        $normalized[] = [
            'date' => $date,
            'room_id' => $roomId,
            'room' => $roomName,
            'person_id' => $personId,
            'person_name' => (string)$doctor['name'],
        ];
    }

    foreach ($planned as $date => $roomAssignments) {
        foreach ($roomAssignments as $roomName => $doctorIds) {
            $previousIds = is_array($all[$date][$roomName] ?? null) ? $all[$date][$roomName] : [];
            $before[$date][$roomName] = array_values(array_map('intval', $previousIds));
            $all[$date][$roomName] = $doctorIds;
            $after[$date][$roomName] = $doctorIds;
        }
    }

    AtiRoomAssignments::save($all, (int)$apiUser['id']);
    apiAudit($db, 'progop.ati_assignments_updated_via_api', 'ati_schedule', null, $before, $after);

    $affectedDates = [];
    foreach (array_keys($after) as $date) {
        $affectedDates[$date] = is_array($all[$date] ?? null) ? $all[$date] : [];
    }
    ksort($affectedDates);
    apiSuccess([
        'message' => 'Repartizarea medicilor ATI pe sali a fost actualizata.',
        'updated' => $normalized,
        'assignments' => $affectedDates,
    ]);
}

function atiAssignmentDateRange(): array
{
    $from = validDate((string)($_GET['date_from'] ?? date('Y-m-d')));
    $to = validDate((string)($_GET['date_to'] ?? (new DateTimeImmutable($from))->modify('+31 days')->format('Y-m-d')));
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    if ($end < $start || $end > $start->modify('+366 days')) {
        apiError('Intervalul trebuie sa fie cronologic si de maximum 366 de zile.');
    }
    return [$from, $to];
}

function atiAssignmentRooms(PDO $db): array
{
    $rows = $db->query('SELECT id,name,display_order FROM operation_rooms WHERE active=1 ORDER BY display_order,name')->fetchAll();
    return array_map(static fn(array $room): array => [
        'id'=>(int)$room['id'],
        'name'=>(string)$room['name'],
        'display_order'=>(int)$room['display_order'],
    ], $rows);
}

function atiAssignmentDoctors(PDO $db): array
{
    $rows = $db->query('SELECT id,display_name FROM people WHERE active=1 AND specialty="ATI" ORDER BY last_name,first_name')->fetchAll();
    return array_map(static fn(array $doctor): array => [
        'id'=>(int)$doctor['id'],
        'name'=>(string)$doctor['display_name'],
    ], $rows);
}
