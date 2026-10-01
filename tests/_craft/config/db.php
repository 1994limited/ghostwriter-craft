<?php

use craft\helpers\App;

return [
    'driver' => App::env('CRAFT_DB_DRIVER') ?: 'mysql',
    'server' => App::env('CRAFT_DB_SERVER') ?: '127.0.0.1',
    'port' => App::env('CRAFT_DB_PORT') ?: 3306,
    'database' => App::env('CRAFT_DB_DATABASE') ?: 'ghostwriter_craft_test',
    'user' => App::env('CRAFT_DB_USER') ?: 'root',
    'password' => App::env('CRAFT_DB_PASSWORD') ?: '',
    'tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX') ?: '',
];
