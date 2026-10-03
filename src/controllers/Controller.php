<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use craft\web\Controller as BaseController;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Refused;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\Response;

/**
 * Every Ghostwriter screen and endpoint needs the one permission.
 */
abstract class Controller extends BaseController
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION);

        return true;
    }

    /**
     * A rule of core's that said no and wasn't answered more particularly
     * by the action (someone else holding the lock, a conflict) is answered
     * with its own status and message.
     */
    public function runAction($id, $params = []): mixed
    {
        try {
            return parent::runAction($id, $params);
        } catch (Refused $refused) {
            return $this->refuse($refused->getMessage(), $refused->status());
        }
    }

    /**
     * A refusal the screen shows as it stands: why the request cannot be done.
     */
    protected function refuse(string $message, int $status = 422): Response
    {
        $this->response->setStatusCode($status);

        return $this->asJson(['message' => $message]);
    }

    /**
     * An absolute control panel address, built from the control panel's
     * configured base URL, or the primary site's; never from the request's
     * Host header. For OAuth callbacks, which must match exactly.
     */
    public static function cpAddress(string $path): string
    {
        $general = \Craft::$app->getConfig()->getGeneral();
        $base = $general->baseCpUrl ? rtrim((string) \craft\helpers\App::parseEnv($general->baseCpUrl), '/') : rtrim((string) \Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/');

        return $base . '/' . trim((string) $general->cpTrigger, '/') . '/' . ltrim($path, '/');
    }

    protected function notConfigured(): ?Response
    {
        $plugin = Plugin::getInstance();

        if ($plugin->studio->configured()) {
            return null;
        }

        return $this->refuse('No API key is set for the ' . $plugin->studio->provider() . ' provider.');
    }
}
