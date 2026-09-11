<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/version.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
echo json_encode(appRelease(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
