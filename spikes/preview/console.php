<?php
// Spike only: boots gw-test-craft's console app from outside the site.
$site = getenv('GW_SITE') ?: getenv('HOME').'/Dev/gw-test-craft';
require $site.'/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH.'/craftcms/cms/bootstrap/console.php';
return $app;
