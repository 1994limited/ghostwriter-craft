<?php

namespace nineteenninetyfour\ghostwriter\ai;

/**
 * A model that makes images. Claude does not, so only OpenAI and Gemini
 * implement this.
 */
interface ImageProvider
{
    public function handle(): string;

    public function defaultImageModel(): string;

    /**
     * @throws ProviderException
     */
    public function image(ImageRequest $request): Image;
}
