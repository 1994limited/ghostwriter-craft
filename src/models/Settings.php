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

    /**
     * Conversations are shared with everyone who may use Ghostwriter: any of
     * them can open, carry on or remove a piece. Off, each person sees only
     * the pieces they started.
     */
    public bool $sharedConversations = true;

    /** Ideas asked for each time the content plan looks for gaps. */
    public int $planSuggestions = 8;

    /**
     * Where overridden prompts are read from, under prompts/. Guides, kinds
     * and the plan are kept in the database; early builds kept them here, and
     * they are imported from here when updating.
     */
    public string $guidesPath = '@config/ghostwriter';

    /**
     * No longer used: early builds kept working state here. Read only when
     * updating, to import it, and kept so older config still loads.
     */
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
            [['openverse', 'suggestKindsAutomatically', 'placeholderImages', 'showGetStarted', 'sharedConversations'], 'boolean'],
            [['sections', 'voiceSections'], 'each', 'rule' => ['string']],
        ];
    }

    /**
     * A word of warning when the model named does not look like one of the
     * chosen provider's: "gpt-…" with Claude, say. Not an error, since new
     * models appear all the time.
     */
    public function modelWarning(): ?string
    {
        $model = strtolower(trim((string) $this->model));

        if ($model === '') {
            return null;
        }

        $families = [
            'anthropic' => ['claude'],
            'openai' => ['gpt', 'chatgpt', 'o1', 'o3', 'o4', 'o5'],
            'gemini' => ['gemini', 'gemma'],
        ];

        foreach ($families[$this->provider] ?? [] as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return null;
            }
        }

        $names = ['anthropic' => 'Claude (Anthropic)', 'openai' => 'ChatGPT (OpenAI)', 'gemini' => 'Gemini (Google)'];

        return \Craft::t('ghostwriter', '“{model}” does not look like a {provider} model. Check it matches the provider, or leave it blank for the default.', [
            'model' => $this->model,
            'provider' => $names[$this->provider] ?? $this->provider,
        ]);
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
