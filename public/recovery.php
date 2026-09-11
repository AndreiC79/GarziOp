<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const RECOVERY_MAX_ATTEMPTS = 5;
const RECOVERY_WINDOW_SECONDS = 900;

$requestFile = dirname(__DIR__) . '/storage/recovery-request.json';
$rateFile = dirname(__DIR__) . '/storage/security/recovery-rate-limit.json';
$request = is_file($requestFile) ? json_decode((string) file_get_contents($requestFile), true) : null;
$available = is_array($request)
    && !empty($request['token_hash'])
    && !empty($request['username'])
    && !empty($request['expires_at'])
    && strtotime((string) $request['expires_at']) > time();
$message = '';
$success = false;
$clientKey = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $available) {
    if (recoveryIsBlocked($rateFile, $clientKey)) {
        $message = 'Prea multe incercari. Reincearca peste 15 minute.';
        recoveryAuditFailure($config, $request, 'rate_limited');
    } else {
        $token = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['confirmation'] ?? '');
        $csrf = (string) ($_POST['csrf_token'] ?? '');

        if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
            $message = 'Sesiunea a expirat. Reincarca pagina.';
            recoveryRegisterFailure($rateFile, $clientKey);
            recoveryAuditFailure($config, $request, 'csrf_invalid');
        } elseif (!hash_equals((string) $request['token_hash'], hash('sha256', $token))) {
            $message = 'Codul de recuperare nu este valid.';
            recoveryRegisterFailure($rateFile, $clientKey);
            recoveryAuditFailure($config, $request, 'token_invalid');
        } elseif (strlen($password) < 12) {
            $message = 'Parola trebuie sa aiba minimum 12 caractere.';
        } elseif ($password !== $confirmation) {
            $message = 'Confirmarea parolei nu corespunde.';
        } else {
            try {
                $db = Database::connect($config['database']);
                $db->beginTransaction();
                $statement = $db->prepare('SELECT id FROM users WHERE username=:username FOR UPDATE');
                $statement->execute(['username'=>$request['username']]);
                $userId = (int) $statement->fetchColumn();
                if ($userId <= 0) throw new RuntimeException('Contul de recuperat nu exista.');
                $db->prepare('UPDATE users SET password_hash=:password_hash,status="active",api_access_level="full",must_change_password=0,failed_login_count=0,locked_until=NULL,password_changed_at=NOW() WHERE id=:id')
                    ->execute(['password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'id'=>$userId]);
                $roleId = (int) $db->query('SELECT id FROM roles WHERE code="super_admin"')->fetchColumn();
                $db->prepare('INSERT IGNORE INTO user_roles(user_id,role_id) VALUES(:user_id,:role_id)')
                    ->execute(['user_id'=>$userId, 'role_id'=>$roleId]);
                $db->prepare('DELETE FROM user_sessions WHERE user_id=:user_id')->execute(['user_id'=>$userId]);
                $db->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,ip_address,user_agent) VALUES(:user_id,"security.emergency_recovery","user",:entity_id,:ip,:agent)')
                    ->execute(['user_id'=>$userId, 'entity_id'=>$userId, 'ip'=>$_SERVER['REMOTE_ADDR']??null, 'agent'=>substr($_SERVER['HTTP_USER_AGENT']??'', 0, 500)]);
                $db->commit();
                @unlink($requestFile);
                recoveryClearFailures($rateFile, $clientKey);
                $success = true;
                $message = 'Accesul Superuser a fost restabilit. Fisierul si codul au fost invalidate.';
            } catch (Throwable $error) {
                if (isset($db) && $db->inTransaction()) $db->rollBack();
                recoveryPrivateLog($error);
                $message = 'Recuperarea nu a putut fi finalizata.';
            }
        }
    }
}

function recoveryAttempts(string $path): array
{
    if (!is_file($path)) return [];
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function recoveryIsBlocked(string $path, string $clientKey): bool
{
    $cutoff = time() - RECOVERY_WINDOW_SECONDS;
    $attempts = array_values(array_filter(recoveryAttempts($path)[$clientKey] ?? [], static fn ($timestamp): bool => (int)$timestamp >= $cutoff));
    return count($attempts) >= RECOVERY_MAX_ATTEMPTS;
}

function recoveryRegisterFailure(string $path, string $clientKey): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) @mkdir($directory, 0775, true);
    $data = recoveryAttempts($path);
    $cutoff = time() - RECOVERY_WINDOW_SECONDS;
    $data[$clientKey] = array_values(array_filter($data[$clientKey] ?? [], static fn ($timestamp): bool => (int)$timestamp >= $cutoff));
    $data[$clientKey][] = time();
    @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function recoveryClearFailures(string $path, string $clientKey): void
{
    $data = recoveryAttempts($path);
    unset($data[$clientKey]);
    if ($data) @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    else @unlink($path);
}

function recoveryAuditFailure(array $config, array $request, string $reason): void
{
    try {
        $db = Database::connect($config['database']);
        $statement = $db->prepare('SELECT id FROM users WHERE username=:username');
        $statement->execute(['username'=>$request['username']]);
        $userId = (int)$statement->fetchColumn() ?: null;
        $db->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,after_data,ip_address,user_agent) VALUES(:user_id,"security.emergency_recovery_failed","user",:entity_id,:after_data,:ip,:agent)')
            ->execute([
                'user_id'=>$userId,
                'entity_id'=>$userId,
                'after_data'=>json_encode(['reason'=>$reason], JSON_UNESCAPED_SLASHES),
                'ip'=>$_SERVER['REMOTE_ADDR']??null,
                'agent'=>substr($_SERVER['HTTP_USER_AGENT']??'', 0, 500),
            ]);
    } catch (Throwable $error) {
        recoveryPrivateLog($error);
    }
}

function recoveryPrivateLog(Throwable $error): void
{
    $directory = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($directory)) @mkdir($directory, 0775, true);
    @file_put_contents($directory . '/app-errors.log', '[' . date('c') . '] recovery | ' . $error->getMessage() . "\n", FILE_APPEND | LOCK_EX);
}
?>
<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Recuperare administrativa</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
<main class="login-panel">
    <div class="login-panel-inner">
        <span class="eyebrow">Acces de urgenta</span>
        <h1>Recuperare Superuser</h1>
        <?php if (!$available && !$success): ?>
            <p>Nu exista o cerere de recuperare activa.</p>
        <?php else: ?>
            <p><?= e($message ?: 'Introdu codul unic generat local. Cererea expira automat dupa 60 de minute.') ?></p>
            <?php if (!$success): ?>
                <form method="post" class="login-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label>Cod unic<input type="password" name="token" required autocomplete="off"></label>
                    <label>Parola noua<input type="password" name="password" minlength="12" required></label>
                    <label>Confirma parola<input type="password" name="confirmation" minlength="12" required></label>
                    <button type="submit">Restabileste accesul</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <a href="index.php">Inapoi la autentificare</a>
    </div>
</main>
</body>
</html>
