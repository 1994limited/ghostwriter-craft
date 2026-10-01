<?php
/**
 * Ghostwriter config.
 *
 * Copy this file to your project's config folder as `ghostwriter.php` to set
 * Ghostwriter's settings in code. Anything set here overrides the plugin's
 * settings page, and can differ per environment.
 *
 * API keys are never set here. Ghostwriter reads them from the environment:
 * ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY, and for photo libraries
 * UNSPLASH_ACCESS_KEY, PEXELS_API_KEY and PIXABAY_API_KEY.
 */

return [
    '*' => [
        // The provider that writes: 'anthropic', 'openai' or 'gemini'.
        // 'provider' => 'anthropic',

        // The model to write with. Null uses the provider's default.
        // 'model' => null,

        // Seconds to wait for one model response.
        // 'timeout' => 300,

        // Section handles Ghostwriter writes for. Empty means every section.
        // 'sections' => [],

        // Section handles read to learn the voice. Empty means every section.
        // 'voiceSections' => [],

        // 'openai' or 'gemini' to make images with. Null uses whichever has a key.
        // 'imageProvider' => null,
        // 'imageModel' => null,

        // Search Openverse for public-domain and CC0 photographs. Needs no key.
        // 'openverse' => true,

        // Put a striped placeholder in image fields a draft leaves empty.
        // 'placeholderImages' => true,

        // Look for kinds of content in each section without being asked.
        // 'suggestKindsAutomatically' => true,

        // Where prompt overrides are read from (under prompts/). Guides,
        // kinds, the plan and conversations are kept in the database.
        // 'guidesPath' => '@config/ghostwriter',
    ],
];
