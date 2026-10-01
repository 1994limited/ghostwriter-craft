<?php

namespace nineteenninetyfour\ghostwriter\planning;

use nineteenninetyfour\ghostwriter\voice\VoiceState;

/**
 * Working state for the content plan screen: whether ideas are being looked
 * for, and suggestions waiting to be looked over.
 */
class PlanState extends VoiceState
{
    protected function name(): string
    {
        return 'plan';
    }
}
