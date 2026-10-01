<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\jobs\SuggestIdeas;
use nineteenninetyfour\ghostwriter\planning\IdeaRepository;
use nineteenninetyfour\ghostwriter\planning\PlanState;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use nineteenninetyfour\ghostwriter\types\ContentType;

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
        $this->plugin->types->save(ContentType::fromArray('guide', ['title' => 'Guide', 'section' => 'articles', 'questions' => [['handle' => 'what', 'label' => 'What is it about?', 'required' => true]]]));
    }

    public function testGhostwriterSuggestsWhatTheSiteIsMissing(): void
    {
        $ideas = $this->plugin->ideas;
        $ideas->add(['title' => 'Rebuild or repair?', 'section' => 'articles']);
        $ideas->update($ideas->add(['title' => 'Our office dog', 'section' => 'articles'])['id'], ['status' => IdeaRepository::DISMISSED]);

        $this->fake->respond('planner', "<ideas>\n- title: How to brief a web agency\n  collection: articles\n  type: guide\n  why: The cost guide sends readers off to get quotes with nothing on how to ask for one.\n  notes: For an owner about to approach agencies. [Add a brief we thought was good]\n- title: Rebuild or repair?\n  collection: articles\n- title: A page about nothing\n  collection: nowhere\n- title: Slow site, lost sale\n  section: articles\n  type: made-up\n</ideas>");

        (new SuggestIdeas(['sections' => ['articles'], 'steer' => 'More for owners.']))->execute(null);

        // Two suggestions wait to be looked over; the repeat and the one for
        // a section not planned for are left out. Nothing is on the plan yet.
        $pending = $this->plugin->planState->get()['pending'];

        $this->assertSame(['How to brief a web agency', 'Slow site, lost sale'], array_column($pending, 'title'));
        $this->assertSame('guide', $pending[0]['type']);
        $this->assertNull($pending[1]['type']);
        $this->assertCount(2, $ideas->all());

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
        ], array_column(array_values($ideas->all()), 'status', 'title'));
        $this->assertSame(PlanState::IDLE, $this->plugin->planState->get()['status']);
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

        $this->assertSame(PlanState::WORKING, $this->action('ghostwriter/plan/suggest', ['sections' => ['articles', 'nowhere'], 'steer' => 'Ecommerce.'])['data']['status']);

        $job = $this->queued(SuggestIdeas::class)[0];
        $this->assertSame(['articles'], $job->sections);
        $this->assertSame('Ecommerce.', $job->steer);

        // The plan is a file in the project, versioned with the site.
        $this->assertFileExists($this->workspace . '/guides/ideas.yaml');
    }

    public function testTheListCanBeClearedWithoutTouchingStartedPieces(): void
    {
        $this->signIn();

        $ideas = $this->plugin->ideas;
        $ideas->add(['title' => 'One', 'section' => 'articles']);
        $ideas->add(['title' => 'Two', 'section' => 'articles']);
        $ideas->update($ideas->add(['title' => 'Started', 'section' => 'articles'])['id'], ['status' => IdeaRepository::DRAFTED, 'session' => 'x']);
        $ideas->update($ideas->add(['title' => 'No', 'section' => 'articles'])['id'], ['status' => IdeaRepository::DISMISSED]);

        $this->assertSame(422, $this->action('ghostwriter/plan/clear', ['status' => 'drafted'])['status']);
        $this->action('ghostwriter/plan/clear', ['status' => 'open']);

        $this->assertSame(['Started' => 'drafted', 'No' => 'dismissed'], array_column(array_values($ideas->all()), 'status', 'title'));
    }

    public function testAnIdeaIsOfferedOnTheEntryAndMarkedDraftedWhenStarted(): void
    {
        $this->signIn(admin: true);

        $idea = $this->plugin->ideas->add(['title' => 'Rebuild or repair?', 'section' => 'articles', 'why' => 'Nothing on it yet.']);

        $offered = $this->action('ghostwriter/sections/show', ['section' => 'articles'], 'GET')['data']['ideas'];
        $this->assertSame([['id' => $idea['id'], 'title' => 'Rebuild or repair?', 'type' => null, 'why' => 'Nothing on it yet.', 'notes' => '']], $offered);

        $target = $this->newDraft($this->articles);
        $session = $this->action('ghostwriter/sessions/start', ['type' => 'guide', 'answers' => ['what' => 'Whether to rebuild.'], 'idea' => $idea['id'], 'elementId' => $target->id])['data']['id'];

        $idea = $this->plugin->ideas->find($idea['id']);
        $this->assertSame(IdeaRepository::DRAFTED, $idea['status']);
        $this->assertSame($session, $idea['session']);

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

        $idea = $this->plugin->ideas->add(['title' => 'Rebuild or repair?', 'section' => 'articles']);

        $this->action('ghostwriter/sections/new', ['section' => 'articles', 'idea' => $idea['id']], 'GET', json: false);

        $location = (string) \Craft::$app->getResponse()->getHeaders()->get('location');

        $this->assertStringContainsString('ghostwriter=new', $location);
        $this->assertStringContainsString('idea=' . $idea['id'], $location);
    }
}
