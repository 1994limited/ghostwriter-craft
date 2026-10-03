<?php

/*
 * Core's "Finish this page" strings that Ghostwriter words differently, by
 * core's key and with core's `:name` parameters. bin/sync-gap-strings
 * uses these in place of core's, until core's own wording catches up.
 */

return [
    'ask' => 'I left a gap in :label: :hint. Only you know this. What should it say?',
    'ask-value' => ':label is empty: :hint. This one needs you.',
    'guide.reason.draft' => 'Only you know this.',
];
