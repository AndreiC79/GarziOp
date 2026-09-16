<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

if (!$config) {
    fwrite(STDERR, "Configuratia aplicatiei lipseste.\n");
    exit(1);
}

$username = trim((string)($argv[1] ?? 'dashboard'));
$temporaryPassword = (string)($argv[2] ?? '');
if (!preg_match('/^[a-z0-9._-]{3,100}$/', $username) || strlen($temporaryPassword) < 12) {
    fwrite(STDERR, "Utilizare: php database/create_dashboard_user.php <username> <parola-temporara-minim-12-caractere>\n");
    exit(1);
}

$db = Database::connect($config['database']);
$db->beginTransaction();
try {
    $db->exec(
        "INSERT INTO roles(code,name,description,is_system)
         VALUES('operating_room_dashboard','Panou bloc operator','Acces exclusiv la panoul tactil al salilor pentru ieri, astazi si maine.',1)
         ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),is_system=VALUES(is_system)"
    );
    $db->exec(
        "INSERT IGNORE INTO role_permissions(role_id,permission_id)
         SELECT r.id,p.id FROM roles r JOIN permissions p ON p.code IN ('progop.view','progop.create','progop.manage_all')
         WHERE r.code='operating_room_dashboard'"
    );
    $statement = $db->prepare('SELECT id FROM users WHERE username=:username LIMIT 1');
    $statement->execute(['username'=>$username]);
    $userId = (int)$statement->fetchColumn();
    if ($userId > 0) {
        $db->prepare(
            'UPDATE users SET person_id=NULL,password_hash=:password_hash,status="active",must_change_password=1,
             failed_login_count=0,locked_until=NULL,api_access_level="none" WHERE id=:id'
        )->execute(['password_hash'=>password_hash($temporaryPassword, PASSWORD_DEFAULT),'id'=>$userId]);
    } else {
        $db->prepare(
            'INSERT INTO users(person_id,username,password_hash,status,must_change_password,api_access_level)
             VALUES(NULL,:username,:password_hash,"active",1,"none")'
        )->execute(['username'=>$username,'password_hash'=>password_hash($temporaryPassword, PASSWORD_DEFAULT)]);
        $userId = (int)$db->lastInsertId();
    }
    $db->prepare('DELETE FROM user_roles WHERE user_id=:user_id')->execute(['user_id'=>$userId]);
    $db->prepare(
        'INSERT INTO user_roles(user_id,role_id)
         SELECT :user_id,id FROM roles WHERE code="operating_room_dashboard"'
    )->execute(['user_id'=>$userId]);
    $db->prepare('DELETE FROM user_permission_overrides WHERE user_id=:user_id')->execute(['user_id'=>$userId]);
    $db->commit();
    fwrite(STDOUT, "Contul {$username} a fost creat sau resetat. Schimbarea parolei este obligatorie la prima autentificare.\n");
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Contul nu a putut fi creat: {$error->getMessage()}\n");
    exit(1);
}
