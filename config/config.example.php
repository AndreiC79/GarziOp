<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Intern Maternitatea Giulesti',
        'base_url' => '/intern/public',
        'timezone' => 'Europe/Bucharest',
        'session_name' => 'intern_mg_session',
        'session_lifetime' => 28800,
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'intern_maternitate',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
];
