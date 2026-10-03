<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\gaps;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use nineteenninetyfour\ghostwriter\tests\support\Pages;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * A count Ghostwriter worked out ("3 filters" from "size, finish and
 * price") goes into the entry as a count to check (decision 8): a step in
 * Finish this page with Looks right, Change it and Remove it, which says
 * when the list has changed since, and a bar to publishing until it's
 * resolved, however the entry goes live.
 */
class CheckStepTest extends TestCase
{
    use Pages;

    private Entry $target;

    private Session $session;

    /** @var array<string, list<string>> */
    private array $errors = [];

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        $this->plugin->getSettings()->stockLibraries = [];
        $this->plugin->getSettings()->onUnfinishedPublish = null;
        $this->plugin->stockLibraries->reset();
        $this->makePagesSection();
        $this->signIn(admin: true);
        $this->serviceType();
        $this->plugin->getSettings()->sections = ['services'];
        Craft::$app->getCache()->flush();

        // The layout with the numbers, put into a new page.
        $this->target = $this->newDraft($this->pages);
        $this->session = $this->writeFirstDraft($this->servicePiece($this->target));
        $this->action('ghostwriter/sessions/choose-layout', ['id' => $this->session->id, 'plan' => 'p2']);
        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $this->session->id, 'elementId' => $this->target->id]);
        $this->assertSame(200, $applied['status'], json_encode($applied['data']));
        $this->fake->reset();
    }

    public function testTheCountIsAStepWithItsThreeFixes(): void
    {
        $check = $this->checkGap();

        $this->assertSame('I counted 3 from “size, finish and price”. Is that right?', $check['message']);
        $this->assertSame('Check me', $check['speech']);
        $this->assertSame(['Looks right', 'Change it', 'Remove it'], array_column($check['fixes'], 'label'));
        $this->assertSame(['confirm', 'change', 'remove'], array_column($check['fixes'], 'action'));
        $this->assertSame(['3', '3', null], array_column($check['fixes'], 'value'));
        $this->assertSame('blocks', $check['field']);
        $this->assertSame('figures', $check['location']['handle']);
        $this->assertSame('[[check: 3 | from: size, finish and price]]', $check['meta']['match']);
        $this->assertNull($check['meta']['stale']);
        $this->assertSame('blocks', $check['severity']);
        $this->fake->assertNothingSent();
    }

    public function testAListThatChangedSinceSaysSoAndOffersTheNewCount(): void
    {
        $this->changeDraft('size, finish and price', 'size, finish, colour and price');

        $check = $this->checkGap();

        $this->assertSame('changed', $check['meta']['stale']);
        $this->assertSame('I counted 3 from “size, finish and price”, but that list has changed since. It now has 4. Use “4” instead?', $check['message']);
        $this->assertSame(['Use “4”', 'Change it', 'Remove it'], array_column($check['fixes'], 'label'));
        $this->assertSame('4', $check['fixes'][0]['value']);

        // Gone from what was given altogether: Change it comes first.
        $this->changeDraft('size, finish, colour and price', 'whatever suits the kitchen');
        $gone = $this->checkGap();
        $this->assertSame('gone', $gone['meta']['stale']);
        $this->assertSame(['Change it', 'Remove it'], array_column($gone['fixes'], 'label'));
    }

    public function testItBarsCreatingThePageUntilItsResolved(): void
    {
        // "Create entry" on the new page: Craft applies its unpublished draft.
        $this->assertFalse($this->create(), 'A count to check blocks going live.');
        $this->assertStringContainsString('Check “3” before publishing.', implode(' ', $this->errors['blocks'] ?? []));
        $this->assertNull(Entry::find()->id($this->target->id)->status(null)->one(), 'Still only a draft.');

        // "Looks right": the marker becomes the count, and the page can go live.
        $stats = $this->formDraft()->getFieldValue('blocks')->status(null)->all()[1];
        $rows = $stats->getFieldValue('figures');
        // A table row as Craft keeps it: by column id, and by handle.
        $rows[0]['col1'] = $rows[0]['value'] = Markers::resolveCheck((string) $rows[0]['value'], $this->checkGap()['meta']['match'], '3');
        $stats->setFieldValue('figures', $rows);
        $stats->setScenario(Element::SCENARIO_ESSENTIALS);
        $this->assertTrue(Craft::$app->getElements()->saveElement($stats), json_encode($stats->getErrors()));

        $this->assertSame([], array_values(array_filter($this->gaps(), fn(array $gap) => $gap['kind'] === 'check')));
        $this->assertTrue($this->create());
        $this->assertSame('3', Entry::find()->id($this->target->id)->one()->getFieldValue('blocks')->all()[1]->getFieldValue('figures')[0]['value']);
    }

    public function testApplyingADraftThatHoldsACountIsBarredToo(): void
    {
        // A live page, and a provisional draft of it holding a count to
        // check, applied as Craft's Save button does (updatingFromDerivative).
        $live = Entry::find()->section('services')->title('Maintenance')->one();
        $draft = Craft::$app->getDrafts()->createDraft($live, Craft::$app->getUser()->getId(), null, null, [], true);
        $draft->setFieldValue('summary', 'Gardens across ' . Markers::check('3 counties', 'Northumberland, Durham and Cumbria') . '.');
        $draft->setScenario(Element::SCENARIO_ESSENTIALS);
        $this->assertTrue(Craft::$app->getElements()->saveElement($draft));

        $draft = Entry::find()->id($draft->id)->drafts(null)->provisionalDrafts(null)->status(null)->one();

        try {
            Craft::$app->getDrafts()->applyDraft($draft);
            $applied = true;
        } catch (\Throwable) {
            $applied = false;
        }

        $this->assertFalse($applied, 'Applying a draft with a count to check is refused.');
        $this->assertStringNotContainsString('[[check:', (string) Entry::find()->id($live->id)->one()->getFieldValue('summary'));
    }

    /**
     * @return array<string, mixed>
     */
    private function checkGap(): array
    {
        $checks = array_values(array_filter($this->gaps(), fn(array $gap) => $gap['kind'] === 'check'));
        $this->assertCount(1, $checks, json_encode(array_column($this->gaps(), 'kind')));

        return $checks[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gaps(): array
    {
        $result = $this->action('ghostwriter/gaps/check', ['elementId' => $this->target->id, 'siteId' => $this->target->siteId], 'GET');
        $this->assertSame(200, $result['status'], json_encode($result['data']));

        return $result['data']['gaps'];
    }

    /**
     * The new page made live, as its Create entry button does.
     */
    private function create(): bool
    {
        $draft = $this->formDraft();
        $draft->enabled = true;
        $draft->setEnabledForSite(true);
        $draft->title = 'Faceted search';
        $draft->setScenario(Element::SCENARIO_ESSENTIALS);
        $this->assertTrue(Craft::$app->getElements()->saveElement($draft));

        // As ElementsController::actionApplyDraft(): the draft is saved in
        // the live scenario first, and its errors stop it.
        $draft = $this->formDraft();
        $draft->setScenario(Element::SCENARIO_LIVE);
        $draft->applyingDraft = true;

        if (!Craft::$app->getElements()->saveElement($draft)) {
            $this->errors = $draft->getErrors();

            return false;
        }

        $draft->applyingDraft = false;
        Craft::$app->getDrafts()->applyDraft($draft);

        return true;
    }

    private function formDraft(): Entry
    {
        return Entry::find()->id($this->target->id)->drafts(null)->status(null)->one();
    }

    private function changeDraft(string $from, string $to): void
    {
        $this->plugin->domain->sessions()->change($this->session->id, function(Session $session) use ($from, $to): void {
            $session->draft = str_replace($from, $to, (string) $session->draft);
        });
    }
}
