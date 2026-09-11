<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

if (!$config) {
    fwrite(STDERR, "Lipseste config/config.php.\n");
    exit(1);
}

$db = Database::connect($config['database']);
$alreadyMigrated = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key');
$alreadyMigrated->execute(['key' => 'legacy_migration_v1']);
if ($alreadyMigrated->fetchColumn()) {
    fwrite(STDOUT, "Migrarea legacy_v1 a fost deja efectuata.\n");
    exit(0);
}

$guardsConfig = readJson('C:/xampp/htdocs/garzi/config.json');
$guardsData = readJson('C:/xampp/htdocs/garzi/date.json');
$progOpConfig = readJson('C:/xampp/htdocs/ProgOp/config.json');
$scheduleFiles = glob('C:/xampp/htdocs/ProgOp/data/schedule-*.json') ?: [];
$adminId = (int) $db->query("SELECT id FROM users WHERE username = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

if ($adminId <= 0) {
    fwrite(STDERR, "Contul admin trebuie creat inaintea migrarii.\n");
    exit(1);
}

$stats = ['people' => 0, 'guards' => 0, 'operations' => 0, 'blocks' => 0, 'types' => 0];
$db->beginTransaction();

try {
    foreach (($guardsConfig['personal'] ?? []) as $person) {
        $id = upsertPerson($db, [
            'first_name' => fixText((string) ($person['prenume'] ?? '')),
            'last_name' => fixText((string) ($person['nume'] ?? '')),
            'display_name' => fixText((string) ($person['prenumeComplet'] ?? '')),
            'specialty' => normalizeSpecialty((string) ($person['specialitate'] ?? 'OG')),
            'category' => fixText((string) ($person['categorie'] ?? '')),
            'position' => (int) ($person['senioritate'] ?? 0),
            'active' => strtoupper((string) ($person['activ'] ?? 'DA')) === 'DA' ? 1 : 0,
            'notes' => fixText((string) ($person['observatii'] ?? '')),
        ]);
        if ($id > 0) {
            $stats['people']++;
        }
    }

    foreach (($progOpConfig['settings']['doctors'] ?? []) as $doctorName) {
        findOrCreatePerson($db, fixText((string) $doctorName));
    }

    $typeIds = [];
    foreach (($progOpConfig['settings']['operationTypes'] ?? []) as $typeName) {
        $cleanName = fixText((string) $typeName);
        $typeIds[normalizeName($cleanName)] = upsertOperationType($db, $cleanName);
        $stats['types']++;
    }

    $roomIds = [];
    foreach (($progOpConfig['settings']['rooms'] ?? ['Sala 1', 'Sala 2', 'Sala 3', 'Sala 4']) as $index => $roomName) {
        $roomIds[(string) $roomName] = upsertRoom($db, (string) $roomName, $index + 1);
    }

    $guardGroups = [];
    foreach (($guardsData['planificare'] ?? []) as $entry) {
        $specialty = normalizeSpecialty((string) ($entry['specialitate'] ?? 'OG'));
        $line = max(1, (int) ($entry['linie'] ?? 1));
        $date = sprintf('%04d-%02d-%02d', (int) $entry['an'], (int) $entry['luna'], (int) $entry['zi']);
        $guardGroups[$specialty . ':' . $line][] = ['entry' => $entry, 'date' => $date, 'specialty' => $specialty, 'line' => $line];
    }

    foreach ($guardGroups as $entries) {
        usort($entries, static fn(array $left, array $right): int => strcmp($left['date'], $right['date']));
        $first = $entries[0];
        $last = $entries[count($entries) - 1];

        $statement = $db->prepare(
            'INSERT INTO guard_plans (specialty, line_no, date_from, date_to, created_by)
             VALUES (:specialty, :line_no, :date_from, :date_to, :created_by)'
        );
        $statement->execute([
            'specialty' => $first['specialty'],
            'line_no' => $first['line'],
            'date_from' => $first['date'],
            'date_to' => $last['date'],
            'created_by' => $adminId,
        ]);
        $planId = (int) $db->lastInsertId();

        $statement = $db->prepare(
            'INSERT INTO guard_plan_versions (plan_id, version_no, status, created_by, published_by, published_at)
             VALUES (:plan_id, 1, "published", :created_by, :published_by, NOW())'
        );
        $statement->execute(['plan_id' => $planId, 'created_by' => $adminId, 'published_by' => $adminId]);
        $versionId = (int) $db->lastInsertId();
        $db->prepare('UPDATE guard_plans SET current_version_id = :version_id WHERE id = :plan_id')
            ->execute(['version_id' => $versionId, 'plan_id' => $planId]);

        foreach ($entries as $guard) {
            $entry = $guard['entry'];
            $personId = findPersonFromGuard($db, $entry);
            if ($personId <= 0) {
                continue;
            }
            $db->prepare(
                'INSERT INTO guard_assignments
                 (version_id, guard_date, specialty, line_no, original_person_id, current_person_id, assignment_state)
                 VALUES (:version_id, :guard_date, :specialty, :line_no, :original_person_id, :current_person_id, "initial")'
            )->execute([
                'version_id' => $versionId,
                'guard_date' => $guard['date'],
                'specialty' => $guard['specialty'],
                'line_no' => $guard['line'],
                'original_person_id' => $personId,
                'current_person_id' => $personId,
            ]);
            $stats['guards']++;
        }
    }

    foreach ($scheduleFiles as $file) {
        $monthData = readJson($file);
        foreach (($monthData['schedule'] ?? []) as $date => $day) {
            foreach (($day['rooms'] ?? []) as $roomName => $roomState) {
                $roomId = $roomIds[$roomName] ?? upsertRoom($db, (string) $roomName, count($roomIds) + 1);
                if (!empty($roomState['blocked'])) {
                    $db->prepare(
                        'INSERT INTO room_blocks (room_id, block_date, reason, created_by)
                         VALUES (:room_id, :block_date, :reason, :created_by)'
                    )->execute([
                        'room_id' => $roomId,
                        'block_date' => $date,
                        'reason' => fixText((string) ($roomState['blockReason'] ?? '')),
                        'created_by' => $adminId,
                    ]);
                    $stats['blocks']++;
                }

                foreach (($roomState['interventions'] ?? []) as $index => $operation) {
                    $doctorId = findOrCreatePerson($db, fixText((string) ($operation['doctor'] ?? 'Medic neprecizat')));
                    $typeName = fixText((string) ($operation['operationType'] ?? 'Nespecificat'));
                    $typeKey = normalizeName($typeName);
                    $typeId = $typeIds[$typeKey] ?? upsertOperationType($db, $typeName);
                    $typeIds[$typeKey] = $typeId;
                    $completed = !empty($operation['completed']);

                    $db->prepare(
                        'INSERT INTO operations
                         (scheduled_date, room_id, display_order, patient_name, diagnostic, operation_type_id,
                          scheduled_doctor_id, created_by, status, custom_notes, is_extra, extra_reason,
                          completed_at, created_at, updated_at)
                         VALUES
                         (:scheduled_date, :room_id, :display_order, :patient_name, :diagnostic, :operation_type_id,
                          :scheduled_doctor_id, :created_by, :status, :custom_notes, :is_extra, :extra_reason,
                          :completed_at, :created_at, :updated_at)'
                    )->execute([
                        'scheduled_date' => $date,
                        'room_id' => $roomId,
                        'display_order' => $index + 1,
                        'patient_name' => fixText((string) ($operation['patientName'] ?? '')),
                        'diagnostic' => fixText((string) ($operation['diagnostic'] ?? '')),
                        'operation_type_id' => $typeId,
                        'scheduled_doctor_id' => $doctorId,
                        'created_by' => $adminId,
                        'status' => $completed ? 'completed' : 'scheduled',
                        'custom_notes' => fixText((string) ($operation['customNotes'] ?? '')),
                        'is_extra' => !empty($operation['extra']) ? 1 : 0,
                        'extra_reason' => fixText((string) ($operation['extraReason'] ?? '')),
                        'completed_at' => $completed && !empty($operation['completedAt']) ? $operation['completedAt'] : null,
                        'created_at' => normalizeDateTime((string) ($operation['createdAt'] ?? '')),
                        'updated_at' => normalizeDateTime((string) ($operation['updatedAt'] ?? '')),
                    ]);
                    $stats['operations']++;
                }
            }
        }
    }

    $db->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)'
    )->execute([
        'key' => 'legacy_migration_v1',
        'value' => json_encode(['completed_at' => date(DATE_ATOM), 'stats' => $stats], JSON_UNESCAPED_UNICODE),
    ]);

    $db->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, after_data, ip_address)
         VALUES (:user_id, "system.legacy_migration", "platform", :after_data, "127.0.0.1")'
    )->execute(['user_id' => $adminId, 'after_data' => json_encode($stats, JSON_UNESCAPED_UNICODE)]);

    $db->commit();
    fwrite(STDOUT, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $error) {
    $db->rollBack();
    fwrite(STDERR, "Migrarea a esuat: {$error->getMessage()}\n");
    exit(1);
}

function readJson(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException("Fisier JSON invalid: {$path}");
    }
    return $data;
}

function fixText(string $value): string
{
    return trim(strtr($value, [
        'Ã®' => 'î', 'ÃŽ' => 'Î', 'Ã¢' => 'â', 'Ã‚' => 'Â', 'Äƒ' => 'ă', 'Ä‚' => 'Ă',
        'È™' => 'ș', 'È˜' => 'Ș', 'È›' => 'ț', 'Èš' => 'Ț', 'ÅŸ' => 'ș', 'Å£' => 'ț',
    ]));
}

function normalizeSpecialty(string $value): string
{
    $value = strtoupper(trim($value));
    return in_array($value, ['OG', 'ATI', 'NN'], true) ? $value : 'OG';
}

function normalizeName(string $value): string
{
    $value = mb_strtolower(fixText($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function nameFingerprint(string $value): string
{
    $parts = array_filter(explode(' ', normalizeName($value)), static fn(string $part): bool => !in_array($part, ['dr', 'prof'], true));
    sort($parts, SORT_STRING);
    return implode('|', $parts);
}

function upsertPerson(PDO $db, array $person): int
{
    $fingerprint = nameFingerprint($person['display_name']);
    foreach ($db->query('SELECT id, display_name FROM people')->fetchAll() as $existing) {
        if (nameFingerprint((string) $existing['display_name']) === $fingerprint) {
            $db->prepare(
                'UPDATE people SET first_name=:first_name, last_name=:last_name, display_name=:display_name,
                 specialty=:specialty, professional_category=:category, rotation_position=:position,
                 active=:active, notes=:notes WHERE id=:id'
            )->execute($person + ['id' => $existing['id']]);
            return (int) $existing['id'];
        }
    }

    $db->prepare(
        'INSERT INTO people
         (first_name, last_name, display_name, specialty, professional_category, rotation_position, active, notes)
         VALUES (:first_name, :last_name, :display_name, :specialty, :category, :position, :active, :notes)'
    )->execute($person);
    return (int) $db->lastInsertId();
}

function findOrCreatePerson(PDO $db, string $displayName): int
{
    $fingerprint = nameFingerprint($displayName);
    foreach ($db->query('SELECT id, display_name FROM people')->fetchAll() as $person) {
        if (nameFingerprint((string) $person['display_name']) === $fingerprint) {
            return (int) $person['id'];
        }
    }

    $parts = preg_split('/\s+/u', trim($displayName)) ?: [$displayName];
    $firstName = array_pop($parts) ?: $displayName;
    $lastName = implode(' ', $parts) ?: $displayName;
    return upsertPerson($db, [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'display_name' => $displayName,
        'specialty' => 'OG',
        'category' => '',
        'position' => null,
        'active' => 1,
        'notes' => 'Importat din ProgOp',
    ]);
}

function findPersonFromGuard(PDO $db, array $entry): int
{
    $legacyId = (int) ($entry['idPersoana'] ?? 0);
    $guardConfig = readJson('C:/xampp/htdocs/garzi/config.json');
    foreach (($guardConfig['personal'] ?? []) as $person) {
        if ((int) ($person['id'] ?? 0) === $legacyId) {
            return findOrCreatePerson($db, fixText((string) $person['prenumeComplet']));
        }
    }
    return findOrCreatePerson($db, fixText((string) ($entry['prenumeComplet'] ?? 'Medic neprecizat')));
}

function upsertOperationType(PDO $db, string $name): int
{
    $statement = $db->prepare('SELECT id FROM operation_types WHERE name = :name');
    $statement->execute(['name' => $name]);
    $id = $statement->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $db->prepare('INSERT INTO operation_types (name) VALUES (:name)')->execute(['name' => $name]);
    return (int) $db->lastInsertId();
}

function upsertRoom(PDO $db, string $name, int $order): int
{
    $db->prepare(
        'INSERT INTO operation_rooms (name, display_order) VALUES (:name, :display_order)
         ON DUPLICATE KEY UPDATE display_order = VALUES(display_order), active = 1'
    )->execute(['name' => $name, 'display_order' => $order]);
    $statement = $db->prepare('SELECT id FROM operation_rooms WHERE name = :name');
    $statement->execute(['name' => $name]);
    return (int) $statement->fetchColumn();
}

function normalizeDateTime(string $value): string
{
    if ($value === '') {
        return date('Y-m-d H:i:s');
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return date('Y-m-d H:i:s');
    }
}
