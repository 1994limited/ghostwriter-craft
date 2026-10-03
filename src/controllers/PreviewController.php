<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\helpers\Html;
use craft\web\Controller as CraftController;
use craft\web\TemplateResponseBehavior;
use craft\web\TemplateResponseFormatter;
use craft\web\View;
use InvalidArgumentException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\preview\CannotPreview;
use Throwable;
use Twig\Error\Error as TwigError;
use yii\base\Event;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Previews: markdown as it reads, for the guide editors, and the writing
 * panel's Preview tab, where a draft is shown as the page it would make.
 *
 * The page preview has two halves (page preview design §7.3):
 * - `prepare` (control panel): works out the values, keeps them for a
 *   while, and hands back a token URL on the site with the block map;
 * - `render` (site, reached only with that token): rebuilds the entry,
 *   unsaved, and serves the page through the section's own template, as
 *   Craft's own preview does.
 */
class PreviewController extends Controller
{
    use FindsPieces;

    /** The render is reached with a token, from a frame in the control panel. */
    protected array|bool|int $allowAnonymous = ['render' => self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE];

    public function beforeAction($action): bool
    {
        // The render is a site request: its token is its permission.
        if ($action->id === 'render') {
            return CraftController::beforeAction($action);
        }

        return parent::beforeAction($action);
    }

    public function actionMarkdown(): Response
    {
        $this->requirePostRequest();

        $markdown = (string) $this->request->getBodyParam('markdown');

        if (mb_strlen($markdown) > 60000) {
            return $this->refuse('Too long to preview.');
        }

        // Raw HTML is escaped: a guide may have been written by a model.
        $html = (string) (new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($markdown);

        return $this->asJson(['html' => $html]);
    }

    /**
     * A session's draft as a page: where to load it, and the map of its
     * blocks. Writes nothing about the entry; the person needs what
     * "Use this draft" needs.
     */
    public function actionPrepare(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->preview) {
            return $this->asJson(['preview' => false, 'reason' => 'off', 'message' => Craft::t('ghostwriter', 'The page preview is switched off.')]);
        }

        $session = $this->session();
        $type = $this->type($session->kind)->forSession($session);
        $entry = $this->target($type);

        if ($session->draft === null) {
            return $this->refuse(Craft::t('ghostwriter', 'There is no draft yet.'));
        }

        try {
            $plan = $this->request->getBodyParam('plan');
            $result = $plugin->previews->prepare($session, $type, $entry, Craft::$app->getUser()->getIdentity(), plan: is_string($plan) && $plan !== '' ? $plan : null);
        } catch (CannotPreview $cannot) {
            return $this->asJson(['preview' => false, 'reason' => $cannot->reason, 'message' => $cannot->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return $this->asJson(['preview' => true] + $result);
    }

    /**
     * The page, rendered from the values prepare() kept, with the draft's
     * entry swapped in unsaved as the placeholder element, then routed as
     * a normal site request (as craft\controllers\PreviewController does).
     * Anything that goes wrong is shown as a short page the panel reads.
     */
    public function actionRender(string $key): Response
    {
        $this->requireToken();

        $started = microtime(true);
        $previews = Plugin::getInstance()->previews;

        foreach ($previews->headers() as $name => $value) {
            $this->response->getHeaders()->set($name, $value);
        }

        $payload = $previews->stored($key);

        if ($payload === null) {
            return $this->problem(410, Craft::t('ghostwriter', 'This preview has expired.'));
        }

        try {
            $element = $previews->element($payload);

            if ($element === null) {
                return $this->problem(404, Craft::t('ghostwriter', 'The entry this preview is for is no longer there.'));
            }

            // For templates: `ghostwriterPreview` is true on these renders
            // only (Twig's globals are fixed by the time an action runs).
            Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function($event): void {
                $event->variables['ghostwriterPreview'] = true;
            });

            Craft::$app->getElements()->setPlaceholderElement($element);

            $this->request->checkIfActionRequest(true, false);
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->checkToken = false;
            $urlManager->setRouteParams([], false);
            $urlManager->setMatchedElement(null);

            $response = Craft::$app->handleRequest($this->request, true);

            // Craft renders a template as the response is sent: render it
            // now, so a template that fails is answered here.
            if ($response->getBehavior(TemplateResponseBehavior::NAME)) {
                (new TemplateResponseFormatter())->format($response);
                $response->format = Response::FORMAT_RAW;
            }
        } catch (Throwable $exception) {
            return $this->failed($exception);
        }

        // Craft's no-cache headers, then ours over them.
        $response->setNoCacheHeaders();

        foreach ($previews->headers() as $name => $value) {
            $response->getHeaders()->set($name, $value);
        }

        $response->getHeaders()->set('Server-Timing', sprintf('render;dur=%d', (int) round((microtime(true) - $started) * 1000)));

        return $response;
    }

    /**
     * The template couldn't render the draft. The panel reads the reason
     * from the page; admins also get the exception and the template.
     */
    private function failed(Throwable $exception): Response
    {
        $status = $exception instanceof HttpException ? $exception->statusCode : 500;
        $twig = $exception;

        while ($twig !== null && !$twig instanceof TwigError) {
            $twig = $twig->getPrevious();
        }

        $line = trim((string) strtok(\NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers::stripText($twig?->getRawMessage() ?? $exception->getMessage()), "\n"));

        Craft::warning(sprintf('The preview could not render: %s (%s)', $line, $exception::class), 'ghostwriter');

        $details = [];

        if (Craft::$app->getUser()->getIdentity()?->admin) {
            $details['class'] = $exception::class;

            if ($twig instanceof TwigError && $twig->getSourceContext()) {
                $details['template'] = $twig->getSourceContext()->getName() . ($twig->getTemplateLine() > 0 ? ':' . $twig->getTemplateLine() : '');
            }
        }

        if ($status === 404 && $line === '') {
            $line = Craft::t('ghostwriter', 'The site has no page at this address.');
        }

        return $this->problem($status, $line !== '' ? $line : Craft::t('ghostwriter', 'Something went wrong.'), $details);
    }

    /**
     * A short page that says why there is no preview, for the panel to
     * read (it is same-origin) and show in its own words.
     *
     * @param array<string, string> $details
     */
    private function problem(int $status, string $message, array $details = []): Response
    {
        $this->response->setStatusCode($status);
        $this->response->format = Response::FORMAT_HTML;

        $attributes = ['data-ghostwriter-preview-error' => $message];

        foreach ($details as $name => $value) {
            $attributes["data-ghostwriter-preview-{$name}"] = $value;
        }

        $this->response->data = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Preview</title></head>'
            . Html::tag('body', Html::tag('p', Html::encode($message)), $attributes)
            . '</html>';

        return $this->response;
    }
}
