<?php

namespace nineteenninetyfour\ghostwriter\ai;

/**
 * A model that writes, and can look at images while it does.
 */
interface TextProvider
{
    /** anthropic, openai or gemini. */
    public function handle(): string;

    /** The model used when none is chosen in the settings. */
    public function defaultModel(): string;

    /**
     * @throws ProviderException when the call fails or the model declines.
     */
    public function text(TextRequest $request): TextResponse;
}
