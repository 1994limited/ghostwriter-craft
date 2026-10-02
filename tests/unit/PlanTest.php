<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\jobs\SuggestIdeas;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The content plan: ideas for what is missing, and drafting from one.
 */
class PlanTest extends TestCase
{
    use Sites;

    protected function _before(): void
    {
        parent::_before();

        $this->makeArticlesSection();
        $this->makeArticle('What Does a Website Cost?', 'A paragraph about cost that is long enough to count as a sample.');
        $this->plugin->types->save($this->plugin->types->make('guide', ['title' => 'Guide', 'section' => 'articles', 'questions' => [['handle' => 'what', 'label' => 'What is it about?', 'required' => true]]]));
    }

    public function testGhostwriterSuggestsWhatTheSiteIsMissing(): void
    {
        $plan = $this->plugin->domain->plan();
        $plan->add(['title' => 'Rebuild or repair?', 'section' => 'articles']);
        $plan->dismiss($plan->add(['title' => 'Our office dog', 'section' => 'articles'])->id);

        $this->fake->respond('planner', "<ideas>\n- title: How to brief a web agency\n  collection: articles\n  type: guide\n  why: The cost guide sends readers off to get quotes with nothing on how to ask for one.\n  notes: For an owner about to approach agencies. [Add a brief we thought was good]\n- title: Rebuild or repair?\n  collection: articles\n- title: A page about nothing\n  collection: nowhere\n- title: Slow site, lost sale\n  section: articles\n  type: made-up\n</ideas>");

        (new SuggestIdeas(['sections' => ['articles'], 'steer' => 'More for owners.']))->execute(null);

        // Two suggestions wait to be looked over; the repeat and the one for
        // a section not planned for are left out. Nothing is on the plan yet.
        $pending = $plan->state()->pending;

        $this->assertSame(['How to brief a web agency', 'Slow site, lost sale'], array_column($pending, 'title'));
        $this->assertSame('guide', $pending[0]['type']);
        $this->assertNull($pending[1]['type']);
        $this->assertCount(2, $this->plugin->plans->ideas());

        // It was shown what exists, what is planned and what was turned down.
        $request = $this->fake->prompted('planner')[0];
        $this->assertStringContainsString('What I am looking for this time: More for owners.', $request->prompt);
        $this->assertStringContainsString('- What Does a Website Cost?: A summary line about What Does a Website Cost?', $request->instructions);
        $this->assertStringContainsString('- `guide`: Guide.', $request->instructions);
        $this->assertStringContainsString('- Our office dog (articles, dismissed)', $request->instructions);

        // The person keeps the first and not the second.
        $this->signIn();
        $this->assertSame([], $this->action('ghostwriter/plan/accept', ['chosen' => [0]])['data']['pending']);

        $this->assertSame([
            'Rebuild or repair?' => 'open',
            'Our office dog' => 'dismissed',
            'How to brief a web agency' => 'open',
            'Slow site, lost sale' => 'dismissed',
        ], array_column($this->plugin->plans->ideas(), 'status', 'title'));
        $this->assertSame('idle', $plan->state()->status);
    }

    public function testThePlanScreenAddsDismissesAndStartsASearch(): void
    {
        $this->signIn();

        $this->assertSame(422, $this->action('ghostwriter/plan/add', ['title' => 'Nowhere', 'section' => 'nowhere'])['status']);

        $idea = $this->action('ghostwriter/plan/add', ['title' => 'Rebuild or repair?', 'section' => 'articles', 'notes' => 'For owners.'])['data']['ideas'][0];

        $this->assertSame('Articles', $idea['sectionTitle']);
        $this->assertStringContainsString('ghostwriter/write/articles', $idea['draftUrl']);
        $this->assertStringContainsString('idea=' . $idea['id'], $idea['draftUrl']);

        $this->assertSame('dismissed', $this->action('ghostwriter/plan/update', ['id' => $idea['id'], 'status' => 'dismissed'])['data']['ideas'][0]['status']);
        $this->assertSame('open', $this->action('ghostwriter/plan/update', ['id' => $idea['id'], 'status' => 'open'])['data']['ideas'][0]['status']);
        $this->assertSame(422, $this->action('ghostwriter/plan/update', ['id' => $idea['id'], 'status' => 'published'])['status']);

        $this->assertSame('working', $this->action('ghostwriter/plan/suggest', ['sections' => ['articles', 'nowhere'], 'steer' => 'Ecommerce.'])['data']['status']);

        $job = $this->queued(SuggestIdeas::class)[0];
        $this->assertSame(['articles'], $job->sections);
        $this->assertSame('Ecommerce.', $job->steer);

        // The plan is kept in the database, one row per idea.
        $this->assertCount(count($this->plugin->plans->ideas()), $this->plugin->store->documents('idea'));
        $this->assertFileDoesNotExist($this->workspace . '/guides/ideas.yaml');
    }

    public function testIdeasComeNewestFirst(): void
    {
        $this->signIn();

        foreach (['First', 'Second', 'Third'] as $title) {
            $this->plugin->domain->plan()->add(['title' => $title, 'section' => 'articles']);
        }

        $this->assertSame(['Third', 'Second', 'First'], array_column($this->action('ghostwriter/plan/status', method: 'GET')['data']['ideas'], 'title'));
    }

    public function testSuggestionsWaitUntilTheyAreDecided(): void
    {
        $this->signIn();
        $this->plugin->domain->plan()->changeState(fn(PlanState $state) => $state->pending = [
            ['title' => 'Rebuild or repair?', 'section' => 'articles', 'type' => null, 'why' => 'Asked often.', 'notes' => ''],
            ['title' => 'Our office dog', 'section' => 'articles', 'type' => null, 'why' => '', 'notes' => ''],
        ]);

        // Still there on the next visit: the screen has them to show.
        $screen = $this->action('ghostwriter/plan/show', method: 'GET')['data']['variables'];
        $this->assertCount(2, $screen['config']['plan']['pending']);
        $this->assertCount(2, $this->action('ghostwriter/plan/status', method: 'GET')['data']['pending']);

        // None ticked: every one is kept as dismissed, so not suggested again.
        $data = $this->action('ghostwriter/plan/accept', ['chosen' => []])['data'];
        $this->assertSame([], $data['pending']);
        $this->assertSame(['dismissed', 'dismissed'], array_column($data['ideas'], 'status'));
    }

    public function testANewBatchJoinsTheOneWaiting(): void
    {
        $plan = $this->plugin->domain->plan();
        $plan->changeState(fn(PlanState $state) => $state->pending = [['title' => 'Rebuild or repair?', 'section' => 'articles', 'type' => null, 'why' => '', 'notes' => '']]);

        $this->fake->respond('planner', "<ideas>\n- title: How to brief a web agency\n  collection: articles\n- title: rebuild or repair?\n  collection: articles\n</ideas>");

        (new SuggestIdeas(['sections' => ['articles']]))->execute(null);

        // Kept until someone decides (E3), and no title twice.
        $this->assertSame(['Rebuild or repair?', 'How to brief a web agency'], array_column($plan->state()->pending, 'title'));
    }

    public function testADismissedIdeaOrAnUnfinishedPieceIsPutBack(): void
    {
        $this->signIn();
        $plan = $this->plugin->domain->plan();
        $me = \Craft::$app->getUser()->getId();
        $open = $plan->add(['title' => 'Open', 'section' => 'articles']);
        $dismissed = $plan->dismiss($plan->add(['title' => 'Dismissed', 'section' => 'articles'])->id);
        $piece = $this->plugin->sessions->save(Session::start(Format::Craft, 'guide', [], $me));
        $started = $plan->start($plan->add(['title' => 'Started', 'section' => 'articles'])->id, $piece->id);

        // A finished piece: its entry has been saved (E6).
        $done = Session::start(Format::Craft, 'guide', [], $me);
        $done->recordId = $this->makeArticle('Written already', 'A paragraph that is long enough to count as the piece itself.')->id;
        $done = $this->plugin->sessions->save($done);
        $finished = $plan->start($plan->add(['title' => 'Finished', 'section' => 'articles'])->id, $done->id);

        $shown = array_column($this->action('ghostwriter/plan/status', method: 'GET')['data']['ideas'], 'finished', 'title');
        $this->assertSame([false, true], [$shown['Started'], $shown['Finished']]);

        // A finished piece stays where it is (E8), and an open idea is already back.
        $refused = $this->action('ghostwriter/plan/update', ['id' => $finished->id, 'status' => 'open']);
        $this->assertSame([409, "A finished piece can't be put back on the plan."], [$refused['status'], $refused['data']['message']]);
        $this->assertSame(409, $this->action('ghostwriter/plan/update', ['id' => $open->id, 'status' => 'open'])['status']);
        $this->assertSame([Idea::DRAFTED, $done->id], [$this->plugin->plans->find($finished->id)->status, (string) $this->plugin->plans->find($finished->id)->session]);

        // Back to ideas (E5): a started piece that isn't finished.
        $this->assertSame(200, $this->action('ghostwriter/plan/update', ['id' => $started->id, 'status' => 'open'])['status']);
        $this->assertSame([Idea::OPEN, null], [$this->plugin->plans->find($started->id)->status, $this->plugin->plans->find($started->id)->session]);

        // Put back: a dismissed idea.
        $this->assertSame(200, $this->action('ghostwriter/plan/update', ['id' => $dismissed->id, 'status' => 'open'])['status']);
        $this->assertSame(Idea::OPEN, $this->plugin->plans->find($dismissed->id)->status);

        $this->assertSame(404, $this->action('ghostwriter/plan/update', ['id' => 'nothing', 'title' => 'X'])['status']);

        // Words change without the state.
        $this->action('ghostwriter/plan/update', ['id' => $finished->id, 'title' => ' Finished, renamed ']);
        $this->assertSame(['Finished, renamed', Idea::DRAFTED], [$this->plugin->plans->find($finished->id)->title, $this->plugin->plans->find($finished->id)->status]);
    }

    public function testTheListCanBeClearedWithoutTouchingStartedPieces(): void
    {
        $this->signIn();

        $plan = $this->plugin->domain->plan();
        $plan->add(['title' => 'One', 'section' => 'articles']);
        $plan->add(['title' => 'Two', 'section' => 'articles']);
        $piece = $this->plugin->sessions->save(Session::start(Format::Craft, 'guide', [], \Craft::$app->getUser()->getId()));
        $plan->start($plan->add(['title' => 'Started', 'section' => 'articles'])->id, $piece->id);
        $plan->dismiss($plan->add(['title' => 'No', 'section' => 'articles'])->id);

        $this->assertSame(422, $this->action('ghostwriter/plan/clear', ['status' => 'drafted'])['status']);
        $this->action('ghostwriter/plan/clear', ['status' => 'open']);

        $this->assertSame(['Started' => 'drafted', 'No' => 'dismissed'], array_column($this->plugin->plans->ideas(), 'status', 'title'));
    }

    public function testAnIdeaIsOfferedOnTheEntryAndMarkedDraftedWhenStarted(): void
    {
        $this->signIn(admin: true);

        $idea = $this->plugin->domain->plan()->add(['title' => 'Rebuild or repair?', 'section' => 'articles', 'why' => 'Nothing on it yet.']);

        $offered = $this->action('ghostwriter/sections/show', ['section' => 'articles'], 'GET')['data']['ideas'];
        $this->assertSame([['id' => $idea->id, 'title' => 'Rebuild or repair?', 'type' => null, 'why' => 'Nothing on it yet.', 'notes' => '']], $offered);

        $target = $this->newDraft($this->articles);
        $session = $this->action('ghostwriter/sessions/start', ['type' => 'guide', 'answers' => ['what' => 'Whether to rebuild.'], 'idea' => $idea->id, 'elementId' => $target->id])['data']['id'];

        $idea = $this->plugin->plans->find($idea->id);
        $this->assertSame(Idea::DRAFTED, $idea->status);
        $this->assertSame($session, $idea->session);

        // No longer offered as something to write.
        $this->assertSame([], $this->action('ghostwriter/sections/show', ['section' => 'articles'], 'GET')['data']['ideas']);

        // The plan shows where it has got to and how to pick it back up.
        $planned = $this->action('ghostwriter/plan/status', method: 'GET')['data']['ideas'][0];

        $this->assertSame('working', $planned['stage']);
        $this->assertFalse($planned['finished']);
        $this->assertStringContainsString('ghostwriter=' . $session, $planned['resumeUrl']);
        $this->assertCount(1, $this->queued(RunSessionTurn::class));

        // Remove the conversation and it is an idea again.
        $this->action('ghostwriter/sessions/delete', ['id' => $session]);
        $this->assertSame('open', $this->action('ghostwriter/plan/status', method: 'GET')['data']['ideas'][0]['status']);
    }

    public function testTheNewEntryLinkOpensThePanelOnTheIdea(): void
    {
        $this->signIn(admin: true);

        $idea = $this->plugin->domain->plan()->add(['title' => 'Rebuild or repair?', 'section' => 'articles']);

        $this->action('ghostwriter/sections/new', ['section' => 'articles', 'idea' => $idea->id], 'GET', json: false);

        $location = (string) \Craft::$app->getResponse()->getHeaders()->get('location');

        $this->assertStringContainsString('ghostwriter=new', $location);
        $this->assertStringContainsString('idea=' . $idea->id, $location);
    }
}
