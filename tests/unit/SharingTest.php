<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\User;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\Launcher;
use nineteenninetyfour\ghostwriter\tests\support\RecordingMutex;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

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
        $this->plugin->types->save($this->plugin->types->make('project', [
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
        $this->assertSame(['Ann Archer', 'Ghostwriter'], [$shown['messages'][0]['from'], $shown['messages'][1]['role'] === 'assistant' ? 'Ghostwriter' : null]);
        $this->assertFalse($shown['messages'][0]['mine']);
        $this->assertSame('Ann Archer', $shown['startedBy']);

        // Bo carries it on; his message is his.
        $sent = $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'Make it shorter.']);
        $this->assertSame(200, $sent['status']);
        $this->assertTrue($sent['data']['messages'][2]['mine']);
        $this->assertSame('Bo Brown', $sent['data']['messages'][2]['from']);

        // Ann sees who sent it, who last changed the piece, and that Bo is
        // waiting on Ghostwriter; she cannot send while his request runs.
        $this->as($this->ann);
        $shown = $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data'];
        $this->assertSame('Bo Brown', $shown['messages'][2]['from']);
        $this->assertSame(['you', 'Bo Brown', 'Bo Brown'], [$shown['startedBy'], $shown['touchedBy'], $shown['waitingOn']]);

        $refused = $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'And add a quote.']);
        $this->assertSame(409, $refused['status']);
        $this->assertSame('Bo Brown is waiting on Ghostwriter.', $refused['data']['message']);
        $this->assertCount(1, $this->queued(RunSessionTurn::class));

        // Bo himself is not told he is waiting on himself.
        $this->as($this->bo);
        $this->assertNull($this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data']['waitingOn']);
    }

    public function testEveryoneSeesTheBriefCardInTheThreadAndCanAgreeToIt(): void
    {
        $session = Session::start(Format::Craft, 'project', [], $this->ann->id);
        $this->as($this->ann);
        $session = $this->plugin->domain->sessions()->open($session, $this->plugin->domain->viewer());
        $session = $this->plugin->domain->sessions()->details($session->id, 'A faceted search for a kitchen maker.', $this->plugin->domain->viewer());
        $this->plugin->domain->sessions()->propose($session->id, new Brief('Faceted search', ['what' => 'A search that narrows the range. [Add: the client]']));

        // Bo sees Ann's question, her reply and the card, as she does.
        $this->as($this->bo);
        $shown = $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data'];

        $this->assertSame(['ask', 'details', 'card'], array_column($shown['messages'], 'step'));
        $this->assertSame('Ann Archer', $shown['messages'][1]['from']);
        $this->assertSame('Faceted search', $shown['card']['title']);
        $this->assertSame(['what'], $shown['card']['open']);

        // And can agree to it; the writing is then his to wait on.
        $agreed = $this->action('ghostwriter/sessions/agree', ['id' => $session->id, 'answers' => ['what' => 'A search that narrows the range for Hearth & Co.']]);
        $this->assertSame(200, $agreed['status']);
        $this->assertTrue($agreed['data']['card']['agreed']);

        $this->as($this->ann);
        $this->assertSame('Bo Brown', $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data']['waitingOn']);
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
        $session->fail('The provider is overloaded.');
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
        $session->claim($this->ann->id, new DomainOptions(Format::Craft));
        $this->plugin->sessions->save($session);

        $plan = $this->plugin->domain->plan();
        $plan->start($plan->add(['title' => 'Faceted search', 'section' => 'articles'])->id, $session->id);

        $this->as($this->bo);
        $idea = $this->action('ghostwriter/plan/status', method: 'GET')['data']['ideas'][0];
        $this->assertSame('drafted', $idea['status']);
        $this->assertSame([null, null, null, null], [$idea['resumeUrl'], $idea['startedBy'], $idea['touchedBy'], $idea['waitingOn']]);

        // Shared, Bo can pick it up and sees whose it is.
        $this->plugin->getSettings()->sharedConversations = true;
        $idea = $this->action('ghostwriter/plan/status', method: 'GET')['data']['ideas'][0];
        $this->assertSame(['Ann Archer', 'Ann Archer'], [$idea['startedBy'], $idea['waitingOn']]);
    }

    public function testOnlyWhoeverStartedASharedPieceOrAnAdminCanRemoveIt(): void
    {
        $session = $this->annsPiece();

        // Bo can carry Ann's piece on, but not remove it: no button, and
        // the request is refused.
        $this->as($this->bo);
        $this->assertFalse($this->summary($session->id)['canDelete']);
        $this->assertStringNotContainsString('data-remove-session="' . $session->id . '"', $this->dashboard());
        $this->assertSame(403, $this->action('ghostwriter/sessions/delete', ['id' => $session->id])['status']);
        $this->assertNotNull($this->plugin->sessions->find($session->id));
        $this->assertSame(200, $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'Make it shorter.'])['status']);

        // Ann started it, so she can.
        $this->as($this->ann);
        $this->assertTrue($this->summary($session->id)['canDelete']);
        $this->assertStringContainsString('data-remove-session="' . $session->id . '"', $this->dashboard());
        $this->assertSame(200, $this->action('ghostwriter/sessions/delete', ['id' => $session->id])['status']);
        $this->assertNull($this->plugin->sessions->find($session->id));

        // An admin manages Ghostwriter, so can remove anyone's.
        $another = $this->annsPiece();
        $this->as($this->bo, admin: true);
        $this->assertTrue($this->summary($another->id)['canDelete']);
        $this->assertStringContainsString('data-remove-session="' . $another->id . '"', $this->dashboard());
        $this->assertSame(200, $this->action('ghostwriter/sessions/delete', ['id' => $another->id])['status']);
        $this->assertNull($this->plugin->sessions->find($another->id));
    }

    public function testHandEditsAreSavedUnderTheSessionsLock(): void
    {
        $session = $this->annsPiece();
        $session->draft = "title: Faceted search\nintro: The old intro.";
        $this->plugin->sessions->save($session);
        $lock = 'ghostwriter:session:' . $session->id;

        $mutex = new RecordingMutex();
        $original = Craft::$app->getMutex();
        Craft::$app->set('mutex', $mutex);

        try {
            // Ann's change to the title lands just before Bo's edit takes the
            // lock: his edit is made to the draft as it is then, so hers stays.
            $mutex->onAcquire = function(string $name) use ($session, $lock, $mutex): void {
                if ($name === $lock) {
                    $mutex->onAcquire = null;
                    $theirs = $this->plugin->sessions->find($session->id);
                    $theirs->draft = "title: Faceted search for shops\nintro: The old intro.";
                    $this->plugin->sessions->save($theirs);
                }
            };

            $this->as($this->bo);
            $edited = $this->action('ghostwriter/sessions/edit-field', ['id' => $session->id, 'path' => '["intro"]', 'value' => 'A new intro.']);
            $this->assertSame(200, $edited['status']);
            $this->assertContains($lock, $mutex->taken);
            $this->assertSame("title: 'Faceted search for shops'\nintro: 'A new intro.'", $this->plugin->sessions->find($session->id)->draft);

            // Edit YAML takes the same lock.
            $mutex->taken = [];
            $this->assertSame(200, $this->action('ghostwriter/sessions/draft', ['id' => $session->id, 'draft' => "title: Faceted search\nintro: Mine."])['status']);
            $this->assertContains($lock, $mutex->taken);

            // While someone else holds it, neither edit is saved.
            $mutex->held = [$lock];

            foreach (['edit-field' => ['path' => '["intro"]', 'value' => 'Not saved.'], 'draft' => ['draft' => 'title: Not saved']] as $action => $body) {
                $refused = $this->action("ghostwriter/sessions/{$action}", ['id' => $session->id] + $body);
                $this->assertSame(409, $refused['status'], "The {$action} edit was saved without the lock.");
                $this->assertStringContainsString('busy', $refused['data']['message']);
            }

            $this->assertSame("title: Faceted search\nintro: Mine.", $this->plugin->sessions->find($session->id)->draft);
        } finally {
            Craft::$app->set('mutex', $original);
        }
    }

    public function testHandEditsWaitForARunningTurn(): void
    {
        $session = $this->annsPiece();
        $session->draft = "title: Faceted search\nintro: The old intro.";
        $session->addMessage('user', 'Make it shorter.', $this->ann->id);
        $session->claim($this->ann->id, new DomainOptions(Format::Craft));
        $this->plugin->sessions->save($session);

        // The turn would write over them when it saves, so both are refused
        // while it runs, saying whose it is.
        $this->as($this->bo);

        foreach (['edit-field' => ['path' => '["intro"]', 'value' => 'Lost.'], 'draft' => ['draft' => 'title: Lost']] as $action => $body) {
            $refused = $this->action("ghostwriter/sessions/{$action}", ['id' => $session->id] + $body);
            $this->assertSame(409, $refused['status'], $action);
            $this->assertSame('Ann Archer is waiting on Ghostwriter.', $refused['data']['message']);
        }

        // Once it has answered, the edits are made to what it wrote.
        $this->fake->respond('writer', '<reply>Shorter.</reply><draft>' . "title: Faceted search\nintro: Short." . '</draft>');
        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);

        $this->assertSame(200, $this->action('ghostwriter/sessions/edit-field', ['id' => $session->id, 'path' => '["title"]', 'value' => 'Search'])['status']);
        $this->assertSame("title: Search\nintro: Short.", $this->plugin->sessions->find($session->id)->draft);
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
        $session = Session::start(Format::Craft, 'project', ['what' => 'A faceted search.'], $this->ann->id);
        $session->recordId = $elementId;
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
        return $this->plugin->domain->sessions()->visible($this->plugin->domain->viewer());
    }

    /**
     * @return array<string, mixed> The dashboard's line for one piece.
     */
    private function summary(string $id): array
    {
        $sessions = $this->action('ghostwriter/dashboard/index', method: 'GET')['data']['variables']['sessions'];

        return array_values(array_filter($sessions, fn(array $s) => $s['id'] === $id))[0];
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
