<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Codeception\Test\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Testing\LayoutLog;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use nineteenninetyfour\ghostwriter\layouts\Layouts;

/**
 * What model pages agree on, place by place, carried into a new one: the
 * case of a studio landing page built with Neo, as on a real site.
 */
class HouseStyleTest extends Unit
{
    private const HERO = '<h1 style="text-align:center;"><span style="color:hsl(0,0%,100%);"><span class="style-uppercase">TITLE</span></span></h1>';

    protected function _before(): void
    {
        LayoutLog::start(getenv('GHOSTWRITER_RECORD_LAYOUTS') ?: null, static::class . '::' . $this->name());
    }

    protected function _after(): void
    {
        LayoutLog::stop();
    }

    public function testAPageIsFilledInFromWhatItsModelsAgreeOn(): void
    {
        $style = $this->learn([
            $this->page('Yacht Studio', 1504),
            $this->page('Architecture Studio', 9330),
            $this->page('Aviation Studio', 9294),
        ]);

        // What the writer hands over: words, and blocks in order.
        [$data, $toFill] = $this->apply(['title' => 'Studio Winch', 'pageBuilder' => [
            ['type' => 'hero', 'enabled' => true, 'children' => [['type' => 'text', 'enabled' => true, 'richText' => '<h1>Studio Winch</h1>']]],
            ['type' => 'breadcrumbs', 'enabled' => true],
            ['type' => 'spacer', 'enabled' => true],
            ['type' => 'body', 'enabled' => true, 'children' => [['type' => 'text', 'enabled' => true, 'richText' => '<p>Words.</p>']]],
            ['type' => 'spacer', 'enabled' => true],
        ]], $style);

        [$hero, $crumbs, $first, $body, $second] = $data['pageBuilder'];

        // Spacers take the heights of the spacer in the same place.
        $this->assertSame([45, 65], [$first['mobile'], $first['desktop']]);
        $this->assertSame([50, 100], [$second['mobile'], $second['desktop']]);

        // Three breadcrumbs, as every page has: Home and Studio are the same
        // everywhere and copied. Without the pages' IDs the third cannot be
        // told for a link to itself, so it goes to example.com for now.
        $this->assertCount(3, $crumbs['crumbs']);
        $this->assertSame('Home', $crumbs['crumbs'][0]['link'][0]['linkText']);
        $this->assertSame('Studio', $crumbs['crumbs'][1]['link'][0]['linkText']);
        $this->assertSame([['type' => 'verbb\\hyper\\links\\Url', 'linkValue' => 'https://example.com', 'linkText' => 'Link to choose']], $crumbs['crumbs'][2]['link']);
        $this->assertSame(['Breadcrumbs: Crumb 3 (links to example.com for now)'], $toFill);

        // The hero heading is dressed as the house dresses it; body text,
        // plain on every page, is left plain.
        $this->assertSame(str_replace('TITLE', 'Studio Winch', self::HERO), $hero['children'][0]['richText']);
        $this->assertSame('<p>Words.</p>', $body['children'][0]['richText']);
    }

    public function testALinkToThePageItselfBecomesALinkToTheNewPage(): void
    {
        $pages = [$this->page('Yacht Studio', 1504), $this->page('Architecture Studio', 9330), $this->page('Visualisation Studio', 3111)];
        unset($pages[2]['pageBuilder'][1]['crumbs'][2]['link']);

        $style = $this->learn($pages, [1504, 9330, 3111]);

        [$data, $toFill] = $this->apply(['title' => 'Studio Winch', 'pageBuilder' => [['type' => 'breadcrumbs', 'enabled' => true]]], $style, 50724, 'Studio Winch');

        // Two of three link the last crumb to themselves and the third leaves
        // it empty, so the new page links to itself, under its own title.
        $last = $data['pageBuilder'][0]['crumbs'][2]['link'][0];

        $this->assertSame([50724], $last['linkValue']);
        $this->assertSame('Studio Winch', $last['linkText']);
        $this->assertSame([], $toFill);

    }

    public function testALinkToThePageItselfIsFoundWhateverEachPageCallsIt(): void
    {
        $pages = [$this->page('Yacht Studio', 1504), $this->page('Architecture Studio', 9330), $this->page('Visualisation Studio', 3111), $this->page('Procurement Team', 2642)];
        $pages[1]['pageBuilder'][1]['crumbs'][2]['link'][0]['linkText'] = 'Architecture';
        unset($pages[2]['pageBuilder'][1]['crumbs'][2]['link'], $pages[3]['pageBuilder'][1]['crumbs'][2]['link']);

        $style = $this->learn($pages, [1504, 9330, 3111, 2642]);
        [$data, $toFill] = $this->apply(['pageBuilder' => [['type' => 'breadcrumbs', 'enabled' => true]]], $style, 50724, 'Studio Winch');

        // Two of four link to themselves, each under its own words; none
        // links anywhere else, so the new page links to itself.
        $this->assertSame([50724], $data['pageBuilder'][0]['crumbs'][2]['link'][0]['linkValue']);
    }

    public function testALinkThePagesUsuallyHaveButDisagreeOnGoesToExampleDotCom(): void
    {
        $pages = [$this->page('One', 1), $this->page('Two', 2), $this->page('Three', 3)];

        // Each page's second crumb goes somewhere different.
        foreach ($pages as $i => $page) {
            $pages[$i]['pageBuilder'][1]['crumbs'][1]['link'][0]['linkValue'] = [100 + $i];
        }

        $style = $this->learn($pages, [1, 2, 3]);
        [$data, $toFill] = $this->apply(['pageBuilder' => [['type' => 'breadcrumbs', 'enabled' => true]]], $style, 9, 'New');

        $this->assertSame('https://example.com', $data['pageBuilder'][0]['crumbs'][1]['link'][0]['linkValue']);
        $this->assertSame(['Breadcrumbs: Crumb 2 (links to example.com for now)'], $toFill);
    }

    public function testALinkThePagesUsuallyLeaveEmptyStaysEmpty(): void
    {
        $pages = [$this->page('One', 1), $this->page('Two', 2), $this->page('Three', 3)];

        foreach ($pages as $i => $page) {
            foreach ([0, 1, 2] as $crumb) {
                unset($pages[$i]['pageBuilder'][1]['crumbs'][$crumb]['link']);
            }
        }

        $style = $this->learn($pages, [1, 2, 3]);
        [$data, $toFill] = $this->apply(['pageBuilder' => [['type' => 'breadcrumbs', 'enabled' => true]]], $style);

        $this->assertArrayNotHasKey('link', $data['pageBuilder'][0]['crumbs'][0]);
        $this->assertNotContains('Breadcrumbs: Crumb 1 (links to example.com for now)', $toFill);
    }

    public function testAPlaceTheModelsDisagreeOnIsLeftAlone(): void
    {
        $pages = [$this->page('One', 1), $this->page('Two', 2)];
        $pages[1]['pageBuilder'][2]['mobile'] = 10;
        $pages[1]['pageBuilder'][0]['children'][0]['richText'] = '<h1>Plain</h1>';

        $style = $this->learn($pages);
        [$data] = $this->apply(['pageBuilder' => [
            ['type' => 'hero', 'enabled' => true, 'children' => [['type' => 'text', 'richText' => '<h1>New</h1>']]],
            ['type' => 'breadcrumbs', 'enabled' => true],
            ['type' => 'spacer', 'enabled' => true],
        ]], $style);

        $this->assertArrayNotHasKey('mobile', $data['pageBuilder'][2]);
        $this->assertSame(65, $data['pageBuilder'][2]['desktop']);
        $this->assertSame('<h1>New</h1>', $data['pageBuilder'][0]['children'][0]['richText']);
    }

    /**
     * What the model pages agree on, learned as the pattern finder learns it.
     *
     * @param array<int, array<string, mixed>> $pages
     * @param array<int, int> $ids The pages' IDs, where the test gives them.
     */
    private function learn(array $pages, array $ids = []): HouseRules
    {
        $pages = array_values($pages);
        $entries = array_map(fn(int $i) => new EntryData($pages[$i], $ids[$i] ?? null), array_keys($pages));

        return (new Layouts())->core->houseStyle()->learn($entries, Schema::fromSpecs($this->schema()));
    }

    /**
     * The house style carried into a new page: its data, and the places
     * still to fill.
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function apply(array $data, HouseRules $style, ?int $id = null, string $title = ''): array
    {
        $result = (new Layouts())->houseStyle($data, Schema::fromSpecs($this->schema()), $style, $id, $title);

        return [$result->data, $result->toFill];
    }

    private function page(string $title, int $id): array
    {
        $link = fn(string $text, int $to) => [['type' => 'verbb\hyper\links\Entry', 'linkValue' => [$to], 'linkText' => $text]];

        return ['title' => $title, 'pageBuilder' => [
            ['id' => $id * 10 + 1, 'type' => 'hero', 'enabled' => true, 'children' => [['id' => $id * 10 + 2, 'type' => 'text', 'enabled' => true, 'richText' => str_replace('TITLE', $title, self::HERO)]]],
            ['id' => $id * 10 + 3, 'type' => 'breadcrumbs', 'enabled' => true, 'crumbs' => [
                ['id' => 1, 'type' => 'crumb', 'enabled' => true, 'link' => $link('Home', 10)],
                ['id' => 2, 'type' => 'crumb', 'enabled' => true, 'link' => $link('Studio', 1166)],
                ['id' => 3, 'type' => 'crumb', 'enabled' => true, 'link' => $link($title, $id)],
            ]],
            ['id' => $id * 10 + 4, 'type' => 'spacer', 'enabled' => true, 'mobile' => 45, 'desktop' => 65],
            ['id' => $id * 10 + 5, 'type' => 'body', 'enabled' => true, 'children' => [['id' => $id * 10 + 6, 'type' => 'text', 'enabled' => true, 'richText' => "<p>About {$title}.</p>"]]],
            ['id' => $id * 10 + 7, 'type' => 'spacer', 'enabled' => true, 'mobile' => 50, 'desktop' => 100],
        ]];
    }

    private function schema(): array
    {
        $text = ['text' => ['display' => 'Text', 'instructions' => '', 'fields' => [['handle' => 'richText', 'type' => 'craft\ckeditor\Field', 'kind' => 'richtext', 'display' => 'Rich text', 'instructions' => '', 'required' => false]]]];
        $children = ['handle' => 'children', 'type' => 'neo-children', 'kind' => 'blocks', 'engine' => 'neo-children', 'display' => 'Blocks inside', 'instructions' => '', 'required' => false, 'sets' => $text];
        $number = fn(string $handle) => ['handle' => $handle, 'type' => 'craft\fields\Number', 'kind' => 'number', 'display' => ucfirst($handle), 'instructions' => '', 'required' => false];

        return [
            ['handle' => 'title', 'type' => 'title', 'kind' => 'text', 'display' => 'Title', 'instructions' => '', 'required' => true],
            ['handle' => 'pageBuilder', 'type' => 'benf\neo\Field', 'kind' => 'blocks', 'engine' => 'neo', 'display' => 'Page builder', 'instructions' => '', 'required' => false, 'sets' => [
                'hero' => ['display' => 'Hero', 'instructions' => '', 'fields' => [$children]],
                'breadcrumbs' => ['display' => 'Breadcrumbs', 'instructions' => '', 'fields' => [
                    ['handle' => 'crumbs', 'type' => 'craft\fields\Matrix', 'kind' => 'reference', 'engine' => 'matrix', 'display' => 'Crumbs', 'instructions' => '', 'required' => false, 'sets' => [
                        'crumb' => ['display' => 'Crumb', 'instructions' => '', 'fields' => [['handle' => 'link', 'type' => 'verbb\hyper\fields\HyperField', 'kind' => 'reference', 'display' => 'Link', 'instructions' => '', 'required' => false]]],
                    ]],
                ]],
                'spacer' => ['display' => 'Spacer', 'instructions' => '', 'fields' => [$number('mobile'), $number('desktop')]],
                'body' => ['display' => 'Body', 'instructions' => '', 'fields' => [$children]],
            ]],
        ];
    }
}
