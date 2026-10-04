<?php

namespace nineteenninetyfour\ghostwriter\comments;

use Craft;
use InvalidArgumentException;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Core\Review\Scope;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use nineteenninetyfour\ghostwriter\ai\CraftLogger;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Comments on the draft (core's Review\Comments), for Craft: the panel's
 * view of the comments sent in the conversation, and what a pin it sends
 * is about. Sent comments are conversation messages, so everyone on the
 * piece sees the same ones, in whichever layout is chosen: a comment
 * points at the draft's words (units), not at a block's place.
 */
class DraftComments
{
    private ?GithubFlavoredMarkdownConverter $markdown = null;

    public function __construct(private DraftLayouts $layouts = new DraftLayouts())
    {
    }

    /**
     * Core's comments, with the Studio and the layouts Apply needs.
     */
    public function comments(): Comments
    {
        $plugin = Plugin::getInstance();

        return new Comments($plugin->domain->sessions(), $plugin->studio->core(), $this->layouts->core(), $plugin->layouts->core, $plugin->studio->logger ?? new CraftLogger());
    }

    /**
     * Every comment sent on the piece, for the pins, the chat and the
     * comments list, in the chosen layout, and the number the editor's next
     * pin takes. Ghostwriter's replies are escaped markdown.
     *
     * @return array{next: int, pins: list<array<string, mixed>>}
     */
    public function present(Session $session): array
    {
        $me = Craft::$app->getUser()->getId();
        $who = fn(int|string|null $id) => $id === null ? null : ((string) $id === (string) $me ? Craft::t('ghostwriter', 'You') : Presenter::name(is_numeric($id) ? (int) $id : null));
        $pins = [];

        foreach ($this->comments()->pins($session) as $pin) {
            $scope = $pin['scope'];

            $pins[] = [
                'number' => $pin['number'],
                'id' => $pin['id'],
                'message' => $pin['message'],
                'answer' => $pin['answer'],
                'status' => $pin['status'],
                'state' => Craft::t('ghostwriter', $pin['state']),
                'kind' => (string) ($scope['kind'] ?? 'block'),
                'units' => array_values(array_map('strval', (array) ($scope['units'] ?? []))),
                'label' => isset($scope['label']) ? (string) $scope['label'] : null,
                'path' => isset($scope['blockPath']) ? (string) $scope['blockPath'] : null,
                'quote' => isset($scope['quote']['exact']) ? (string) $scope['quote']['exact'] : null,
                'body' => $pin['body'],
                'by' => $who($pin['by']),
                'mine' => $pin['by'] !== null && (string) $pin['by'] === (string) $me,
                'reply' => $pin['reply'] === null ? null : $this->html($pin['reply']),
                'changes' => array_map(fn(array $change) => [
                    'unit' => $change['unit'],
                    'diff' => $change['diff'],
                    'layout' => $change['layout'],
                    'filled' => array_map(fn(array $filled) => ['ask' => $filled['ask'], 'value' => $filled['value']], $change['filled']),
                ], $pin['changes']),
                'canPutBack' => $pin['canPutBack'],
                'putBackBy' => $pin['putBack'] !== null ? $who($pin['putBack']['by'] ?? null) : null,
                'resolvedBy' => $pin['resolved'] !== null ? $who($pin['resolved']['by'] ?? null) : null,
                'blocks' => $pin['blocks'],
                'inLayout' => (bool) $pin['inLayout'],
            ];
        }

        return ['next' => Comments::nextNumber($session), 'pins' => $pins];
    }

    /**
     * What a pin is about, from where it was made: a block's units, some
     * words in it, or the whole page. Words are anchored to the one unit of
     * the block that holds them; words the draft doesn't have (a template
     * that reworded them) fall back to the block. A block another layout
     * fills from pieces or extras takes what that plan places there.
     *
     * @param array<string, mixed> $input kind, units, label, path, planPath, quote {exact, prefix, suffix}
     * @throws InvalidArgumentException when the block has no words to comment on.
     */
    public function scope(Session $session, array $input): Scope
    {
        $kind = (string) ($input['kind'] ?? '');
        $label = isset($input['label']) && is_string($input['label']) && trim($input['label']) !== '' ? mb_substr(trim($input['label']), 0, 120) : null;
        $chosen = $this->layouts->plan($session);
        $plan = $chosen?->id;
        $path = isset($input['path']) && is_string($input['path']) && $input['path'] !== '' ? mb_substr($input['path'], 0, 500) : null;

        if ($kind === 'page') {
            return Scope::page($label, $plan);
        }

        if (!in_array($kind, ['block', 'text'], true)) {
            throw new InvalidArgumentException('Say what the comment is on: a block, some words or the whole page.');
        }

        $units = $this->units($session);
        $extras = Extras::fromArray($session->extras);
        $given = array_values(array_unique(array_map('strval', array_filter((array) ($input['units'] ?? []), 'is_scalar'))));
        $known = array_values(array_filter($given, fn(string $id) => $units->get($id) !== null || $extras->item($id) !== null));
        $planPath = isset($input['planPath']) && is_string($input['planPath']) ? $input['planPath'] : '';

        if ($known === [] && $planPath !== '' && $chosen !== null) {
            foreach (Comments::blocksOf($chosen) as $at => $refs) {
                if ($at === $planPath || str_starts_with($at, $planPath . '/')) {
                    array_push($known, ...array_values(array_filter($refs, fn(string $id) => $units->get($id) !== null || $extras->item($id) !== null)));
                }
            }

            $known = array_values(array_unique($known));
        }

        if ($known === []) {
            throw new InvalidArgumentException('That block has no writing of its own to comment on. Comment on the whole page instead.');
        }

        $exact = trim((string) ($input['quote']['exact'] ?? ''));

        if ($kind === 'text' && $exact !== '') {
            $quote = new TextQuote(mb_substr($exact, 0, TextQuote::MAX_EXACT), (string) ($input['quote']['prefix'] ?? ''), (string) ($input['quote']['suffix'] ?? ''));
            $finder = new QuoteFinder();

            foreach ($known as $id) {
                $text = $units->get($id)?->markdown;

                if ($text !== null && ($match = $finder->find($quote, $text, null, true)) !== null) {
                    return Scope::text($id, $match->fuzzy ? $match->requote($text) : $quote, $label, $plan, $path);
                }
            }
        }

        return Scope::block($known, $label, $plan, $path);
    }

    /**
     * The draft's units now, with their ids.
     */
    public function units(Session $session): Units
    {
        $schema = $this->layouts->schema($session) ?? throw new InvalidArgumentException('The entry type this was written for no longer exists.');

        return Units::fromDraft(Draft::parse((string) $session->draft)->data, $schema, Plugin::getInstance()->layouts->core->richText)->restore($session->units);
    }

    private function html(string $body): string
    {
        $this->markdown ??= new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);

        try {
            return trim((string) $this->markdown->convert($body));
        } catch (Throwable) {
            return htmlspecialchars($body);
        }
    }
}
