<?php

return [
    'devMode' => true,
    'securityKey' => getenv('CRAFT_SECURITY_KEY') ?: 'ghostwriter-tests-only',
    'cpTrigger' => 'admin',
    // Jobs are run by hand in the tests, never by a web request.
    'runQueueAutomatically' => false,
];
