<?php

return [
    'components' => [
        // Craft runs garbage collection at random (10 in a million app
        // starts), and Ghostwriter queues Content to revisit's daily pass
        // with it. Tests start an app each, so now and then one ran the
        // pass behind a test's back. Garbage collection only when a test
        // asks for it (Gc::run(true)).
        'gc' => [
            'probability' => 0,
        ],
    ],
];
