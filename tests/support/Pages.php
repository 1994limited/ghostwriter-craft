<?php

namespace nineteenninetyfour\ghostwriter\tests\support;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;

/**
 * A services section like Northfold's pages, for layouts: a Matrix page
 * builder with a hero, rich text, a quote and a call to action, and three
 * live pages built three ways, so the site has patterns to follow and
 * nothing is copied whole.
 *
 * The writer's draft and its extras, and the planner's two layouts, are
 * what the fake model answers with.
 */
trait Pages
{
    protected Section $pages;

    public const PAGE_DRAFT = "title: Faceted search\nsummary: Why narrowing the range helps.\nblocks:\n  - type: hero\n    heading: Find it faster\n    subheading: A search for a kitchen appliance maker.\n  - type: copy\n    body: |\n      Shoppers rarely know the model number.\n\n      ## What we built\n\n      A search that narrows the range by size, finish and price.\n\n      ## What changed\n\n      Fewer calls to the showroom.\n  - type: cta\n    heading: Talk to us about search\n    button: Get in touch";

    /** A count of a list in the draft (the model says 5; core counts 3), and a pull quote. */
    public const PAGE_EXTRAS = "<extras>\n- kind: stats\n  items:\n    - text: \"5 filters\"\n      value: \"5\"\n      label: filters\n      source: { from: draft, quote: \"size, finish and price\" }\n- kind: pull_quote\n  items:\n    - text: Shoppers rarely know the model number.\n      source: { from: draft, quote: \"Shoppers rarely know the model number.\" }\n</extras>";

    public const PAGE_PLANS = "<plans>\n- name: Sections apart\n  description: Each section in its own block, the quote between\n  blocks:\n    - type: hero\n      place: { heading: u3, subheading: u4 }\n    - type: copy\n      place: { body: u5 }\n    - type: pullQuote\n      place: { quote: x2.1 }\n    - type: copy\n      place: { body: [u6, u7] }\n    - type: cta\n      place: { heading: u8, button: u9 }\n- name: Numbers first\n  description: The count up front, then the story\n  blocks:\n    - type: hero\n      place: { heading: u3, subheading: u4 }\n    - type: stats\n      place: { figures: [x1.1] }\n    - type: copy\n      place: { body: [u5, u6, u7] }\n    - type: cta\n      place: { heading: u8, button: u9 }\n</plans>";

    protected function makePagesSection(): Section
    {
        $heading = $this->pageField(PlainText::class, 'heading');

        $builder = $this->makeMatrix('blocks', [
            $this->makeEntryType('hero', [$heading, $this->pageField(PlainText::class, 'subheading')], hasTitle: false),
            $this->makeEntryType('copy', [$this->pageField(Ckeditor::class, 'body')], hasTitle: false),
            $this->makeEntryType('pullQuote', [$this->pageField(PlainText::class, 'quote', ['multiline' => true]), $this->pageField(PlainText::class, 'attribution')], hasTitle: false),
            $this->makeEntryType('stats', [$this->pageField(Table::class, 'figures', ['columns' => ['col1' => ['heading' => 'Value', 'handle' => 'value', 'type' => 'singleline'], 'col2' => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline']]])], hasTitle: false),
            $this->makeEntryType('cta', [$heading, $this->pageField(PlainText::class, 'button')], hasTitle: false),
        ]);

        $this->pages = $this->makeSection('services', [$this->makeEntryType('service', [
            $this->pageField(PlainText::class, 'summary', ['multiline' => true]),
            $builder,
        ])]);

        $pages = [
            'Garden design' => ['hero', 'copy', 'pullQuote', 'copy', 'cta'],
            'Planting plans' => ['hero', 'stats', 'copy', 'cta'],
            'Maintenance' => ['hero', 'copy', 'pullQuote', 'cta'],
        ];
        $day = 1;

        foreach ($pages as $title => $sequence) {
            $blocks = [];

            foreach ($sequence as $i => $type) {
                $blocks['new' . ($i + 1)] = ['type' => $type, 'enabled' => true, 'fields' => match ($type) {
                    'hero' => ['heading' => "{$title}, done well", 'subheading' => "What {$title} involves."],
                    'copy' => ['body' => "<p>{$title} starts with a visit, number {$i}, and a long talk about the garden.</p><h2>How it works</h2><p>We draw it up for {$title}.</p>"],
                    'pullQuote' => ['quote' => "{$title} changed how we use the garden.", 'attribution' => 'A client'],
                    'stats' => ['figures' => [['col1' => '12', 'col2' => "{$title} gardens"]]],
                    'cta' => ['heading' => "Ask about {$title}", 'button' => 'Contact us'],
                }];
            }

            $this->makeEntry($this->pages, $title, ['summary' => "A short summary of {$title} for lists.", 'blocks' => $blocks], postDate: '2026-02-0' . $day++);
        }

        return $this->pages;
    }

    protected function serviceType(): ContentType
    {
        return $this->plugin->types->save($this->plugin->types->make('service', [
            'title' => 'Service page',
            'description' => 'A page about one service.',
            'section' => 'services',
            'questions' => [['handle' => 'what', 'label' => 'What is it?', 'type' => 'textarea', 'required' => true]],
        ]));
    }

    /**
     * A piece whose first draft has just been asked for, for the entry given.
     */
    protected function servicePiece(?Entry $target = null): Session
    {
        $session = Session::start(Format::Craft, 'service', ['what' => 'Faceted search.'], Craft::$app->getUser()->getId());
        $session->addMessage('user', 'What is it? Faceted search.');
        $session->status = Session::WORKING;
        $session->recordId = $target?->id;

        return $this->plugin->sessions->save($session);
    }

    /**
     * The first draft written with its extras, and two other layouts.
     */
    protected function writeFirstDraft(Session $session, ?string $plans = null): Session
    {
        $this->fake->respond('writer', "<reply>Here is a first draft.</reply>\n<draft>\n" . self::PAGE_DRAFT . "\n</draft>\n" . self::PAGE_EXTRAS);
        $this->fake->respond('layout-planner', $plans ?? self::PAGE_PLANS);

        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);

        return $this->plugin->sessions->find($session->id);
    }

    private function pageField(string $class, string $handle, array $config = []): \craft\base\FieldInterface
    {
        return Craft::$app->getFields()->getFieldByHandle($handle) ?? $this->makeField($class, $handle, $config);
    }
}
