<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Statistics.php';
apiRequirePlatformAdministrator();
apiRequirePermission('guards.manage_people', 'guards.generate', 'guards.publish', 'admin.permissions', 'admin.users');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    loadAdminConfiguration($db);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Metoda nu este acceptata.', 405);
}

apiVerifyCsrf();
$input = apiInput();
match ((string) ($input['action'] ?? '')) {
    'save_guard_line_configuration' => saveGuardLineConfiguration($db, $input),
    'save_og_rotation' => saveOgRotation($db, $input),
    'generate_og_line1' => generateOgLine1($db, $input),
    'publish_og_line1' => publishOgLine1($db, $input),
    'restore_guard_version' => restoreGuardVersion($db, $input),
    'create_progop_backup' => createProgOpBackup($db),
    'delete_progop_backup' => deleteProgOpBackup($db, $input),
    'save_person' => savePerson($db, $input),
    'save_guard_eligibility' => saveGuardEligibility($db, $input),
    'delete_person' => deletePerson($db, $input),
    'save_user' => saveUser($db, $input),
    'save_user_permissions' => saveUserPermissions($db, $input),
    'reset_user_password' => resetUserPassword($db, $input),
    'create_api_token' => createApiToken($db, $input),
    'revoke_api_token' => revokeApiToken($db, $input),
    'delete_api_token' => deleteApiToken($db, $input),
    default => apiError('Actiune necunoscuta.'),
};

function loadAdminConfiguration(PDO $db): never
{
    global $apiPermissions, $apiUser;
    $canManagePeople = in_array('guards.manage_people', $apiPermissions, true) || in_array('admin.users', $apiPermissions, true);
    $canManageUsers = in_array('admin.users', $apiPermissions, true);
    $canManagePermissions = in_array('admin.permissions', $apiPermissions, true);
    $canViewAudit = in_array('admin.audit', $apiPermissions, true);
    $canGenerateReports = Statistics::canGenerate($db, (int)$apiUser['id']);
    $rows = $db->query('SELECT specialty, line_count, line_labels, line1_protected, updated_at FROM guard_specialty_settings ORDER BY FIELD(specialty, "OG", "ATI", "NN")')->fetchAll();
    $lineMonth = validMonth((string)($_GET['line_month'] ?? date('Y-m')));
    $people = $canManagePeople ? $db->query(
        'SELECT p.*,u.id user_id,u.username,u.status user_status,u.api_access_level,u.must_change_password,u.last_login_at
         FROM people p LEFT JOIN users u ON u.person_id=p.id
         ORDER BY p.active DESC,p.specialty,p.last_name,p.first_name'
    )->fetchAll() : [];
    $userRoles = [];
    foreach ($canManageUsers ? $db->query('SELECT ur.user_id,r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id ORDER BY r.name') : [] as $row) {
        $userRoles[(int) $row['user_id']][] = $row['code'];
    }
    $rolePermissions = [];
    foreach ($canManageUsers ? $db->query('SELECT r.code role_code,p.code permission_code FROM roles r LEFT JOIN role_permissions rp ON rp.role_id=r.id LEFT JOIN permissions p ON p.id=rp.permission_id ORDER BY r.name,p.module,p.name') : [] as $row) {
        if ($row['permission_code'] !== null) $rolePermissions[$row['role_code']][] = $row['permission_code'];
    }
    $effective = [];
    foreach ($people as $person) {
        if (empty($person['user_id'])) continue;
        $effective[(int) $person['user_id']] = Auth::permissions($db, (int) $person['user_id']);
    }
    $rotation = $db->query(
        'SELECT p.id,p.display_name,p.title,p.professional_category,r.position
         FROM og_line1_rotation r JOIN people p ON p.id=r.person_id
         WHERE p.active=1 AND p.specialty="OG" AND p.professional_category NOT LIKE "Rezident%"
         ORDER BY r.position'
    )->fetchAll();
    $rotationCandidates = $db->query(
        'SELECT p.id,p.display_name,p.title,p.professional_category
         FROM people p LEFT JOIN og_line1_rotation r ON r.person_id=p.id
         WHERE p.active=1 AND p.specialty="OG" AND p.professional_category NOT LIKE "Rezident%" AND r.person_id IS NULL
         ORDER BY p.last_name,p.first_name'
    )->fetchAll();
    $latestBatch = $db->query(
        'SELECT b.*,p.display_name start_person_name,u.username created_by_name
         FROM guard_generation_batches b JOIN people p ON p.id=b.start_person_id JOIN users u ON u.id=b.created_by
         WHERE b.specialty="OG" AND b.line_no=1 ORDER BY b.id DESC LIMIT 1'
    )->fetch() ?: null;
    $apiTokens = $canManagePermissions ? $db->query(
        'SELECT t.id,t.user_id,t.name,t.token_prefix,t.permissions,t.expires_at,t.last_used_at,t.last_ip,t.revoked_at,t.created_at,
                u.username,p.display_name
         FROM api_tokens t JOIN users u ON u.id=t.user_id LEFT JOIN people p ON p.id=u.person_id
         ORDER BY (t.revoked_at IS NULL AND t.expires_at>NOW()) DESC,t.created_at DESC'
    )->fetchAll() : [];
    foreach ($apiTokens as &$token) {
        $decoded = json_decode((string) $token['permissions'], true);
        $token['permissions'] = is_array($decoded) ? $decoded : [];
    }
    unset($token);
    $auditLogs = [];
    $connectedUsers = [];
    $auditStats = [];
    if ($canViewAudit) {
        $db->exec('DELETE FROM user_sessions WHERE expires_at <= NOW()');
        $auditSearch = mb_substr(trim((string)($_GET['audit_search'] ?? '')), 0, 100);
        $auditModule = in_array((string)($_GET['audit_module'] ?? ''), ['auth','guards','progop','admin','reports'], true) ? (string)$_GET['audit_module'] : '';
        $auditSql = 'SELECT a.id,a.action,a.entity_type,a.entity_id,a.before_data,a.after_data,a.ip_address,a.created_at,
                            u.username,p.display_name,t.name api_token_name
                     FROM audit_logs a
                     LEFT JOIN users u ON u.id=a.user_id
                     LEFT JOIN people p ON p.id=u.person_id
                     LEFT JOIN api_tokens t ON t.id=a.api_token_id WHERE 1=1';
        $auditParams = [];
        if ($auditSearch !== '') {
            $normalizedAuditSearch = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $auditSearch) ?: $auditSearch);
            $actionAliases = [];
            if (str_contains($normalizedAuditSearch, 'schimb')) $actionAliases[] = '%exchange%';
            if (str_contains($normalizedAuditSearch, 'prelu')) $actionAliases = array_merge($actionAliases, ['%takeover%','%transfer%']);
            if (str_contains($normalizedAuditSearch, 'ced')) $actionAliases[] = '%transfer%';
            if (str_contains($normalizedAuditSearch, 'sterg')) $actionAliases = array_merge($actionAliases, ['%deleted%','%removed%','%archived%']);
            if (str_contains($normalizedAuditSearch, 'adaug')) $actionAliases = array_merge($actionAliases, ['%created%','%added%']);
            if (str_contains($normalizedAuditSearch, 'operat')) $actionAliases[] = 'progop.%';
            if (str_contains($normalizedAuditSearch, 'garda') || str_contains($normalizedAuditSearch, 'garzi')) $actionAliases[] = 'guards.%';
            if (str_contains($normalizedAuditSearch, 'token')) $actionAliases[] = '%api_token%';
            if (str_contains($normalizedAuditSearch, 'parol')) $actionAliases[] = '%password%';
            $aliasSql = '';
            foreach (array_values(array_unique($actionAliases)) as $index => $pattern) { $aliasSql .= ' OR a.action LIKE :search_alias_'.$index; $auditParams['search_alias_'.$index] = $pattern; }
            $auditSql .= ' AND (a.action LIKE :search_action OR a.entity_type LIKE :search_entity OR CAST(a.entity_id AS CHAR) LIKE :search_id OR a.before_data LIKE :search_before OR a.after_data LIKE :search_after OR u.username LIKE :search_username OR p.display_name LIKE :search_name'.$aliasSql.')';
            foreach (['search_action','search_entity','search_id','search_before','search_after','search_username','search_name'] as $key) $auditParams[$key] = '%'.$auditSearch.'%';
        }
        if ($auditModule !== '') { $auditSql .= ' AND a.action LIKE :search_module'; $auditParams['search_module'] = $auditModule.'.%'; }
        $auditSql .= ' ORDER BY a.created_at DESC,a.id DESC LIMIT 500';
        try {
            $auditStatement = $db->prepare($auditSql);
            $auditStatement->execute($auditParams);
            $auditLogs = $auditStatement->fetchAll();
            $auditLookups = auditLookups($db);
            foreach ($auditLogs as &$auditLog) {
                $auditLog['detail'] = auditDetail($auditLog, $auditLookups);
                unset($auditLog['before_data'], $auditLog['after_data']);
            }
            unset($auditLog);
        } catch (PDOException $error) {
            apiLogException($error, 'admin.audit_load');
            $auditLogs = [];
        }
        $connectedUsers = $db->query(
            'SELECT us.user_id,u.username,p.display_name,COUNT(*) session_count,MAX(us.last_activity_at) last_activity_at
             FROM user_sessions us
             JOIN users u ON u.id=us.user_id
             LEFT JOIN people p ON p.id=u.person_id
             WHERE us.expires_at>NOW() AND us.last_activity_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)
             GROUP BY us.user_id,u.username,p.display_name
             ORDER BY last_activity_at DESC'
        )->fetchAll();
        $auditStats = [
            'connected_users' => count($connectedUsers),
            'connected_sessions' => (int) $db->query('SELECT COUNT(*) FROM user_sessions WHERE expires_at>NOW() AND last_activity_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)')->fetchColumn(),
            'actions_last_24h' => (int) $db->query('SELECT COUNT(*) FROM audit_logs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)')->fetchColumn(),
            'active_accounts' => (int) $db->query('SELECT COUNT(*) FROM users WHERE status="active"')->fetchColumn(),
        ];
    }
    $guardVersions = [];
    $backupFiles = [];
    if ($canViewAudit || in_array('guards.restore', $apiPermissions, true)) {
        $guardVersions = $db->query(
            'SELECT gv.id,gv.version_no,gv.status,gv.created_at,gv.published_at,gv.restored_from_version_id,
                    gp.id plan_id,gp.specialty,gp.line_no,gp.date_from,gp.date_to,gp.current_version_id,
                    p.display_name created_by_name,COUNT(ga.id) assignment_count
             FROM guard_plan_versions gv
             JOIN guard_plans gp ON gp.id=gv.plan_id
             JOIN users u ON u.id=gv.created_by
             LEFT JOIN people p ON p.id=u.person_id
             LEFT JOIN guard_assignments ga ON ga.version_id=gv.id
             GROUP BY gv.id,gv.version_no,gv.status,gv.created_at,gv.published_at,gv.restored_from_version_id,
                      gp.id,gp.specialty,gp.line_no,gp.date_from,gp.date_to,gp.current_version_id,p.display_name
             ORDER BY gp.date_from DESC,FIELD(gp.specialty,"OG","ATI","NN"),gp.line_no,gv.version_no DESC LIMIT 150'
        )->fetchAll();
        $backupFiles = progOpBackupFiles();
    }
    apiSuccess([
        'guardLineConfiguration' => monthlyAdminLineConfiguration($rows, $lineMonth),
        'guardLineMonth' => $lineMonth,
        'people' => $people,
        'userRoles' => $userRoles,
        'rolePermissions' => $rolePermissions,
        'effectivePermissions' => $effective,
        'roles' => $canManageUsers ? $db->query('SELECT code,name,description FROM roles ORDER BY is_system DESC,name')->fetchAll() : [],
        'permissions' => $canManagePermissions ? $db->query('SELECT code,module,name,description FROM permissions ORDER BY module,name')->fetchAll() : [],
        'ogRotation' => $rotation,
        'ogRotationCandidates' => $rotationCandidates,
        'latestOgGeneration' => $latestBatch,
        'apiTokens' => $apiTokens,
        'auditLogs' => $auditLogs,
        'connectedUsers' => $connectedUsers,
        'auditStats' => $auditStats,
        'guardVersions' => $guardVersions,
        'backupFiles' => $backupFiles,
        'canGenerateGuards' => in_array('guards.generate', $apiPermissions, true),
        'canPublishGuards' => in_array('guards.publish', $apiPermissions, true),
        'canManageUsers' => $canManageUsers,
        'canManagePermissions' => $canManagePermissions,
        'canManageApiTokens' => $canManagePermissions,
        'canViewAudit' => $canViewAudit,
        'canGenerateReports' => $canGenerateReports,
        'canRestoreGuards' => in_array('guards.restore', $apiPermissions, true),
        'currentPersonId' => $apiUser['person_id'] === null ? null : (int) $apiUser['person_id'],
    ]);
}

function saveGuardEligibility(PDO $db, array $input): never
{
    global $apiPermissions;
    if (!array_intersect(['guards.manage_people','admin.users'], $apiPermissions)) apiError('Nu ai dreptul sa modifici participarea la garzi.', 403);
    $personId = (int)($input['person_id'] ?? 0);
    $eligible = !empty($input['guard_eligible']) ? 1 : 0;
    $statement = $db->prepare(
        'SELECT p.*,u.id user_id FROM people p JOIN users u ON u.person_id=p.id
         WHERE p.id=:id AND p.active=1 AND p.specialty IN ("OG","ATI","NN") LIMIT 1'
    );
    $statement->execute(['id'=>$personId]);
    $before = $statement->fetch();
    if (!$before) apiError('Medicul activ cu cont nu a fost gasit.', 404);

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE people SET guard_eligible=:eligible WHERE id=:id')->execute(['eligible'=>$eligible,'id'=>$personId]);
        if (!$eligible && (string)$before['specialty'] === 'OG') {
            $db->prepare('DELETE FROM og_line1_rotation WHERE person_id=:id')->execute(['id'=>$personId]);
        }
        apiAudit($db, 'admin.guard_eligibility_updated', 'person', $personId, ['guard_eligible'=>(int)$before['guard_eligible']], ['guard_eligible'=>$eligible]);
        $db->commit();
        apiSuccess(['message'=>$eligible ? 'Medicul participa la garzi si apare in liste.' : 'Medicul a fost scos din listele de garda.']);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Participarea la garzi nu a putut fi salvata.', 500);
    }
}

function auditLookups(PDO $db): array
{
    $map = static function (array $rows): array {
        $result = [];
        foreach ($rows as $row) $result[(int) $row['id']] = (string) $row['name'];
        return $result;
    };
    return [
        'people' => $map($db->query('SELECT id,display_name name FROM people')->fetchAll()),
        'rooms' => $map($db->query('SELECT id,name FROM operation_rooms')->fetchAll()),
        'types' => $map($db->query('SELECT id,name FROM operation_types')->fetchAll()),
        'operations' => array_column($db->query('SELECT * FROM operations')->fetchAll(), null, 'id'),
    ];
}

function auditData(?string $json): mixed
{
    if ($json === null || $json === '') return null;
    $decoded = json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function auditFirst(mixed $value): array
{
    if (!is_array($value)) return [];
    if (array_is_list($value)) return is_array($value[0] ?? null) ? $value[0] : [];
    return $value;
}

function auditPersonName(array $lookups, mixed $id): string
{
    return $lookups['people'][(int) $id] ?? ('medicul #' . (int) $id);
}

function auditGuardText(array $item, array $lookups): string
{
    if (!$item) return 'garda necunoscuta';
    $name = auditPersonName($lookups, $item['current_person_id'] ?? $item['original_person_id'] ?? 0);
    $date = !empty($item['guard_date']) ? date('d.m.Y', strtotime((string) $item['guard_date'])) : 'data necunoscuta';
    return sprintf('garda %s L%d din %s a lui %s', $item['specialty'] ?? '', (int) ($item['line_no'] ?? 0), $date, $name);
}

function auditOperationText(array $item, array $lookups, int $operationId = 0): string
{
    $stored = is_array($lookups['operations'][$operationId] ?? null) ? $lookups['operations'][$operationId] : [];
    $item = array_merge($stored, $item);
    $patient = trim((string) ($item['patient_name'] ?? $item['patient'] ?? '')) ?: 'pacient nespecificat';
    $doctorId = $item['doctor_id'] ?? $item['scheduled_doctor_id'] ?? 0;
    $roomId = (int) ($item['room_id'] ?? 0);
    $typeId = (int) ($item['operation_type_id'] ?? $item['type_id'] ?? 0);
    $dateValue = $item['date'] ?? $item['scheduled_date'] ?? null;
    $date = $dateValue ? date('d.m.Y', strtotime((string) $dateValue)) : 'data necunoscuta';
    $doctor = auditPersonName($lookups, $doctorId);
    $room = $lookups['rooms'][$roomId] ?? ('sala #' . $roomId);
    $type = $lookups['types'][$typeId] ?? 'tip nespecificat';
    return sprintf('%s, %s, medic %s, %s, %s', $patient, $type, $doctor, $room, $date);
}

function auditDetail(array $log, array $lookups): string
{
    $beforeRaw = auditData($log['before_data'] ?? null);
    $afterRaw = auditData($log['after_data'] ?? null);
    $before = auditFirst($beforeRaw);
    $after = auditFirst($afterRaw);
    $action = (string) $log['action'];
    $beforeName = (string) ($before['display_name'] ?? $before['username'] ?? 'persoana necunoscuta');
    $afterName = (string) ($after['display_name'] ?? $after['username'] ?? $beforeName);
    $guardBefore = auditGuardText($before, $lookups);
    $guardAfter = auditGuardText($after, $lookups);
    $details = match ($action) {
        'admin.person_created' => 'A adaugat persoana ' . $afterName . '.',
        'admin.person_updated' => 'A modificat datele lui ' . $afterName . '.',
        'admin.person_archived' => 'A arhivat persoana ' . $beforeName . ' si i-a oprit accesul la garzi.',
        'admin.person_deleted' => 'A sters persoana ' . $beforeName . '.',
        'admin.user_created' => 'A creat contul ' . ((string) ($after['username'] ?? ('#' . $log['entity_id']))) . '.',
        'admin.user_updated' => 'A modificat contul ' . ((string) ($after['username'] ?? $before['username'] ?? ('#' . $log['entity_id']))) . '.',
        'guards.assignment_added' => 'A adaugat ' . $guardAfter . '.',
        'guards.assignment_removed', 'guards.assignment_deleted' => 'A sters ' . $guardBefore . '.',
        'guards.assignment_replaced', 'guards.admin_correction' => 'A inlocuit ' . $guardBefore . ' cu ' . $guardAfter . '.',
        'guards.takeover' => 'A preluat ' . $guardBefore . '; noul titular este ' . auditPersonName($lookups, $after['current_person_id'] ?? 0) . '.',
        'guards.transfer' => 'A cedat ' . $guardBefore . ' catre ' . auditPersonName($lookups, $after['current_person_id'] ?? 0) . '.',
        'guards.exchange' => auditExchangeDetail($beforeRaw, $afterRaw, $lookups),
        'guards.assignment_line_moved' => auditGuardLineMoveDetail($beforeRaw, $afterRaw, $lookups),
        'guards.undo' => 'A anulat ultima modificare de garzi; a fost restaurata starea anterioara.',
        'guards.version_restored' => sprintf('A restaurat versiunea #%d ca versiune noua #%d pentru planul de garzi #%d.', (int) ($before['version_id'] ?? 0), (int) ($after['version_id'] ?? 0), (int) ($after['plan_id'] ?? 0)),
        'progop.operation_created' => 'A adaugat operatia: ' . auditOperationText($after, $lookups, (int) $log['entity_id']) . '.',
        'progop.operation_updated' => 'A modificat operatia #' . (int) $log['entity_id'] . ': ' . auditOperationText($after, $lookups, (int) $log['entity_id']) . '.',
        'progop.operation_deleted' => 'A sters operatia: ' . auditOperationText($before, $lookups, (int) $log['entity_id']) . '.',
        'progop.extra_approval_requested' => 'A trimis spre aprobare operatia EXTRA: ' . auditOperationText($after, $lookups, (int)$log['entity_id']) . '.',
        'progop.extra_approved' => 'A aprobat operatia EXTRA: ' . auditOperationText($after, $lookups, (int)$log['entity_id']) . '.',
        'progop.extra_rejected' => 'A marcat ca neaprobata operatia EXTRA: ' . auditOperationText($after, $lookups, (int)$log['entity_id']) . '.',
        'progop.operation_status' => 'A schimbat starea operatiei #' . (int) $log['entity_id'] . ' in ' . ((string) ($after['status'] ?? 'necunoscuta')) . '.',
        'progop.room_blocked' => 'A blocat ' . ($lookups['rooms'][(int) ($after['room_id'] ?? 0)] ?? 'sala') . ' in ' . (!empty($after['date']) ? date('d.m.Y', strtotime((string) $after['date'])) : 'data necunoscuta') . '.',
        'progop.room_unblocked' => 'A deblocat ' . ($lookups['rooms'][(int) ($before['room_id'] ?? 0)] ?? 'sala') . ' pentru ' . (!empty($before['block_date']) ? date('d.m.Y', strtotime((string) $before['block_date'])) : 'data necunoscuta') . '.',
        'progop.backup_created' => 'A creat copia Programului operator ' . ((string) ($after['file'] ?? '')) . '.',
        'admin.api_token_deleted' => 'A sters definitiv tokenul API inactiv ' . ((string)($before['name'] ?? ('#'.$log['entity_id']))) . '.',
        default => '',
    };
    if ($details !== '' && !empty($log['api_token_name'])) $details .= ' Actiune efectuata prin tokenul ' . $log['api_token_name'] . '.';
    return $details;
}

function auditExchangeDetail(mixed $beforeRaw, mixed $afterRaw, array $lookups): string
{
    if (!is_array($beforeRaw) || !is_array($afterRaw) || count($beforeRaw) < 2 || count($afterRaw) < 2) return 'A efectuat un schimb de garzi.';
    return 'A schimbat ' . auditGuardText($beforeRaw[0], $lookups) . ' cu ' . auditGuardText($beforeRaw[1], $lookups) . '.';
}

function auditGuardLineMoveDetail(mixed $beforeRaw, mixed $afterRaw, array $lookups): string
{
    if (!is_array($beforeRaw) || !is_array($afterRaw) || !$beforeRaw || !$afterRaw) return 'A mutat o garda intre linii.';
    $detail = 'A mutat ' . auditGuardText($beforeRaw[0], $lookups) . ' pe ' . auditGuardText($afterRaw[0], $lookups) . '.';
    if (count($beforeRaw) > 1 && count($afterRaw) > 1) {
        $detail .= ' Garda ' . auditGuardText($beforeRaw[1], $lookups) . ' a fost mutata prin rocada pe ' . auditGuardText($afterRaw[1], $lookups) . '.';
    }
    return $detail;
}

function progOpBackupFiles(): array
{
    $directory = dirname(__DIR__, 2) . '/storage/backups';
    if (!is_dir($directory)) return [];
    $files = [];
    foreach (glob($directory . '/backup_sql_*.json') ?: [] as $path) {
        $files[] = ['name' => basename($path), 'size' => filesize($path), 'created_at' => date('Y-m-d H:i:s', filemtime($path))];
    }
    usort($files, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    return $files;
}

function createProgOpBackup(PDO $db): never
{
    apiRequirePermission('progop.manage_all', 'admin.audit');
    $directory = dirname(__DIR__, 2) . '/storage/backups';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) apiError('Directorul de backup nu a putut fi creat.', 500);
    $file = 'backup_sql_' . date('Ymd_His') . '.json';
    $data = [
        'format' => 'intern-progop-json-v1', 'created_at' => date(DATE_ATOM),
        'people' => $db->query('SELECT * FROM people')->fetchAll(),
        'operations' => $db->query('SELECT * FROM operations')->fetchAll(),
        'blocks' => $db->query('SELECT * FROM room_blocks')->fetchAll(),
        'types' => $db->query('SELECT * FROM operation_types')->fetchAll(),
    ];
    file_put_contents($directory . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    apiAudit($db, 'progop.backup_created', 'backup', null, null, ['file' => $file]);
    apiSuccess(['message' => 'Copia Programului operator a fost creata.', 'file' => $file]);
}

function deleteProgOpBackup(PDO $db, array $input): never
{
    apiRequirePermission('progop.manage_all', 'admin.audit');
    $file = basename((string) ($input['file'] ?? ''));
    if (!preg_match('/^backup_sql_\d{8}_\d{6}\.json$/', $file)) apiError('Fisierul de backup nu este valid.');
    $path = dirname(__DIR__, 2) . '/storage/backups/' . $file;
    if (!is_file($path)) apiError('Fisierul de backup nu mai exista.', 404);
    if (!unlink($path)) apiError('Fisierul de backup nu a putut fi sters.', 500);
    apiAudit($db, 'progop.backup_deleted', 'backup', null, ['file' => $file], null);
    apiSuccess(['message' => 'Copia JSON a fost stearsa.']);
}

function restoreGuardVersion(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('guards.restore');
    $sourceVersionId = (int) ($input['version_id'] ?? 0);
    if ($sourceVersionId <= 0) apiError('Versiunea nu este valida.');
    $db->beginTransaction();
    try {
        $sourceStatement = $db->prepare(
            'SELECT gv.*,gp.current_version_id,gp.specialty,gp.line_no,gp.date_from,gp.date_to
             FROM guard_plan_versions gv JOIN guard_plans gp ON gp.id=gv.plan_id
             WHERE gv.id=:id FOR UPDATE'
        );
        $sourceStatement->execute(['id' => $sourceVersionId]);
        $source = $sourceStatement->fetch();
        if (!$source) throw new DomainException('Versiunea nu a fost gasita.');
        if ((int) $source['current_version_id'] === $sourceVersionId) throw new DomainException('Aceasta este deja versiunea activa.');
        $planId = (int) $source['plan_id'];
        $versionStatement = $db->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM guard_plan_versions WHERE plan_id=:plan_id');
        $versionStatement->execute(['plan_id' => $planId]);
        $newVersionNo = (int) $versionStatement->fetchColumn();
        $db->prepare(
            'INSERT INTO guard_plan_versions(plan_id,version_no,status,start_person_id,next_person_id,restored_from_version_id,created_by,published_by,published_at)
             VALUES(:plan_id,:version_no,"published",:start_person_id,:next_person_id,:restored_from,:user_id,:user_id,NOW())'
        )->execute([
            'plan_id' => $planId, 'version_no' => $newVersionNo, 'start_person_id' => $source['start_person_id'],
            'next_person_id' => $source['next_person_id'], 'restored_from' => $sourceVersionId, 'user_id' => $apiUser['id'],
        ]);
        $newVersionId = (int) $db->lastInsertId();
        $db->prepare(
            'INSERT INTO guard_assignments(version_id,guard_date,specialty,line_no,original_person_id,current_person_id,assignment_state,created_by_user_id)
             SELECT :new_version,guard_date,specialty,line_no,original_person_id,current_person_id,assignment_state,created_by_user_id
             FROM guard_assignments WHERE version_id=:source_version'
        )->execute(['new_version' => $newVersionId, 'source_version' => $sourceVersionId]);
        $currentVersionId = (int) $source['current_version_id'];
        if ($currentVersionId > 0) $db->prepare('UPDATE guard_plan_versions SET status="superseded" WHERE id=:id')->execute(['id' => $currentVersionId]);
        $db->prepare('UPDATE guard_plans SET current_version_id=:version_id WHERE id=:plan_id')->execute(['version_id' => $newVersionId, 'plan_id' => $planId]);
        apiAudit($db, 'guards.version_restored', 'guard_plan_version', $newVersionId,
            ['plan_id' => $planId, 'version_id' => $sourceVersionId, 'replaced_version_id' => $currentVersionId],
            ['plan_id' => $planId, 'version_id' => $newVersionId, 'restored_from_version_id' => $sourceVersionId]);
        $db->commit();
        apiSuccess(['message' => sprintf('Versiunea %d a fost restaurata ca versiunea %d.', (int) $source['version_no'], $newVersionNo)]);
    } catch (DomainException $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($error->getMessage(), 409);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Versiunea nu a putut fi restaurata.', 500);
    }
}

function saveOgRotation(PDO $db, array $input): never
{
    apiRequirePermission('guards.generate', 'guards.manage_people');
    $ids = array_values(array_unique(array_map('intval', is_array($input['person_ids'] ?? null) ? $input['person_ids'] : [])));
    if (!$ids) apiError('Rotația OG L1 trebuie sa contina cel putin un medic.');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $db->prepare(
        "SELECT id FROM people WHERE id IN ($placeholders) AND active=1 AND specialty='OG' AND professional_category NOT LIKE 'Rezident%'"
    );
    $statement->execute($ids);
    if (count($statement->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) apiError('Lista contine persoane care nu sunt medici OG activi.', 409);
    $db->beginTransaction();
    try {
        $before = $db->query('SELECT person_id,position FROM og_line1_rotation ORDER BY position')->fetchAll();
        $db->exec('DELETE FROM og_line1_rotation');
        $insert = $db->prepare('INSERT INTO og_line1_rotation(person_id,position) VALUES(:person_id,:position)');
        foreach ($ids as $index => $personId) $insert->execute(['person_id' => $personId, 'position' => $index + 1]);
        apiAudit($db, 'guards.og_rotation_updated', 'og_line1_rotation', null, $before, ['person_ids' => $ids]);
        $db->commit();
        apiSuccess(['message' => 'Ordinea pentru OG Linia 1 a fost salvata.']);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Ordinea nu a putut fi salvata.', 500);
    }
}

function generateOgLine1(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('guards.generate');
    if (!ogLine1Protected($db)) apiError('Activeaza protectia OG Linia 1 inainte de generare.', 409);
    $startDate = validDate((string) ($input['start_date'] ?? ''));
    $months = (int) ($input['months'] ?? 0);
    $startPersonId = (int) ($input['start_person_id'] ?? 0);
    if ($startDate < date('Y-m-d')) apiError('Generarea poate incepe doar de astazi sau din viitor.');
    if ($months < 1 || $months > 24) apiError('Numarul de luni trebuie sa fie intre 1 si 24.');
    $rotation = $db->query(
        'SELECT r.person_id,p.display_name FROM og_line1_rotation r JOIN people p ON p.id=r.person_id
         WHERE p.active=1 AND p.specialty="OG" AND p.professional_category NOT LIKE "Rezident%" ORDER BY r.position'
    )->fetchAll();
    $personIds = array_map(static fn(array $row): int => (int) $row['person_id'], $rotation);
    $startIndex = array_search($startPersonId, $personIds, true);
    if ($startIndex === false || !$personIds) apiError('Medicul de inceput trebuie sa fie in ordinea activa OG L1.');
    $start = new DateTimeImmutable($startDate);
    $end = $start->modify('+' . $months . ' months')->modify('-1 day');

    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO guard_generation_batches(specialty,line_no,start_date,end_date,months_count,start_person_id,status,created_by)
             VALUES("OG",1,:start_date,:end_date,:months_count,:start_person_id,"draft",:created_by)'
        )->execute(['start_date'=>$startDate,'end_date'=>$end->format('Y-m-d'),'months_count'=>$months,'start_person_id'=>$startPersonId,'created_by'=>$apiUser['id']]);
        $batchId = (int) $db->lastInsertId();
        $cursor = $start->modify('first day of this month');
        $dayOffset = 0;
        while ($cursor <= $end) {
            $monthStart = $cursor->format('Y-m-01');
            $monthEnd = $cursor->modify('last day of this month')->format('Y-m-d');
            $planStatement = $db->prepare(
                'SELECT id,current_version_id FROM guard_plans WHERE specialty="OG" AND line_no=1
                 AND date_from=:date_from AND date_to=:date_to ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $planStatement->execute(['date_from'=>$monthStart,'date_to'=>$monthEnd]);
            $plan = $planStatement->fetch();
            if (!$plan) {
                $db->prepare('INSERT INTO guard_plans(specialty,line_no,date_from,date_to,created_by) VALUES("OG",1,:date_from,:date_to,:created_by)')
                    ->execute(['date_from'=>$monthStart,'date_to'=>$monthEnd,'created_by'=>$apiUser['id']]);
                $planId = (int) $db->lastInsertId();
                $currentVersionId = null;
            } else {
                $planId = (int) $plan['id'];
                $currentVersionId = $plan['current_version_id'] ? (int) $plan['current_version_id'] : null;
            }
            $versionStatement = $db->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM guard_plan_versions WHERE plan_id=:plan_id');
            $versionStatement->execute(['plan_id'=>$planId]);
            $versionNo = (int) $versionStatement->fetchColumn();
            $db->prepare(
                'INSERT INTO guard_plan_versions(plan_id,version_no,status,start_person_id,created_by)
                 VALUES(:plan_id,:version_no,"draft",:start_person_id,:created_by)'
            )->execute(['plan_id'=>$planId,'version_no'=>$versionNo,'start_person_id'=>$startPersonId,'created_by'=>$apiUser['id']]);
            $versionId = (int) $db->lastInsertId();
            if ($currentVersionId) {
                $db->prepare(
                    'INSERT INTO guard_assignments(version_id,guard_date,specialty,line_no,original_person_id,current_person_id,assignment_state)
                     SELECT :new_version,guard_date,specialty,line_no,original_person_id,current_person_id,assignment_state
                     FROM guard_assignments WHERE version_id=:old_version'
                )->execute(['new_version'=>$versionId,'old_version'=>$currentVersionId]);
            }
            $rangeStart = max($startDate, $monthStart);
            $rangeEnd = min($end->format('Y-m-d'), $monthEnd);
            $db->prepare('DELETE FROM guard_assignments WHERE version_id=:version_id AND guard_date BETWEEN :date_from AND :date_to AND specialty="OG" AND line_no=1')
                ->execute(['version_id'=>$versionId,'date_from'=>$rangeStart,'date_to'=>$rangeEnd]);
            $insertAssignment = $db->prepare(
                'INSERT INTO guard_assignments(version_id,guard_date,specialty,line_no,original_person_id,current_person_id,assignment_state)
                 VALUES(:version_id,:guard_date,"OG",1,:original_person_id,:current_person_id,"initial")'
            );
            $day = new DateTimeImmutable($rangeStart);
            $rangeLast = new DateTimeImmutable($rangeEnd);
            while ($day <= $rangeLast) {
                $personId = $personIds[($startIndex + $dayOffset) % count($personIds)];
                $insertAssignment->execute(['version_id'=>$versionId,'guard_date'=>$day->format('Y-m-d'),'original_person_id'=>$personId,'current_person_id'=>$personId]);
                $dayOffset++;
                $day = $day->modify('+1 day');
            }
            $nextPersonId = $personIds[($startIndex + $dayOffset) % count($personIds)];
            $db->prepare('UPDATE guard_plan_versions SET next_person_id=:next_person_id WHERE id=:id')->execute(['next_person_id'=>$nextPersonId,'id'=>$versionId]);
            $db->prepare('INSERT INTO guard_generation_batch_versions(batch_id,plan_id,version_id) VALUES(:batch_id,:plan_id,:version_id)')
                ->execute(['batch_id'=>$batchId,'plan_id'=>$planId,'version_id'=>$versionId]);
            $cursor = $cursor->modify('+1 month');
        }
        apiAudit($db, 'guards.og_line1_generated', 'guard_generation_batch', $batchId, null, ['start_date'=>$startDate,'end_date'=>$end->format('Y-m-d'),'months'=>$months,'start_person_id'=>$startPersonId]);
        $db->commit();
        apiSuccess(['message'=>'Draftul OG Linia 1 a fost generat. Verifica intervalul si publica-l cand este pregatit.','batch_id'=>$batchId]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiLogException($error, 'admin.generate_og_line1');
        apiError('Draftul nu a putut fi generat.', 500);
    }
}

function publishOgLine1(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('guards.publish');
    if (!ogLine1Protected($db)) apiError('Activeaza protectia OG Linia 1 inainte de publicare.', 409);
    $batchId = (int) ($input['batch_id'] ?? 0);
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT * FROM guard_generation_batches WHERE id=:id AND specialty="OG" AND line_no=1 FOR UPDATE');
        $statement->execute(['id'=>$batchId]);
        $batch = $statement->fetch();
        if (!$batch || $batch['status'] !== 'draft') throw new DomainException('Draftul nu mai este disponibil pentru publicare.');
        $versions = $db->prepare('SELECT plan_id,version_id FROM guard_generation_batch_versions WHERE batch_id=:batch_id ORDER BY plan_id');
        $versions->execute(['batch_id'=>$batchId]);
        foreach ($versions as $version) {
            $current = $db->prepare('SELECT current_version_id FROM guard_plans WHERE id=:id FOR UPDATE');
            $current->execute(['id'=>$version['plan_id']]);
            $oldVersion = $current->fetchColumn();
            if ($oldVersion) $db->prepare('UPDATE guard_plan_versions SET status="superseded" WHERE id=:id')->execute(['id'=>$oldVersion]);
            $db->prepare('UPDATE guard_plan_versions SET status="published",published_by=:user_id,published_at=NOW() WHERE id=:id')
                ->execute(['user_id'=>$apiUser['id'],'id'=>$version['version_id']]);
            $db->prepare('UPDATE guard_plans SET current_version_id=:version_id WHERE id=:plan_id')
                ->execute(['version_id'=>$version['version_id'],'plan_id'=>$version['plan_id']]);
        }
        $db->prepare('UPDATE guard_generation_batches SET status="published",published_by=:user_id,published_at=NOW() WHERE id=:id')
            ->execute(['user_id'=>$apiUser['id'],'id'=>$batchId]);
        apiAudit($db, 'guards.og_line1_published', 'guard_generation_batch', $batchId, ['status'=>'draft'], ['status'=>'published']);
        $db->commit();
        apiSuccess(['message'=>'Programul OG Linia 1 a fost publicat.']);
    } catch (DomainException $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($error->getMessage(), 409);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Programul nu a putut fi publicat.', 500);
    }
}

function saveGuardLineConfiguration(PDO $db, array $input): never
{
    global $apiUser;
    $month = validMonth((string)($input['month'] ?? ''));
    $requested = $input['line_counts'] ?? null;
    $requestedExtra = $input['extra_lines'] ?? [];
    if (!is_array($requested)) apiError('Configuratia liniilor nu este valida.');

    $beforeRows = $db->query('SELECT specialty, line_count, line_labels, line1_protected FROM guard_specialty_settings ORDER BY FIELD(specialty, "OG", "ATI", "NN")')->fetchAll();
    $before = monthlyAdminLineConfiguration($beforeRows, $month);
    $defaults = defaultLineLabels();
    $validated = [];
    foreach (['OG', 'ATI', 'NN'] as $specialty) {
        $count = (int) ($requested[$specialty] ?? 0);
        if ($count < 1 || $count > 8) apiError('Numarul liniilor deschise pentru ' . $specialty . ' trebuie sa fie intre 1 si 8; aplicatia poate deschide automat inca o linie.');
        $existing = $before[$specialty]['labels'] ?? [];
        $labels = [];
        for ($index = 0; $index < $count; $index++) {
            $labels[] = (string) ($existing[$index] ?? $defaults[$specialty][$index] ?? ('L' . ($index + 1)));
        }
        $validated[$specialty] = [
            'line_count' => $count,
            'extra_enabled' => !empty($requestedExtra[$specialty]),
            'labels' => $labels,
            'line1_protected' => $specialty === 'OG' && !empty($input['og_line1_protected']) ? 1 : 0,
        ];
    }
    try {
        $all = guardMonthlyLineSettings();
        $all[$month] = array_map(static fn(array $configuration): array => [
            'line_count' => (int)$configuration['line_count'],
            'extra_enabled' => !empty($configuration['extra_enabled']),
        ], $validated);
        ksort($all);
        writeGuardMonthlyLineSettings($all, (int)$apiUser['id']);
        $db->prepare('UPDATE guard_specialty_settings SET line1_protected=:protected,updated_by=:user WHERE specialty="OG"')
            ->execute(['protected'=>!empty($input['og_line1_protected']) ? 1 : 0,'user'=>$apiUser['id']]);
        apiAudit($db, 'guards.monthly_line_configuration_updated', 'guard_line_configuration', null, ['month'=>$month,'configuration'=>$before], ['month'=>$month,'configuration'=>$validated]);
        apiSuccess(['message' => 'Configuratia lunii ' . $month . ' a fost salvata. Garzile existente pe liniile ascunse au fost pastrate.', 'guardLineConfiguration' => $validated]);
    } catch (Throwable $error) {
        apiError('Configuratia nu a putut fi salvata.', 500);
    }
}

function savePerson(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('admin.users', 'guards.manage_people');
    $personId = (int) ($input['person_id'] ?? 0);
    $firstName = trim((string) ($input['first_name'] ?? ''));
    $lastName = trim((string) ($input['last_name'] ?? ''));
    $displayName = trim($lastName . ' ' . $firstName);
    $title = trim((string) ($input['title'] ?? ''));
    $specialty = strtoupper(trim((string) ($input['specialty'] ?? 'OG')));
    $category = trim((string) ($input['professional_category'] ?? 'Medic'));
    if ($firstName === '' || $lastName === '' || $displayName === '' || !in_array($specialty, ['OG', 'ATI', 'NN', 'ASISTENTA'], true)) {
        apiError('Numele, prenumele si specialitatea sau grupa sunt obligatorii.');
    }
    $duplicate = $db->prepare('SELECT id FROM people WHERE first_name=:first_name AND last_name=:last_name AND id<>:id LIMIT 1');
    $duplicate->execute(['first_name'=>$firstName,'last_name'=>$lastName,'id'=>$personId]);
    if ($duplicate->fetchColumn()) apiError('Exista deja o persoana cu acelasi nume si prenume.', 409);
    $data = [
        'first_name' => mb_substr($firstName, 0, 100), 'last_name' => mb_substr($lastName, 0, 100),
        'display_name' => mb_substr($displayName, 0, 220), 'title' => mb_substr($title, 0, 100),
        'specialty' => $specialty, 'professional_category' => mb_substr($category, 0, 100),
        'rotation_position' => ($input['rotation_position'] ?? '') === '' ? null : max(1, (int) $input['rotation_position']),
        'active' => !empty($input['active']) ? 1 : 0, 'guard_eligible' => $specialty !== 'ASISTENTA' && !empty($input['guard_eligible']) ? 1 : 0,
        'notes' => trim((string) ($input['notes'] ?? '')),
    ];
    if ($personId > 0) {
        $statement = $db->prepare('SELECT * FROM people WHERE id=:id'); $statement->execute(['id' => $personId]); $before = $statement->fetch();
        if (!$before) apiError('Persoana nu a fost gasita.', 404);
        $db->prepare('UPDATE people SET first_name=:first_name,last_name=:last_name,display_name=:display_name,title=:title,specialty=:specialty,professional_category=:professional_category,rotation_position=:rotation_position,active=:active,guard_eligible=:guard_eligible,notes=:notes WHERE id=:id')->execute($data + ['id' => $personId]);
        apiAudit($db, 'admin.person_updated', 'person', $personId, $before, $data);
    } else {
        $db->prepare('INSERT INTO people(first_name,last_name,display_name,title,specialty,professional_category,rotation_position,active,guard_eligible,notes) VALUES(:first_name,:last_name,:display_name,:title,:specialty,:professional_category,:rotation_position,:active,:guard_eligible,:notes)')->execute($data);
        $personId = (int) $db->lastInsertId();
        apiAudit($db, 'admin.person_created', 'person', $personId, null, $data);
    }
    apiSuccess(['message' => 'Datele persoanei au fost salvate.', 'person_id' => $personId]);
}

function deletePerson(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('admin.users', 'guards.manage_people');
    $personId = (int) ($input['person_id'] ?? 0);
    if ($personId <= 0) apiError('Persoana nu este valida.');
    if ((int) ($apiUser['person_id'] ?? 0) === $personId) apiError('Nu iti poti sterge propria persoana.', 409);
    $statement = $db->prepare('SELECT * FROM people WHERE id=:id');
    $statement->execute(['id' => $personId]);
    $person = $statement->fetch();
    if (!$person) apiError('Persoana nu a fost gasita.', 404);

    $references = $db->prepare(
        'SELECT
            (SELECT COUNT(*) FROM users WHERE person_id=:id_user) +
            (SELECT COUNT(*) FROM guard_assignments WHERE original_person_id=:id_guard_a OR current_person_id=:id_guard_b) +
            (SELECT COUNT(*) FROM operations WHERE scheduled_doctor_id=:id_operation) +
            (SELECT COUNT(*) FROM guard_generation_batches WHERE start_person_id=:id_batch) +
            (SELECT COUNT(*) FROM guard_plan_versions WHERE start_person_id=:id_version_a OR next_person_id=:id_version_b)'
    );
    $references->execute([
        'id_user'=>$personId,'id_guard_a'=>$personId,'id_guard_b'=>$personId,'id_operation'=>$personId,
        'id_batch'=>$personId,'id_version_a'=>$personId,'id_version_b'=>$personId,
    ]);
    $hasHistory = (int) $references->fetchColumn() > 0;

    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM og_line1_rotation WHERE person_id=:id')->execute(['id' => $personId]);
        if ($hasHistory) {
            $db->prepare('UPDATE people SET active=0,guard_eligible=0 WHERE id=:id')->execute(['id' => $personId]);
            $db->prepare('UPDATE users SET status="inactive" WHERE person_id=:id')->execute(['id' => $personId]);
            apiAudit($db, 'admin.person_archived', 'person', $personId, $person, ['active'=>0,'guard_eligible'=>0]);
            $message = 'Persoana a fost arhivata pentru a pastra istoricul garzilor, operatiilor si logurilor.';
        } else {
            $db->prepare('DELETE FROM people WHERE id=:id')->execute(['id' => $personId]);
            apiAudit($db, 'admin.person_deleted', 'person', $personId, $person, null);
            $message = 'Persoana a fost stearsa.';
        }
        $db->commit();
        apiSuccess(['message' => $message, 'archived' => $hasHistory]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError('Persoana nu a putut fi stearsa.', 500);
    }
}

function saveUser(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('admin.users');
    $userId = (int) ($input['user_id'] ?? 0);
    $personId = (int) ($input['person_id'] ?? 0);
    $username = strtolower(trim((string) ($input['username'] ?? '')));
    $status = (string) ($input['status'] ?? 'active');
    $apiAccessLevel=(string)($input['api_access_level']??'none');
    $roles = array_values(array_unique(array_filter(array_map('strval', is_array($input['roles'] ?? null) ? $input['roles'] : []))));
    if ($personId <= 0 || !preg_match('/^[a-z0-9._-]{3,100}$/', $username) || !in_array($status, ['active', 'inactive', 'locked'], true) || !in_array($apiAccessLevel,['none','read','schedule','full'],true)) {
        apiError('Persoana, username-ul si starea contului nu sunt valide.');
    }
    if ($userId === (int) $apiUser['id'] && $status !== 'active') apiError('Nu iti poti dezactiva propriul cont.', 409);
    $roleIds = [];
    if ($roles) {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $statement = $db->prepare("SELECT id,code FROM roles WHERE code IN ($placeholders)"); $statement->execute($roles);
        foreach ($statement as $row) $roleIds[(string) $row['code']] = (int) $row['id'];
        if (count($roleIds) !== count($roles)) apiError('Unul dintre roluri nu este valid.');
    }
    if ($userId > 0) assertAdminRoleContinuity($db, $userId, $roles, $status, (int)$apiUser['id']);
    $temporaryPassword = trim((string) ($input['temporary_password'] ?? ''));
    $db->beginTransaction();
    try {
        if ($userId > 0) {
            $statement = $db->prepare('SELECT id,person_id,username,status FROM users WHERE id=:id FOR UPDATE'); $statement->execute(['id' => $userId]); $before = $statement->fetch();
            if (!$before) throw new DomainException('Contul nu a fost gasit.');
            $params = ['person_id'=>$personId,'username'=>$username,'status'=>$status,'api_access_level'=>$apiAccessLevel,'id'=>$userId];
            $sql = 'UPDATE users SET person_id=:person_id,username=:username,status=:status,api_access_level=:api_access_level,failed_login_count=0,locked_until=NULL';
            if ($temporaryPassword !== '') { validateTemporaryPassword($temporaryPassword); $sql .= ',password_hash=:password_hash,must_change_password=1'; $params['password_hash'] = password_hash($temporaryPassword, PASSWORD_DEFAULT); }
            $db->prepare($sql . ' WHERE id=:id')->execute($params);
        } else {
            if ($temporaryPassword === '') $temporaryPassword = generateTemporaryPassword();
            validateTemporaryPassword($temporaryPassword);
            $db->prepare('INSERT INTO users(person_id,username,password_hash,status,api_access_level,must_change_password) VALUES(:person_id,:username,:password_hash,:status,:api_access_level,1)')->execute(['person_id'=>$personId,'username'=>$username,'password_hash'=>password_hash($temporaryPassword,PASSWORD_DEFAULT),'status'=>$status,'api_access_level'=>$apiAccessLevel]);
            $userId = (int) $db->lastInsertId(); $before = null;
        }
        $db->prepare('DELETE FROM user_roles WHERE user_id=:user_id')->execute(['user_id'=>$userId]);
        $insertRole = $db->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(:user_id,:role_id)');
        foreach ($roles as $role) $insertRole->execute(['user_id'=>$userId,'role_id'=>$roleIds[$role]]);
        if (array_intersect(['assistant_editor','assistant_viewer'], $roles)) {
            $db->prepare('UPDATE people SET guard_eligible=0 WHERE id=:id')->execute(['id'=>$personId]);
        }
        apiAudit($db, $before ? 'admin.user_updated' : 'admin.user_created', 'user', $userId, $before, ['person_id'=>$personId,'username'=>$username,'status'=>$status,'api_access_level'=>$apiAccessLevel,'roles'=>$roles]);
        $db->commit();
        apiSuccess(['message'=>'Contul a fost salvat.','user_id'=>$userId,'temporary_password'=>$temporaryPassword !== '' ? $temporaryPassword : null]);
    } catch (PDOException $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiError($error->getCode() === '23000' ? 'Username-ul sau persoana este deja asociata altui cont.' : 'Contul nu a putut fi salvat.', 409);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        apiLogException($error, 'admin.save_user');
        apiError('Contul nu a putut fi salvat.', 500);
    }
}

function saveUserPermissions(PDO $db, array $input): never
{
    global $apiUser;
    apiRequirePermission('admin.permissions');
    $userId = (int) ($input['user_id'] ?? 0);
    $desired = array_flip(array_map('strval', is_array($input['permissions'] ?? null) ? $input['permissions'] : []));
    if (isFullAdminRoleUser($db, $userId) && (!isset($desired['admin.users']) || !isset($desired['admin.permissions']))) {
        if ($userId === (int)$apiUser['id']) apiError('Nu iti poti elimina propriile drepturi administrative esentiale.', 409);
        if (countOtherFullAdmins($db, $userId) === 0) apiError('Nu poti elimina drepturile ultimului administrator complet activ.', 409);
    }
    $all = $db->query('SELECT id,code FROM permissions')->fetchAll();
    $baseStatement = $db->prepare('SELECT p.code FROM user_roles ur JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id=:user_id');
    $baseStatement->execute(['user_id'=>$userId]); $base = array_flip($baseStatement->fetchAll(PDO::FETCH_COLUMN));
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM user_permission_overrides WHERE user_id=:user_id')->execute(['user_id'=>$userId]);
        $insert = $db->prepare('INSERT INTO user_permission_overrides(user_id,permission_id,allowed) VALUES(:user_id,:permission_id,:allowed)');
        foreach ($all as $permission) {
            $wanted = isset($desired[$permission['code']]); $fromRole = isset($base[$permission['code']]);
            if ($wanted !== $fromRole) $insert->execute(['user_id'=>$userId,'permission_id'=>$permission['id'],'allowed'=>$wanted?1:0]);
        }
        apiAudit($db,'admin.permissions_updated','user',$userId,null,['permissions'=>array_keys($desired)]); $db->commit();
        apiSuccess(['message'=>'Drepturile utilizatorului au fost salvate.']);
    } catch (Throwable $error) { if($db->inTransaction())$db->rollBack();apiError('Drepturile nu au putut fi salvate.',500); }
}

function assertAdminRoleContinuity(PDO $db, int $userId, array $newRoles, string $newStatus, int $actorUserId): void
{
    if (!isFullAdminRoleUser($db, $userId)) return;
    $keepsFullAccess = $newStatus === 'active' && (bool) array_intersect(['platform_admin', 'super_admin'], $newRoles);
    if ($keepsFullAccess) return;
    if ($userId === $actorUserId) apiError('Nu iti poti elimina propriul rol administrativ complet.', 409);
    if (countOtherFullAdmins($db, $userId) === 0) apiError('Nu poti elimina sau dezactiva ultimul administrator complet activ.', 409);
}

function isFullAdminRoleUser(PDO $db, int $userId): bool
{
    $statement = $db->prepare('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE ur.user_id=:user_id AND u.status="active" AND r.code IN ("platform_admin","super_admin")');
    $statement->execute(['user_id'=>$userId]);
    return (int)$statement->fetchColumn() > 0;
}

function countOtherFullAdmins(PDO $db, int $excludedUserId): int
{
    $statement = $db->prepare('SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.status="active" AND u.id<>:excluded_user_id AND r.code IN ("platform_admin","super_admin")');
    $statement->execute(['excluded_user_id'=>$excludedUserId]);
    return (int)$statement->fetchColumn();
}

function resetUserPassword(PDO $db, array $input): never
{
    apiRequirePermission('admin.users'); $userId=(int)($input['user_id']??0);$password=trim((string)($input['temporary_password']??''));if($password==='')$password=generateTemporaryPassword();validateTemporaryPassword($password);
    $statement=$db->prepare('UPDATE users SET password_hash=:hash,must_change_password=1,failed_login_count=0,locked_until=NULL WHERE id=:id');$statement->execute(['hash'=>password_hash($password,PASSWORD_DEFAULT),'id'=>$userId]);if($statement->rowCount()===0)apiError('Contul nu a fost gasit.',404);apiAudit($db,'admin.password_reset','user',$userId,null,['must_change_password'=>1]);apiSuccess(['message'=>'Parola temporara a fost resetata.','temporary_password'=>$password]);
}

function createApiToken(PDO $db, array $input): never
{
    global $apiUser, $apiPermissions, $apiToken;
    apiRequirePermission('admin.permissions');
    if ($apiToken !== null) apiError('Tokenurile API pot fi administrate doar din sesiunea web.', 403);
    $name = trim((string) ($input['name'] ?? ''));
    $targetUserId=(int)($input['user_id']??0);
    $expiresOn = validDate((string) ($input['expires_on'] ?? ''));
    $requested = array_values(array_unique(array_filter(array_map('strval', is_array($input['permissions'] ?? null) ? $input['permissions'] : []))));
    if (mb_strlen($name) < 3 || mb_strlen($name) > 120) apiError('Numele tokenului trebuie sa aiba intre 3 si 120 de caractere.');
    if (!$requested) apiError('Selecteaza cel putin un drept pentru token.');
    $targetStatement=$db->prepare('SELECT id,api_access_level FROM users WHERE id=:id AND status="active"');$targetStatement->execute(['id'=>$targetUserId]);$target=$targetStatement->fetch();
    if(!$target||$target['api_access_level']==='none')apiError('Contul selectat nu are acces API activ.',403);
    $targetPermissions=Auth::permissions($db,$targetUserId);$allowed=apiPermissionsForLevel((string)$target['api_access_level'],$targetPermissions);
    if(array_diff($requested,$allowed))apiError('Tokenul depaseste nivelul API permis contului selectat.',403);
    $today = new DateTimeImmutable('today');
    $expiry = new DateTimeImmutable($expiresOn . ' 23:59:59');
    if ($expiry <= $today || $expiry > $today->modify('+365 days')->setTime(23, 59, 59)) {
        apiError('Expirarea tokenului trebuie sa fie intre maine si cel mult 365 de zile.');
    }
    $plainToken = 'intern_' . bin2hex(random_bytes(32));
    $prefix = substr($plainToken, 0, 18);
    $db->prepare(
        'INSERT INTO api_tokens(user_id,name,token_prefix,token_hash,permissions,expires_at,created_by)
         VALUES(:user_id,:name,:token_prefix,:token_hash,:permissions,:expires_at,:created_by)'
    )->execute([
        'user_id'=>$targetUserId,'name'=>mb_substr($name,0,120),'token_prefix'=>$prefix,
        'token_hash'=>hash('sha256',$plainToken),'permissions'=>json_encode($requested, JSON_UNESCAPED_UNICODE),
        'expires_at'=>$expiry->format('Y-m-d H:i:s'),'created_by'=>$apiUser['id'],
    ]);
    $tokenId = (int) $db->lastInsertId();
    apiAudit($db, 'admin.api_token_created', 'api_token', $tokenId, null, ['name'=>$name,'permissions'=>$requested,'expires_at'=>$expiry->format(DATE_ATOM)]);
    apiSuccess(['message'=>'Tokenul API a fost creat. Este afisat o singura data.','api_token'=>$plainToken,'token_id'=>$tokenId]);
}

function revokeApiToken(PDO $db, array $input): never
{
    global $apiToken;
    apiRequirePermission('admin.permissions');
    if ($apiToken !== null) apiError('Tokenurile API pot fi administrate doar din sesiunea web.', 403);
    $tokenId = (int) ($input['token_id'] ?? 0);
    $statement = $db->prepare('UPDATE api_tokens SET revoked_at=NOW() WHERE id=:id AND revoked_at IS NULL');
    $statement->execute(['id'=>$tokenId]);
    if ($statement->rowCount() === 0) apiError('Tokenul nu a fost gasit sau este deja revocat.', 404);
    apiAudit($db, 'admin.api_token_revoked', 'api_token', $tokenId, null, ['revoked'=>true]);
    apiSuccess(['message'=>'Tokenul API a fost revocat.']);
}

function deleteApiToken(PDO $db, array $input): never
{
    global $apiToken;
    apiRequirePermission('admin.permissions');
    if ($apiToken !== null) apiError('Tokenurile API pot fi administrate doar din sesiunea web.', 403);
    $tokenId = (int)($input['token_id'] ?? 0);
    $statement = $db->prepare('SELECT id,name,revoked_at,expires_at FROM api_tokens WHERE id=:id');
    $statement->execute(['id'=>$tokenId]);
    $token = $statement->fetch();
    if (!$token) apiError('Tokenul nu a fost gasit.', 404);
    if ($token['revoked_at'] === null && strtotime((string)$token['expires_at']) > time()) apiError('Un token activ trebuie revocat inainte de stergere.', 409);
    $db->prepare('DELETE FROM api_tokens WHERE id=:id')->execute(['id'=>$tokenId]);
    apiAudit($db, 'admin.api_token_deleted', 'api_token', $tokenId, $token, ['deleted'=>true]);
    apiSuccess(['message'=>'Tokenul inactiv a fost sters definitiv.']);
}

function generateTemporaryPassword(): string { return 'Mg!' . bin2hex(random_bytes(5)) . 'A7'; }
function apiPermissionsForLevel(string $level,array $effective): array { $presets=['read'=>['guards.view','progop.view'],'schedule'=>['guards.view','progop.view','progop.create','progop.edit_own']];return $level==='full'?$effective:array_values(array_intersect($presets[$level]??[],$effective)); }
function validateTemporaryPassword(string $password): void { if(strlen($password)<12)apiError('Parola temporara trebuie sa aiba minimum 12 caractere.'); }

function guardMonthlyLineSettingsPath(): string
{
    return dirname(__DIR__, 2) . '/storage/config/guard_monthly_lines.json';
}

function guardMonthlyLineSettings(): array
{
    $path = guardMonthlyLineSettingsPath();
    if (!is_file($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded['months'] ?? null) ? $decoded['months'] : [];
}

function writeGuardMonthlyLineSettings(array $months, int $userId): void
{
    $path = guardMonthlyLineSettingsPath();
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    $payload = ['format_version'=>1,'updated_at'=>date(DATE_ATOM),'updated_by'=>$userId,'months'=>$months];
    if (file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Configuratia lunara nu a putut fi scrisa.');
    }
}

function monthlyAdminLineConfiguration(array $rows, string $month): array
{
    $configuration = normalizeConfigurations($rows);
    $monthly = guardMonthlyLineSettings()[$month] ?? [];
    foreach (['OG','ATI','NN'] as $specialty) {
        $saved = $monthly[$specialty] ?? null;
        $savedCount = is_array($saved) ? ($saved['line_count'] ?? null) : $saved;
        if ($savedCount !== null) $configuration[$specialty]['line_count'] = max(1, min(8, (int)$savedCount));
        else $configuration[$specialty]['line_count'] = max(1, min(8, (int)$configuration[$specialty]['line_count']));
        $configuration[$specialty]['extra_enabled'] = is_array($saved)
            ? !empty($saved['extra_enabled'])
            : $saved !== null;
    }
    return $configuration;
}

function normalizeConfigurations(array $rows): array
{
    $result = [];
    foreach ($rows as $row) {
        $labels = json_decode((string) $row['line_labels'], true);
        $result[(string) $row['specialty']] = [
            'line_count' => (int) $row['line_count'],
            'labels' => is_array($labels) ? $labels : [],
            'line1_protected' => !empty($row['line1_protected']),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
    return $result;
}

function defaultLineLabels(): array
{
    return [
        'OG' => ['L1 Spec.', 'L2 Spec.', 'L3 Rez.', 'L4 Rez.', 'L5 Rez.', 'L6 Rez.', 'L7 Rez.', 'L8 Rez.', 'L9 Rez.'],
        'ATI' => ['L1 ATI', 'L2 ATI', 'L3 ATI', 'L4 ATI', 'L5 ATI', 'L6 ATI', 'L7 ATI', 'L8 ATI', 'L9 ATI'],
        'NN' => ['L1 NN', 'L2 NN', 'L3 NN', 'L4 NN', 'L5 NN', 'L6 NN', 'L7 NN', 'L8 NN', 'L9 NN'],
    ];
}

function ogLine1Protected(PDO $db): bool
{
    return (bool) $db->query('SELECT line1_protected FROM guard_specialty_settings WHERE specialty="OG"')->fetchColumn();
}
