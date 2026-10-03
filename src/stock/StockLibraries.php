<?php

namespace nineteenninetyfour\ghostwriter\stock;

use Craft;
use craft\helpers\App;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;
use nineteenninetyfour\ghostwriter\events\RegisterStockLibrariesEvent;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * The photo libraries beyond the four free ones: paid libraries the site
 * has configured, and the demo library.
 *
 * - **Free:** Unsplash, Pexels, Pixabay (keys in .env) and Openverse, as
 *   before (core's StockSearch holds them).
 * - **Paid:** each a core adapter, used once its keys are in .env and it
 *   is switched on in the settings. Getty Images (with iStock) and
 *   Shutterstock are listed with their keys' status, and stay inert until
 *   core has their adapters (COMING). Others can be added with
 *   EVENT_REGISTER_LIBRARIES.
 * - **Demo:** core's FakeLibrary as "Demo stock (no charge)", for test
 *   sites and screenshots. Only in dev mode or with `stockDemo` set, and
 *   never when CRAFT_ENVIRONMENT is production. It calls nobody and
 *   charges nothing.
 */
class StockLibraries extends Component
{
    /** Fired to add paid libraries. */
    public const EVENT_REGISTER_LIBRARIES = 'registerLibraries';

    public const DEMO = 'demo';

    /** "Search in" choices beside a library's ID. */
    public const FREE = 'free';

    public const EVERYTHING = 'everything';

    /**
     * Paid libraries the settings list before core has their adapters:
     * their keys' status is shown (read from .env, never stored or shown),
     * and nothing is searched or licensed.
     *
     * @var array<string, array{label: string, short: string, env: array<int, string>, note: string, oauth: bool}>
     */
    public const COMING = [
        'getty' => [
            'label' => 'Getty Images and iStock',
            'short' => 'Getty',
            'env' => ['GETTY_API_KEY', 'GETTY_API_SECRET'],
            'note' => 'A key and secret from your own Getty Images or iStock account rep, under your own agreement. An iStock key works here too.',
            'oauth' => false,
        ],
        'shutterstock' => [
            'label' => 'Shutterstock',
            'short' => 'Shutterstock',
            'env' => ['SHUTTERSTOCK_API_KEY', 'SHUTTERSTOCK_API_SECRET'],
            'note' => 'Needs a Shutterstock API plan (a shutterstock.com web plan can’t license through the API), and an account connected here.',
            'oauth' => true,
        ],
    ];

    /** Short names for the source chip on a result. */
    private const SHORT = ['unsplash' => 'Unsplash', 'pexels' => 'Pexels', 'pixabay' => 'Pixabay', 'openverse' => 'Openverse', self::DEMO => 'Demo stock', 'istock' => 'iStock', 'adobe' => 'Adobe Stock', 'alamy' => 'Alamy'];

    private ?FakeLibrary $demo = null;

    /** @var array<string, PhotoLibrary>|null */
    private ?array $registered = null;

    /**
     * Every paid library the site has, configured or not, by ID: the demo
     * library (where allowed) and any registered.
     *
     * @return array<string, PhotoLibrary>
     */
    public function all(): array
    {
        if ($this->registered === null) {
            $event = new RegisterStockLibrariesEvent();

            if ($this->demoAllowed()) {
                $event->libraries[] = $this->demo();
            }

            $this->trigger(self::EVENT_REGISTER_LIBRARIES, $event);
            $this->registered = [];

            foreach ($event->libraries as $library) {
                if ($library instanceof PhotoLibrary && !$library->capabilities()->free && !in_array($library->id(), StockSearch::SOURCES, true)) {
                    $this->registered[$library->id()] = $library;
                }
            }
        }

        return $this->registered;
    }

    /**
     * Paid libraries that can be searched now: configured (keys present,
     * connected where needed) and switched on.
     *
     * @return array<string, PhotoLibrary>
     */
    public function paid(): array
    {
        return array_filter($this->all(), fn(PhotoLibrary $library) => $this->enabled($library->id()) && $library->available());
    }

    public function get(string $id): ?PhotoLibrary
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * A paid library that can be searched now and sells licences.
     */
    public function licensable(string $id): ?LicensableLibrary
    {
        $library = $this->paid()[$id] ?? $this->all()[$id] ?? null;

        return $library instanceof LicensableLibrary ? $library : null;
    }

    /**
     * Whether a library is switched on in the settings. On unless switched off.
     */
    public function enabled(string $id): bool
    {
        return (bool) (Plugin::getInstance()->getSettings()->stockLibraries[$id] ?? true);
    }

    /**
     * The demo library may be offered: in dev mode or with `stockDemo` set,
     * and never in production.
     */
    public function demoAllowed(): bool
    {
        if (self::isProduction()) {
            return false;
        }

        return Craft::$app->getConfig()->getGeneral()->devMode || Plugin::getInstance()->getSettings()->demoRequested();
    }

    public static function isProduction(): bool
    {
        return strtolower((string) (App::env('CRAFT_ENVIRONMENT') ?? Craft::$app->env ?? '')) === 'production';
    }

    /**
     * The demo library, its photos and licence options scripted. Its comps
     * are drawn by core; its thumbnails are served by the stock controller.
     */
    public function demo(): FakeLibrary
    {
        if ($this->demo !== null) {
            return $this->demo;
        }

        $demo = new FakeLibrary(self::DEMO, 'Demo stock (no charge)');

        foreach (self::DEMO_PHOTOS as $i => [$title, $width, $height, $editorial]) {
            $id = sprintf('demo-%02d', $i + 1);
            $demo->withPhotos(new Photo(
                self::DEMO,
                $id,
                '',
                'Demo photographer/Demo stock',
                null,
                'Royalty-free',
                title: $title,
                description: $title,
                width: $width,
                height: $height,
                offer: Offer::paid(Cost::units(1, Cost::DOWNLOAD), $editorial ? Offer::EDITORIAL : Offer::ROYALTY_FREE),
                editorial: $editorial,
                restrictions: $editorial ? 'Editorial use only. Not for advertising or promotion.' : null,
                collection: 'Demo collection',
            ));
            $demo->withQuotes(
                $id,
                new Quote($id, 'demo-standard', 'Standard licence, 2,400 px', Cost::units(1, Cost::DOWNLOAD), 'demo', '2400'),
                new Quote($id, 'demo-extended', 'Extended licence, 2,400 px', Cost::units(2, Cost::DOWNLOAD), 'demo', '2400', extended: true),
            );
        }

        return $this->demo = $demo;
    }

    /**
     * A library's name as the dialog's chips give it: "Getty", "Unsplash".
     */
    public function shortLabel(string $id): string
    {
        return self::SHORT[$id] ?? (self::COMING[$id]['short'] ?? ($this->get($id)?->label() ?? StockSearch::LABELS[$id] ?? ucfirst($id)));
    }

    /**
     * A library's whole name: "Getty Images", "Demo stock (no charge)".
     */
    public function label(string $id): string
    {
        return $this->get($id)?->label() ?? StockSearch::LABELS[$id] ?? (self::COMING[$id]['label'] ?? ucfirst($id));
    }

    /**
     * A library's name on a stand-in: "Getty Images", "Demo stock".
     */
    public function standInName(string $id): string
    {
        return trim((string) preg_replace('/\s*\(.*\)$/', '', $this->label($id)));
    }

    /**
     * Whether each key a library needs is in the environment. Read each
     * time; the values are never stored or shown.
     *
     * @param array<int, string> $names
     * @return array<string, bool>
     */
    public static function keyStatus(array $names): array
    {
        $status = [];

        foreach ($names as $name) {
            $value = App::env($name);
            $status[$name] = is_string($value) && trim($value) !== '';
        }

        return $status;
    }

    /**
     * The "Search in" choices for a person: free libraries, each paid
     * library that can be searched, and everything. A paid library that
     * isn't set up is listed for managers, to say where to set it up, and
     * left out for everyone else.
     *
     * @return array<int, array{value: string, label: string, short?: string, disabled?: bool}>
     */
    public function sourceOptions(bool $manager): array
    {
        $options = [['value' => self::FREE, 'label' => Craft::t('ghostwriter', 'Free libraries')]];

        foreach ($this->all() as $id => $library) {
            if (!$this->enabled($id)) {
                continue;
            }

            if ($library->available()) {
                // The select gives the whole name; running text the short one.
                $options[] = ['value' => $id, 'label' => $library->label(), 'short' => $this->standInName($id)];
            } elseif ($manager) {
                $options[] = ['value' => $id, 'label' => Craft::t('ghostwriter', '{library} (connect in Settings)', ['library' => $library->label()]), 'disabled' => true];
            }
        }

        if ($this->paid() !== []) {
            $options[] = ['value' => self::EVERYTHING, 'label' => Craft::t('ghostwriter', 'Everything')];
        }

        return $options;
    }

    /**
     * A source that can be searched now, else the free libraries.
     */
    public function normaliseSource(?string $source): string
    {
        if ($source === self::EVERYTHING) {
            return $this->paid() !== [] ? self::EVERYTHING : self::FREE;
        }

        return $source !== null && isset($this->paid()[$source]) ? $source : self::FREE;
    }

    /**
     * Forget the libraries worked out this request: for tests, and after
     * the settings change.
     */
    public function reset(): void
    {
        $this->registered = null;
        $this->demo = null;
    }

    /**
     * The demo library's photos: title, width, height, editorial only.
     */
    private const DEMO_PHOTOS = [
        ['Sedum roof in late summer', 1600, 1067, false],
        ['Grasses moving in the wind', 1600, 1067, false],
        ['Stone path through a cottage garden', 1600, 1067, false],
        ['Raised beds on a rooftop', 1600, 1200, false],
        ['A gardener planting a hedge', 1200, 1600, false],
        ['Rain garden after a storm', 1600, 900, false],
        ['Frost on seed heads in January', 1600, 1067, false],
        ['A walled kitchen garden', 1600, 1067, false],
        ['Flower show crowds at the gates', 1600, 1067, true],
        ['A city park reopening ceremony', 1600, 1067, true],
        ['Terracotta pots on a sunny step', 1200, 1200, false],
        ['A meadow in early June', 1600, 1067, false],
    ];
}
