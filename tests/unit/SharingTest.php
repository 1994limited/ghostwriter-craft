<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\User;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\Launcher;
use nineteenninetyfour\ghostwriter\sessions\Session;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use nineteenninetyfour\ghostwriter\types\ContentType;

/**
 * Conversations are shared with everyone who may use Ghostwriter (decision
 * E7), unless sharedConversations is off, when each is kept to the person
 * who started it.
 */
class SharingTest extends TestCase
{
    use Sites;

    private User $ann;

    private User $bo;

    protected function _before(): void
    {
        parent::_before();

        $this->makeArticlesSection();
        $this->plugin->types->save(ContentType::fromArray('project', [
            'title' => 'Article',
            'section' => 'articles',
            'questions' => [['handle' => 'what', 'label' => 'What was built?', 'type' => 'textarea', 'required' => true]],
        ]));

        $this->bo = $this->person('Bo', 'Brown');
        $this->ann = $this->person('Ann', 'Archer');
    }

    public function testEveryoneWithGhostwriterSeesAndCarriesOnAPiece(): void
    {
        $session = $this->annsPiece();

        // Bo sees it on the dashboard and can open it.
        $this->as($this->bo);
        $this->assertContains($session->id, array_column($this->dashboardSessions(), 'id'));
        $this->assertStringContainsString('Started by Ann Archer', $this->dashboard());

        $shown = $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data'];
        $this->assertSame(['Ann Archer', 'Ghostwriter'], [$shown['messages'][1]['from'], $shown['messages'][2]['role'] === 'assistant' ? 'Ghostwriter' : null]);
        $this->assertFalse($shown['messages'][1]['mine']);
        $this->assertSame('Ann Archer', $shown['startedBy']);

        // Bo carries it on; his message is his.
        $sent = $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'Make it shorter.']);
        $this->assertSame(200, $sent['status']);
        $this->assertTrue($sent['data']['messages'][3]['mine']);
        $this->assertSame('Bo Brown', $sent['data']['messages'][3]['from']);

        // Ann sees who sent it, who last changed the piece, and that Bo is
        // waiting on Ghostwriter; she cannot send while his request runs.
        $this->as($this->ann);
        $shown = $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data'];
        $this->assertSame('Bo Brown', $shown['messages'][3]['from']);
        $this->assertSame(['you', 'Bo Brown', 'Bo Brown'], [$shown['startedBy'], $shown['touchedBy'], $shown['waitingOn']]);

        $refused = $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'And add a quote.']);
        $this->assertSame(409, $refused['status']);
        $this->assertSame('Bo Brown is waiting on Ghostwriter.', $refused['data']['message']);
        $this->assertCount(1, $this->queued(RunSessionTurn::class));

        // Bo himself is not told he is waiting on himself.
        $this->as($this->bo);
        $this->assertNull($this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data']['waitingOn']);
    }

    public function testTheEntryCarriesOnWithWhoeverStartedItsConversation(): void
    {
        Craft::$app->getRequest()->setIsCpRequest(true);
        $draft = $this->newDraft(Craft::$app->getEntries()->getSectionByHandle('articles'));
        $session = $this->annsPiece($draft->id);

        $this->as($this->bo, admin: true);
        $this->assertStringContainsString('"current":"' . $session->id . '"', $this->launcherScript($draft));

        $this->plugin->getSettings()->sharedConversations = false;
        $this->assertStringContainsString('"current":null', $this->launcherScript($draft));
    }

    public function testWithSharingOffEachPieceIsKeptToWhoeverStartedIt(): void
    {
        $this->plugin->getSettings()->sharedConversations = false;
        $session = $this->annsPiece();

        $this->as($this->bo);
        $this->assertNotContains($session->id, array_column($this->dashboardSessions(), 'id'));
        $this->assertStringNotContainsString('Started by', $this->dashboard());

        foreach (['show' => 'GET', 'message' => 'POST', 'retry' => 'POST', 'draft' => 'POST', 'delete' => 'POST'] as $action => $method) {
            $this->assertSame(404, $this->action("ghostwriter/sessions/{$action}", ['id' => $session->id, 'message' => 'Hi'], $method)['status'], $action);
        }

        // Ann's own, as before, with nobody else's name on anything.
        $this->as($this->ann);
        $shown = $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data'];
        $this->assertSame([null, null, null], [$shown['startedBy'], $shown['touchedBy'], $shown['waitingOn']]);
        $this->assertContains($session->id, array_column($this->dashboardSessions(), 'id'));
    }

    public function testTryingAgainRecordsWhoIsWaitingAndRunsOnce(): void
    {
        $session = $this->annsPiece();
        $session->status = Session::FAILED;
        $session->error = 'The provider is overloaded.';
        $session->addMessage('user', 'Make it shorter.', $this->ann->id);
        $this->plugin->sessions->save($session);

        // Bo tries Ann's failed turn again: he is the one waiting now.
        $this->as($this->bo);
        $retried = $this->action('ghostwriter/sessions/retry', ['id' => $session->id]);
        $this->assertSame(200, $retried['status']);
        $this->assertSame($this->bo->id, $this->plugin->sessions->find($session->id)->runBy);

        // Ann sees him waiting, and can neither try again nor send meanwhile.
        $this->as($this->ann);
        $this->assertSame('Bo Brown', $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data']['waitingOn']);
        $this->assertSame('Bo Brown is waiting on Ghostwriter.', $this->action('ghostwriter/sessions/retry', ['id' => $session->id])['data']['message']);
        $this->assertSame(409, $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'And add a quote.'])['status']);
        $this->assertCount(1, $this->queued(RunSessionTurn::class));
    }

    public function testWithSharingOffThePlanDoesNotOpenOrNameSomeoneElsesPiece(): void
    {
        $this->plugin->getSettings()->sharedConversations = false;
        $session = $this->annsPiece();
        $session->run($this->ann->id);
        $this->plugin->sessions->save($session);

        $ideas = $this->plugin->ideas;
        $ideas->update($ideas->add(['title' => 'Faceted search', 'section' => 'articles'])['id'], ['status' => \nineteenninetyfour\ghostwriter\planning\IdeaRepository::DRAFTED, 'session' => $session->id]);

        $this->as($this->bo);
        $idea = $this->action('ghostwriter/plan/status', method: 'GET')['data']['ideas'][0];
        $this->assertSame('drafted', $idea['status']);
        $this->assertSame([null, null, null, null], [$idea['resumeUrl'], $idea['startedBy'], $idea['touchedBy'], $idea['waitingOn']]);

        // Shared, Bo can pick it up and sees whose it is.
        $this->plugin->getSettings()->sharedConversations = true;
        $idea = $this->action('ghostwriter/plan/status', method: 'GET')['data']['ideas'][0];
        $this->assertSame(['Ann Archer', 'Ann Archer'], [$idea['startedBy'], $idea['waitingOn']]);
    }

    public function testSomeoneWithoutGhostwriterSeesNothing(): void
    {
        $session = $this->annsPiece();

        $this->signIn(permitted: false);

        $this->assertSame(403, $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['status']);
    }

    /**
     * A piece Ann started and Ghostwriter answered, waiting for more.
     */
    private function annsPiece(?int $elementId = null): Session
    {
        $session = Session::start('project', ['what' => 'A faceted search.'], $this->ann->id);
        $session->elementId = $elementId;
        $session->addMessage('user', 'The brief.', $this->ann->id);
        $session->addMessage('user', 'Who is it for?', $this->ann->id);
        $session->addMessage('assistant', 'Shall I draft it?');

        return $this->plugin->sessions->save($session);
    }

    private function person(string $first, string $last): User
    {
        $user = $this->signIn();
        $user->firstName = $first;
        $user->lastName = $last;
        Craft::$app->getElements()->saveElement($user, false);

        return $user;
    }

    private function as(User $user, bool $admin = false): void
    {
        $user->admin = $admin;
        Craft::$app->getUser()->setIdentity($user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dashboardSessions(): array
    {
        return $this->plugin->sessions->visibleTo((int) Craft::$app->getUser()->getId());
    }

    private function dashboard(): string
    {
        Craft::$app->getView()->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
        $response = $this->action('ghostwriter/dashboard/index', method: 'GET');

        return Craft::$app->getView()->renderPageTemplate($response['data']['template'], $response['data']['variables'], \craft\web\View::TEMPLATE_MODE_CP);
    }

    private function launcherScript(\craft\elements\Entry $entry): string
    {
        $view = Craft::$app->getView();
        $view->js = [];
        Launcher::buttonFor($entry);

        return implode("\n", array_merge(...array_values($view->js ?: [[]])));
    }
}
