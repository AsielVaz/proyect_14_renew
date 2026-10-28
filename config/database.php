<?php

declare(strict_types=1);

return [
    'driver' => env_value('DB_DRIVER'),
    'host' => env_value('DB_HOST'),
    'port' => (int) env_value('DB_PORT'),
    'database' => env_value('DB_DATABASE'),
    'username' => env_value('DB_USERNAME'),
    'password' => env_value('DB_PASSWORD'),
    'charset' => env_value('DB_CHARSET', 'utf8mb4'),
];
