<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\gaps;

use Craft;
use craft\base\Element;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\OnPublish;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\GuardOutcome;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PublishGuardContract;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\stock\StockMarkers;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the one publish guard, through Craft's own save
 * hooks: Save on a live entry (the live scenario), saving a draft, and
 * applying a draft (Craft saves the canonical entry in the essentials
 * scenario, with `updatingFromDerivative`).
 */
class PublishGuardTest extends TestCase
{
    use PublishGuardContract;

    private ?Section $section = null;

    private Assets $image;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        $this->plugin->getSettings()->stockLibraries = [];
        $this->plugin->getSettings()->onUnfinishedPublish = null;
        $this->plugin->getSettings()->stockOnPublish = 'block';
        $this->plugin->stockLibraries->reset();
        $this->plugin->stockComps->reset();
        StockMarkers::reset();
        $this->signIn();
    }

    protected function guardMode(OnPublish $mode): void
    {
        $this->plugin->getSettings()->onUnfinishedPublish = $mode->value;
    }

    protected function guardEntry(string $text, bool $stockPreview = false): mixed
    {
        $section = $this->section();
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'title' => 'Tickets',
            'enabled' => true,
        ]);
        $entry->setAuthorIds([Craft::$app->getUser()->getId()]);
        $entry->setFieldValue('body', $this->html($text));

        if ($stockPreview) {
            $entry->setFieldValue('image', [$this->preview()]);
        }

        return $entry;
    }

    protected function guardPublish(mixed $entry): GuardOutcome
    {
        $entry->setScenario(Element::SCENARIO_LIVE);

        return $this->outcome(fn() => Craft::$app->getElements()->saveElement($entry), $entry);
    }

    protected function guardSaveDraft(mixed $entry): GuardOutcome
    {
        $entry->setScenario(Element::SCENARIO_LIVE);

        return $this->outcome(fn() => Craft::$app->getDrafts()->saveElementAsDraft($entry, Craft::$app->getUser()->getId(), markAsSaved: false), $entry);
    }

    protected function guardOtherWaysLive(mixed $entry): array
    {
        // A live, finished entry; then a provisional draft with the
        // unfinished text, applied as Craft's Save button does.
        $live = $this->guardEntry('Tickets cost £12.');
        $live->setScenario(Element::SCENARIO_LIVE);
        $this->assertTrue(Craft::$app->getElements()->saveElement($live), json_encode($live->getErrors()));

        $draft = Craft::$app->getDrafts()->createDraft($live, Craft::$app->getUser()->getId(), null, null, [], true);
        $draft->setFieldValue('body', $entry->getFieldValue('body'));
        $draft->setScenario(Element::SCENARIO_ESSENTIALS);
        $this->assertTrue(Craft::$app->getElements()->saveElement($draft));

        $draft = Entry::find()->id($draft->id)->drafts(null)->provisionalDrafts(null)->status(null)->one();

        $applied = $this->outcome(function() use ($draft): bool {
            try {
                Craft::$app->getDrafts()->applyDraft($draft);

                return true;
            } catch (\Throwable) {
                return false;
            }
        }, $draft);

        $this->assertStringNotContainsString('[[ask:', (string) Entry::find()->id($live->id)->one()->getFieldValue('body'), 'The live entry is unchanged.');

        return ['applying a draft' => $applied];
    }

    protected function guardTextField(): string
    {
        return 'body';
    }

    protected function guardImageField(): string
    {
        return 'image';
    }

    public function testAMarkerInABlockIsNamedOnThePageBuilder(): void
    {
        $entry = $this->guardEntry('Finished.');
        $entry->setFieldValue('blocks', ['entries' => ['new1' => ['type' => 'feature', 'enabled' => true, 'fields' => ['heading' => 'Open [[ask: opening days]]']]], 'sortOrder' => ['new1']]);

        $outcome = $this->guardPublish($entry);

        $this->assertFalse($outcome->saved);
        $this->assertSame('Feature: Heading: Add opening days before publishing.', $entry->getFirstError('blocks'));
    }

    public function testAMarkerInASectionGhostwriterDoesntWriteForIsNotOurs(): void
    {
        $withPreview = $this->guardEntry('Words.', stockPreview: true);
        $this->plugin->getSettings()->sections = ['somewhere-else'];

        $this->assertTrue($this->guardPublish($this->guardEntry('A wiki-style [[item]] and [[ask: something]].'))->saved);

        // A stock preview is still a bar, in any section.
        $this->assertFalse($this->guardPublish($withPreview)->saved);
    }

    public function testADisabledEntrySavesFreely(): void
    {
        $entry = $this->guardEntry('Tickets cost [[ask: adult ticket price]].');
        $entry->enabled = false;

        $this->assertTrue($this->guardPublish($entry)->saved);
    }

    /**
     * Run a save and see what it left: whether it saved, the entry's
     * errors, and any notice it flashed.
     *
     * @param callable(): bool $save
     */
    private function outcome(callable $save, Entry $entry): GuardOutcome
    {
        $session = Craft::$app->getSession();
        $session->removeAllFlashes();

        $saved = $save();

        $warnings = [];

        foreach ($session->getAllFlashes(true) as $key => $flash) {
            if (str_contains((string) $key, 'notice')) {
                array_walk_recursive($flash, function($value, $name) use (&$warnings): void {
                    if (is_string($value) && in_array($name, ['message', 0], true)) {
                        $warnings[] = $value;
                    }
                });

                if (is_string($flash)) {
                    $warnings[] = $flash;
                }
            }
        }

        return new GuardOutcome($saved, $entry->getErrors(), array_values(array_unique($warnings)));
    }

    private function html(string $markdown): string
    {
        $field = (new SchemaReader())->schema($this->section()->getEntryTypes()[0])->field('body');

        return (new HtmlDialect())->fromMarkdown($markdown, $field);
    }

    /**
     * A stock photo preview, put in through the image dialog's own path.
     */
    private function preview(): int
    {
        $carrier = $this->makeEntry($this->section(), 'Carrier', live: false);
        $asset = $this->plugin->imagePicker->insertPreview(ImageSlot::for($this->image, $carrier), $this->plugin->stockLibraries->demo(), 'demo-03');
        $this->plugin->stockComps->reset();

        return (int) $asset->id;
    }

    private function section(): Section
    {
        if ($this->section !== null) {
            return $this->section;
        }

        $volume = $this->makeVolume();
        $sources = ['sources' => ['volume:' . $volume->uid], 'defaultUploadLocationSource' => 'volume:' . $volume->uid];
        $this->image = $this->makeField(Assets::class, 'image', $sources + ['maxRelations' => 1]);
        $feature = $this->makeEntryType('feature', [$this->makeField(PlainText::class, 'heading')], hasTitle: false);

        return $this->section = $this->makeSection('events', [$this->makeEntryType('event', [
            $this->makeField(Ckeditor::class, 'body'),
            $this->image,
            $this->makeMatrix('blocks', [$feature]),
        ])]);
    }
}
