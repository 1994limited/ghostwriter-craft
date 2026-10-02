<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Stock photos in the control panel: checking a paid library's connection
 * from the settings, and the demo library's thumbnails.
 */
class StockController extends Controller
{
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
     * The address of a demo photo's thumbnail.
     */
    public static function demoThumbUrl(string $id): string
    {
        return \craft\helpers\UrlHelper::actionUrl('ghostwriter/stock/demo-thumb', ['id' => $id]);
    }
}
