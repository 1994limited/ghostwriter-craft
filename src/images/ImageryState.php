<?php

namespace nineteenninetyfour\ghostwriter\images;

use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\voice\VoiceState;

/**
 * Working state for the image style screen, kept apart from the voice guide's.
 */
class ImageryState extends VoiceState
{
    protected function path(): string
    {
        return Plugin::getInstance()->paths->storage('imagery.json');
    }
}
