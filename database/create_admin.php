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

$username = trim($argv[1] ?? '');
$password = $argv[2] ?? '';

if ($username === '' || strlen($password) < 12) {
    fwrite(STDERR, "Utilizare: php database/create_admin.php username parola-de-minimum-12-caractere\n");
    exit(1);
}

$db = Database::connect($config['database']);
$db->beginTransaction();

try {
    $statement = $db->prepare(
        'INSERT INTO users (username, password_hash, must_change_password)
         VALUES (:username, :password_hash, 1)'
    );
    $statement->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $db->lastInsertId();

    $roleId = (int) $db->query("SELECT id FROM roles WHERE code = 'super_admin'")->fetchColumn();
    $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)')
        ->execute(['user_id' => $userId, 'role_id' => $roleId]);

    $db->prepare(
        'INSERT INTO role_permissions (role_id, permission_id)
         SELECT :role_id, id FROM permissions
         ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)'
    )->execute(['role_id' => $roleId]);

    $db->commit();
    fwrite(STDOUT, "Contul de super administrator a fost creat.\n");
} catch (Throwable $error) {
    $db->rollBack();
    fwrite(STDERR, "Contul nu a putut fi creat: {$error->getMessage()}\n");
    exit(1);
}
