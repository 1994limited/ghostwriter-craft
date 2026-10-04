<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use craft\elements\Entry;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewStatus;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Suggest edits' review: the `reviewer` call (one per part of a long
 * page, as the confirm said) and the `verifier`'s second pass, validated
 * by core and stored on the review, which the guide is polling. The entry
 * is read as the person's form had it when they asked: their provisional
 * draft, if they had one. Nothing is saved to the entry.
 */
class ReviewEdits extends Job
{
    public string $reviewId = '';

    /** The element the form was editing: the entry, or its provisional draft. */
    public int $elementId = 0;

    public int $siteId = 0;

    /** The language the reasons are written in: the person's. */
    public string $replyLanguage = 'en';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $store = $plugin->editReviewStore;

        // Once: a job handed out twice finds its review already taken.
        if ($store->find($this->reviewId)?->status !== ReviewStatus::Queued) {
            return;
        }

        try {
            $entry = Entry::find()->id($this->elementId)->siteId($this->siteId)->drafts(null)->provisionalDrafts(null)->status(null)->one();

            if (!$entry instanceof Entry) {
                throw new \RuntimeException('The entry has gone.');
            }

            $now = new DateTimeImmutable();
            $plugin->revisit->reviews()->run($this->reviewId, $plugin->suggest->input($entry, $now, $this->replyLanguage), $now);
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter: a review stopped: {$exception->getMessage()}", 'ghostwriter');

            // Not left running: the guide says it failed.
            $review = $store->find($this->reviewId);

            if ($review !== null && $review->status->isRunning()) {
                $review->status = ReviewStatus::Failed;
                $review->error = $exception->getMessage();
                $review->finishedAt = (new DateTimeImmutable())->format(DATE_ATOM);
                $store->save($review);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Reviewing a page for Suggest edits');
    }
}
