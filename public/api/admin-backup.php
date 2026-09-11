<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
apiRequirePlatformAdministrator();
apiRequirePermission('progop.manage_all', 'admin.audit');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError('Metoda nu este acceptata.', 405);

$file = basename((string) ($_GET['file'] ?? ''));
if (!preg_match('/^backup_sql_\d{8}_\d{6}\.json$/', $file)) apiError('Fisierul de backup nu este valid.');
$path = dirname(__DIR__, 2) . '/storage/backups/' . $file;
if (!is_file($path)) apiError('Fisierul de backup nu exista.', 404);

apiAudit($db, 'progop.backup_downloaded', 'backup', null, null, ['file' => $file]);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
