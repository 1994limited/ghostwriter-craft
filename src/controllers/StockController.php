<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Asset;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\StockImage;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicenceRefused;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\QuoteChanged;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Account;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PreviewableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\stock\CraftAssetReplacer;
use nineteenninetyfour\ghostwriter\stock\StockComps;
use nineteenninetyfour\ghostwriter\stock\StockFiles;
use nineteenninetyfour\ghostwriter\stock\StockView;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Stock photos in the control panel: a preview's comp for signed-in
 * editors, the badges and panels that mark previews, "License & replace"
 * and "Request licence", checking a paid library's connection from the
 * settings, and the demo library's thumbnails.
 */
class StockController extends Controller
{
    /**
     * A preview's comp, for signed-in editors only (the route is the
     * control panel's, behind its sign-in and the Use Ghostwriter
     * permission). Never cached, never indexed. Once the comp has gone
     * (its period ended, or the photo was licensed) the asset's own file
     * is sent instead.
     */
    public function actionComp(string $id): Response
    {
        $plugin = Plugin::getInstance();
        $image = $plugin->stockImages->find($id) ?? throw new NotFoundHttpException();
        $comp = $image->isUnlicensed() ? $image->comp() : null;
        $headers = $this->response->getHeaders();
        $headers->set('Cache-Control', 'private, no-store');
        $headers->set('X-Robots-Tag', 'noindex');

        // A library whose terms allow nothing to be stored: its own preview.
        if ($comp !== null && preg_match('#^https://#', $comp)) {
            return $this->redirect($comp);
        }

        $file = $comp !== null ? $plugin->store->file($comp) : null;

        if ($file !== null) {
            return $this->response->sendContentAsFile($file['content'], "{$image->id}-comp.{$file['extension']}", ['mimeType' => $file['mime'], 'inline' => true]);
        }

        $asset = is_numeric($image->asset->id) ? Asset::find()->id((int) $image->asset->id)->status(null)->one() : null;

        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException();
        }

        return $this->response->sendContentAsFile((string) $asset->getContents(), $asset->getFilename(), ['mimeType' => $asset->getMimeType(), 'inline' => true]);
    }

    /**
     * The badges for an image field's assets: previews, licences in flight
     * or failed, licensed files not yet in place.
     */
    public function actionBadges(): Response
    {
        $ids = array_map('intval', (array) $this->request->getQueryParam('assetIds', []));

        return $this->asJson(['badges' => StockView::badges($ids, Craft::$app->getUser()->getIdentity())]);
    }

    /**
     * What the "License & replace" confirm step shows: the licence options
     * this account can buy for the photo, each with its cost in the
     * account's own terms, the restrictions, the credit, and the seat and
     * storage notices.
     */
    public function actionQuote(string $id): Response
    {
        [$image, $library] = $this->licensable($id);

        try {
            $quotes = $library->quotes($image->externalId);
        } catch (PhotoUnavailable $exception) {
            return $this->refuse($this->refusal($exception, $library->label()));
        }

        try {
            $account = $library->account();
        } catch (Throwable) {
            $account = null;
        }

        $libraries = Plugin::getInstance()->stockLibraries;

        return $this->asJson([
            'id' => $image->id,
            'library' => $libraries->standInName($image->library),
            'externalId' => $image->externalId,
            'title' => $image->title,
            'thumb' => $image->comp() !== null ? StockComps::url($image->id) : null,
            'editorial' => $image->editorial,
            'restrictions' => $image->restrictions,
            'credit' => $image->creditLine,
            'quotes' => array_map(fn(Quote $quote) => [
                'option' => $quote->option,
                'name' => $quote->licenceName,
                'cost' => self::costLine($quote, $account, $libraries->standInName($image->library)),
                'extended' => $quote->extended,
                'notices' => self::seatNotices($quote),
                'terms' => $quote->terms,
            ], $quotes),
            'usedOn' => count($image->usages()),
        ]);
    }

    /**
     * "License & replace": the option confirmed is looked up again among
     * the library's own (never taken from the browser), the licence is
     * bought once (core's StockImages::license()), and the stand-in's file
     * is swapped for the licensed original.
     */
    public function actionLicense(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        [$image, $library] = $this->licensable((string) $this->request->getRequiredBodyParam('id'));
        $label = $plugin->stockLibraries->standInName($image->library);

        if ($image->editorial && !$this->request->getBodyParam('acknowledge')) {
            return $this->refuse(Craft::t('ghostwriter', 'This image is for editorial use only. Tick the box to confirm, then license it.'));
        }

        $option = (string) $this->request->getRequiredBodyParam('option');

        try {
            $quote = null;

            foreach ($library->quotes($image->externalId) as $offered) {
                if ($offered->option === $option) {
                    $quote = $offered;
                }
            }

            if ($quote === null) {
                return $this->refuse(Craft::t('ghostwriter', 'That licence option isn’t offered any more. Check the options and license again.'), 409);
            }

            $comp = $image->comp();
            $person = $plugin->domain->person();
            $stock = $plugin->domain->stock();
            $stock->quoted($image->id, $quote, $person);
            $licensed = $stock->license($image->id, $library, $quote, new CraftAssetReplacer(), $person);
        } catch (LicensingUncertain) {
            return $this->refuse(Craft::t('ghostwriter', 'We couldn’t confirm the purchase. Ghostwriter will check with {library} in a few minutes; don’t buy it again.', ['library' => $label]), 409);
        } catch (PhotoUnavailable $exception) {
            return $this->refuse($this->refusal($exception, $label), $exception instanceof QuoteChanged ? 409 : 422);
        }

        StockFiles::forget($comp);
        $plugin->stockComps->reset();

        return $this->asJson($this->licensedResponse($licensed));
    }

    /**
     * "Download again and replace": a licence bought whose file isn't in
     * place yet. Never buys again.
     */
    public function actionReplaceAgain(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        [$image, $library] = $this->licensable((string) $this->request->getRequiredBodyParam('id'));
        $image = $plugin->domain->stock()->replaceAgain($image->id, $library, new CraftAssetReplacer(), $plugin->domain->person());

        return $this->asJson($this->licensedResponse($image));
    }

    /**
     * "Request licence", from someone who may not license: the ledger
     * notes who asked, and the request goes to the top of the Previews
     * tab. (Email notifications are for later.)
     */
    public function actionRequest(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $image = $plugin->stockImages->find((string) $this->request->getRequiredBodyParam('id'));

        if ($image === null || !$image->isUnlicensed()) {
            return $this->refuse(Craft::t('ghostwriter', 'That image doesn’t need a licence now.'), 404);
        }

        $user = Craft::$app->getUser()->getIdentity();
        $plugin->stockImages->request($image->id, $user?->id, $plugin->domain->person($user)?->name);

        return $this->asJson([
            'message' => Craft::t('ghostwriter', 'Licence requested. A manager will see it at the top of Stock images.'),
            'badge' => StockView::many([$image], $user)[0],
        ]);
    }

    /**
     * "Refresh preview": a preview whose comp period ended gets its comp
     * downloaded again, once at most, on an editor's click (terms check Q2).
     */
    public function actionRefresh(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $image = $plugin->stockImages->find((string) $this->request->getRequiredBodyParam('id')) ?? throw new NotFoundHttpException();
        $library = $plugin->stockLibraries->paid()[$image->library] ?? null;

        if (!$library instanceof PreviewableLibrary) {
            return $this->refuse(Craft::t('ghostwriter', 'Ghostwriter isn’t connected to {library}. Check its key in Settings, then try again.', ['library' => $plugin->stockLibraries->standInName($image->library)]));
        }

        if (!$image->mayRefreshComp()) {
            return $this->refuse(Craft::t('ghostwriter', 'This preview has been refreshed once already. License it or remove it.'), 409);
        }

        try {
            $preview = $library->preview($image->externalId);
        } catch (PhotoUnavailable $exception) {
            return $this->refuse($exception->getMessage());
        }

        $comp = $preview->url;

        if ($preview->file !== null) {
            $comp = 'stock-comp-' . bin2hex(random_bytes(10));
            $plugin->store->putFile($comp, $preview->file->content, $preview->file->mime, $preview->file->extension);
        }

        $old = $image->comp();
        $image = $plugin->domain->stock()->compRefreshed($image->id, (string) $comp, $preview->keepUntil, $plugin->domain->person());
        StockFiles::forget($old);
        $plugin->stockComps->reset();

        return $this->asJson(['message' => Craft::t('ghostwriter', 'Preview refreshed.'), 'badge' => StockView::many([$image])[0]]);
    }

    /**
     * "Check connection": who the library is connected as, and what the
     * account has left. Managers only, as the settings are.
     */
    public function actionCheck(): Response
    {
        $this->requirePostRequest();

        if (!Plugin::canManage(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException();
        }

        $id = (string) $this->request->getRequiredBodyParam('library');
        $library = Plugin::getInstance()->stockLibraries->get($id);

        if ($library === null) {
            return $this->refuse(Craft::t('ghostwriter', 'Ghostwriter can’t search or license here yet.'), 404);
        }

        if (!$library->available()) {
            return $this->refuse(Craft::t('ghostwriter', '{library} isn’t set up: add its key and secret to your .env file.', ['library' => $library->label()]));
        }

        if (!$library instanceof LicensableLibrary) {
            return $this->asJson(['account' => Craft::t('ghostwriter', 'Connected. Searching only: nothing is licensed here.'), 'products' => []]);
        }

        try {
            $account = $library->account();
        } catch (PhotoUnavailable $exception) {
            return $this->refuse($exception->getMessage());
        } catch (Throwable $exception) {
            Craft::warning("Checking {$id} failed: {$exception->getMessage()}", 'ghostwriter');

            return $this->refuse(Craft::t('ghostwriter', 'Ghostwriter couldn’t reach {library}. Check the key and secret, and try again.', ['library' => $library->label()]));
        }

        $products = [];

        foreach ($account->products as $product) {
            $parts = [$product['name']];

            if ($product['remaining'] !== null) {
                $parts[] = Craft::t('ghostwriter', '{remaining} left', ['remaining' => $product['remaining']->label()]);
            }

            if ($product['resetsAt'] !== null) {
                $parts[] = Craft::t('ghostwriter', 'resets {date}', ['date' => Craft::$app->getFormatter()->asDate($product['resetsAt'], 'medium')]);
            }

            if ($product['termEndsAt'] !== null) {
                $parts[] = Craft::t('ghostwriter', 'term ends {date}', ['date' => Craft::$app->getFormatter()->asDate($product['termEndsAt'], 'medium')]);
            }

            $products[] = implode(', ', $parts);
        }

        return $this->asJson([
            'account' => Craft::t('ghostwriter', 'Connected as {name}', ['name' => $account->name ?? $library->label()]),
            'products' => $products,
        ]);
    }

    /**
     * A demo library photo's thumbnail: its watermarked comp, drawn by core.
     * The demo library calls nobody, so its thumbnails are served here.
     */
    public function actionDemoThumb(): Response
    {
        $libraries = Plugin::getInstance()->stockLibraries;

        if (!$libraries->demoAllowed()) {
            throw new NotFoundHttpException();
        }

        try {
            $preview = $libraries->demo()->preview((string) $this->request->getRequiredQueryParam('id'));
        } catch (PhotoUnavailable) {
            throw new NotFoundHttpException();
        }

        $file = $preview->file ?? throw new NotFoundHttpException();
        $this->response->getHeaders()->set('Cache-Control', 'private, max-age=3600');
        $this->response->getHeaders()->set('X-Robots-Tag', 'noindex');

        return $this->response->sendContentAsFile($file->content, $file->photo->id . '.' . $file->extension, ['mimeType' => $file->mime, 'inline' => true]);
    }

    /**
     * A record that may be licensed now by this person, and its library.
     *
     * @return array{0: StockImage, 1: LicensableLibrary}
     */
    private function licensable(string $id): array
    {
        $plugin = Plugin::getInstance();

        if (!StockView::mayLicense(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException(Craft::t('ghostwriter', 'Ask a manager to license'));
        }

        $image = $plugin->stockImages->find($id) ?? throw new NotFoundHttpException(Craft::t('ghostwriter', 'That stock image could not be found.'));
        $library = $plugin->stockLibraries->paid()[$image->library] ?? null;

        if (!$library instanceof LicensableLibrary) {
            throw new BadRequestHttpException(Craft::t('ghostwriter', 'Ghostwriter isn’t connected to {library}. Check its key in Settings, then try again.', ['library' => $plugin->stockLibraries->standInName($image->library)]));
        }

        return [$image, $library];
    }

    /**
     * What to tell the editor when a library refused (§8.3).
     */
    private function refusal(PhotoUnavailable $exception, string $library): string
    {
        return match (true) {
            $exception instanceof NotConnected => Craft::t('ghostwriter', 'Ghostwriter isn’t connected to {library}. Check its key in Settings, then try again.', ['library' => $library]),
            $exception instanceof InsufficientBalance => Craft::t('ghostwriter', 'Your {library} account has nothing left to license this with. Nothing was charged.', ['library' => $library]),
            $exception instanceof QuoteChanged => $exception->quote !== null
                ? Craft::t('ghostwriter', 'The price has changed: it is now {cost}. Check it and license again.', ['cost' => $exception->quote->costLabel()])
                : Craft::t('ghostwriter', 'The price has changed since you confirmed it. Check it and license again.'),
            $exception instanceof LicenceRefused => Craft::t('ghostwriter', '{library} wouldn’t license it: {reason} Nothing was charged.', ['library' => $library, 'reason' => rtrim($exception->getMessage(), '.') . '.']),
            default => $exception->getMessage(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function licensedResponse(StockImage $image): array
    {
        $asset = is_numeric($image->asset->id) ? Asset::find()->id((int) $image->asset->id)->status(null)->one() : null;

        return [
            'message' => $image->isReplaced()
                ? Craft::t('ghostwriter', 'Licensed. The preview has been replaced with the full image.')
                : Craft::t('ghostwriter', 'Licensed, but the file couldn’t be put in place. Use Download again and replace; it won’t be bought again.'),
            'replaced' => $image->isReplaced(),
            'assetId' => $asset?->id,
            'thumb' => $asset !== null ? Craft::$app->getAssets()->getThumbUrl($asset, 240, 240) : null,
            'badge' => StockView::many([$image])[0],
        ];
    }

    /**
     * The cost in the account's own terms (§8.3): "Uses 1 of your 742
     * remaining downloads (Premium Access, resets 1 Nov)"; or that it isn't
     * known before licensing.
     */
    public static function costLine(Quote $quote, ?Account $account, string $library): string
    {
        $cost = $quote->cost;

        if ($cost === null) {
            return Craft::t('ghostwriter', '{library} will charge this to your account; the cost isn’t available before licensing.', ['library' => $library]);
        }

        if ($cost->isMoney()) {
            return Craft::t('ghostwriter', 'Costs {cost}.', ['cost' => $cost->label()]);
        }

        foreach ($account?->products ?? [] as $product) {
            $remaining = $product['remaining'];

            if ($remaining !== null && $remaining->unit === $cost->unit && ($quote->productType === null || $product['type'] === null || $product['type'] === $quote->productType)) {
                $details = array_filter([$product['name'], $product['resetsAt'] !== null ? Craft::t('ghostwriter', 'resets {date}', ['date' => Craft::$app->getFormatter()->asDate($product['resetsAt'], 'd MMM')]) : null]);

                return Craft::t('ghostwriter', 'Uses {cost} of your {remaining} remaining {unit} ({details})', [
                    'cost' => $cost->units,
                    'remaining' => $remaining->units,
                    'unit' => $remaining->unit . ($remaining->units === 1 ? '' : 's'),
                    'details' => implode(', ', $details),
                ]);
            }
        }

        return Craft::t('ghostwriter', 'Uses {cost}.', ['cost' => $cost->label()]);
    }

    /**
     * The seat and storage notices for a licence option (§8.3, Q13). They
     * inform; they don't block.
     *
     * @return array<int, string>
     */
    public static function seatNotices(Quote $quote): array
    {
        $notices = [];

        if ($quote->productType === 'creditpack' && !$quote->extended) {
            $notices[] = Craft::t('ghostwriter', 'A standard iStock licence is for one person at a time and doesn’t cover keeping the file on a shared server. Choose the extended licence if several editors will use it.');
        }

        if ($quote->productType === 'premiumaccess') {
            $notices[] = Craft::t('ghostwriter', 'Images from Premium Access must be removed from shared storage if your agreement ends.');
        }

        return $notices;
    }

    /**
     * The address of a demo photo's thumbnail.
     */
    public static function demoThumbUrl(string $id): string
    {
        return \craft\helpers\UrlHelper::actionUrl('ghostwriter/stock/demo-thumb', ['id' => $id]);
    }
}
