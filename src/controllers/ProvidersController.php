<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\helpers\UrlHelper;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectedKey;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\ConnectsProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\Pkce;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * "Connect with OpenRouter", "Disconnect" and "Check connection", following
 * core's docs/connecting-accounts.md:
 *
 * - **connect**: a random `state` and a PKCE verifier are kept together in
 *   the control panel session, and the person is sent to OpenRouter to
 *   sign in, with the verifier's S256 challenge;
 * - **callback**: both are taken from the session (single use), the
 *   `state` is checked with hash_equals(), and only then is the code
 *   exchanged, with the verifier, for a key kept encrypted (DbProviderKeys);
 * - **disconnect** (POST, with Craft's CSRF check): the key is forgotten;
 * - **check** (POST): what OpenRouter says about the key in use.
 *
 * Admins only. A key in .env (OPENROUTER_API_KEY) always wins: core then
 * refuses to connect or disconnect.
 */
class ProvidersController extends Controller
{
    /** Where the state and verifier are kept in the session, per provider. */
    private const KEPT = 'ghostwriter.connect.';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::canManage(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException();
        }

        return true;
    }

    public function actionConnect(string $id): Response
    {
        $connection = $this->connection($id);
        $state = bin2hex(random_bytes(16));
        $verifier = Pkce::verifier();

        try {
            $url = $connection->authorizationUrl($state, self::callbackUrl($id), Pkce::challenge($verifier));
        } catch (ProviderException $exception) {
            Craft::$app->getSession()->setError($exception->getMessage());

            return $this->redirect(self::settingsUrl());
        }

        Craft::$app->getSession()->set(self::KEPT . $id, ['state' => $state, 'verifier' => $verifier]);

        return $this->redirect($url);
    }

    public function actionCallback(string $id): Response
    {
        $connection = $this->connection($id);
        $session = Craft::$app->getSession();
        $kept = $session->get(self::KEPT . $id);
        $session->remove(self::KEPT . $id);

        if (!is_array($kept) || !is_string($kept['state'] ?? null) || !is_string($kept['verifier'] ?? null) || $kept['state'] === '' || !hash_equals($kept['state'], (string) $this->request->getQueryParam('state'))) {
            throw new ForbiddenHttpException(Craft::t('ghostwriter', 'That sign-in link has expired or was already used. Connect again from the settings.'));
        }

        $code = (string) $this->request->getQueryParam('code');

        if ($code === '') {
            $session->setError(Craft::t('ghostwriter', 'Not connected: OpenRouter wasn’t given access.'));

            return $this->redirect(self::settingsUrl());
        }

        try {
            $key = $connection->connect($code, $kept['verifier']);
        } catch (ProviderException $exception) {
            $session->setError($exception->getMessage());

            return $this->redirect(self::settingsUrl());
        }

        $session->setNotice(Craft::t('ghostwriter', 'Connected to OpenRouter ({key}).', ['key' => $key->masked()]));

        return $this->redirect(self::settingsUrl());
    }

    public function actionDisconnect(string $id): Response
    {
        $this->requirePostRequest();

        try {
            $this->connection($id)->disconnect();
        } catch (ProviderException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $message = Craft::t('ghostwriter', 'Disconnected from OpenRouter. To revoke the key, delete it at openrouter.ai/settings/keys.');

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['message' => $message]);
        }

        Craft::$app->getSession()->setNotice($message);

        return $this->redirect(self::settingsUrl());
    }

    /**
     * "Check connection": the account's credit, or why the key isn't accepted.
     */
    public function actionCheck(string $id): Response
    {
        $this->requirePostRequest();

        try {
            $account = $this->connection($id)->account();
        } catch (ProviderException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return $this->asJson(['message' => $account->summary(), 'exhausted' => $account->exhausted()]);
    }

    /**
     * The callback's absolute address, the same in both calls. Built from
     * the control panel's configured base URL, or the primary site's, never
     * from the request's Host header. OpenRouter takes https://, or
     * http://localhost.
     */
    public static function callbackUrl(string $id): string
    {
        return self::cpAddress("ghostwriter/providers/{$id}/callback");
    }

    /**
     * What the settings row shows for a provider that can be connected.
     *
     * @return array{source: ?string, masked: ?string, connectUrl: string}
     */
    public static function status(string $id = 'openrouter'): array
    {
        $providers = Plugin::getInstance()->providers;
        $key = $providers->providerKeys()->get($id);

        return [
            'source' => $providers->source($id),
            'masked' => is_string($key) && $key !== '' ? ConnectedKey::mask($key) : null,
            'connectUrl' => UrlHelper::cpUrl("ghostwriter/providers/{$id}/connect"),
        ];
    }

    private static function settingsUrl(): string
    {
        return UrlHelper::cpUrl('settings/plugins/ghostwriter') . '#settings-ai-provider';
    }

    private function connection(string $id): ConnectsProvider
    {
        $connection = Plugin::getInstance()->providers->connection();

        if ($connection->provider() !== $id) {
            throw new NotFoundHttpException();
        }

        return $connection;
    }
}
