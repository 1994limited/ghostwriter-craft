<?php

namespace nineteenninetyfour\ghostwriter\models;

use craft\base\Model;

/**
 * Ghostwriter's settings. They are edited on the plugin's settings page and,
 * as with any Craft plugin, a config/ghostwriter.php file in the project
 * overrides whatever is saved there.
 *
 * API keys are never settings. They are read from the environment
 * (ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY and the photo library
 * keys) each time they are needed, and never stored.
 */
class Settings extends Model
{
    /** The provider that writes: anthropic, openai or gemini. */
    public string $provider = 'anthropic';

    /** Leave null to use the provider's default model. */
    public ?string $model = null;

    /** Seconds to wait for one response. Long drafts take a while. */
    public int $timeout = 300;

    /**
     * Section handles Ghostwriter writes for. Empty means every section.
     *
     * @var string[]
     */
    public array $sections = [];

    /**
     * Section handles read when the voice guide is written. Empty means every section.
     *
     * @var string[]
     */
    public array $voiceSections = [];

    /** openai or gemini; null uses whichever has an API key. Claude does not make images. */
    public ?string $imageProvider = null;

    public ?string $imageModel = null;

    /** Openverse needs no key, and is searched for public-domain and CC0 work only. */
    public bool $openverse = true;

    /** How much of the site is read for the voice guide, so one scan is one affordable request. */
    public int $voiceMaxEntries = 24;

    public int $voiceMaxCharsPerEntry = 6000;

    public int $voiceMaxChars = 90000;

    /**
     * Put a striped placeholder in image fields a draft leaves empty, where
     * the section's pages usually have an image, so the layout shows where
     * pictures go.
     */
    public bool $placeholderImages = true;

    /** Images looked at per section when the image style guide is written. */
    public int $imageGuideSamples = 10;

    /**
     * Look for kinds of content in each section without being asked: the
     * first time a section is seen, and again once ten or more entries have
     * been published there since. Each look is one model call.
     */
    public bool $suggestKindsAutomatically = true;

    /**
     * Whether Get started shows on the dashboard and in the menu. Not kept
     * in project config: it is applied to Ghostwriter's own state when the
     * settings are saved, where the dashboard's Hide button also writes.
     */
    public bool $showGetStarted = true;

    /** Ideas asked for each time the content plan looks for gaps. */
    public int $planSuggestions = 8;

    /**
     * Where the guides, content types, plan and any overridden prompts are
     * kept. They are the project's to version, so they sit in its config
     * folder by default.
     */
    public string $guidesPath = '@config/ghostwriter';

    /** Where working state (sessions, job status) is kept. Not content; not versioned. */
    public string $storagePath = '@storage/ghostwriter';

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['provider'], 'in', 'range' => ['anthropic', 'openai', 'gemini']],
            [['imageProvider'], 'in', 'range' => ['openai', 'gemini'], 'skipOnEmpty' => true],
            [['timeout'], 'integer', 'min' => 30, 'max' => 1800],
            [['voiceMaxEntries', 'voiceMaxCharsPerEntry', 'voiceMaxChars', 'imageGuideSamples', 'planSuggestions'], 'integer', 'min' => 1],
            [['model', 'imageModel', 'guidesPath', 'storagePath'], 'string'],
            [['openverse', 'suggestKindsAutomatically', 'placeholderImages', 'showGetStarted'], 'boolean'],
            [['sections', 'voiceSections'], 'each', 'rule' => ['string']],
        ];
    }

    /**
     * Everything but showGetStarted, which is not project config.
     *
     * @return array<string|int, string|callable>
     */
    public function fields(): array
    {
        $fields = parent::fields();
        unset($fields['showGetStarted']);

        return $fields;
    }

    /**
     * Empty strings from the settings form mean "not set".
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        foreach (['model', 'imageModel', 'imageProvider'] as $key) {
            if (array_key_exists($key, $values) && $values[$key] === '') {
                $values[$key] = null;
            }
        }

        foreach (['sections', 'voiceSections'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = array_values(array_filter((array) $values[$key], fn($handle) => is_string($handle) && $handle !== '' && $handle !== '*'));
            }
        }

        parent::setAttributes($values, $safeOnly);
    }
}
