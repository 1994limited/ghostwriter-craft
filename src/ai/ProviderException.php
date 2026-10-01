<?php

namespace nineteenninetyfour\ghostwriter\ai;

use RuntimeException;

/**
 * A model call that did not work. The message is written to be shown to the
 * person who asked, so it says what happened in plain words.
 */
class ProviderException extends RuntimeException
{
}
