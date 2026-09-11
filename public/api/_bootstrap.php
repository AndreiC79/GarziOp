<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!$config) {
    apiError('Aplicatia nu este configurata.', 503);
}

try {
    $db = Database::connect($config['database']);
} catch (Throwable) {
    apiError('Baza de date nu este disponibila.', 503);
}

$apiToken = null;
$bearerToken = apiBearerToken();
if ($bearerToken !== null) {
    $tokenHash = hash('sha256', $bearerToken);
    $statement = $db->prepare(
        'SELECT t.id token_id,t.name token_name,t.permissions,t.expires_at,
                u.id,u.person_id,u.username,u.must_change_password,p.display_name,p.specialty
         FROM api_tokens t
         JOIN users u ON u.id=t.user_id
         LEFT JOIN people p ON p.id=u.person_id
         WHERE t.token_hash=:token_hash AND t.revoked_at IS NULL AND t.expires_at>NOW() AND u.status="active"
         LIMIT 1'
    );
    $statement->execute(['token_hash' => $tokenHash]);
    $authenticated = $statement->fetch();
    if (!$authenticated) {
        apiError('Token API invalid, expirat sau revocat.', 401);
    }
    $apiToken = [
        'id' => (int) $authenticated['token_id'],
        'name' => (string) $authenticated['token_name'],
    ];
    $apiUser = [
        'id' => (int) $authenticated['id'],
        'person_id' => $authenticated['person_id'] === null ? null : (int) $authenticated['person_id'],
        'username' => (string) $authenticated['username'],
        'must_change_password' => (int) $authenticated['must_change_password'],
        'display_name' => $authenticated['display_name'],
        'specialty' => $authenticated['specialty'],
    ];
    $userPermissions = Auth::permissions($db, (int) $apiUser['id']);
    $tokenPermissions = json_decode((string) $authenticated['permissions'], true);
    $apiPermissions = array_values(array_intersect($userPermissions, is_array($tokenPermissions) ? $tokenPermissions : []));
    $db->prepare('UPDATE api_tokens SET last_used_at=NOW(),last_ip=:last_ip WHERE id=:id')
        ->execute(['last_ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'id' => $apiToken['id']]);
    $db->prepare(
        'INSERT INTO audit_logs(user_id,api_token_id,action,entity_type,before_data,after_data,ip_address,user_agent)
         VALUES(:user_id,:api_token_id,"api.remote_access","api_endpoint",NULL,:after_data,:ip_address,:user_agent)'
    )->execute([
        'user_id' => $apiUser['id'],
        'api_token_id' => $apiToken['id'],
        'after_data' => json_encode([
            'token_name' => $apiToken['name'],
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'path' => $_SERVER['REQUEST_URI'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
    header('X-API-Authenticated: bearer');
} else {
    $apiUser = Auth::user($db);
    if (!$apiUser) {
        apiError('Autentificare necesara.', 401);
    }
    $apiPermissions = Auth::permissions($db, (int) $apiUser['id']);
}

function apiSuccess(array $data = []): never
{
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiLogException(Throwable $error, string $context): void
{
    $directory = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        error_log($context . ': ' . $error->getMessage());
        return;
    }

    $entry = sprintf(
        "[%s] %s | %s | %s:%d\n%s\n\n",
        date('c'),
        $context,
        $error->getMessage(),
        $error->getFile(),
        $error->getLine(),
        $error->getTraceAsString()
    );
    @file_put_contents($directory . '/app-errors.log', $entry, FILE_APPEND | LOCK_EX);
}

function apiInput(): array
{
    $input = json_decode((string) file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function apiRequirePermission(string ...$permissions): void
{
    global $apiPermissions;
    foreach ($permissions as $permission) {
        if (in_array($permission, $apiPermissions, true)) {
            return;
        }
    }
    apiError('Nu ai dreptul necesar pentru aceasta actiune.', 403);
}

function apiRequirePlatformAdministrator(): void
{
    global $db, $apiUser;
    if (isset($_SESSION['local_lab_role'])) {
        if (!in_array((string)$_SESSION['local_lab_role'], ['platform_admin','super_admin'], true)) {
            apiError('Administrarea este disponibila numai Administratorului platformei si Superuserului.', 403);
        }
        return;
    }
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM user_roles ur
         JOIN roles r ON r.id=ur.role_id
         WHERE ur.user_id=:user_id AND r.code IN ("platform_admin","super_admin")'
    );
    $statement->execute(['user_id' => (int) $apiUser['id']]);
    if ((int) $statement->fetchColumn() === 0) {
        apiError('Administrarea este disponibila numai Administratorului platformei si Superuserului.', 403);
    }
}

function apiVerifyCsrf(): void
{
    global $apiToken;
    if ($apiToken !== null) {
        return;
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        apiError('Sesiunea a expirat. Reincarca pagina.', 419);
    }
}

function apiAudit(PDO $db, string $action, string $entityType, ?int $entityId, mixed $before = null, mixed $after = null): void
{
    global $apiUser, $apiToken;
    $statement = $db->prepare(
        'INSERT INTO audit_logs
         (user_id, api_token_id, action, entity_type, entity_id, before_data, after_data, ip_address, user_agent)
         VALUES (:user_id, :api_token_id, :action, :entity_type, :entity_id, :before_data, :after_data, :ip_address, :user_agent)'
    );
    $statement->execute([
        'user_id' => $apiUser['id'],
        'api_token_id' => $apiToken['id'] ?? null,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'before_data' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        'after_data' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    ]);
}

function apiBearerToken(): ?string
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorization = trim((string) ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
    }
    if ($authorization === '') {
        return null;
    }
    if (!preg_match('/^Bearer\s+([^\s]+)$/i', $authorization, $matches)) {
        apiError('Antetul Authorization nu este valid.', 401);
    }
    return $matches[1];
}

function validMonth(string $month): string
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        apiError('Luna selectata nu este valida.');
    }
    return $month;
}

function validDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        apiError('Data selectata nu este valida.');
    }
    return $date;
}
