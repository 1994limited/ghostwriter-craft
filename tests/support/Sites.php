<?php

namespace nineteenninetyfour\ghostwriter\tests\support;

use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\ButtonGroup;
use craft\fields\Dropdown;
use craft\fields\Entries;
use craft\fields\Lightswitch;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\models\Section;

/**
 * The three shapes of site Ghostwriter has to serve, built for tests:
 *
 *   articles  a Matrix page builder, much like the Statamic addon's tests
 *   news      a Neo page builder whose writing sits in child blocks, as on
 *             a real site that uses Neo
 *   press     fixed fields and no page builder at all
 */
trait Sites
{
    protected Section $articles;

    protected Section $news;

    protected Section $press;

    protected function makeArticlesSection(): Section
    {
        $heading = $this->field(PlainText::class, 'heading');
        $related = $this->field(Entries::class, 'relatedEntry');

        $builder = $this->makeMatrix('pageBuilder', [
            $this->makeEntryType('hero', [$heading, $related], hasTitle: false),
            $this->makeEntryType('longForm', [
                $this->field(Ckeditor::class, 'content'),
                $this->field(Lightswitch::class, 'numbered'),
                $this->field(ButtonGroup::class, 'width', ['options' => [['label' => 'Narrow', 'value' => 'narrow', 'default' => true], ['label' => 'Wide', 'value' => 'wide']]]),
            ], hasTitle: false),
            $this->makeEntryType('cards', [$heading, $this->field(Table::class, 'items', ['columns' => ['col1' => ['heading' => 'Text', 'handle' => 'text', 'type' => 'singleline']]])], hasTitle: false),
            $this->makeEntryType('related', [$heading, $this->field(Number::class, 'limit')], hasTitle: false),
            $this->makeEntryType('gallery', [$this->field(PlainText::class, 'caption')], hasTitle: false),
        ]);

        return $this->articles = $this->makeSection('articles', [$this->makeEntryType('article', [
            $this->field(PlainText::class, 'summary', ['multiline' => true, 'instructions' => 'Shown in lists.']),
            $related,
            $this->field(Dropdown::class, 'theme', ['options' => [['label' => 'Light', 'value' => 'light', 'default' => true], ['label' => 'Dark', 'value' => 'dark']]]),
            $this->field(Dropdown::class, 'kind', ['options' => [['label' => 'Project', 'value' => 'project'], ['label' => 'Guide', 'value' => 'guide']]]),
            $builder,
        ])]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function makeArticle(string $title, string $paragraph, array $overrides = [], ?string $postDate = null, bool $live = true): Entry
    {
        return $this->makeEntry($this->articles, $title, $overrides + [
            'summary' => 'A summary line about ' . $title . ' that is long enough to count.',
            'theme' => 'light',
            'pageBuilder' => [
                'new1' => ['type' => 'hero', 'enabled' => true, 'fields' => []],
                'new2' => ['type' => 'longForm', 'enabled' => true, 'fields' => [
                    'content' => '<h2>The Problem</h2><p>' . $paragraph . '</p><blockquote><p>Challenge accepted.</p></blockquote>',
                    'numbered' => true,
                    'width' => 'narrow',
                ]],
                'new3' => ['type' => 'cards', 'enabled' => true, 'fields' => ['heading' => 'Broader uses', 'items' => [['col1' => 'Property searches'], ['col1' => 'Recipe selection']]]],
                'new4' => ['type' => 'related', 'enabled' => true, 'fields' => ['heading' => 'More articles', 'limit' => 3]],
                'new5' => ['type' => 'gallery', 'enabled' => false, 'fields' => ['caption' => 'Switched off']],
            ],
        ], $live, postDate: $postDate);
    }

    protected function makeNewsSection(): Section
    {
        $builder = $this->makeNeo('newsBuilder', [
            ['handle' => 'textWithAsset', 'fields' => [$this->field(Number::class, 'paddingTop'), $this->field(Lightswitch::class, 'reverse')], 'children' => ['text', 'button']],
            ['handle' => 'text', 'topLevel' => false, 'fields' => [$this->field(Ckeditor::class, 'richText')]],
            ['handle' => 'button', 'topLevel' => false, 'fields' => [$this->field(Entries::class, 'linkTo')]],
            ['handle' => 'spacer', 'fields' => [$this->field(Number::class, 'height')]],
            ['handle' => 'assetSingle', 'fields' => [$this->field(Entries::class, 'picture')]],
        ]);

        return $this->news = $this->makeSection('news', [$this->makeEntryType('newsArticle', [$builder])]);
    }

    /**
     * A press release: a picture, then text with its writing in a child
     * block, a spacer, more text, a spacer.
     *
     * @param array<int, string> $paragraphs
     */
    protected function makeNewsArticle(string $title, array $paragraphs, ?string $postDate = null): Entry
    {
        $blocks = ['new1' => ['type' => 'assetSingle', 'enabled' => true, 'level' => 1, 'fields' => []]];
        $n = 1;

        foreach ($paragraphs as $paragraph) {
            $blocks['new' . ++$n] = ['type' => 'textWithAsset', 'enabled' => true, 'level' => 1, 'fields' => ['paddingTop' => 40, 'reverse' => false]];
            $blocks['new' . ++$n] = ['type' => 'text', 'enabled' => true, 'level' => 2, 'fields' => ['richText' => "<h3>{$title}</h3><p>{$paragraph}</p>"]];
            $blocks['new' . ++$n] = ['type' => 'spacer', 'enabled' => true, 'level' => 1, 'fields' => ['height' => 80]];
        }

        return $this->makeEntry($this->news, $title, ['newsBuilder' => ['blocks' => $blocks, 'sortOrder' => array_keys($blocks)]], postDate: $postDate);
    }

    protected function makePressSection(): Section
    {
        return $this->press = $this->makeSection('press', [$this->makeEntryType('pressItem', [
            $this->field(PlainText::class, 'subheading'),
            $this->field(Entries::class, 'thumbnail'),
        ])]);
    }

    /**
     * A field, made once and reused, as fields are across a Craft site.
     */
    private function field(string $class, string $handle, array $config = []): \craft\base\FieldInterface
    {
        return \Craft::$app->getFields()->getFieldByHandle($handle) ?? $this->makeField($class, $handle, $config);
    }
}
