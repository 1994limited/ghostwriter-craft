<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\ApplyComments;
use nineteenninetyfour\ghostwriter\tests\support\Pages;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Comments on the page (page preview design §9, decision 9): pins not sent
 * yet are the panel's; Apply sends them as one message in the conversation,
 * one reviser call answers each, and Put back and Resolve act on the
 * answer. Every model reply is the fake's.
 */
class CommentsTest extends TestCase
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

    public function testApplySendsOneMessageAndOneCallAnswersEachComment(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());
        $this->fake->reset();
        $this->clearQueue();

        $this->fake->respond('reviser', "<changes>\n- comment: 1\n  reply: Warmer.\n  units:\n    u8: Talk to us about your search\n- comment: 2\n  reply: Said how.\n  replace:\n    - unit: u6\n      exact: \"A search that narrows the range by size, finish and price.\"\n      with: \"A search that quickly narrows the range by size, finish and price.\"\n- comment: 3\n  reply: I can’t change a photo from a comment.\n- comment: 4\n  reply: Added the figure.\n  units:\n    u7: Calls to the showroom fell by 40%.\n</changes>");

        $started = $this->apply($session, [
            ['kind' => 'block', 'units' => ['u8', 'u9'], 'label' => 'Call to action', 'body' => 'Warmer, please.'],
            ['kind' => 'text', 'units' => ['u5', 'u6', 'u7'], 'label' => 'Copy', 'quote' => ['exact' => 'A search that narrows the range by size, finish and price.', 'prefix' => 'What we built ', 'suffix' => ''], 'body' => 'Say <b>how</b>.'],
            ['kind' => 'page', 'body' => 'Is the photo right?'],
            ['kind' => 'block', 'units' => ['u7'], 'label' => 'Copy', 'body' => 'Say how much fewer.'],
        ]);

        $this->assertSame(200, $started['status'], json_encode($started['data']));
        $this->assertSame('working', $started['data']['status']);
        $message = end($started['data']['messages']);
        $this->assertSame(['user', [1, 2, 3, 4]], [$message['role'], array_column($message['comments']['items'], 'number')]);
        $this->assertSame(['u6'], $message['comments']['items'][1]['scope']['units'], 'words are anchored to the one unit that holds them');
        $this->assertSame(['sending', 'sending', 'sending', 'sending'], array_column($started['data']['comments']['pins'], 'status'));
        $this->assertCount(1, $this->queued(ApplyComments::class));

        // While it runs, a message and a second Apply wait.
        $this->assertSame(409, $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'Hello'])['status']);
        $this->assertSame(409, $this->apply($session, [['kind' => 'page', 'body' => 'Shorter.']])['status']);

        $this->runQueue();

        $this->assertSame(['reviser'], array_map(fn($request) => $request->agent, $this->fake->requests()));
        $detail = (new Presenter())->detail($this->plugin->sessions->find($session->id));
        $pins = $detail['comments']['pins'];
        $this->assertSame('idle', $detail['status']);
        $this->assertSame(['changed', 'changed', 'replied', 'refused'], array_column($pins, 'status'));
        $this->assertStringContainsString('Talk to us about your search', (string) $detail['draft']);
        $this->assertStringContainsString('quickly narrows', (string) $detail['draft']);
        $this->assertStringNotContainsString('40%', (string) $detail['draft'], 'a figure nobody gave is refused');
        $this->assertStringContainsString('40%', (string) $pins[3]['reply']);
        $this->assertContains(['+', 'your '], $pins[0]['changes'][0]['diff']);
        $this->assertSame('<p>Warmer.</p>', $pins[0]['reply']);
        $this->assertSame('Say <b>how</b>.', $pins[1]['body'], 'as written; the panel escapes it');

        // Put back, resolve, reopen: no model.
        $this->fake->reset();
        $answer = $pins[0]['answer'];
        $back = $this->action('ghostwriter/comments/put-back', ['id' => $session->id, 'answer' => $answer, 'number' => 1]);
        $this->assertSame(200, $back['status']);
        $this->assertStringNotContainsString('your search', (string) $back['data']['draft']);
        $this->assertSame(['changed', 'You', false], [$back['data']['comments']['pins'][0]['status'], $back['data']['comments']['pins'][0]['putBackBy'], $back['data']['comments']['pins'][0]['canPutBack']]);
        $this->assertSame(409, $this->action('ghostwriter/comments/put-back', ['id' => $session->id, 'answer' => $answer, 'number' => 1])['status']);

        $resolved = $this->action('ghostwriter/comments/resolve', ['id' => $session->id, 'answer' => $answer, 'number' => 1]);
        $this->assertSame(['resolved', 'You'], [$resolved['data']['comments']['pins'][0]['status'], $resolved['data']['comments']['pins'][0]['resolvedBy']]);
        $reopened = $this->action('ghostwriter/comments/resolve', ['id' => $session->id, 'answer' => $answer, 'number' => 1, 'resolved' => false]);
        $this->assertSame('changed', $reopened['data']['comments']['pins'][0]['status']);
        $this->assertSame(404, $this->action('ghostwriter/comments/resolve', ['id' => $session->id, 'answer' => $answer, 'number' => 9])['status']);

        // In another layout, the same comments sit on its blocks.
        $this->action('ghostwriter/sessions/choose-layout', ['id' => $session->id, 'plan' => 'p1']);
        $detail = (new Presenter())->detail($this->plugin->sessions->find($session->id));
        $this->assertSame(['blocks/4'], $detail['comments']['pins'][0]['blocks']);
        $this->fake->assertNothingSent();
    }

    public function testARunThatFailsAnswersEveryCommentAndFreesThePiece(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());
        $this->clearQueue();
        $this->fake->respond('reviser', fn() => throw new ProviderException('The provider is busy.'));

        $this->assertSame(200, $this->apply($session, [['kind' => 'block', 'units' => ['u8'], 'label' => 'Call to action', 'body' => 'Warmer.']])['status']);
        $this->runQueue();

        $detail = (new Presenter())->detail($this->plugin->sessions->find($session->id));
        $this->assertSame(['idle', 'failed'], [$detail['status'], $detail['comments']['pins'][0]['status']]);
        $this->assertStringContainsString('The provider is busy.', (string) $detail['comments']['pins'][0]['reply']);
    }

    public function testRefusalsSayWhyNextToTheirPin(): void
    {
        $session = $this->writeFirstDraft($this->servicePiece());
        $ok = ['kind' => 'block', 'units' => ['u3'], 'body' => 'Fine.'];

        $this->assertSame(['comments' => ['There are no comments to apply.']], $this->apply($session, [])['data']['errors']);
        $this->assertSame(['comments.1.body' => ['Write the comment first.']], $this->apply($session, [$ok, ['kind' => 'block', 'units' => ['u3'], 'body' => "  \n "]])['data']['errors']);
        $this->assertSame(['comments.0.body' => ['A comment can be at most 2000 characters.']], $this->apply($session, [['kind' => 'block', 'units' => ['u3'], 'body' => str_repeat('a', 2001)]])['data']['errors']);
        $this->assertSame(['comments.0' => ['Say what the comment is on: a block, some words or the whole page.']], $this->apply($session, [['units' => ['u3'], 'body' => 'Hm.']])['data']['errors']);
        $this->assertSame(['comments.1' => ['That block has no writing of its own to comment on. Comment on the whole page instead.']], $this->apply($session, [$ok, ['kind' => 'block', 'units' => ['u99'], 'body' => 'Hm.']])['data']['errors']);
        $this->assertSame(['comments' => ['Apply at most 12 comments at a time.']], $this->apply($session, array_fill(0, 13, $ok))['data']['errors']);
        $this->assertSame(422, $this->apply($session, [])['status']);
        $this->assertFalse($this->plugin->sessions->find($session->id)->isWorking());
    }

    public function testAPieceFromBeforeCommentsTakesThem(): void
    {
        // A draft with no unit ids or layouts stored: an entry edited before comments.
        $session = $this->servicePiece();
        $session->draft = self::PAGE_DRAFT;
        $session->status = Session::IDLE;
        $session = $this->plugin->sessions->save($session);
        $this->assertSame([[], []], [$session->units, $session->plans]);
        $this->clearQueue();

        $marker = "\u{E0067}\u{E0077}\u{E0062}\u{E0032}\u{E007F}";
        $result = $this->apply($session, [
            ['kind' => 'block', 'units' => ['u3', 'u4'], 'label' => 'Hero', 'planPath' => 'blocks/0', 'body' => 'Warmer.'],
            ['kind' => 'text', 'units' => ['u5', 'u6', 'u7'], 'label' => 'Copy', 'quote' => ['exact' => "Fewer calls to the showroom.{$marker}"], 'body' => "Say how many.{$marker}"],
        ]);

        $this->assertSame(200, $result['status'], json_encode($result['data']));
        $pins = $result['data']['comments']['pins'];
        $this->assertSame([['u3', 'u4'], ['u7']], array_column($pins, 'units'));
        $this->assertSame([true, true], array_column($pins, 'inLayout'));
        $this->assertSame(['Fewer calls to the showroom.', 'Say how many.'], [$pins[1]['quote'], $pins[1]['body']]);
    }

    /**
     * @param list<array<string, mixed>> $comments
     * @return array{status: int, data: array<string, mixed>}
     */
    private function apply(Session $session, array $comments): array
    {
        return $this->action('ghostwriter/comments/apply', ['id' => $session->id, 'comments' => $comments]);
    }
}
