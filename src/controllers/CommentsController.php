<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Refused;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comment;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use nineteenninetyfour\ghostwriter\comments\DraftComments;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\ApplyComments;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Comments on the draft in the Preview (page preview design §9, decision
 * 9). Pins not sent yet are the editor's own, in their panel; **Apply N
 * comments** sends them as one message in the conversation, claiming the
 * piece as Send does, and one reviser call answers them in the queue.
 * Resolving and Put back act on a comment's result in that answer. Every
 * answer is the piece as the panel shows it.
 */
class CommentsController extends Controller
{
    use FindsPieces;

    /**
     * **Apply N comments**: each pin's words, and where it was made. A
     * refusal names the pin it is about (`errors["comments.1"]`), in words.
     */
    public function actionApply(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        if ($session->draft === null) {
            return $this->refuse(Craft::t('ghostwriter', 'There is no draft to comment on yet.'));
        }

        $given = $this->request->getBodyParam('comments');

        if (!is_array($given) || $given === []) {
            return $this->invalid(['comments' => Craft::t('ghostwriter', 'There are no comments to apply.')]);
        }

        if (count($given) > Comments::PER_APPLY) {
            return $this->invalid(['comments' => Craft::t('ghostwriter', 'Apply at most {max} comments at a time.', ['max' => Comments::PER_APPLY])]);
        }

        $drafts = new DraftComments();
        $comments = [];
        $errors = [];

        foreach (array_values($given) as $i => $comment) {
            if (!is_array($comment)) {
                $errors["comments.{$i}"] = Craft::t('ghostwriter', 'Write the comment first.');

                continue;
            }

            // Words copied from the preview never carry its invisible markers.
            $body = is_string($comment['body'] ?? null) ? trim(str_replace("\r", '', PreviewMarkers::stripText($comment['body']))) : '';

            foreach (['exact', 'prefix', 'suffix'] as $part) {
                if (isset($comment['quote'][$part]) && is_string($comment['quote'][$part])) {
                    $comment['quote'][$part] = PreviewMarkers::stripText($comment['quote'][$part]);
                }
            }

            if ($body === '') {
                $errors["comments.{$i}.body"] = Craft::t('ghostwriter', 'Write the comment first.');

                continue;
            }

            if (mb_strlen($body) > Comment::MAX_BODY) {
                $errors["comments.{$i}.body"] = Craft::t('ghostwriter', 'A comment can be at most {max} characters.', ['max' => Comment::MAX_BODY]);

                continue;
            }

            try {
                $comments[] = ['scope' => $drafts->scope($session, $comment), 'body' => $body];
            } catch (InvalidArgumentException $exception) {
                $errors["comments.{$i}"] = Craft::t('ghostwriter', $exception->getMessage());
            }
        }

        if ($errors !== []) {
            return $this->invalid($errors);
        }

        $plugin = Plugin::getInstance();
        $session = $this->guarded(fn() => $drafts->comments()->apply($session->id, $plugin->domain->viewer(), $comments));

        if ($session instanceof Response) {
            return $session;
        }

        ApplyComments::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * Resolve (or, with `resolved` false, reopen) a comment Ghostwriter answered.
     */
    public function actionResolve(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();
        $answer = (int) $this->request->getRequiredBodyParam('answer');
        $number = (int) $this->request->getRequiredBodyParam('number');
        $resolved = filter_var($this->request->getBodyParam('resolved', true), FILTER_VALIDATE_BOOLEAN);
        $plugin = Plugin::getInstance();

        $session = $this->guarded(fn() => (new DraftComments())->comments()->resolve($session->id, $plugin->domain->viewer(), $answer, $number, $resolved));

        return $session instanceof Response ? $session : $this->asJson((new Presenter())->detail($session));
    }

    /**
     * **Put it back**: a comment's change undone. No model.
     */
    public function actionPutBack(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();
        $answer = (int) $this->request->getRequiredBodyParam('answer');
        $number = (int) $this->request->getRequiredBodyParam('number');
        $plugin = Plugin::getInstance();
        $site = (new DraftLayouts())->context($session);

        if ($site === null) {
            return $this->refuse(Craft::t('ghostwriter', 'The entry type this was written for no longer exists.'));
        }

        $session = $this->guarded(fn() => (new DraftComments())->comments()->putBack($session->id, $plugin->domain->viewer(), $answer, $number, $site));

        return $session instanceof Response ? $session : $this->asJson((new Presenter())->detail($session));
    }

    /**
     * @param array<string, string> $errors
     */
    private function invalid(array $errors): Response
    {
        $this->response->setStatusCode(422);

        return $this->asJson(['message' => reset($errors), 'errors' => array_map(fn(string $message) => [$message], $errors)]);
    }

    /**
     * @param callable(): Session $change
     */
    private function guarded(callable $change): Session|Response
    {
        try {
            return $change();
        } catch (Busy $busy) {
            return $this->refuse($busy->waitingOn !== null
                ? Craft::t('ghostwriter', '{name} is waiting on Ghostwriter.', ['name' => Presenter::name((int) $busy->waitingOn)])
                : Craft::t('ghostwriter', $busy->getMessage()), $busy->status());
        } catch (NotFound $notFound) {
            return $this->refuse(Craft::t('ghostwriter', $notFound->getMessage() ?: 'That comment has gone.'), 404);
        } catch (NotAllowed) {
            throw new NotFoundHttpException('No such piece of writing.');
        } catch (Refused $refused) {
            return $this->refuse(Craft::t('ghostwriter', $refused->getMessage()), $refused->status());
        }
    }
}
