<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapRefused;
use NineteenNinetyFour\Ghostwriter\Core\Studio\GapRequest;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * One "fix that writes" in "Finish this page" ("Write it for me", "Write
 * around it"): one small gap-filler request, made because an editor
 * pressed a button that says it uses Ghostwriter. The answer is kept in
 * Ghostwriter's state for the guide to put into the form; nothing is saved
 * into the entry here.
 */
class FillGap extends Job
{
    /** The state key the guide polls. */
    public string $key = '';

    /** Who asked: only they are shown the answer. */
    public int $by = 0;

    /** GapRequest::SUMMARY or GapRequest::WRITE_AROUND. */
    public string $task = GapRequest::SUMMARY;

    public string $label = '';

    /** The page's text (summary), or the sentence holding the marker (write around). */
    public string $text = '';

    public ?string $missing = null;

    public ?int $limit = null;

    /** The gap's ID, kind and hint, so core can refuse a fact. */
    public array $gap = [];

    public function execute($queue): void
    {
        $store = Plugin::getInstance()->store;

        try {
            $result = Plugin::getInstance()->studio->core()->fillGap($this->request());
            $store->putState($this->key, ['status' => 'done', 'text' => (string) $result->value, 'by' => $this->by]);
        } catch (GapRefused $refused) {
            $store->putState($this->key, ['status' => 'failed', 'message' => $refused->getMessage(), 'by' => $this->by]);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');
            $store->putState($this->key, ['status' => 'failed', 'message' => $exception->getMessage(), 'by' => $this->by]);
        }
    }

    public function request(): GapRequest
    {
        $gap = \nineteenninetyfour\ghostwriter\gaps\Gaps::gapFromArray($this->gap);

        return $this->task === GapRequest::WRITE_AROUND
            ? GapRequest::writeAround($gap, $this->text)
            : GapRequest::summary($this->label, $this->text, $this->limit ?? GapRequest::SUMMARY_LIMIT, $gap);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Writing a line for {label}', ['label' => $this->label]);
    }
}
