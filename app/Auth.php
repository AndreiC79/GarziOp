<?php

declare(strict_types=1);

final class Auth
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;
    private const SESSION_LIFETIME_SECONDS = 28800;
    private const SESSION_TOUCH_INTERVAL_SECONDS = 60;

    public static function user(PDO $db): ?array
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return null;
        }

        $statement = $db->prepare(
            'SELECT u.id, u.person_id, u.username, u.must_change_password,
                    p.display_name, p.first_name, p.specialty
             FROM users u
             LEFT JOIN people p ON p.id = u.person_id
             WHERE u.id = :id AND u.status = "active"'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        if (!$user) {
            self::logout();
            return null;
        }

        self::trackSession($db, (int) $user['id']);
        return $user;
    }

    public static function attempt(PDO $db, string $username, string $password): bool
    {
        $statement = $db->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => trim($username)]);
        $user = $statement->fetch();

        if (!$user || $user['status'] === 'inactive') {
            return false;
        }

        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            self::recordFailedAttempt($db, $user);
            return false;
        }

        $db->prepare(
            'UPDATE users
             SET failed_login_count = 0, locked_until = NULL, status = "active", last_login_at = NOW()
             WHERE id = :id'
        )->execute(['id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::trackSession($db, (int) $user['id'], true);
        self::audit($db, (int) $user['id'], 'auth.login', 'user', (int) $user['id']);

        return true;
    }

    public static function changePassword(PDO $db, int $userId, string $currentPassword, string $newPassword): bool
    {
        $statement = $db->prepare('SELECT password_hash FROM users WHERE id = :id AND status = "active"');
        $statement->execute(['id' => $userId]);
        $currentHash = $statement->fetchColumn();

        if (!$currentHash || !password_verify($currentPassword, (string) $currentHash)) {
            return false;
        }

        $db->prepare(
            'UPDATE users
             SET password_hash = :password_hash, must_change_password = 0, password_changed_at = NOW()
             WHERE id = :id'
        )->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);

        self::audit($db, $userId, 'auth.password_changed', 'user', $userId);
        return true;
    }

    public static function logout(?PDO $db = null): void
    {
        if ($db && isset($_SESSION['user_id'])) {
            self::audit($db, (int) $_SESSION['user_id'], 'auth.logout', 'user', (int) $_SESSION['user_id']);
            $db->prepare('DELETE FROM user_sessions WHERE id = :id')->execute(['id' => self::sessionKey()]);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    private static function trackSession(PDO $db, int $userId, bool $force = false): void
    {
        $lastTouch = (int) ($_SESSION['session_last_touch'] ?? 0);
        if (!$force && $lastTouch >= time() - self::SESSION_TOUCH_INTERVAL_SECONDS) {
            return;
        }

        $statement = $db->prepare(
            'INSERT INTO user_sessions
                (id, user_id, ip_address, user_agent, last_activity_at, expires_at)
             VALUES
                (:id, :user_id, :ip_address, :user_agent, NOW(), :expires_at)
             ON DUPLICATE KEY UPDATE
                user_id=VALUES(user_id), ip_address=VALUES(ip_address), user_agent=VALUES(user_agent),
                last_activity_at=NOW(), expires_at=VALUES(expires_at)'
        );
        $statement->execute([
            'id' => self::sessionKey(),
            'user_id' => $userId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            'expires_at' => date('Y-m-d H:i:s', time() + self::SESSION_LIFETIME_SECONDS),
        ]);
        $_SESSION['session_last_touch'] = time();
    }

    private static function sessionKey(): string
    {
        return hash('sha256', session_id());
    }

    public static function permissions(PDO $db, int $userId): array
    {
        $statement = $db->prepare(
            'SELECT DISTINCT p.code
             FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :user_id
               AND NOT EXISTS (
                   SELECT 1 FROM user_permission_overrides upo
                   WHERE upo.user_id = ur.user_id AND upo.permission_id = p.id AND upo.allowed = 0
               )
             UNION
             SELECT p.code
             FROM permissions p
             INNER JOIN user_permission_overrides upo ON upo.permission_id = p.id
             WHERE upo.user_id = :override_user_id AND upo.allowed = 1'
        );
        $statement->execute(['user_id' => $userId, 'override_user_id' => $userId]);

        return array_column($statement->fetchAll(), 'code');
    }

    private static function recordFailedAttempt(PDO $db, array $user): void
    {
        $attempts = (int) $user['failed_login_count'] + 1;
        $shouldLock = $attempts >= self::MAX_FAILED_ATTEMPTS;
        $lockedUntil = $shouldLock ? date('Y-m-d H:i:s', strtotime('+' . self::LOCK_MINUTES . ' minutes')) : null;

        $statement = $db->prepare(
            'UPDATE users SET failed_login_count = :attempts, locked_until = :locked_until, status = :status WHERE id = :id'
        );
        $statement->execute([
            'attempts' => $attempts,
            'locked_until' => $lockedUntil,
            'status' => $shouldLock ? 'locked' : $user['status'],
            'id' => $user['id'],
        ]);

        self::audit($db, (int) $user['id'], 'auth.login_failed', 'user', (int) $user['id']);
    }

    private static function audit(PDO $db, ?int $userId, string $action, ?string $entityType, ?int $entityId): void
    {
        $statement = $db->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent)
             VALUES (:user_id, :action, :entity_type, :entity_id, :ip_address, :user_agent)'
        );
        $statement->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    }
}
