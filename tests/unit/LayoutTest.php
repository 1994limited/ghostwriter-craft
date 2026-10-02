<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Reading a field layout, learning the pattern from existing entries, and
 * building entry data from a draft: the three steps that let one writer
 * serve a Matrix site, a Neo site and a plain-fields site alike.
 */
class LayoutTest extends TestCase
{
    use Sites;

    private const PARAGRAPH = 'When on-site search is done right it helps your customers find what they need and nudges them towards a decision. Offer up a complex range badly and those high end sales vanish.';

    protected function _before(): void
    {
        parent::_before();

        $this->makeArticlesSection();
        $this->makeNewsSection();
        $this->makePressSection();
    }

    public function testTheReaderReducesFieldsToKinds(): void
    {
        $schema = $this->keyed($this->schema('articles'));

        $this->assertSame('text', $schema['title']['kind']);
        $this->assertTrue($schema['title']['required']);
        $this->assertSame('longtext', $schema['summary']['kind']);
        $this->assertSame('Shown in lists.', $schema['summary']['instructions']);
        $this->assertSame('reference', $schema['relatedEntry']['kind']);
        $this->assertSame(['light' => 'Light', 'dark' => 'Dark'], $schema['theme']['options']);
        $this->assertSame('blocks', $schema['pageBuilder']['kind']);
        $this->assertSame('matrix', $schema['pageBuilder']['engine']);

        $sets = $schema['pageBuilder']['sets'];
        $this->assertSame(['hero', 'longForm', 'cards', 'related', 'gallery'], array_keys($sets));

        $longForm = $this->keyed($sets['longForm']['fields']);
        $this->assertSame('richtext', $longForm['content']['kind']);
        $this->assertSame('toggle', $longForm['numbered']['kind']);
        $this->assertSame(['narrow' => 'Narrow', 'wide' => 'Wide'], $longForm['width']['options']);

        $items = $this->keyed($sets['cards']['fields'])['items'];
        $this->assertSame('rows', $items['kind']);
        $this->assertSame(['text' => 'col1'], $items['columns']);
    }

    public function testNeoBlocksAreSetsAndTheirChildrenAFieldOfTheirOwn(): void
    {
        $builder = $this->keyed($this->schema('news'))['newsBuilder'];

        $this->assertSame('neo', $builder['engine']);

        // Only top-level block types sit at the top.
        $this->assertSame(['textWithAsset', 'spacer', 'assetSingle'], array_keys($builder['sets']));

        $children = $this->keyed($builder['sets']['textWithAsset']['fields'])['children'];
        $this->assertSame('blocks', $children['kind']);
        $this->assertSame(['text', 'button'], array_keys($children['sets']));
        $this->assertSame('richtext', $this->keyed($children['sets']['text']['fields'])['richText']['kind']);
    }

    public function testFixedFieldsNeedNoPageBuilder(): void
    {
        $this->assertSame(['title' => 'text', 'subheading' => 'text', 'thumbnail' => 'reference'], array_column($this->schema('press'), 'kind', 'handle'));
    }

    public function testThePatternComesFromTheEntriesAlreadyThere(): void
    {
        $hub = $this->makeEntry($this->press, 'Hub');

        foreach (['One', 'Two', 'Three'] as $i => $title) {
            $this->makeArticle($title, self::PARAGRAPH . " {$title}.", ['relatedEntry' => [$hub->id]], postDate: "2026-01-0" . ($i + 1));
        }

        $pattern = $this->pattern('articles')->toArray();
        $blocks = $pattern['blocks']['pageBuilder'];

        $this->assertSame(3, $pattern['entries']);

        // The switched-off gallery is not part of the page.
        $this->assertSame(['hero', 'longForm', 'cards', 'related'], $blocks['sequence']);
        $this->assertArrayNotHasKey('gallery', $blocks['usage']);
        $this->assertSame(1.0, $blocks['usage']['longForm']);

        // The same on every entry, so house defaults rather than writing.
        $this->assertSame(['numbered' => true, 'width' => 'narrow'], $blocks['fixed']['longForm']);
        $this->assertSame(['heading' => 'More articles', 'limit' => 3], $blocks['fixed']['related']);
        $this->assertSame('Broader uses', $blocks['fixed']['cards']['heading']);
        $this->assertSame([$hub->id], $pattern['fixed']['relatedEntry']);

        // The writer chooses the theme; a choice is never a house default.
        $this->assertArrayNotHasKey('theme', $pattern['fixed']);

        // Examples are in the form drafts are written in: markdown, no IDs,
        // no references, and without the settings that never change.
        $example = $pattern['examples'][0];
        $this->assertSame('Three', $example['title']);
        $this->assertArrayNotHasKey('relatedEntry', $example);
        $this->assertSame(['type' => 'hero'], $example['pageBuilder'][0]);
        $this->assertStringStartsWith("## The Problem\n\n" . self::PARAGRAPH, $example['pageBuilder'][1]['content']);
        $this->assertStringEndsWith('> Challenge accepted.', $example['pageBuilder'][1]['content']);
        $this->assertArrayNotHasKey('width', $example['pageBuilder'][1]);

        // Blocks whose content never changes are copied, not written, so the
        // examples only show where they go.
        $this->assertSame(['hero', 'cards', 'related'], $blocks['boilerplate']);
        $this->assertSame(['type' => 'cards'], $example['pageBuilder'][2]);
    }

    public function testANeoPatternReadsTheWritingInChildBlocks(): void
    {
        $this->makeNewsArticle('Launch', ['We launched a fabric collection with a partner we admire.', 'It goes on sale in spring.'], '2026-01-02');
        $this->makeNewsArticle('Award', ['Our founder received a lifetime achievement award.'], '2026-01-01');

        $pattern = $this->pattern('news')->toArray();
        $blocks = $pattern['blocks']['newsBuilder'];

        // The commonest order is a tie; the newest entry's way wins.
        $this->assertSame(['assetSingle', 'textWithAsset', 'spacer', 'textWithAsset', 'spacer'], $blocks['sequence']);

        // Spacers and pictures carry no writing, so they are copied as they are.
        $this->assertSame(['assetSingle', 'spacer'], $blocks['boilerplate']);
        $this->assertSame(['paddingTop' => 40, 'reverse' => false], $blocks['fixed']['textWithAsset']);

        $example = $pattern['examples'][0];
        $this->assertSame(['type' => 'spacer'], $example['newsBuilder'][2]);
        $this->assertSame([['type' => 'text', 'richText' => "### Launch\n\nWe launched a fabric collection with a partner we admire."]], $example['newsBuilder'][1]['children']);
    }

    public function testABlockThatNeverChangesIsCopiedIntoTheEntry(): void
    {
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->makeArticle($title, self::PARAGRAPH . " {$title}.");
        }

        $schema = $this->schema('articles');
        $pattern = $this->pattern('articles');

        // Whatever the writer put in such a block, the copy wins.
        $built = $this->build(['title' => 'New', 'pageBuilder' => [
            ['type' => 'cards', 'items' => [['text' => 'Something made up']]],
        ]], $schema, $pattern)['data'];
        $cards = $built['pageBuilder'][0];

        $this->assertSame('Broader uses', $cards['heading']);
        $this->assertSame(['Property searches', 'Recipe selection'], array_column($cards['items'], 'text'));

        // The copy is new content, not the blocks of the entry it came from.
        $this->assertArrayNotHasKey('id', $cards);
    }

    public function testContentPastedInAFewVersionsIsStillCopied(): void
    {
        $website = [['col1' => 'Website step']];
        $app = [['col1' => 'App step']];

        foreach (['One' => $website, 'Two' => $website, 'Three' => $website, 'Four' => $app, 'Five' => $app] as $title => $items) {
            $this->makeArticle($title, self::PARAGRAPH, ['pageBuilder' => [
                'new1' => ['type' => 'cards', 'enabled' => true, 'fields' => ['heading' => 'Steps', 'items' => $items]],
                'new2' => ['type' => 'gallery', 'enabled' => true, 'fields' => ['caption' => 'Caption for ' . $title]],
            ]]);
        }

        $blocks = $this->pattern('articles')->toArray()['blocks']['pageBuilder'];

        // Neither wording is on 80% of entries, but none is an entry's own.
        $this->assertSame(['cards'], $blocks['boilerplate']);
        $this->assertContains($blocks['fixed']['cards']['items'][0]['text'], ['Website step', 'App step']);
        $this->assertArrayNotHasKey('gallery', $blocks['fixed']);
    }

    public function testATypeCanLearnFromOnlySomeOfTheEntries(): void
    {
        $this->makeArticle('One', self::PARAGRAPH, ['kind' => 'project']);
        $this->makeArticle('Two', self::PARAGRAPH, ['kind' => 'guide', 'pageBuilder' => ['new1' => ['type' => 'longForm', 'enabled' => true, 'fields' => ['content' => '<p>Guide.</p>']]]]);

        $this->assertSame(['longForm'], $this->pattern('articles', ['kind' => 'guide'])->toArray()['blocks']['pageBuilder']['sequence']);

        // Nothing matches yet, so the whole section is the evidence.
        $this->assertSame(2, $this->pattern('articles', ['kind' => 'newsletter'])->entries);
    }

    public function testTheBriefDescribesWhatIsUsedAndNamesTheRest(): void
    {
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->makeArticle($title, self::PARAGRAPH . " {$title}.");
        }

        $text = $this->describe('articles');

        $this->assertStringContainsString('- `title` (short text, required)', $text);
        $this->assertStringContainsString('- `summary` (plain text). Shown in lists', $text);
        $this->assertStringContainsString('`longForm`: LongForm, on 100% of entries', $text);
        $this->assertStringContainsString('- `content` (markdown)', $text);
        $this->assertStringContainsString('write `type: cards` and nothing else', $text);
        $this->assertStringContainsString('in this order: hero, longForm, cards, related', $text);
        $this->assertStringContainsString('Also available but not normally used here: `gallery` (Gallery)', $text);
        $this->assertStringContainsString('Never ask for their text: hero, cards, related', $text);

        // Settings that never change, and fields a person fills in, are left out.
        $this->assertStringNotContainsString('`width`', $text);
        $this->assertStringNotContainsString('relatedEntry', $text);
    }

    public function testTheBriefExplainsNeoChildBlocks(): void
    {
        $this->makeNewsArticle('Launch', ['We launched a fabric collection.']);
        $this->makeNewsArticle('Award', ['Our founder received an award.']);

        $text = $this->describe('news');

        $this->assertStringContainsString('a block that holds other blocks lists them under `children`', $text);
        $this->assertStringContainsString('- `children` (list of blocks)', $text);
        $this->assertStringContainsString('- `richText` (markdown)', $text);

        // A builder of pictures has nothing to write; a person fills it in.
        $this->assertStringNotContainsString('picture', $text);
    }

    public function testADraftBecomesPageBuilderData(): void
    {
        $hub = $this->makeEntry($this->press, 'Hub');

        foreach (['One', 'Two', 'Three'] as $title) {
            $this->makeArticle($title, self::PARAGRAPH . " {$title}.", ['relatedEntry' => [$hub->id]]);
        }

        $schema = $this->schema('articles');

        $built = $this->build([
            'title' => 'New Piece',
            'summary' => "A summary\nover two lines.",
            'relatedEntry' => [99],
            'theme' => 'Dark',
            'made_up' => 'nope',
            'pageBuilder' => [
                ['type' => 'hero'],
                ['type' => 'longForm', 'width' => 'Wide', 'content' => "## The Problem\n\nIt was **hard**.\n\n> Challenge *accepted*.\n\n<script>alert(1)</script>"],
                ['type' => 'cards', 'heading' => 'Where else', 'items' => [['text' => 'Property'], ['text' => 'Recipes']]],
                ['type' => 'related'],
                ['type' => 'carousel', 'caption' => 'No such block'],
            ],
        ], $schema, $this->pattern('articles'), ['kind' => 'guide']);

        $data = $built['data'];
        $blocks = $data['pageBuilder'];

        $this->assertSame('New Piece', $data['title']);
        $this->assertSame("A summary\nover two lines.", $data['summary']);
        $this->assertArrayNotHasKey('made_up', $data);
        $this->assertSame('dark', $data['theme']);

        // The type's default, then the section's house default. The entry
        // the writer named is ignored: references are not the writer's.
        $this->assertSame('guide', $data['kind']);
        $this->assertSame([$hub->id], $data['relatedEntry']);

        $this->assertSame(['hero', 'longForm', 'cards', 'related'], array_column($blocks, 'type'));
        $this->assertTrue($blocks[0]['enabled']);

        // Markdown became HTML, with anything the model wrote as HTML
        // escaped; the label "Wide" became its key.
        $this->assertStringContainsString('<h2>The Problem</h2>', $blocks[1]['content']);
        $this->assertStringContainsString('<strong>hard</strong>', $blocks[1]['content']);
        $this->assertStringContainsString('<blockquote>', $blocks[1]['content']);
        $this->assertStringNotContainsString('<script>', $blocks[1]['content']);
        $this->assertSame('wide', $blocks[1]['width']);
        $this->assertTrue($blocks[1]['numbered']);

        // Cards are the same on every entry, so the copy is used and said so.
        $this->assertSame('Broader uses', $blocks[2]['heading']);
        $this->assertSame(['Property searches', 'Recipe selection'], array_column($blocks[2]['items'], 'text'));
        $this->assertContains('Cards is the same on every entry here, so its usual content was used in place of what was drafted.', $built['notes']);

        // A boilerplate block written as its type alone gets its usual content.
        $this->assertSame('More articles', $blocks[3]['heading']);
        $this->assertSame(3, $blocks[3]['limit']);

        $this->assertCount(3, $built['notes']);
        $this->assertStringContainsString('"carousel" cannot go in', implode(' ', $built['notes']));
    }

    public function testANeoDraftKeepsItsChildren(): void
    {
        $this->makeNewsArticle('Launch', ['We launched a fabric collection.']);
        $this->makeNewsArticle('Award', ['Our founder received an award.']);

        $schema = $this->schema('news');

        $built = $this->build([
            'title' => 'Fresh News',
            'newsBuilder' => [
                ['type' => 'assetSingle'],
                ['type' => 'textWithAsset', 'children' => [
                    ['type' => 'text', 'richText' => "### Fresh\n\nSomething happened."],
                    ['type' => 'spacer'],
                ]],
                ['type' => 'spacer'],
            ],
        ], $schema, $this->pattern('news'));

        $blocks = $built['data']['newsBuilder'];

        $this->assertSame(['assetSingle', 'textWithAsset', 'spacer'], array_column($blocks, 'type'));
        $this->assertSame(40, $blocks[1]['paddingTop']);
        $this->assertSame(80, $blocks[2]['height']);
        $this->assertSame(['text'], array_column($blocks[1]['children'], 'type'));
        $this->assertStringContainsString('<h3>Fresh</h3>', $blocks[1]['children'][0]['richText']);

        // A spacer cannot go inside text-with-asset here, and is reported.
        $this->assertStringContainsString('"spacer" cannot go in children', implode(' ', $built['notes']));
    }

    public function testAPlainSectionNeedsNoPageBuilder(): void
    {
        $schema = $this->schema('press');

        $built = $this->build(['title' => 'BOAT International', 'subheading' => "Fitness afloat:\nthe yachts"], $schema, $this->pattern('press'));

        $this->assertSame(['title' => 'BOAT International', 'subheading' => 'Fitness afloat: the yachts'], $built['data']);
        $this->assertSame([], $built['notes']);

        // With nothing published there is no pattern, and the brief says so.
        $text = $this->describe('press');
        $this->assertStringContainsString('- `subheading` (short text)', $text);
        $this->assertStringNotContainsString('usually build', $text);
    }

    /**
     * How a section's entries are put together, as the plugin finds it.
     *
     * @param array<string, mixed> $where
     */
    private function pattern(string $section, array $where = []): Pattern
    {
        return $this->plugin->layouts->pattern($section, Schema::fromSpecs($this->schema($section)), null, $where);
    }

    /**
     * The section's fields, described as the writer is shown them.
     */
    private function describe(string $section): string
    {
        return $this->plugin->layouts->layout(Schema::fromSpecs($this->schema($section)), $this->pattern($section))->fields;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<int, array<string, mixed>> $schema
     * @param array<string, mixed> $defaults
     * @return array{data: array<string, mixed>, notes: array<int, string>}
     */
    private function build(array $draft, array $schema, Pattern $pattern, array $defaults = []): array
    {
        return $this->plugin->layouts->build($draft, Schema::fromSpecs($schema), $pattern, $defaults)->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function schema(string $section): array
    {
        return (new SchemaReader())->read(\Craft::$app->getEntries()->getSectionByHandle($section)->getEntryTypes()[0]);
    }

    /**
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, array<string, mixed>>
     */
    private function keyed(array $schema): array
    {
        return array_column($schema, null, 'handle');
    }
}
