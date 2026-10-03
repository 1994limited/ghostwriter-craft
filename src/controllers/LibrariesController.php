<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\helpers\UrlHelper;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * "Connect account" and "Disconnect" for a paid library that licenses
 * with a person's own sign-in (Shutterstock), following core's
 * docs/connecting-accounts.md:
 *
 * - **connect**: a random, single-use `state` is kept in the control
 *   panel session, and the person is sent to the library to sign in;
 * - **callback**: the `state` is taken from the session (single use) and
 *   checked with hash_equals() before the code is exchanged, with the
 *   same callback address and `state`;
 * - **disconnect** (POST, with Craft's CSRF check): the tokens are
 *   forgotten.
 *
 * Managers only, as the settings are. Tokens are kept by the library
 * through the site's LibraryTokens, encrypted; this never touches them.
 */
class LibrariesController extends Controller
{
    /** Where the single-use state is kept in the session, per library. */
    private const STATE = 'ghostwriter.oauth.';

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
        $library = $this->library($id);
        $state = bin2hex(random_bytes(16));
        Craft::$app->getSession()->set(self::STATE . $id, $state);

        return $this->redirect($library->authorizationUrl($state, self::callbackUrl($id)));
    }

    public function actionCallback(string $id): Response
    {
        $library = $this->library($id);
        $session = Craft::$app->getSession();
        $expected = $session->get(self::STATE . $id);
        $session->remove(self::STATE . $id);

        if (!is_string($expected) || $expected === '' || !hash_equals($expected, (string) $this->request->getQueryParam('state'))) {
            throw new ForbiddenHttpException(Craft::t('ghostwriter', 'That sign-in link has expired or was already used. Connect again from the settings.'));
        }

        if ($this->request->getQueryParam('error')) {
            $session->setError(Craft::t('ghostwriter', 'Not connected: {library} wasn’t given access.', ['library' => $library->label()]));

            return $this->redirect(self::settingsUrl());
        }

        try {
            $library->connect((string) $this->request->getQueryParam('code'), self::callbackUrl($id), $expected);
        } catch (NotConnected $exception) {
            $session->setError($exception->getMessage());

            return $this->redirect(self::settingsUrl());
        }

        $session->setNotice(Craft::t('ghostwriter', '{library} is connected.', ['library' => $library->label()]));

        return $this->redirect(self::settingsUrl());
    }

    public function actionDisconnect(string $id): Response
    {
        $this->requirePostRequest();

        $library = $this->library($id);
        $library->disconnect();
        $message = Craft::t('ghostwriter', '{library} is disconnected. Licences already bought stay in the ledger.', ['library' => $library->label()]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['message' => $message]);
        }

        Craft::$app->getSession()->setNotice($message);

        return $this->redirect(self::settingsUrl());
    }

    /**
     * The callback's absolute address, the same in both calls. Built from
     * the control panel's configured base URL, or the primary site's,
     * never from the request's Host header.
     */
    public static function callbackUrl(string $id): string
    {
        return self::cpAddress("ghostwriter/libraries/{$id}/callback");
    }

    /**
     * What to paste into the library's own callback field: Shutterstock
     * takes a host name and path, not a whole URL.
     */
    public static function callbackHostAndPath(string $id): string
    {
        $url = parse_url(self::callbackUrl($id));

        return ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '') . ($url['path'] ?? '');
    }

    private static function settingsUrl(): string
    {
        return UrlHelper::cpUrl('settings/plugins/ghostwriter') . '#stock-photos';
    }

    private function library(string $id): ConnectsAccount
    {
        $library = Plugin::getInstance()->stockLibraries->get($id);

        if (!$library instanceof ConnectsAccount || !$library->capabilities()->needsOAuth) {
            throw new NotFoundHttpException();
        }

        return $library;
    }
}
