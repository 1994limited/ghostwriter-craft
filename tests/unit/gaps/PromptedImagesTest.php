<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\gaps;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\Section;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Which empty fields "Finish this page" counts on Craft: a required plain
 * field is Craft's to report; an empty image the page looks like it needs
 * (required, or filled on most of the section's live entries) is counted in
 * the menu and brings the guide out; an optional image few entries use
 * isn't. The section's fill rates are counted again when one of its
 * entries is saved.
 */
class PromptedImagesTest extends TestCase
{
    private const BODY = '<p>Our new roof garden sits above the café, with raised beds of herbs for the kitchen and a few tables among them.</p>';

    private \craft\models\Volume $volume;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        $this->plugin->getSettings()->stockLibraries = [];
        $this->volume = $this->makeVolume();
    }

    public function testAnEmptyRequiredHeroImageIsCountedWithItsReason(): void
    {
        $journal = $this->section('journal', heroRequired: true);
        $entry = $this->makeEntry($journal, 'A roof garden for a cafe in Newcastle', ['body' => self::BODY]);

        $payload = $this->plugin->gaps->payload($entry);

        $this->assertSame(1, $payload['count'], json_encode($payload['gaps']));
        $this->assertSame(1, $payload['prompting'], 'It brings the guide out on load, so the header shows it.');
        [$gap] = $payload['gaps'];
        $this->assertSame('image-empty|heroImage||0', $gap['id']);
        $this->assertSame('prompt', $gap['severity']);
        $this->assertSame('Hero image', $gap['label']);
        $this->assertSame('Hero image is required. Add one?', $gap['message']);
        $this->assertSame('required', $gap['meta']['why']);
        $this->assertSame(['Find a photo', 'Choose from Assets'], array_column($gap['fixes'], 'label'));

        // Never blocks: Craft enforces required on save.
        $this->assertTrue($this->plugin->gaps->readiness($entry)->ready());
    }

    public function testANewUntouchedEntryIsNotPrompted(): void
    {
        $journal = $this->section('journal', heroRequired: true);
        $entry = $this->makeEntry($journal, 'A roof garden', live: false);

        $this->assertSame(0, $this->plugin->gaps->payload($entry)['count']);
    }

    public function testAnOptionalImageMostEntriesHaveIsPromptedAndOneFewHaveIsNot(): void
    {
        $news = $this->section('news');
        $photo = $this->makeAsset($this->volume, 'garden.jpg');

        foreach (['Market day', 'New menu', 'Late opening', 'Bees arrive'] as $title) {
            $this->makeEntry($news, $title, ['body' => self::BODY]);
        }

        $entry = $this->makeEntry($news, 'A roof garden', ['body' => self::BODY]);
        $this->assertSame(0, $this->plugin->gaps->payload($entry)['count'], 'No news entry has a picture: no gap.');

        // Saving entries in the section counts its fill rates again.
        foreach (Entry::find()->section('news')->title(['Market day', 'New menu', 'Late opening', 'Bees arrive'])->all() as $sibling) {
            $sibling->setFieldValue('picture', [$photo->id]);
            Craft::$app->getElements()->saveElement($sibling);
        }

        $payload = $this->plugin->gaps->payload($entry);
        $this->assertSame(1, $payload['count'], json_encode($payload['gaps']));
        $this->assertSame('Picture is empty, but most News entries have one. Add one?', $payload['gaps'][0]['message']);
        $this->assertSame('siblings', $payload['gaps'][0]['meta']['why']);
        $this->assertSame(0.8, $payload['gaps'][0]['meta']['filled'], '4 of the 5 live entries.');
    }

    public function testARequiredPlainFieldIsLeftToCraftAndAnOptionalHeroWithNoEntriesToGoByIsPrompted(): void
    {
        $journal = $this->section('journal', heroRequired: false, summaryRequired: true);
        $entry = $this->makeEntry($journal, 'A roof garden', ['body' => self::BODY]);
        $gaps = $this->plugin->gaps->payload($entry)['gaps'];

        $this->assertSame(['heroImage'], array_column($gaps, 'field'), 'The empty required summary is Craft\'s to report.');
        $this->assertSame('prominent', $gaps[0]['meta']['why'], 'One live entry is too few to go by; its name says it is the hero.');
        $this->assertSame('Hero image is the page\'s main image, and it\'s empty. Add one?', $gaps[0]['message']);
    }

    /**
     * A section with a hero image, a body and a picture, as gw-test-craft's
     * journal is.
     */
    private function section(string $handle, bool $heroRequired = false, bool $summaryRequired = false): Section
    {
        $sources = ['sources' => ['volume:' . $this->volume->uid], 'defaultUploadLocationSource' => 'volume:' . $this->volume->uid, 'maxRelations' => 1];
        $type = $this->makeEntryType($handle, [
            $this->makeField(PlainText::class, $handle . 'Summary'),
            $this->named($this->makeField(Assets::class, $handle === 'journal' ? 'heroImage' : 'picture', $sources), $handle === 'journal' ? 'Hero image' : 'Picture'),
            $this->makeField(Ckeditor::class, 'body'),
        ]);

        $this->require($type, array_keys(array_filter([$handle . 'Summary' => $summaryRequired, 'heroImage' => $heroRequired])));

        return $this->makeSection($handle, [$type]);
    }

    private function named(\craft\base\FieldInterface $field, string $name): \craft\base\FieldInterface
    {
        $field->name = $name;
        Craft::$app->getFields()->saveField($field);

        return $field;
    }

    /**
     * @param list<string> $handles
     */
    private function require(EntryType $type, array $handles): void
    {
        foreach ($type->getFieldLayout()->getCustomFieldElements() as $element) {
            if (in_array($element->getField()->handle, $handles, true)) {
                $element->required = true;
            }
        }

        Craft::$app->getEntries()->saveEntryType($type);
    }
}
