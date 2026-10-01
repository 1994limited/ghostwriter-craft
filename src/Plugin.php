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
use craft\services\Dashboard;
use craft\services\UserPermissions;
use craft\web\twig\variables\Cp;
use craft\web\UrlManager;
use nineteenninetyfour\ghostwriter\ai\Providers;
use nineteenninetyfour\ghostwriter\ai\Studio;
use nineteenninetyfour\ghostwriter\content\ContentScanner;
use nineteenninetyfour\ghostwriter\content\ProseExtractor;
use nineteenninetyfour\ghostwriter\images\ImagePicker;
use nineteenninetyfour\ghostwriter\images\ImageRequests;
use nineteenninetyfour\ghostwriter\images\ImageryGuide;
use nineteenninetyfour\ghostwriter\images\ImageryState;
use nineteenninetyfour\ghostwriter\models\Settings;
use nineteenninetyfour\ghostwriter\planning\IdeaRepository;
use nineteenninetyfour\ghostwriter\planning\PlanState;
use nineteenninetyfour\ghostwriter\sessions\SessionRepository;
use nineteenninetyfour\ghostwriter\types\KindSuggestions;
use nineteenninetyfour\ghostwriter\types\TypeRepository;
use nineteenninetyfour\ghostwriter\types\TypeState;
use nineteenninetyfour\ghostwriter\voice\VoiceGuide;
use nineteenninetyfour\ghostwriter\voice\VoiceState;
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
 * @property-read VoiceGuide $voiceGuide
 * @property-read VoiceState $voiceState
 * @property-read TypeRepository $types
 * @property-read TypeState $typeState
 * @property-read SessionRepository $sessions
 * @property-read KindSuggestions $kinds
 * @property-read ImageryGuide $imageryGuide
 * @property-read ImageryState $imageryState
 * @property-read ImagePicker $imagePicker
 * @property-read ImageRequests $imageRequests
 * @property-read IdeaRepository $ideas
 * @property-read PlanState $planState
 * @property-read Onboarding $onboarding
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** The one permission Ghostwriter adds. Editing an entry still needs Craft's own. */
    public const PERMISSION = 'ghostwriter:use';

    public string $schemaVersion = '1.1.0';

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
                'voiceGuide' => VoiceGuide::class,
                'voiceState' => VoiceState::class,
                'types' => TypeRepository::class,
                'typeState' => TypeState::class,
                'sessions' => SessionRepository::class,
                'kinds' => KindSuggestions::class,
                'imageryGuide' => ImageryGuide::class,
                'imageryState' => ImageryState::class,
                'imagePicker' => ImagePicker::class,
                'imageRequests' => ImageRequests::class,
                'ideas' => IdeaRepository::class,
                'planState' => PlanState::class,
                'onboarding' => Onboarding::class,
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
        });

        // The launcher sits beside the entry's own buttons.
        Event::on(Entry::class, Element::EVENT_DEFINE_ADDITIONAL_BUTTONS, function(DefineHtmlEvent $event): void {
            /** @var Entry $entry */
            $entry = $event->sender;

            $event->html .= Launcher::buttonFor($entry);
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
                    // Until it is put away: then it is reached from the foot of the dashboard.
                    'setup' => !$this->onboarding->hidden() ? ['label' => Craft::t('ghostwriter', 'Get started'), 'url' => 'ghostwriter/setup'] : null,
                    'dashboard' => ['label' => Craft::t('ghostwriter', 'Dashboard'), 'url' => 'ghostwriter'],
                    'plan' => ['label' => Craft::t('ghostwriter', 'Content plan'), 'url' => 'ghostwriter/plan'],
                    'voice' => ['label' => Craft::t('ghostwriter', 'Voice guide'), 'url' => 'ghostwriter/voice'],
                    'imagery' => ['label' => Craft::t('ghostwriter', 'Image style'), 'url' => 'ghostwriter/imagery'],
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
        ]);
    }
}
