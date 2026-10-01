<?php

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');

define('CRAFT_ROOT_PATH', dirname(__DIR__));
define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');

require CRAFT_VENDOR_PATH . '/autoload.php';

// The database settings live in tests/.env, so the suite needs no config of its own.
if (is_file(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();
}

$devMode = true;

TestSetup::configureCraft();
