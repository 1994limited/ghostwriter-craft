<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use nineteenninetyfour\ghostwriter\gaps\LinkSuggestions;

/**
 * Finish this page's "Suggest links": one `seo-editor` and one
 * `seo-verifier` call on the entry as the form has it (LinkSuggestions),
 * made because an editor pressed a button that says it uses Ghostwriter.
 * What it found is kept in Ghostwriter's state for the guide; nothing is
 * saved into the entry here.
 */
class SuggestLinks extends Job
{
    /** The state key the guide polls. */
    public string $key = '';

    /** Who asked: only they are shown what was found. */
    public int $by = 0;

    /** The entry as the form has it: its draft's or provisional draft's ID. */
    public int $elementId = 0;

    public int $siteId = 0;

    public function execute($queue): void
    {
        (new LinkSuggestions())->run($this->key, $this->by, $this->elementId, $this->siteId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Finding pages to link to');
    }
}
