<?php

namespace nineteenninetyfour\ghostwriter\models;

use craft\base\Model;
use craft\helpers\App;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\BaseUrl;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\OnPublish;

/**
 * Ghostwriter's settings. They are edited on the plugin's settings page and,
 * as with any Craft plugin, a config/ghostwriter.php file in the project
 * overrides whatever is saved there.
 *
 * API keys are never settings. They are read from the environment
 * (ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY, OPENROUTER_API_KEY and
 * the photo library keys) each time they are needed, and never stored. The
 * one exception is a key from "Connect with OpenRouter", which is kept
 * encrypted (DbProviderKeys) and loses to OPENROUTER_API_KEY when both are
 * there.
 */
class Settings extends Model
{
    /** The provider that writes: anthropic, openai, gemini or openrouter. */
    public string $provider = 'anthropic';

    /** Leave null to use the provider's default model. */
    public ?string $model = null;

    /** The providers a base URL can be set for. */
    public const BASE_URL_PROVIDERS = ['anthropic', 'openai', 'gemini', 'openrouter'];

    /** The providers that write. */
    public const PROVIDERS = ['anthropic', 'openai', 'gemini', 'openrouter'];

    /** The providers that make images. */
    public const IMAGE_PROVIDERS = ['openai', 'gemini', 'openrouter'];

    /**
     * A gateway or proxy that speaks a provider's own API, per provider, in
     * place of the provider's own address. Blank for the provider's own. Each
     * may be an environment variable ("$GHOSTWRITER_ANTHROPIC_BASE_URL"). It
     * must be https://, except for localhost, 127.0.0.1 and [::1].
     *
     * @var array<string, string>
     */
    public array $baseUrls = ['anthropic' => '', 'openai' => '', 'gemini' => '', 'openrouter' => ''];

    /**
     * With OpenRouter, the model for each tier of work, by OpenRouter model
     * id ("anthropic/claude-opus-5.5"): `writing` for everything that
     * writes, `quick` for the photo helpers and gap fixes. Blank for core's
     * default. The Model setting, when set, still applies to every job.
     *
     * @var array<string, string>
     */
    public array $openrouterModels = ['writing' => '', 'quick' => ''];

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

    /** openai, gemini or openrouter; null uses whichever has an API key. Claude does not make images. */
    public ?string $imageProvider = null;

    public ?string $imageModel = null;

    /** Openverse needs no key, and is searched for public-domain and CC0 work only. */
    public bool $openverse = true;

    /** "On publish" choices for a page that still holds a stock photo preview. */
    public const STOCK_BLOCK = 'block';

    public const STOCK_WARN = 'warn';

    /**
     * Paid photo libraries switched on or off, by ID ("demo", "getty",
     * "shutterstock"). A library not listed is on. Its keys still come
     * from .env.
     *
     * @var array<string, bool>
     */
    public array $stockLibraries = [];

    /**
     * Where the image dialog's "Search in" starts for someone who hasn't
     * chosen yet: "free", "everything" or a paid library's ID. Each person's
     * last choice wins after that.
     */
    public string $stockDefaultSource = 'free';

    /** Include editorial-only images in searches by default. */
    public bool $stockIncludeEditorial = false;

    /**
     * The stock photos setting from before "Finish this page": read only
     * when `onUnfinishedPublish` isn't set, so a config file that names it
     * keeps working.
     */
    public string $stockOnPublish = self::STOCK_BLOCK;

    /**
     * When an entry is saved live with something still to finish (a fact
     * to add, a link to choose, an image placeholder, template text, or a
     * stock photo preview not licensed yet): "block" refuses the save with
     * a message on each field; "warn" saves it and says what is left.
     * Drafts always save. Empty follows `stockOnPublish`, else blocks.
     */
    public ?string $onUnfinishedPublish = null;

    /**
     * Open the "Finish this page" guide by itself after Ghostwriter puts a
     * draft into an entry, whether or not it was last minimised.
     */
    public bool $finishOpenAfterDraft = true;

    /**
     * Offer the demo library ("Demo stock (no charge)") outside dev mode,
     * for a test site or screenshots. Never in production, whatever this
     * says. May be an environment variable ("$GHOSTWRITER_STOCK_DEMO").
     */
    public bool|string $stockDemo = '$GHOSTWRITER_STOCK_DEMO';

    /**
     * Point Shutterstock at its sandbox (api-sandbox.shutterstock.com),
     * where licensing charges nothing and gives a watermarked file. Null
     * follows dev mode: the sandbox in dev mode, the real API otherwise.
     * May be an environment variable ("$GHOSTWRITER_SHUTTERSTOCK_SANDBOX").
     */
    public bool|string|null $shutterstockSandbox = null;

    /** Days a stock photo stand-in no entry uses is kept before cleanup removes it. */
    public int $stockUnusedDays = 30;

    /**
     * Suggest edits flags counts and prices about the organisation ("team
     * of 6", "from £450") in pages a year old or more as Facts to check,
     * and the review may flag claims of its own. Off, neither is asked
     * about. Closing dates are always checked.
     */
    public bool $claimChecks = true;

    /**
     * Content to revisit checks links to other sites once a week (at most
     * once a week per address, one request a second per site), so a page
     * that has gone shows as a broken link. Off, no other site is ever
     * asked anything. Links to the site's own entries are always checked.
     */
    public bool $checkExternalLinks = false;

    /**
     * Sections ordered by date (channels: news, a journal) weigh a page's
     * age and its past years at a quarter, as old news is expected to be
     * old. The handles of those where age should count in full.
     *
     * @var array<int, string>
     */
    public array $ageInFullSections = [];

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

    /**
     * A new entry Ghostwriter puts a draft into starts unpublished: its
     * Enabled switch is turned off in the form, so it can be saved at once
     * (a stock photo preview never blocks a disabled entry) and an AI draft
     * is never published by accident. The editor switches it on when ready.
     * Existing entries are never changed.
     */
    public bool $draftsUnpublished = true;

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

    /**
     * The Preview tab in the writing panel: the draft rendered through the
     * section's own page template, with nothing saved.
     */
    public bool $preview = true;

    /**
     * Hosts whose scripts may run in the Preview tab, besides the site's
     * own (a CDN the templates load from). Third-party scripts such as tag
     * managers and analytics are blocked there.
     *
     * @var array<int, string>
     */
    public array $previewScriptHosts = [];

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
            [['provider'], 'in', 'range' => self::PROVIDERS],
            [['imageProvider'], 'in', 'range' => self::IMAGE_PROVIDERS, 'skipOnEmpty' => true],
            [['openrouterModels'], 'validateOpenrouterModels'],
            [['timeout'], 'integer', 'min' => 30, 'max' => 1800],
            [['voiceMaxEntries', 'voiceMaxCharsPerEntry', 'voiceMaxChars', 'imageGuideSamples', 'planSuggestions', 'stockUnusedDays'], 'integer', 'min' => 1],
            [['stockOnPublish'], 'in', 'range' => [self::STOCK_BLOCK, self::STOCK_WARN]],
            [['onUnfinishedPublish'], 'in', 'range' => [self::STOCK_BLOCK, self::STOCK_WARN], 'skipOnEmpty' => true],
            [['finishOpenAfterDraft'], 'boolean'],
            [['stockDefaultSource'], 'match', 'pattern' => '/^[a-z0-9_-]{1,64}$/'],
            [['stockIncludeEditorial'], 'boolean'],
            [['shutterstockSandbox'], 'safe'],
            [['stockLibraries'], 'each', 'rule' => ['boolean']],
            [['model', 'imageModel', 'guidesPath', 'storagePath'], 'string'],
            [['openverse', 'suggestKindsAutomatically', 'placeholderImages', 'showGetStarted', 'sharedConversations', 'draftsUnpublished', 'preview'], 'boolean'],
            [['previewScriptHosts'], 'each', 'rule' => ['match', 'pattern' => '/^[a-z0-9*.:\/-]+$/i']],
            [['sections', 'voiceSections', 'ageInFullSections'], 'each', 'rule' => ['string']],
            [['claimChecks', 'checkExternalLinks'], 'boolean'],
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
     * Whether `stockDemo` asks for the demo library, with any environment
     * variable read. An unset variable means no.
     */
    public function demoRequested(): bool
    {
        return App::parseBooleanEnv($this->stockDemo) ?? false;
    }

    /**
     * Whether Shutterstock calls go to its sandbox: as set, else in dev
     * mode. Never by default in production.
     */
    public function usesShutterstockSandbox(): bool
    {
        $set = $this->shutterstockSandbox === null || $this->shutterstockSandbox === '' ? null : App::parseBooleanEnv($this->shutterstockSandbox);

        return $set ?? (\Craft::$app->getConfig()->getGeneral()->devMode && !\nineteenninetyfour\ghostwriter\stock\StockLibraries::isProduction());
    }

    /**
     * Whether a live save holding a preview is refused (or only warned of).
     */
    public function blocksPreviewsOnPublish(): bool
    {
        return $this->onPublish() === OnPublish::Block;
    }

    /**
     * What publishing with something unfinished does: `onUnfinishedPublish`,
     * else the older `stockOnPublish`; anything but "warn" blocks.
     */
    public function onPublish(): OnPublish
    {
        return OnPublish::fromConfig($this->onUnfinishedPublish, $this->stockOnPublish);
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
            // OpenRouter names every model company/model.
            'openrouter' => [],
        ];

        if ($this->provider === 'openrouter') {
            return str_contains($model, '/') ? null : \Craft::t('ghostwriter', '“{model}” does not look like an OpenRouter model. OpenRouter names them company/model, such as anthropic/claude-opus-5.5.', ['model' => $this->model]);
        }

        foreach ($families[$this->provider] ?? [] as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return null;
            }
        }

        $names = self::providerNames();

        return \Craft::t('ghostwriter', '“{model}” does not look like a {provider} model. Check it matches the provider, or leave it blank for the default.', [
            'model' => $this->model,
            'provider' => $names[$this->provider] ?? $this->provider,
        ]);
    }

    /**
     * The providers by name, as the settings and Get started show them.
     *
     * @return array<string, string>
     */
    public static function providerNames(): array
    {
        return ['anthropic' => 'Claude (Anthropic)', 'openai' => 'ChatGPT (OpenAI)', 'gemini' => 'Gemini (Google)', 'openrouter' => 'OpenRouter'];
    }

    /**
     * An OpenRouter model id per tier: company/model, or blank.
     */
    public function validateOpenrouterModels(string $attribute): void
    {
        foreach ($this->openrouterModels as $tier => $model) {
            if (!in_array($tier, ['writing', 'quick'], true)) {
                $this->addError($attribute, \Craft::t('ghostwriter', 'Unknown tier “{tier}”.', ['tier' => $tier]));
            } elseif ($model !== '' && !preg_match('#^[a-z0-9][a-z0-9._-]*/[a-zA-Z0-9][a-zA-Z0-9._:~-]*$#', $model)) {
                $this->addError("$attribute.$tier", \Craft::t('ghostwriter', 'Use an OpenRouter model id, such as anthropic/claude-opus-5.5.'));
            }
        }
    }

    /**
     * The OpenRouter model chosen for a tier, or null for core's default.
     */
    public function openrouterModel(string $tier): ?string
    {
        $model = trim((string) ($this->openrouterModels[$tier] ?? ''));

        return $model === '' ? null : $model;
    }

    /** Whether Suggest edits asks about counts, prices and claims. */
    public function checksClaims(): bool
    {
        return (bool) $this->claimChecks;
    }

    /** Whether Content to revisit checks links to other sites once a week. */
    public function checksExternalLinks(): bool
    {
        return (bool) $this->checkExternalLinks;
    }

    /**
     * Dated sections where age counts in full.
     *
     * @return array<int, string>
     */
    public function ageInFull(): array
    {
        return array_values(array_filter($this->ageInFullSections, 'is_string'));
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

        if (array_key_exists('openrouterModels', $values)) {
            $given = is_array($values['openrouterModels']) ? $values['openrouterModels'] : [];
            $values['openrouterModels'] = ['writing' => is_string($given['writing'] ?? null) ? trim($given['writing']) : '', 'quick' => is_string($given['quick'] ?? null) ? trim($given['quick']) : ''];
        }

        // The settings form sends "1", "0" or an environment variable.
        if (array_key_exists('logReplies', $values) && is_string($values['logReplies'])) {
            $given = trim($values['logReplies']);
            $values['logReplies'] = $given === '' ? false : (str_starts_with($given, '$') ? $given : (App::normalizeBooleanValue($given) ?? $given));
        }

        if (array_key_exists('shutterstockSandbox', $values) && is_string($values['shutterstockSandbox'])) {
            $given = trim($values['shutterstockSandbox']);
            $values['shutterstockSandbox'] = $given === '' ? null : (str_starts_with($given, '$') ? $given : App::normalizeBooleanValue($given));
        }

        // Lightswitches post "1" or "".
        if (array_key_exists('stockLibraries', $values)) {
            $values['stockLibraries'] = array_map(fn($on) => (bool) $on, array_filter(is_array($values['stockLibraries']) ? $values['stockLibraries'] : [], fn($key) => is_string($key) && preg_match('/^[a-z0-9_-]{1,64}$/', $key), ARRAY_FILTER_USE_KEY));
        }

        foreach (['sections', 'voiceSections', 'ageInFullSections'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = array_values(array_filter((array) $values[$key], fn($handle) => is_string($handle) && $handle !== '' && $handle !== '*'));
            }
        }

        parent::setAttributes($values, $safeOnly);
    }
}
