<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkProposals;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use nineteenninetyfour\ghostwriter\ai\CraftLogger;
use nineteenninetyfour\ghostwriter\jobs\SuggestLinks;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\suggest\EntryChecks;
use Throwable;

/**
 * Finish this page's **Suggest links** on a Craft entry (SEO layer §12,
 * `few-links`): core's SeoPass::suggestLinksFor() on the entry as the form
 * has it (its draft or provisional draft), in the queue (SuggestLinks, as
 * "Write it for me"), one `seo-editor` and one `seo-verifier` call.
 *
 * What it found is kept in Ghostwriter's state under a key for the page
 * view, for the person who asked: the guide sends the key with each check,
 * and Gaps hands the proposals to the gap finder, which makes each link
 * still to make a "Link it" step. Nothing is written into the entry here:
 * Link it links the words in CKEditor (nested entries too), and Craft
 * saves the draft as it would for any typing.
 */
class LinkSuggestions
{
    public const WORKING = 'working';

    public const DONE = 'done';

    public const FAILED = 'failed';

    private const PREFIX = 'gap-links:';

    /** Starts Suggest links on an entry; the id the guide polls and sends back with each check. */
    public function start(Entry $entry, int $by): string
    {
        $id = bin2hex(random_bytes(8));

        Plugin::getInstance()->store->putState(self::PREFIX . $id, ['status' => self::WORKING, 'by' => $by]);
        SuggestLinks::start(['key' => self::PREFIX . $id, 'by' => $by, 'elementId' => (int) $entry->id, 'siteId' => (int) $entry->siteId]);

        return $id;
    }

    /**
     * Where Suggest links is, for whoever asked: working, done (how many
     * links were found, and why none) or failed. Null for anyone else.
     *
     * @return array{status: string, found?: int, none?: string, message?: string}|null
     */
    public function status(string $id, int $by): ?array
    {
        $state = $this->state($id, $by);

        if ($state === null) {
            return null;
        }

        $proposals = is_array($state['proposals'] ?? null) ? LinkProposals::fromArray($state['proposals']) : null;

        return array_filter([
            'status' => (string) ($state['status'] ?? self::FAILED),
            'found' => $proposals !== null ? count($proposals->links) : null,
            'none' => $proposals?->none,
            'message' => is_string($state['message'] ?? null) ? $state['message'] : null,
        ], fn($value) => $value !== null);
    }

    /** What Suggest links found, for whoever asked; null until it has finished. */
    public function proposals(?string $id, int $by): ?LinkProposals
    {
        $state = $id !== null && $id !== '' ? $this->state($id, $by) : null;

        return ($state['status'] ?? null) === self::DONE && is_array($state['proposals'] ?? null) ? LinkProposals::fromArray($state['proposals']) : null;
    }

    /** The job's work: the two calls on the entry as it stands. */
    public function run(string $key, int $by, int $elementId, int $siteId): void
    {
        $store = Plugin::getInstance()->store;

        try {
            $entry = Entry::find()->id($elementId)->siteId($siteId)->drafts(null)->provisionalDrafts(null)->status(null)->one();

            if (!$entry instanceof Entry) {
                $store->putState($key, ['status' => self::FAILED, 'by' => $by, 'message' => Craft::t('ghostwriter', 'That entry can’t be found any more.')]);

                return;
            }

            $proposals = $this->find($entry);
            $store->putState($key, ['status' => self::DONE, 'by' => $by, 'proposals' => $proposals->toArray()]);
        } catch (ProviderException $exception) {
            Craft::warning("Ghostwriter: Suggest links found nothing, as the call failed: {$exception->getMessage()}", 'ghostwriter');
            $store->putState($key, ['status' => self::FAILED, 'by' => $by, 'message' => Craft::t('ghostwriter', 'Ghostwriter couldn’t look for pages to link to just now. Try again, or add a link yourself.')]);
        } catch (Throwable $exception) {
            Craft::error($exception, 'ghostwriter');
            $store->putState($key, ['status' => self::FAILED, 'by' => $by, 'message' => $exception->getMessage()]);
        }
    }

    private function find(Entry $entry): LinkProposals
    {
        $plugin = Plugin::getInstance();
        $suggest = $plugin->suggest;
        $writer = $plugin->studio->inputs()->writerContext($suggest->kind($entry), $suggest->voice(), '');
        $links = new LinkContext(
            $plugin->linkIndex,
            Gaps::links(),
            (string) $entry->getSection()?->handle,
            (int) $entry->siteId,
            $entry->getSection() !== null ? EntryChecks::ref($entry) : null,
            $writer->kind,
            $writer->voice,
            $entry->getSite()->language,
        );
        $pass = new SeoPass(logger: $plugin->studio->logger ?? new CraftLogger(), studio: $plugin->studio->core());

        return $pass->suggestLinksFor($plugin->gaps->context($entry), $links, (string) $entry->title);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function state(string $id, int $by): ?array
    {
        if (preg_match('/^[0-9a-f]{16}$/', $id) !== 1) {
            return null;
        }

        $state = Plugin::getInstance()->store->state(self::PREFIX . $id);

        return $state !== [] && (int) ($state['by'] ?? 0) === $by ? $state : null;
    }
}
