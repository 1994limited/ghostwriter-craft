<?php

namespace nineteenninetyfour\ghostwriter\ai;

use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ImagerySample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanContext;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanGroup;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanItem;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlannedIdea;
use NineteenNinetyFour\Ghostwriter\Core\Studio\TypeSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\VoiceSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use nineteenninetyfour\ghostwriter\layouts\EntryReader;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * Craft's objects as the inputs core's Studio takes: sections, entry types
 * and their field layouts, entries, content types and sessions. Choosing
 * what to show (which entries, how many) stays here, as it did before core.
 */
class StudioInputs
{
    /** Entries shown when suggesting kinds: enough to see the pattern. */
    public const KIND_SAMPLE = 60;

    /** Entries per section shown to the planner. */
    public const PLAN_ENTRIES = 150;

    /** Titles shown to the brief writer. */
    public const BRIEF_TITLES = 40;

    /**
     * @param array<int, array{title: string, section: string, url: ?string, text: string}> $samples From the content scanner.
     * @return array<int, VoiceSample>
     */
    public function voiceSamples(array $samples): array
    {
        return array_map(fn(array $sample) => new VoiceSample($sample['title'], $sample['section'], $sample['text']), array_values($samples));
    }

    /**
     * @param array<int, int> $examples Entry IDs chosen to model the type on; empty for the section's newest.
     */
    public function typeSurvey(Section $section, EntryType $entryType, ?string $title = null, array $examples = []): TypeSurvey
    {
        $layouts = Plugin::getInstance()->layouts;
        $schema = (new SchemaReader())->schema($entryType);
        $pattern = $layouts->pattern($section->handle, $schema, $entryType->handle, [], $examples);

        return new TypeSurvey(
            Craft::t('site', $section->name),
            $section->handle,
            $layouts->layout($schema, $pattern),
            $title,
            $examples !== [],
        );
    }

    /**
     * A content type's field layout and examples, as the writer sees them.
     */
    public function layout(ContentType $type): Layout
    {
        $entryType = Plugin::getInstance()->types->entryType($type)
            ?? throw new \InvalidArgumentException("The section \"{$type->group}\" no longer exists.");

        $layouts = Plugin::getInstance()->layouts;
        $schema = (new SchemaReader())->schema($entryType);

        return $layouts->layout($schema, $layouts->pattern($type->group, $schema, $type->variant, $type->where, $type->examples));
    }

    /**
     * The newest live entries of a section, each with the block types it is
     * built from and how it opens, and the kinds already taught or turned down.
     */
    public function kindSurvey(Section $section): KindSurvey
    {
        $plugin = Plugin::getInstance();
        $several = count($section->getEntryTypes()) > 1;
        $entries = Entry::find()->section($section->handle)->status('live')->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])->limit(self::KIND_SAMPLE)->all();

        $reader = new SchemaReader();
        $data = new EntryReader();
        $samples = [];

        foreach ($entries as $entry) {
            $type = $entry->getType();
            $builders = array_values(array_filter($reader->read($type), fn(array $spec) => $spec['kind'] === 'blocks'));
            $blocks = $builders ? (array) ($data->read($entry, [$builders[0]])[$builders[0]['handle']] ?? []) : [];
            $built = array_values(array_map(fn($block) => (string) $block['type'], array_filter($blocks, fn($block) => ($block['enabled'] ?? true) !== false)));

            $samples[] = new KindSample(
                (int) $entry->id,
                (string) $entry->title,
                $plugin->prose->fromEntry($entry),
                $built,
                $entry->getParent()?->title,
                $type->handle,
                $several ? $type->name : null,
            );
        }

        return new KindSurvey(
            Craft::t('site', $section->name),
            $section->handle,
            $samples,
            $this->kinds($plugin->types->forSection($section->handle)),
            $plugin->types->suggestions($section->handle)->dismissed,
        );
    }

    /**
     * The sections to plan for, with what each holds, and the plan so far.
     *
     * @param array<int, string> $sections Section handles; unknown ones are left out.
     * @param array<int, Idea> $plan Ideas already on the plan.
     */
    public function planContext(array $sections, array $plan, string $voice, string $steer = ''): PlanContext
    {
        $plugin = Plugin::getInstance();
        $groups = [];

        foreach ($sections as $handle) {
            $section = Craft::$app->getEntries()->getSectionByHandle($handle);

            if (!$section) {
                continue;
            }

            $entries = Entry::find()->section($handle)->status(null)->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])->limit(self::PLAN_ENTRIES)->all();

            $groups[] = new PlanGroup(
                Craft::t('site', $section->name),
                $handle,
                $this->kinds($plugin->types->forSection($handle)),
                array_map(fn(Entry $entry) => PlanItem::fromProse((string) $entry->title, $entry->getStatus() === Entry::STATUS_LIVE, $plugin->prose->fromEntry($entry)), $entries),
            );
        }

        return new PlanContext(
            $groups,
            array_map(fn(Idea $idea) => new PlannedIdea($idea->title, $idea->group, $idea->status), array_values($plan)),
            $voice,
            $steer,
            $plugin->getSettings()->planSuggestions,
        );
    }

    /**
     * Titles of a section's newest entries, any status, for the brief writer.
     *
     * @return array<int, string>
     */
    public function briefTitles(ContentType $type): array
    {
        $entries = Entry::find()->section($type->group)->status(null)->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])->limit(self::BRIEF_TITLES)->all();

        return array_map(fn(Entry $entry) => (string) $entry->title, $entries);
    }

    /**
     * @param array<int, array{label: string, entry: string, image: Image, asset?: \NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef|null, filename?: string|null}> $samples From the image sampler.
     * @return array<int, ImagerySample>
     */
    public function imagerySamples(array $samples): array
    {
        return array_map(fn(array $sample) => new ImagerySample($sample['label'], $sample['entry'], $sample['image'], $sample['asset'] ?? null, $sample['filename'] ?? null), array_values($samples));
    }

    public function kind(ContentType $type): ContentKind
    {
        return $type->toStudio();
    }

    public function conversation(Session $session): Conversation
    {
        return new Conversation($session->messages, $session->draft, $session->answers);
    }

    public function writerContext(ContentType $type, string $voice, string $images): WriterContext
    {
        return new WriterContext($this->kind($type), $voice, $this->layout($type), $images);
    }

    /**
     * Content types by name only, as the kind finder and planner list them.
     *
     * @param array<int, ContentType> $types
     * @return array<int, ContentKind>
     */
    private function kinds(array $types): array
    {
        return array_map(fn(ContentType $type) => new ContentKind($type->handle, $type->title, $type->description), array_values($types));
    }
}
