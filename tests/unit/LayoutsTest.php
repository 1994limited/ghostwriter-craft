<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\RefreshLayouts;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\tests\support\Pages;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Layouts and extras (page preview design §5, §6): the writer's draft and
 * up to two other layouts of the same words, chosen on the session and
 * applied by "Use this draft"; the extras the writer prepared, edited or
 * deleted in the Text tab. No setting: every first draft is laid out.
 */
class LayoutsTest extends TestCase
{
    use Pages;

    protected function _before(): void
    {
        parent::_before();

        $this->makePagesSection();
        $this->signIn(admin: true);
        $this->serviceType();
        Craft::$app->getCache()->flush();
    }

    public function testTheFirstDraftIsLaidOutThreeWaysForTwoCalls(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());

        // The writer (with extras), then the planner: nothing else.
        $this->assertSame(['writer', 'layout-planner'], array_map(fn($request) => $request->agent, $this->fake->requests()));
        $this->assertStringContainsString('Extras you may prepare', $this->fake->prompted('writer')[0]->instructions);
        $this->assertSame(Session::IDLE, $session->status);

        $detail = (new Presenter())->detail($session);
        $this->assertFalse($detail['layouts']['planning']);
        $this->assertSame('w', $detail['layouts']['chosen']);
        $this->assertSame(['As written', 'Sections apart', 'Numbers first'], array_column($detail['layouts']['plans'], 'name'));
        $this->assertSame([3, 5, 4], array_column($detail['layouts']['plans'], 'blocks'));
        $this->assertSame([false, true, false], array_column($detail['layouts']['plans'], 'suggested'), 'The one most like the site’s pages.');

        // The extras, with where each came from: the count is core's, not the model's.
        $stats = $detail['extras'][0];
        $this->assertSame(['stats', 'Numbers', false, 'Not used in As written'], [$stats['kind'], $stats['label'], $stats['used'], $stats['usedLabel']]);
        // As stored, markers and all: the extras list shows the count to check as a chip.
        $this->assertSame('[[check: 3 filters | from: size, finish and price]]', $stats['items'][0]['text']);
        $this->assertSame(['value' => '[[check: 3 | from: size, finish and price]]', 'label' => 'filters'], $stats['items'][0]['parts']);
        $this->assertSame('Counted from the draft: “size, finish and price”', $stats['items'][0]['source']['label']);
        $this->assertSame(['key' => 'needs-review', 'label' => 'Needs review'], $stats['items'][0]['state']);
        $this->assertSame('from the draft', $detail['extras'][1]['items'][0]['source']['label']);
    }

    public function testTheDraftShowsWhileTheOtherLayoutsAreFound(): void
    {
        $session = $this->servicePiece();
        $seen = null;

        $this->fake->respond('writer', "<reply>Here is a first draft.</reply>\n<draft>\n" . self::PAGE_DRAFT . "\n</draft>\n" . self::PAGE_EXTRAS);
        $this->fake->respond('layout-planner', function() use ($session, &$seen) {
            $seen = $this->plugin->sessions->find($session->id);

            return self::PAGE_PLANS;
        });

        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);

        // While the planner works, the draft is in and the piece is still
        // working, so nothing changes under it.
        $this->assertSame(self::PAGE_DRAFT, $seen->draft);
        $this->assertSame(Session::WORKING, $seen->status);
        $this->assertTrue((new Presenter())->detail($seen)['layouts']['planning']);

        $this->assertSame(Session::IDLE, $this->plugin->sessions->find($session->id)->status);
    }

    public function testAPlannerThatFailsLeavesOnlyTheWritersLayout(): void
    {
        $session = $this->servicePiece();
        $this->fake->respond('writer', "<reply>Here is a first draft.</reply>\n<draft>\n" . self::PAGE_DRAFT . "\n</draft>");
        $this->fake->failWith('layout-planner', new ProviderException('Overloaded.', 'fake'));

        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);

        $session = $this->plugin->sessions->find($session->id);
        $this->assertSame(Session::IDLE, $session->status);
        $this->assertNull($session->error, 'No error: the draft is there.');
        $this->assertSame(['w'], array_column((new Presenter())->detail($session)['layouts']['plans'], 'id'));
    }

    public function testALaterTurnCallsOnlyTheWriterAndKeepsTheLayoutsAndExtras(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());
        $this->fake->reset();

        $session = $this->plugin->domain->sessions()->send($session->id, 'Make the change sound bigger.', $this->plugin->domain->viewer());
        $this->fake->respond('writer', "<reply>Done.</reply>\n<draft>\n" . str_replace('Fewer calls to the showroom.', 'Far fewer calls to the showroom.', self::PAGE_DRAFT) . "\n</draft>");

        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);
        $session = $this->plugin->sessions->find($session->id);

        $this->fake->assertNotSent('layout-planner');
        $this->assertSame(['x1', 'x2'], array_column($session->extras, 'id'), 'A turn with no extras keeps the ones there were.');
        $this->assertSame(['w', 'p1', 'p2'], array_column($session->plans, 'id'));
        $this->assertSame([false, false, false], array_map(fn($plan) => (bool) ($plan['stale'] ?? false), $session->plans));
        $this->assertSame('u7', $this->unitAt($session, 'blocks/1/body', 2), 'The changed section keeps its id.');
    }

    public function testChoosingALayoutIsSharedAndUseThisDraftAppliesIt(): void
    {
        $target = $this->newDraft($this->pages);
        $session = $this->writeFirstDraft($this->servicePiece($target));
        $this->fake->reset();

        $chosen = $this->action('ghostwriter/sessions/choose-layout', ['id' => $session->id, 'plan' => 'p2']);

        $this->assertSame(200, $chosen['status'], json_encode($chosen['data']));
        $this->assertSame('p2', $chosen['data']['layouts']['chosen']);
        $this->assertSame('p2', $this->plugin->sessions->find($session->id)->plan, 'Stored on the piece, for everyone on it.');

        // Blocks and Text follow the chosen layout.
        $blocks = $chosen['data']['preview'][2]['items'];
        $this->assertSame(['hero', 'stats', 'copy', 'cta'], array_column($blocks, 'type'));
        $this->assertSame('Used in Numbers first', $chosen['data']['extras'][0]['usedLabel']);

        // The Preview renders it, and each card's layout can be asked for.
        $p2 = $this->action('ghostwriter/preview/prepare', ['id' => $session->id, 'elementId' => $target->id])['data'];
        $w = $this->action('ghostwriter/preview/prepare', ['id' => $session->id, 'elementId' => $target->id, 'plan' => 'w'])['data'];
        $this->assertSame(['p2', 'w'], [$p2['plan'], $w['plan']]);
        $this->assertNotSame($p2['hash'], $w['hash']);
        $this->assertContains('Stats', array_column($p2['map'], 'label'));
        $this->assertNotContains('Stats', array_column($w['map'], 'label'));

        // "Use this draft" puts the chosen layout in, count to check and all.
        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(200, $applied['status'], json_encode($applied['data']));

        $draft = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $saved = $draft->getFieldValue('blocks')->status(null)->all();
        $this->assertSame(['hero', 'stats', 'copy', 'cta'], array_map(fn(Entry $block) => $block->getType()->handle, $saved));
        $this->assertSame('[[check: 3 | from: size, finish and price]]', $saved[1]->getFieldValue('figures')[0]['value']);
        $this->assertStringContainsString('What we built', (string) $saved[2]->getFieldValue('body'));
        $this->fake->assertNothingSent();

        // The writer's layout chosen again is the draft as written.
        $this->action('ghostwriter/sessions/choose-layout', ['id' => $session->id, 'plan' => 'w']);
        $this->assertNull($this->plugin->sessions->find($session->id)->plan);
    }

    public function testWordsAreEditedWhereTheyLiveInWhicheverLayout(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());
        $this->fake->reset();
        $detail = $this->action('ghostwriter/sessions/choose-layout', ['id' => $session->id, 'plan' => 'p1'])['data'];
        $blocks = $detail['preview'][2]['items'];

        $this->assertSame(['hero', 'copy', 'pullQuote', 'copy', 'cta'], array_column($blocks, 'type'));

        // A heading placed whole is its own place in the draft; the quote
        // is an extra; a section taken out of a longer text is put together.
        $heading = $blocks[0]['fields'][0];
        $this->assertSame([true, ['blocks', 0, 'heading']], [$heading['editable'], $heading['path']]);
        $quote = $blocks[2]['fields'][0];
        $this->assertSame(['x2.1', null], [$quote['extra'], $quote['part']]);
        $body = $blocks[1]['fields'][0];
        $this->assertFalse($body['editable']);
        $this->assertTrue($body['assembled']);

        $edited = $this->action('ghostwriter/sessions/edit-field', ['id' => $session->id, 'path' => json_encode($heading['path']), 'value' => 'Find it in seconds']);
        $this->assertSame(200, $edited['status']);
        $this->assertSame('Find it in seconds', Draft::parse($edited['data']['draft'])->data['blocks'][0]['heading']);
        $this->assertSame('p1', $edited['data']['layouts']['chosen'], 'The layout follows the words.');
        $this->assertSame(['hero', 'copy', 'pullQuote', 'copy', 'cta'], array_column($edited['data']['preview'][2]['items'], 'type'), 'However much it changed, it is the same heading in the same place.');
        $this->assertSame('Find it in seconds', $edited['data']['preview'][2]['items'][0]['fields'][0]['text']);

        $quoted = $this->action('ghostwriter/sessions/edit-extra', ['id' => $session->id, 'item' => 'x2.1', 'value' => 'Nobody knows the model number.']);
        $this->assertSame(200, $quoted['status'], json_encode($quoted['data']));
        $this->assertSame('Nobody knows the model number.', $quoted['data']['extras'][1]['items'][0]['text']);
        $this->assertSame('your words', $quoted['data']['extras'][1]['items'][0]['source']['label']);
        $this->assertSame('Nobody knows the model number.', $quoted['data']['preview'][2]['items'][2]['fields'][0]['text']);
        $this->fake->assertNothingSent();
    }

    public function testAnExtraLeftAloneKeepsItsCount(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());

        $same = $this->action('ghostwriter/sessions/edit-extra', ['id' => $session->id, 'item' => 'x1.1', 'part' => 'value', 'value' => '3']);
        $this->assertSame(200, $same['status']);
        $this->assertTrue($same['data']['extras'][0]['items'][0]['review'], 'Still to check.');

        // Left alone as stored (the chip's words, markers and all): still to check too.
        $stored = $this->action('ghostwriter/sessions/edit-extra', ['id' => $session->id, 'item' => 'x1.1', 'part' => 'value', 'value' => '[[check: 3 | from: size, finish and price]]']);
        $this->assertTrue($stored['data']['extras'][0]['items'][0]['review'], 'Still to check.');

        $changed = $this->action('ghostwriter/sessions/edit-extra', ['id' => $session->id, 'item' => 'x1.1', 'part' => 'label', 'value' => 'ways to narrow it']);
        $this->assertSame(['value' => '[[check: 3 | from: size, finish and price]]', 'label' => 'ways to narrow it'], $changed['data']['extras'][0]['items'][0]['parts']);

        $empty = $this->action('ghostwriter/sessions/edit-extra', ['id' => $session->id, 'item' => 'x1.1', 'value' => ' ']);
        $this->assertSame(422, $empty['status']);
    }

    public function testDeletingAnExtraRearrangesTheLayoutsThatUsedIt(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());
        $this->action('ghostwriter/sessions/choose-layout', ['id' => $session->id, 'plan' => 'p1']);

        $deleted = $this->action('ghostwriter/sessions/delete-extra', ['id' => $session->id, 'item' => 'x2']);

        $this->assertSame(200, $deleted['status'], json_encode($deleted['data']));
        $this->assertSame(['x1'], array_column($deleted['data']['extras'], 'id'));
        $session = $this->plugin->sessions->find($session->id);
        $this->assertStringNotContainsString('x2.1', (string) json_encode($session->plans), 'No layout places it any more.');
        $this->assertNotContains('pullQuote', array_column($deleted['data']['preview'][2]['items'], 'type'));

        $this->assertSame(422, $this->action('ghostwriter/sessions/delete-extra', ['id' => $session->id, 'item' => 'x9'])['status']);
    }

    public function testAStaleLayoutCantBeChosenUntilTheLayoutsAreRefreshed(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());
        $session->plans[2]['stale'] = true;
        $this->plugin->sessions->save($session);
        $this->fake->reset();

        $refused = $this->action('ghostwriter/sessions/choose-layout', ['id' => $session->id, 'plan' => 'p2']);
        $this->assertSame(409, $refused['status']);
        $this->assertTrue((new Presenter())->detail($this->plugin->sessions->find($session->id))['layouts']['plans'][2]['stale']);

        $this->clearQueue();
        $refresh = $this->action('ghostwriter/sessions/refresh-layouts', ['id' => $session->id]);

        $this->assertSame(200, $refresh['status'], json_encode($refresh['data']));
        $this->assertSame('working', $refresh['data']['status']);
        $this->assertTrue($refresh['data']['layouts']['planning']);
        $this->assertCount(1, $this->queued(RefreshLayouts::class));
        $this->assertSame(409, $this->action('ghostwriter/sessions/refresh-layouts', ['id' => $session->id])['status'], 'One run at a time.');

        $this->fake->respond('layout-planner', self::PAGE_PLANS);
        (new RefreshLayouts(['sessionId' => $session->id]))->execute(null);

        $this->assertSame(['layout-planner'], array_map(fn($request) => $request->agent, $this->fake->requests()), 'One call.');
        $session = $this->plugin->sessions->find($session->id);
        $this->assertSame(Session::IDLE, $session->status);
        $this->assertSame([false, false, false], array_map(fn($plan) => (bool) ($plan['stale'] ?? false), $session->plans));
        $this->assertSame(200, $this->action('ghostwriter/sessions/choose-layout', ['id' => $session->id, 'plan' => 'p2'])['status']);
    }

    public function testEditingTheDraftByHandCarriesTheLayoutsOver(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());
        $this->fake->reset();

        $yaml = str_replace("  - type: cta\n", "  - type: copy\n    body: A new closing line.\n  - type: cta\n", self::PAGE_DRAFT);
        $saved = $this->action('ghostwriter/sessions/draft', ['id' => $session->id, 'draft' => $yaml]);

        $this->assertSame(200, $saved['status'], json_encode($saved['data']));
        $session = $this->plugin->sessions->find($session->id);
        $this->assertSame('u8', $this->unitAt($session, 'blocks/3/heading'), 'Ids carried over the edit.');
        $this->assertSame('u10', $this->unitAt($session, 'blocks/2/body', 0));
        $this->assertStringContainsString('u10', (string) json_encode($session->plans[1]), 'The new words are in the other layouts too.');
        $this->fake->assertNothingSent();
    }

    public function testAGapIsResolvedFromItsChipInTheDraftWithoutAModel(): void
    {
        $target = $this->newDraft($this->pages);
        $session = $this->writeFirstDraft($this->servicePiece($target));
        $data = Draft::parse($session->draft)->data;
        $data['blocks'][0]['subheading'] = 'A search for [[ask: client name]], built in [[ask: client name]] weeks.';
        $data['blocks'][1]['body'] = str_replace('Fewer calls to the showroom.', 'Fewer calls to the showroom. [Ask us how](#gw-link:contact-page).', $data['blocks'][1]['body']);
        $session->draft = \Symfony\Component\Yaml\Yaml::dump($data, 20, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $this->plugin->sessions->save($session);
        $this->fake->reset();

        $resolve = fn(array $body) => $this->action('ghostwriter/sessions/resolve-gap', ['id' => $session->id] + $body);
        $draft = fn() => Draft::parse((string) $this->plugin->sessions->find($session->id)->draft)->data;

        // From the Preview: the second chip with that hint, answered exactly as typed.
        $second = $resolve(['kind' => 'ask', 'hint' => 'Client  Name', 'occurrence' => 1, 'value' => 'six (*about*)']);
        $this->assertSame(200, $second['status'], json_encode($second['data']));
        $this->assertSame('A search for [[ask: client name]], built in six (*about*) weeks.', $draft()['blocks'][0]['subheading']);

        // From the Text tab: by its path.
        $first = $resolve(['kind' => 'ask', 'hint' => 'client name', 'path' => json_encode(['blocks', 0, 'subheading']), 'value' => 'Hartley Kitchens']);
        $this->assertSame(200, $first['status']);
        $this->assertSame('A search for Hartley Kitchens, built in six (*about*) weeks.', $draft()['blocks'][0]['subheading']);
        $this->assertSame(422, $resolve(['kind' => 'ask', 'hint' => 'client name', 'value' => 'x'])['status']);

        // A count in an extra, confirmed in its text and its number.
        $count = $resolve(['kind' => 'check', 'hint' => '3', 'list' => 'size, finish and price', 'value' => '3']);
        $this->assertSame(200, $count['status'], json_encode($count['data']));
        $this->assertSame('3 filters', $count['data']['extras'][0]['items'][0]['text']);
        $this->assertSame(['value' => '3', 'label' => 'filters'], $count['data']['extras'][0]['items'][0]['parts']);
        $this->assertFalse($count['data']['extras'][0]['items'][0]['review']);

        // A link in the writing, and a link field the draft doesn't hold (kept by its hint).
        $this->assertSame(200, $resolve(['kind' => 'link', 'hint' => 'contact page', 'value' => '/contact'])['status']);
        $this->assertStringContainsString('[Ask us how](/contact).', $draft()['blocks'][1]['body']);
        $this->assertSame(200, $resolve(['kind' => 'link', 'hint' => 'button link', 'value' => '/contact', 'reference' => '{entry:1@1:url||/contact}'])['status']);
        $this->assertSame(['button link' => ['link' => '{entry:1@1:url||/contact}', 'url' => '/contact']], $draft()['gw_links']);

        // Use this draft: the chosen links aren't a field to build.
        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(200, $applied['status'], json_encode($applied['data']));
        $this->assertStringNotContainsString('gw_links', (string) json_encode($applied['data']));

        $links = $this->action('ghostwriter/sessions/links', ['id' => $session->id, 'q' => 'garden design'], 'GET');
        $this->assertSame(200, $links['status']);
        $this->assertArrayHasKey('entries', $links['data']);

        $this->fake->assertNothingSent();
    }

    private function unitAt(Session $session, string $path, ?int $part = null): ?string
    {
        foreach ($session->units['units'] ?? [] as $id => $unit) {
            if ($unit['path'] === $path && ($unit['part'] ?? null) === $part) {
                return $id;
            }
        }

        return null;
    }
}
