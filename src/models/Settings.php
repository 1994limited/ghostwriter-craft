<?php

namespace nineteenninetyfour\ghostwriter\models;

use craft\base\Model;
use craft\helpers\App;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\BaseUrl;

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

    /** The providers a base URL can be set for. */
    public const BASE_URL_PROVIDERS = ['anthropic', 'openai', 'gemini'];

    /**
     * A gateway or proxy that speaks a provider's own API, per provider, in
     * place of the provider's own address. Blank for the provider's own. Each
     * may be an environment variable ("$GHOSTWRITER_ANTHROPIC_BASE_URL"). It
     * must be https://, except for localhost, 127.0.0.1 and [::1].
     *
     * @var array<string, string>
     */
    public array $baseUrls = ['anthropic' => '', 'openai' => '', 'gemini' => ''];

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
     * Look for kinds of content in each section when Get started's kinds
     * step opens: sections never looked at, and those with ten or more
     * entries published since the last look. Elsewhere kinds are suggested
     * only when someone asks. Each look is one model call.
     */
    public bool $suggestKindsAutomatically = true;

    /**
     * Whether Get started shows on the Overview and in the menu. Not kept
     * in project config: it is applied to Ghostwriter's own state when the
     * settings are saved, where the Overview's Hide button also writes.
     */
    public bool $showGetStarted = true;

    /**
     * Conversations are shared with everyone who may use Ghostwriter: any of
     * them can open or carry on a piece, and the person who started it or an
     * admin can remove it. Off, each person sees only the pieces they started.
     */
    public bool $sharedConversations = true;

    /** Ideas asked for each time the content plan looks for gaps. */
    public int $planSuggestions = 8;

    /**
     * Put the model's whole reply in the log when it can't be read, for
     * troubleshooting. Off, only what was wrong with it is logged. Prompts
     * and keys are never logged either way. May be an environment variable
     * ("$GHOSTWRITER_LOG_REPLIES").
     */
    public bool|string $logReplies = false;

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
            [['baseUrls'], 'validateBaseUrls'],
            [['logReplies'], 'validateLogReplies'],
        ];
    }

    /**
     * On, off, or an environment variable holding one of those.
     */
    public function validateLogReplies(string $attribute): void
    {
        $value = $this->$attribute;

        if (is_string($value) && !str_starts_with($value, '$') && App::normalizeBooleanValue($value) === null) {
            $this->addError($attribute, \Craft::t('ghostwriter', 'Choose yes or no, or an environment variable.'));
        }
    }

    /**
     * Whether whole unreadable replies go in the log, with any environment
     * variable read. An unset variable means no.
     */
    public function logsReplies(): bool
    {
        return App::parseBooleanEnv($this->logReplies) ?? false;
    }

    /**
     * Each base URL, once any environment variable in it is read, must be one
     * core will send a key to. The error is kept against the provider's own
     * field, as "baseUrls.anthropic".
     */
    public function validateBaseUrls(string $attribute): void
    {
        foreach (self::BASE_URL_PROVIDERS as $provider) {
            try {
                BaseUrl::check($provider, $this->baseUrl($provider));
            } catch (NotConfigured) {
                $this->addError("$attribute.$provider", \Craft::t('ghostwriter', 'Use an https:// address (http:// only for localhost, 127.0.0.1 or [::1]), with no query string or password.'));
            }
        }
    }

    /**
     * The base URL for a provider, with environment variables read, or null
     * for the provider's own address.
     */
    public function baseUrl(string $provider): ?string
    {
        $value = App::parseEnv(trim((string) ($this->baseUrls[$provider] ?? '')));

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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

        if (array_key_exists('baseUrls', $values)) {
            $given = is_array($values['baseUrls']) ? $values['baseUrls'] : [];
            $values['baseUrls'] = [];

            foreach (self::BASE_URL_PROVIDERS as $provider) {
                $values['baseUrls'][$provider] = is_string($given[$provider] ?? null) ? trim($given[$provider]) : '';
            }
        }

        // The settings form sends "1", "0" or an environment variable.
        if (array_key_exists('logReplies', $values) && is_string($values['logReplies'])) {
            $given = trim($values['logReplies']);
            $values['logReplies'] = $given === '' ? false : (str_starts_with($given, '$') ? $given : (App::normalizeBooleanValue($given) ?? $given));
        }

        foreach (['sections', 'voiceSections'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = array_values(array_filter((array) $values[$key], fn($handle) => is_string($handle) && $handle !== '' && $handle !== '*'));
            }
        }

        parent::setAttributes($values, $safeOnly);
    }
}
