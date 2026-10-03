<?php

namespace nineteenninetyfour\ghostwriter\stock;

use Craft;
use craft\elements\Asset;
use craft\events\DefineAssetUrlEvent;
use craft\helpers\UrlHelper;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * Where editors see a paid photo's comp (§7.0). The comp is never an
 * asset: the field holds the stand-in, whose address is turned into the
 * control panel's comp route (`ghostwriter/stock/<id>/comp`) for:
 *
 * - control panel requests (the field's thumbnail, the asset editor), and
 * - previews (Live Preview, "View draft", share links) opened by someone
 *   signed in who may use Ghostwriter.
 *
 * Anyone else, including a share link opened signed out, gets the
 * stand-in. A transform asked for of a preview gets the comp at its own
 * size: no transform of the stand-in is made for them.
 */
class StockComps extends Component
{
    /** @var array<int, array{id: string, state: string}>|null Ledger records with a comp, by asset ID, this request. */
    private ?array $comps = null;

    private ?bool $mayView = null;

    public function defineUrl(DefineAssetUrlEvent $event): void
    {
        /** @var Asset|null $asset */
        $asset = $event->asset ?? $event->sender;

        if (!$asset instanceof Asset || !$asset->id) {
            return;
        }

        $comp = $this->comps()[(int) $asset->id] ?? null;

        if ($comp !== null && $this->mayViewComps()) {
            $event->url = self::url($comp['id']);
            $event->handled = true;
        }
    }

    /**
     * The comp route for a ledger record. Its query changes with the asset's
     * date, so a refreshed comp isn't the cached one.
     */
    public static function url(string $id, ?int $version = null): string
    {
        return UrlHelper::cpUrl("ghostwriter/stock/{$id}/comp", $version !== null ? ['v' => $version] : []);
    }

    /**
     * Whether this request may be shown comps: a control panel request, or
     * a preview, by someone signed in who may use Ghostwriter. Never from
     * the console or a queue job.
     */
    public function mayViewComps(): bool
    {
        if ($this->mayView !== null) {
            return $this->mayView;
        }

        $request = Craft::$app->getRequest();

        if (!$request instanceof \craft\web\Request) {
            return $this->mayView = false;
        }

        if (!$request->getIsCpRequest() && !$request->getIsPreview() && !$request->getIsLivePreview()) {
            return $this->mayView = false;
        }

        $user = Craft::$app->getUser()->getIdentity();

        return $this->mayView = $user !== null && $user->can(Plugin::PERMISSION);
    }

    /**
     * The comp's ledger ID by its stand-in's file name, for every stand-in
     * shown as a comp to this person. The preview's locator finds a
     * stand-in by file name; its comp's address names the ledger ID instead.
     *
     * @return array<string, string>
     */
    public function compNames(): array
    {
        $comps = $this->mayViewComps() ? $this->comps() : [];

        if ($comps === []) {
            return [];
        }

        $names = [];

        foreach (Asset::find()->id(array_keys($comps))->status(null)->site('*')->unique()->all() as $asset) {
            $names[(string) $asset->filename] = $comps[(int) $asset->id]['id'];
        }

        return $names;
    }

    /**
     * @return array<int, array{id: string, state: string}>
     */
    private function comps(): array
    {
        if ($this->comps === null) {
            $plugin = Plugin::getInstance();
            $this->comps = $plugin->stockUsages->ledgerIsEmpty() ? [] : $plugin->stockImages->withComps();
        }

        return $this->comps;
    }

    /**
     * Forget what this request worked out: after a comp is added or goes,
     * and for tests.
     */
    public function reset(): void
    {
        $this->comps = null;
        $this->mayView = null;
    }
}
