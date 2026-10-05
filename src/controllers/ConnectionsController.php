<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Connections\ConnectionRefused;
use nineteenninetyfour\ghostwriter\connections\ConnectionsPage;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Settings → Connections: a card for every service Ghostwriter uses, each
 * set up by pasting a key that is checked live before it is kept,
 * encrypted with the security key (DbCredentialStore). Admins only, as
 * for the plugin's settings.
 */
class ConnectionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::canManage(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException(ConnectionsPage::strings()->get('forbidden'));
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('ghostwriter/connections', ConnectionsPage::payload());
    }

    public function actionStatus(): Response
    {
        return $this->asJson(ConnectionsPage::payload());
    }

    /**
     * Check & save: the pasted fields are checked with the service, and
     * kept only when it accepts them.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $id = (string) $this->request->getRequiredBodyParam('service');
        $fields = array_map(fn($value) => is_string($value) ? mb_substr($value, 0, 2000) : null, (array) $this->request->getBodyParam('fields', []));
        $connections = ConnectionsPage::connections();
        $service = $connections->services()->get($id) ?? throw new NotFoundHttpException();
        $strings = ConnectionsPage::strings();

        try {
            if ($connections->fromEnvironment($service)) {
                throw new ConnectionRefused('env-wins', ['service' => $service->name, 'variable' => $service->required()[0]->env]);
            }

            $result = Plugin::getInstance()->providers->check()->check($service, $fields);

            if (!$result->ok) {
                return $this->refuse((string) $result->message($strings));
            }

            $connections->save($id, $fields);
        } catch (ConnectionRefused $refused) {
            return $this->refuse($refused->translated($strings));
        }

        return $this->asJson(['message' => $strings->get('saved', ['service' => $service->name]), 'card' => ConnectionsPage::card($service)]);
    }

    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();

        $id = (string) $this->request->getRequiredBodyParam('service');
        $connections = ConnectionsPage::connections();
        $service = $connections->services()->get($id) ?? throw new NotFoundHttpException();
        $strings = ConnectionsPage::strings();

        try {
            $connections->forget($id);
        } catch (ConnectionRefused $refused) {
            return $this->refuse($refused->translated($strings), 409);
        }

        return $this->asJson(['message' => $strings->get('disconnected', ['service' => $service->name]), 'card' => ConnectionsPage::card($service)]);
    }
}
