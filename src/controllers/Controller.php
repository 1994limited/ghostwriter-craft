<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use craft\web\Controller as BaseController;
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
     * A refusal the screen shows as it stands: why the request cannot be done.
     */
    protected function refuse(string $message, int $status = 422): Response
    {
        $this->response->setStatusCode($status);

        return $this->asJson(['message' => $message]);
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
