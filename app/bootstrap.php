<?php

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));

$configFile = PROJECT_ROOT . '/config/config.php';
$config = file_exists($configFile) ? require $configFile : null;

if ($config) {
    date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Bucharest');
    session_name($config['app']['session_name'] ?? 'intern_mg_session');
    session_set_cookie_params([
        'lifetime' => $config['app']['session_lifetime'] ?? 28800,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once PROJECT_ROOT . '/app/Database.php';
require_once PROJECT_ROOT . '/app/Auth.php';

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Sesiunea formularului a expirat. Reincarca pagina.');
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
