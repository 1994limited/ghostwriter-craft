<?php
/**
 * Ghostwriter config.
 *
 * Copy this file to your project's config folder as `ghostwriter.php` to set
 * Ghostwriter's settings in code. Anything set here overrides the plugin's
 * settings page, and can differ per environment.
 *
 * API keys are never set here. Ghostwriter reads them from the environment:
 * ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY, OPENROUTER_API_KEY (or
 * Connect with OpenRouter in the settings; .env wins), and for photo libraries
 * UNSPLASH_ACCESS_KEY, PEXELS_API_KEY and PIXABAY_API_KEY, and for paid ones
 * GETTY_API_KEY/GETTY_API_SECRET and SHUTTERSTOCK_API_KEY/SHUTTERSTOCK_API_SECRET.
 */

return [
    '*' => [
        // The provider that writes: 'anthropic', 'openai', 'gemini' or 'openrouter'.
        // 'provider' => 'anthropic',

        // The model to write with. Null uses the provider's default.
        // 'model' => null,

        // With OpenRouter, a model per tier of work, by OpenRouter model id.
        // Blank uses core's default (Claude Opus for writing, Sonnet for quick jobs).
        // 'openrouterModels' => ['writing' => '', 'quick' => ''],

        // A gateway or proxy that speaks the provider's own API, per provider.
        // Blank calls the provider at its own address. Must be https://, except
        // for localhost. An environment variable works too.
        // 'baseUrls' => [
        //     'anthropic' => '$GHOSTWRITER_ANTHROPIC_BASE_URL',
        //     'openai' => '',
        //     'gemini' => '',
        //     'openrouter' => '',
        // ],

        // Seconds to wait for one model response. Queue jobs are given
        // three times this plus a minute, since busy providers are retried.
        // 'timeout' => 300,

        // Section handles Ghostwriter writes for. Empty means every section.
        // 'sections' => [],

        // Section handles read to learn the voice. Empty means every section.
        // 'voiceSections' => [],

        // 'openai', 'gemini' or 'openrouter' to make images with. Null uses whichever has a key.
        // 'imageProvider' => null,
        // 'imageModel' => null,

        // Search Openverse for public-domain and CC0 photographs. Needs no key.
        // 'openverse' => true,

        // Put a striped placeholder in image fields a draft leaves empty.
        // 'placeholderImages' => true,

        // A new entry Ghostwriter writes starts unpublished (Enabled off), so
        // it saves at once and is never published by accident. Existing
        // entries are never changed.
        // 'draftsUnpublished' => true,

        // Finish this page. Whether an entry with something still to finish
        // (a fact to add, a link to choose, an image placeholder, a stock
        // photo preview not licensed) is blocked from going live, or saved
        // with a warning ('block' or 'warn'; 'stockOnPublish' is still read
        // when this isn't set); and whether the guide opens by itself after
        // a draft is put into an entry.
        // 'onUnfinishedPublish' => 'block',
        // 'finishOpenAfterDraft' => true,

        // Stock photos. Paid libraries switched on or off, by ID; where
        // "Search in" starts; and editorial images.
        // 'stockLibraries' => [],
        // 'stockDefaultSource' => 'free',
        // 'stockIncludeEditorial' => false,
        // Offer the demo library outside dev mode (never in production).
        // 'stockDemo' => '$GHOSTWRITER_STOCK_DEMO',
        // Shutterstock's sandbox (charges nothing): true, false, or null for dev mode only.
        // 'shutterstockSandbox' => null,
        // Days before a preview no entry uses is cleaned up.
        // 'stockUnusedDays' => 30,

        // Suggest kinds of content for each section when Get started's kinds
        // step opens. Elsewhere kinds are only suggested when someone asks.
        // 'suggestKindsAutomatically' => true,

        // Share every conversation with everyone who may use Ghostwriter.
        // False: each person sees only the pieces they started.
        // 'sharedConversations' => true,

        // How much of the site is read for the voice guide.
        // 'voiceMaxEntries' => 24,
        // 'voiceMaxCharsPerEntry' => 6000,
        // 'voiceMaxChars' => 90000,

        // Images looked at per section when the image style guide is written.
        // 'imageGuideSamples' => 10,

        // Ideas asked for each time the content plan looks for gaps.
        // 'planSuggestions' => 8,

        // Put the model's whole reply in Craft's log when it can't be read,
        // for troubleshooting. Off, only what was wrong is logged. Replies
        // hold your content. An environment variable works too.
        // 'logReplies' => '$GHOSTWRITER_LOG_REPLIES',

        // Where prompt overrides are read from (under prompts/). Guides,
        // kinds, the plan and conversations are kept in the database.
        // 'guidesPath' => '@config/ghostwriter',
    ],
];
