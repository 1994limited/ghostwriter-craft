<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\base\Field;
use craft\events\DefineFieldHtmlEvent;
use craft\events\DefineHtmlEvent;
use craft\fields\Assets;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\elements\Asset;
use craft\events\DefineAssetUrlEvent;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\ElementEvent;
use craft\events\ModelEvent;
use craft\services\Gc;
use craft\events\RegisterElementTableAttributesEvent;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\events\TemplateEvent;
use craft\web\View;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use nineteenninetyfour\ghostwriter\ai\Providers;
use nineteenninetyfour\ghostwriter\ai\Studio;
use nineteenninetyfour\ghostwriter\content\ContentScanner;
use nineteenninetyfour\ghostwriter\content\ProseExtractor;
use nineteenninetyfour\ghostwriter\domain\CraftLock;
use nineteenninetyfour\ghostwriter\domain\DbGuideStore;
use nineteenninetyfour\ghostwriter\domain\DbImageRequestStore;
use nineteenninetyfour\ghostwriter\domain\DbKindStore;
use nineteenninetyfour\ghostwriter\domain\DbPlanStore;
use nineteenninetyfour\ghostwriter\domain\DbSessionStore;
use nineteenninetyfour\ghostwriter\domain\DbLibraryTokens;
use nineteenninetyfour\ghostwriter\domain\DbProviderKeys;
use nineteenninetyfour\ghostwriter\domain\DbStockImageStore;
use nineteenninetyfour\ghostwriter\domain\DbWaitingStore;
use nineteenninetyfour\ghostwriter\domain\Domain;
use nineteenninetyfour\ghostwriter\images\ImagePicker;
use nineteenninetyfour\ghostwriter\stock\PublishGuard;
use nineteenninetyfour\ghostwriter\stock\StockCleanup;
use nineteenninetyfour\ghostwriter\stock\StockComps;
use nineteenninetyfour\ghostwriter\stock\StockLibraries;
use nineteenninetyfour\ghostwriter\stock\StockMarkers;
use nineteenninetyfour\ghostwriter\stock\StockUsages;
use nineteenninetyfour\ghostwriter\layouts\Layouts;
use nineteenninetyfour\ghostwriter\models\Settings;
use nineteenninetyfour\ghostwriter\types\TypeRepository;
use nineteenninetyfour\ghostwriter\widgets\GhostwriterWidget;
use yii\base\Event;

/**
 * Ghostwriter learns how a site writes, then drafts new entries in that
 * voice through a short questionnaire and a follow-up conversation.
 *
 * @property-read Providers $providers
 * @property-read Studio $studio
 * @property-read Paths $paths
 * @property-read Store $store
 * @property-read ContentScanner $scanner
 * @property-read ProseExtractor $prose
 * @property-read Domain $domain
 * @property-read CraftLock $lock
 * @property-read DbSessionStore $sessions
 * @property-read DbPlanStore $plans
 * @property-read DbKindStore $kindStore
 * @property-read DbGuideStore $guides
 * @property-read DbImageRequestStore $imageStore
 * @property-read DbWaitingStore $waitingStore
 * @property-read DbStockImageStore $stockImages
 * @property-read StockUsages $stockUsages
 * @property-read StockLibraries $stockLibraries
 * @property-read \nineteenninetyfour\ghostwriter\gaps\Gaps $gaps
 * @property-read StockComps $stockComps
 * @property-read StockCleanup $stockCleanup
 * @property-read DbLibraryTokens $libraryTokens
 * @property-read DbProviderKeys $providerKeys
 * @property-read TypeRepository $types
 * @property-read ImagePicker $imagePicker
 * @property-read Onboarding $onboarding
 * @property-read Layouts $layouts
 * @property-read \nineteenninetyfour\ghostwriter\preview\Previews $previews
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** The one permission Ghostwriter adds. Editing an entry still needs Craft's own. */
    public const PERMISSION = 'ghostwriter:use';

    /** Licensing stock images spends money: a permission of its own, given to nobody by default (admins have it). */
    public const LICENSE_PERMISSION = 'ghostwriter:license';

    public string $schemaVersion = '1.2.0';

    public bool $hasCpSettings = true;

    /**
     * No CP section of Craft's own making: that would add an "Access
     * Ghostwriter" permission beside ours. The nav item is added by hand
     * below and shown to whoever has the one permission.
     */
    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'providers' => Providers::class,
                'studio' => Studio::class,
                'paths' => Paths::class,
                'store' => Store::class,
                'scanner' => ContentScanner::class,
                'prose' => ProseExtractor::class,
                // Core's domain rules, and the stores and lock they work over.
                'domain' => Domain::class,
                'lock' => CraftLock::class,
                'sessions' => DbSessionStore::class,
                'plans' => DbPlanStore::class,
                'kindStore' => DbKindStore::class,
                'guides' => DbGuideStore::class,
                'imageStore' => DbImageRequestStore::class,
                'waitingStore' => DbWaitingStore::class,
                // The stock image ledger, and where its images are used.
                'stockImages' => DbStockImageStore::class,
                'stockUsages' => StockUsages::class,
                'stockLibraries' => StockLibraries::class,
                'stockComps' => StockComps::class,
                'stockCleanup' => StockCleanup::class,
                // A connected library account's tokens, encrypted.
                'libraryTokens' => DbLibraryTokens::class,
                // A model provider's key from "Connect with OpenRouter", encrypted.
                'providerKeys' => DbProviderKeys::class,
                'types' => TypeRepository::class,
                'imagePicker' => ImagePicker::class,
                'onboarding' => Onboarding::class,
                'layouts' => Layouts::class,
                // Finish this page: what is unfinished in an entry, and the publish guard.
                'gaps' => \nineteenninetyfour\ghostwriter\gaps\Gaps::class,
                // The Preview tab: the draft rendered by the site's own templates, unsaved.
                'previews' => \nineteenninetyfour\ghostwriter\preview\Previews::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = [
                'heading' => 'Ghostwriter',
                'permissions' => [
                    self::PERMISSION => ['label' => Craft::t('ghostwriter', 'Use Ghostwriter')],
                    self::LICENSE_PERMISSION => [
                        'label' => Craft::t('ghostwriter', 'License stock images'),
                        'info' => Craft::t('ghostwriter', 'Buys licences from the site’s paid photo libraries, which spends money or allowance.'),
                    ],
                ],
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event): void {
            $event->rules['ghostwriter'] = 'ghostwriter/dashboard/index';
            $event->rules['ghostwriter/voice'] = 'ghostwriter/voice/show';
            $event->rules['ghostwriter/imagery'] = 'ghostwriter/imagery/show';
            $event->rules['ghostwriter/plan'] = 'ghostwriter/plan/show';
            $event->rules['ghostwriter/setup'] = 'ghostwriter/setup/show';
            $event->rules['ghostwriter/types/<handle:[a-z0-9_-]+>'] = 'ghostwriter/types/edit';
            $event->rules['ghostwriter/teach/<section:[a-zA-Z0-9_-]+>'] = 'ghostwriter/types/teach';
            $event->rules['ghostwriter/write/<section:[a-zA-Z0-9_-]+>'] = 'ghostwriter/sections/new';
            $event->rules['ghostwriter/stock'] = 'ghostwriter/stock/index';
            // "Connect account" for libraries that license with a person's own sign-in.
            $event->rules['ghostwriter/libraries/<id:[a-z0-9_-]+>/connect'] = 'ghostwriter/libraries/connect';
            $event->rules['ghostwriter/libraries/<id:[a-z0-9_-]+>/callback'] = 'ghostwriter/libraries/callback';
            $event->rules['ghostwriter/libraries/<id:[a-z0-9_-]+>/disconnect'] = 'ghostwriter/libraries/disconnect';
            // Connect with OpenRouter (core's docs/connecting-accounts.md).
            $event->rules['ghostwriter/providers/<id:openrouter>/connect'] = 'ghostwriter/providers/connect';
            $event->rules['ghostwriter/providers/<id:openrouter>/callback'] = 'ghostwriter/providers/callback';
            $event->rules['ghostwriter/providers/<id:openrouter>/disconnect'] = 'ghostwriter/providers/disconnect';
            $event->rules['ghostwriter/providers/<id:openrouter>/check'] = 'ghostwriter/providers/check';
            // A paid photo's comp, for signed-in editors only (§7.0).
            $event->rules['ghostwriter/stock/<id:[0-9a-f]{26}>/comp'] = 'ghostwriter/stock/comp';
        });

        // The launcher sits beside the entry's own buttons.
        Event::on(Entry::class, Element::EVENT_DEFINE_ADDITIONAL_BUTTONS, function(DefineHtmlEvent $event): void {
            /** @var Entry $entry */
            $entry = $event->sender;

            $event->html .= Launcher::buttonFor($entry);
            // "Finish this page": the count pill, and the guide behind it.
            $event->html .= \nineteenninetyfour\ghostwriter\gaps\FinishGuide::buttonFor($entry);
        });

        // And beside "New entry" on the entry index.
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function(TemplateEvent $event): void {
            if ($event->templateMode === View::TEMPLATE_MODE_CP && in_array($event->template, ['entries', 'entries/index'], true)) {
                Launcher::registerIndexButton();
            }
        });

        // Where stock images are used, from Craft's relations, kept up to
        // date as entries are saved; a deleted asset's record is removed.
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event): void {
            $this->stockUsages->afterSave($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event): void {
            $this->stockUsages->afterDelete($event->element);
        });

        // No page goes live holding a stock photo preview (§7.1): entries
        // are blocked (or warned, by setting); global sets and categories
        // are warned.
        foreach ([Entry::class, \craft\elements\GlobalSet::class, \craft\elements\Category::class] as $class) {
            Event::on($class, Element::EVENT_BEFORE_SAVE, function(ModelEvent $event): void {
                PublishGuard::beforeSave($event);
            });
        }

        // Comps past their period go, unused stand-ins are cleared, and
        // licences of unknown outcome are settled, with Craft's garbage
        // collection (§7.4).
        Event::on(Gc::class, Gc::EVENT_RUN, function(): void {
            $this->stockCleanup->run();
        });

        // Editors see a paid photo's comp where the stand-in is (§7.0).
        Event::on(Asset::class, Asset::EVENT_DEFINE_URL, function(DefineAssetUrlEvent $event): void {
            $this->stockComps->defineUrl($event);
        });

        // The asset's stock licence: in its sidebar, and as an index column (§7.2).
        Event::on(Asset::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event): void {
            /** @var Asset $asset */
            $asset = $event->sender;
            $event->html .= StockMarkers::sidebarHtml($asset);
        });

        Event::on(Asset::class, Element::EVENT_REGISTER_TABLE_ATTRIBUTES, function(RegisterElementTableAttributesEvent $event): void {
            $event->tableAttributes[StockMarkers::COLUMN] = ['label' => Craft::t('ghostwriter', 'Stock licence')];
        });

        Event::on(Asset::class, Element::EVENT_DEFINE_ATTRIBUTE_HTML, function(DefineAttributeHtmlEvent $event): void {
            if ($event->attribute === StockMarkers::COLUMN) {
                /** @var Asset $asset */
                $asset = $event->sender;
                $event->html = StockMarkers::columnHtml($asset);
                $event->handled = true;
            }
        });

        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event): void {
            $event->types[] = GhostwriterWidget::class;
        });

        Event::on(Assets::class, Field::EVENT_DEFINE_INPUT_HTML, function(DefineFieldHtmlEvent $event): void {
            /** @var Assets $field */
            $field = $event->sender;

            $event->html .= ImageButton::htmlFor($field, $event->element, $event->inline);
        });

        Event::on(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS, function(RegisterCpNavItemsEvent $event): void {
            $user = Craft::$app->getUser();

            if (!$user->checkPermission(self::PERMISSION)) {
                return;
            }

            $item = [
                'url' => 'ghostwriter',
                'label' => 'Ghostwriter',
                'icon' => __DIR__ . '/icon-mask.svg',
                'subnav' => array_filter([
                    // Until it is put away: then it is reached from the foot of the Overview.
                    'setup' => !$this->onboarding->hidden() ? ['label' => Craft::t('ghostwriter', 'Get started'), 'url' => 'ghostwriter/setup'] : null,
                    'overview' => ['label' => Craft::t('ghostwriter', 'Overview'), 'url' => 'ghostwriter'],
                    'plan' => ['label' => Craft::t('ghostwriter', 'Content plan'), 'url' => 'ghostwriter/plan'],
                    'voice' => ['label' => Craft::t('ghostwriter', 'Voice guide'), 'url' => 'ghostwriter/voice'],
                    'imagery' => ['label' => Craft::t('ghostwriter', 'Image style'), 'url' => 'ghostwriter/imagery'],
                    // The stock image ledger, once there is anything in it or a paid library to use.
                    'stock' => !$this->stockUsages->ledgerIsEmpty() || $this->stockLibraries->paid() !== []
                        ? ['label' => Craft::t('ghostwriter', 'Stock images'), 'url' => 'ghostwriter/stock']
                        : null,
                    'settings' => $user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges
                        ? ['label' => Craft::t('ghostwriter', 'Settings'), 'url' => 'settings/plugins/ghostwriter']
                        : null,
                ]),
            ];

            // With the other plugins, ahead of Utilities, Settings and the
            // Plugin Store, where Craft would put a plugin's own section.
            $after = array_search(true, array_map(fn(array $navItem) => in_array($navItem['url'] ?? null, ['utilities', 'settings', 'plugin-store'], true), $event->navItems), true);

            array_splice($event->navItems, $after === false ? count($event->navItems) : $after, 0, [$item]);
        });
    }

    /**
     * Whether someone manages Ghostwriter for the whole site: hiding Get
     * started, removing anyone's piece when conversations are shared. Like
     * the plugin's settings, that is an admin's call.
     */
    public static function canManage(?\craft\elements\User $user): bool
    {
        return (bool) $user?->admin;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * The Get started switch is Ghostwriter's own state, not project config.
     */
    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();

        $this->onboarding->hide(!$this->getSettings()->showGetStarted);
    }

    protected function settingsHtml(): ?string
    {
        $this->getSettings()->showGetStarted = !$this->onboarding->hidden();

        return Craft::$app->getView()->renderTemplate('ghostwriter/_settings', [
            'settings' => $this->getSettings(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'keys' => $this->providers->keyStatus(),
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile('ghostwriter')),
            'modelDefaults' => \NineteenNinetyFour\Ghostwriter\Core\Ai\Models::TEXT_DEFAULTS,
            'providerNames' => Settings::providerNames(),
            'openrouter' => \nineteenninetyfour\ghostwriter\controllers\ProvidersController::status(),
            'openrouterTiers' => \NineteenNinetyFour\Ghostwriter\Core\Ai\Models::OPENROUTER_TIERS,
            'openrouterChoices' => \NineteenNinetyFour\Ghostwriter\Core\Ai\Models::OPENROUTER_TEXT_CHOICES,
            'canManage' => self::canManage(Craft::$app->getUser()->getIdentity()),
            'stock' => $this->stockSettings(),
        ]);
    }

    /**
     * What the settings' Stock photos section lists: each free library and
     * whether its key is set, each paid library with its keys' status (from
     * .env; never stored or shown), and the "Search in" choices.
     *
     * @return array<string, mixed>
     */
    private function stockSettings(): array
    {
        $libraries = $this->stockLibraries;
        $libraries->reset();
        $credentials = \NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\Credentials::ENV;
        $free = [];

        foreach (['unsplash', 'pexels', 'pixabay'] as $id) {
            $free[] = ['id' => $id, 'label' => $libraries->label($id), 'keys' => StockLibraries::keyStatus([$credentials[$id]])];
        }

        $paid = [];

        foreach ($libraries->all() as $id => $library) {
            $connects = $library instanceof \NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
            $paid[] = [
                'id' => $id,
                'label' => $library->label(),
                'keys' => StockLibraries::keyStatus(StockLibraries::LISTED[$id]['env'] ?? []),
                'connects' => $connects && $library->capabilities()->needsOAuth,
                'connected' => $connects && $library->connected(),
                'callback' => $connects ? \nineteenninetyfour\ghostwriter\controllers\LibrariesController::callbackHostAndPath($id) : null,
                'available' => $library->available(),
                'enabled' => $libraries->enabled($id),
                'licensable' => $library instanceof \NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary,
                'oauth' => $library->capabilities()->needsOAuth,
                'demo' => $id === StockLibraries::DEMO,
                'coming' => false,
                'note' => $id === StockLibraries::DEMO
                    ? \Craft::t('ghostwriter', 'Offered in dev mode, or with stockDemo in config/ghostwriter.php. Never in production. It calls nobody and charges nothing.')
                    : (isset(StockLibraries::LISTED[$id]) ? \Craft::t('ghostwriter', StockLibraries::LISTED[$id]['note']) : null),
                'sandbox' => $id === 'shutterstock' && $this->getSettings()->usesShutterstockSandbox(),
            ];
        }

        foreach (StockLibraries::LISTED as $id => $listed) {
            if (!isset($libraries->all()[$id])) {
                $paid[] = [
                    'id' => $id,
                    'label' => $listed['label'],
                    'keys' => StockLibraries::keyStatus($listed['env']),
                    'available' => false,
                    'enabled' => $libraries->enabled($id),
                    'licensable' => true,
                    'oauth' => false,
                    'demo' => false,
                    'coming' => $listed['coming'],
                    'unset' => !$listed['coming'],
                    'note' => \Craft::t('ghostwriter', $listed['note']),
                ];
            }
        }

        return [
            'free' => $free,
            'paid' => $paid,
            'sources' => $libraries->sourceOptions(true),
        ];
    }
}
