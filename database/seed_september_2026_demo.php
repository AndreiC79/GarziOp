<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$bootstrapCandidates = [
    dirname(__DIR__) . '/app/bootstrap.php',
    dirname(__DIR__, 3) . '/intern/app/bootstrap.php',
];
$bootstrapFile = null;
foreach ($bootstrapCandidates as $candidate) {
    if (is_file($candidate)) {
        $bootstrapFile = $candidate;
        break;
    }
}
if ($bootstrapFile === null) {
    fwrite(STDERR, "Nu a fost gasit app/bootstrap.php.\n");
    exit(1);
}
require $bootstrapFile;

$db = Database::connect($config['database']);
$adminUserId = 5;
$startDate = new DateTimeImmutable('2026-09-01');
$endDate = new DateTimeImmutable('2026-09-30');
$demoMarker = '[DATE FICTIVE SEPTEMBRIE 2026]';

function lineOneSnapshot(PDO $db): string
{
    $statement = $db->query(
        "SELECT ga.id, ga.guard_date, ga.original_person_id, ga.current_person_id, ga.assignment_state
         FROM guard_assignments ga
         INNER JOIN guard_plan_versions gv ON gv.id = ga.version_id
         INNER JOIN guard_plans gp ON gp.id = gv.plan_id
         WHERE gp.specialty = 'OG'
           AND ga.line_no = 1
           AND ga.guard_date BETWEEN '2026-09-01' AND '2026-09-30'
           AND gp.current_version_id = gv.id
         ORDER BY ga.guard_date, ga.id"
    );

    return json_encode($statement->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
}

function peopleForCategories(PDO $db, array $categories): array
{
    $placeholders = implode(',', array_fill(0, count($categories), '?'));
    $statement = $db->prepare(
        "SELECT id
         FROM people
         WHERE specialty = 'OG'
           AND active = 1
           AND guard_eligible = 1
           AND professional_category IN ($placeholders)
         ORDER BY last_name, first_name, id"
    );
    $statement->execute($categories);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function currentVersionForLine(PDO $db, int $line): int
{
    $statement = $db->prepare(
        "SELECT current_version_id
         FROM guard_plans
         WHERE specialty = 'OG'
           AND line_no = :line_no
           AND date_from <= '2026-09-01'
           AND date_to >= '2026-09-30'
         ORDER BY id DESC
         LIMIT 1"
    );
    $statement->execute(['line_no' => $line]);
    $versionId = (int) $statement->fetchColumn();
    if ($versionId <= 0) {
        throw new RuntimeException("Lipseste planul curent OG pentru linia {$line}.");
    }

    return $versionId;
}

function addOperation(
    PDO $db,
    string $date,
    int $roomId,
    int $displayOrder,
    int $sequence,
    int $operationTypeId,
    int $doctorId,
    int $adminUserId,
    string $demoMarker,
    string $diagnostic
): void {
    $statement = $db->prepare(
        'INSERT INTO operations
            (scheduled_date, room_id, display_order, patient_name, diagnostic, operation_type_id,
             scheduled_doctor_id, created_by, status, custom_notes, is_extra)
         VALUES
            (:scheduled_date, :room_id, :display_order, :patient_name, :diagnostic, :operation_type_id,
             :scheduled_doctor_id, :created_by, \'scheduled\', :custom_notes, 0)'
    );
    $patientName = sprintf('Pacienta fictiva SEP-%03d', $sequence);
    $statement->execute([
        'scheduled_date' => $date,
        'room_id' => $roomId,
        'display_order' => $displayOrder,
        'patient_name' => $patientName,
        'diagnostic' => $diagnostic,
        'operation_type_id' => $operationTypeId,
        'scheduled_doctor_id' => $doctorId,
        'created_by' => $adminUserId,
        'custom_notes' => $demoMarker . ' Date exclusiv pentru testarea interfetei.',
    ]);

    $operationId = (int) $db->lastInsertId();
    $afterData = json_encode([
        'scheduled_date' => $date,
        'room_id' => $roomId,
        'patient_name' => $patientName,
        'operation_type_id' => $operationTypeId,
        'scheduled_doctor_id' => $doctorId,
        'demo' => true,
    ], JSON_THROW_ON_ERROR);
    $db->prepare(
        "INSERT INTO operation_events (operation_id, event_type, performed_by, after_data)
         VALUES (:operation_id, 'created', :performed_by, :after_data)"
    )->execute([
        'operation_id' => $operationId,
        'performed_by' => $adminUserId,
        'after_data' => $afterData,
    ]);
}

$db->beginTransaction();

try {
    $lineOneBefore = lineOneSnapshot($db);
    $lineOneRows = json_decode($lineOneBefore, true, 512, JSON_THROW_ON_ERROR);
    if (count($lineOneRows) !== 30) {
        throw new RuntimeException('Linia 1 oficiala nu contine exact 30 de zile. Popularea a fost oprita.');
    }

    $activateSafta = $db->prepare(
        "UPDATE people
         SET active = 1, guard_eligible = 1
         WHERE specialty = 'OG' AND last_name = 'SAFTA' AND first_name = 'Bianca'"
    );
    $activateSafta->execute();
    if ($activateSafta->rowCount() < 1) {
        $exists = $db->query(
            "SELECT COUNT(*) FROM people
             WHERE specialty = 'OG' AND last_name = 'SAFTA' AND first_name = 'Bianca'
               AND active = 1 AND guard_eligible = 1"
        )->fetchColumn();
        if ((int) $exists !== 1) {
            throw new RuntimeException('SAFTA Bianca nu a putut fi activata.');
        }
    }

    $lineCategories = [
        2 => ['Medic', 'Rezident an V'],
        3 => ['Rezident an IV'],
        4 => ['Rezident an III'],
        5 => ['Rezident an II'],
        6 => ['Rezident an I'],
    ];
    $guardCounts = [];
    $insertGuard = $db->prepare(
        "INSERT INTO guard_assignments
            (version_id, guard_date, specialty, line_no, original_person_id, current_person_id, assignment_state)
         VALUES
            (:version_id, :guard_date, 'OG', :line_no, :person_id, :person_id_current, 'initial')"
    );

    foreach ($lineCategories as $line => $categories) {
        $versionId = currentVersionForLine($db, $line);
        $people = peopleForCategories($db, $categories);
        if ($people === []) {
            throw new RuntimeException("Linia {$line} nu are medici eligibili.");
        }

        $delete = $db->prepare(
            "DELETE FROM guard_assignments
             WHERE version_id = :version_id
               AND line_no = :line_no
               AND guard_date BETWEEN '2026-09-01' AND '2026-09-30'"
        );
        $delete->execute(['version_id' => $versionId, 'line_no' => $line]);

        $index = 0;
        for ($date = $startDate; $date <= $endDate; $date = $date->modify('+1 day')) {
            $personId = $people[$index % count($people)];
            $insertGuard->execute([
                'version_id' => $versionId,
                'guard_date' => $date->format('Y-m-d'),
                'line_no' => $line,
                'person_id' => $personId,
                'person_id_current' => $personId,
            ]);
            $index++;
        }
        $guardCounts[$line] = $index;
    }

    $deleteDemoEvents = $db->prepare(
        "DELETE oe
         FROM operation_events oe
         INNER JOIN operations o ON o.id = oe.operation_id
         WHERE o.scheduled_date BETWEEN '2026-09-01' AND '2026-09-30'
           AND o.custom_notes LIKE :marker"
    );
    $deleteDemoEvents->execute(['marker' => $demoMarker . '%']);

    $deleteDemoOperations = $db->prepare(
        "DELETE FROM operations
         WHERE scheduled_date BETWEEN '2026-09-01' AND '2026-09-30'
           AND custom_notes LIKE :marker"
    );
    $deleteDemoOperations->execute(['marker' => $demoMarker . '%']);

    $doctors = $db->query(
        "SELECT id
         FROM people
         WHERE specialty = 'OG' AND active = 1
         ORDER BY last_name, first_name, id"
    )->fetchAll(PDO::FETCH_COLUMN);
    $doctors = array_map('intval', $doctors);
    if ($doctors === []) {
        throw new RuntimeException('Nu exista medici OG activi pentru operatii.');
    }

    $sequence = 0;
    $workdayIndex = 0;
    $roomCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $doctorIndex = array_search(20, $doctors, true);
    $doctorIndex = $doctorIndex === false ? 0 : $doctorIndex;

    for ($date = $startDate; $date <= $endDate; $date = $date->modify('+1 day')) {
        if ((int) $date->format('N') >= 6) {
            continue;
        }

        $dateString = $date->format('Y-m-d');
        $roomPlan = [
            1 => array_fill(0, 6 + ($workdayIndex % 5), [11, 'Sarcina la termen - indicatie cezariana']),
            2 => array_merge(
                array_fill(0, 2, [11, 'Sarcina la termen - indicatie cezariana']),
                array_fill(0, 2 + ($workdayIndex % 2), [10, 'Patologie ginecologica programata'])
            ),
            3 => array_fill(0, 3 + ($workdayIndex % 2), [9, 'Patologie ginecologica - abord laparoscopic']),
            4 => [],
        ];

        $smallTypes = [
            [14, 'Indicatie de curetaj uterin'],
            [13, 'Indicatie de histeroscopie'],
            [15, 'Indicatie de conizatie'],
            [16, 'Mica interventie ginecologica'],
        ];
        for ($smallIndex = 0; $smallIndex < 3 + ($workdayIndex % 3); $smallIndex++) {
            $roomPlan[4][] = $smallTypes[($workdayIndex + $smallIndex) % count($smallTypes)];
        }

        foreach ($roomPlan as $roomId => $items) {
            foreach ($items as $displayOrder => [$operationTypeId, $diagnostic]) {
                $sequence++;
                $doctorId = $doctors[$doctorIndex % count($doctors)];
                $doctorIndex++;
                addOperation(
                    $db,
                    $dateString,
                    $roomId,
                    $displayOrder + 1,
                    $sequence,
                    $operationTypeId,
                    $doctorId,
                    $adminUserId,
                    $demoMarker,
                    $diagnostic
                );
                $roomCounts[$roomId]++;
            }
        }
        $workdayIndex++;
    }

    $lineOneAfter = lineOneSnapshot($db);
    if (!hash_equals($lineOneBefore, $lineOneAfter)) {
        throw new RuntimeException('Linia 1 s-a modificat neasteptat. Toate schimbarile au fost anulate.');
    }

    $db->prepare(
        "INSERT INTO audit_logs (user_id, action, entity_type, after_data)
         VALUES (:user_id, 'demo.september_2026.populate', 'calendar_month', :after_data)"
    )->execute([
        'user_id' => $adminUserId,
        'after_data' => json_encode([
            'month' => '2026-09',
            'safta_bianca_activated' => true,
            'line_1_preserved' => true,
            'guard_counts' => $guardCounts,
            'operation_counts_by_room' => $roomCounts,
            'operations_total' => $sequence,
        ], JSON_THROW_ON_ERROR),
    ]);

    $db->commit();
    fwrite(STDOUT, json_encode([
        'safta_bianca_activated' => true,
        'line_1_preserved' => true,
        'guards' => $guardCounts,
        'operations_by_room' => $roomCounts,
        'operations_total' => $sequence,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
