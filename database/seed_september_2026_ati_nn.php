<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connect($config['database']);
$adminUserId = 5;
$start = new DateTimeImmutable('2026-09-01');
$end = new DateTimeImmutable('2026-09-30');

function currentGuardVersion(PDO $db, string $specialty, int $line, int $userId): int
{
    $statement = $db->prepare(
        "SELECT id,current_version_id FROM guard_plans
         WHERE specialty=:specialty AND line_no=:line_no
           AND date_from<='2026-09-01' AND date_to>='2026-09-30'
         ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $statement->execute(['specialty' => $specialty, 'line_no' => $line]);
    $plan = $statement->fetch();
    if (!$plan) {
        $db->prepare(
            "INSERT INTO guard_plans (specialty,line_no,date_from,date_to,created_by)
             VALUES (:specialty,:line_no,'2026-09-01','2026-09-30',:created_by)"
        )->execute(['specialty' => $specialty, 'line_no' => $line, 'created_by' => $userId]);
        $plan = ['id' => (int) $db->lastInsertId(), 'current_version_id' => null];
    }

    $versionId = (int) ($plan['current_version_id'] ?? 0);
    if ($versionId <= 0) {
        $versionNumber = (int) $db->query('SELECT COALESCE(MAX(version_no),0)+1 FROM guard_plan_versions WHERE plan_id=' . (int) $plan['id'])->fetchColumn();
        $db->prepare(
            "INSERT INTO guard_plan_versions
                (plan_id,version_no,status,created_by,published_by,published_at)
             VALUES (:plan_id,:version_no,'published',:created_by,:published_by,NOW())"
        )->execute([
            'plan_id' => $plan['id'], 'version_no' => $versionNumber,
            'created_by' => $userId, 'published_by' => $userId,
        ]);
        $versionId = (int) $db->lastInsertId();
        $db->prepare('UPDATE guard_plans SET current_version_id=:version_id WHERE id=:plan_id')
            ->execute(['version_id' => $versionId, 'plan_id' => $plan['id']]);
    }

    return $versionId;
}

$db->beginTransaction();
try {
    $configuration = ['ATI' => 1, 'NN' => 2];
    $summary = [];
    $insert = $db->prepare(
        "INSERT INTO guard_assignments
            (version_id,guard_date,specialty,line_no,original_person_id,current_person_id,assignment_state)
         VALUES
            (:version_id,:guard_date,:specialty,:line_no,:person_id,:current_person_id,'initial')"
    );

    foreach ($configuration as $specialty => $lineCount) {
        $peopleStatement = $db->prepare(
            'SELECT id FROM people
             WHERE specialty=:specialty AND active=1 AND guard_eligible=1
             ORDER BY last_name,first_name,id'
        );
        $peopleStatement->execute(['specialty' => $specialty]);
        $people = array_map('intval', $peopleStatement->fetchAll(PDO::FETCH_COLUMN));
        if (!$people) {
            throw new RuntimeException("Nu exista persoane eligibile pentru {$specialty}.");
        }

        for ($line = 1; $line <= $lineCount; $line++) {
            $versionId = currentGuardVersion($db, $specialty, $line, $adminUserId);
            $delete = $db->prepare(
                "DELETE FROM guard_assignments
                 WHERE version_id=:version_id AND specialty=:specialty AND line_no=:line_no
                   AND guard_date BETWEEN '2026-09-01' AND '2026-09-30'"
            );
            $delete->execute(['version_id' => $versionId, 'specialty' => $specialty, 'line_no' => $line]);

            $index = ($line - 1) * max(1, (int) floor(count($people) / $lineCount));
            $count = 0;
            for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
                $personId = $people[$index % count($people)];
                $insert->execute([
                    'version_id' => $versionId,
                    'guard_date' => $date->format('Y-m-d'),
                    'specialty' => $specialty,
                    'line_no' => $line,
                    'person_id' => $personId,
                    'current_person_id' => $personId,
                ]);
                $index++;
                $count++;
            }
            $summary[$specialty]['line_' . $line] = $count;
        }
    }

    $db->prepare(
        "INSERT INTO audit_logs (user_id,action,entity_type,after_data)
         VALUES (:user_id,'demo.september_2026.ati_nn_populated','calendar_month',:after_data)"
    )->execute([
        'user_id' => $adminUserId,
        'after_data' => json_encode($summary, JSON_THROW_ON_ERROR),
    ]);
    $db->commit();
    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL);
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
