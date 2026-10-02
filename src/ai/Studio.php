<?php

namespace nineteenninetyfour\ghostwriter\ai;

use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Effort;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Truncated;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Text\LenientYaml;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use nineteenninetyfour\ghostwriter\layouts\PatternFinder;
use nineteenninetyfour\ghostwriter\layouts\SchemaDescriber;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\sessions\Session;
use nineteenninetyfour\ghostwriter\types\ContentType;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use yii\base\Component;

/**
 * Every call Ghostwriter makes to a model goes through here: building the
 * instructions from the prompts and turning the answer back into something
 * the rest of the plugin can use. ghostwriter-core chooses the provider and
 * model from the settings and makes the call.
 */
class Studio extends Component
{
    /** The most room an answer is given when it needs more than its usual limit. */
    private const MAX_TOKENS_CEILING = 32000;

    /** Entries shown when suggesting kinds: enough to see the pattern. */
    private const KIND_SAMPLE = 60;

    /** Examples are trimmed to this many characters each. */
    private const EXAMPLE_LIMIT = 7000;

    /**
     * Whether the chosen provider has an API key to call with.
     */
    public function configured(): bool
    {
        return Plugin::getInstance()->providers->configured();
    }

    public function provider(): string
    {
        return Plugin::getInstance()->providers->handle();
    }

    /**
     * @param array<int, array{title: string, section: string, url: ?string, text: string}> $samples
     */
    public function analyseVoice(array $samples): TaggedResponse
    {
        $prompt = 'Here are ' . count($samples) . " samples of published writing from the website.\n\n"
            . implode("\n\n", array_map(fn(array $sample, int $i) => sprintf(
                "<sample number=\"%d\" collection=\"%s\" title=\"%s\">\n%s\n</sample>",
                $i + 1,
                htmlspecialchars($sample['section']),
                htmlspecialchars($sample['title']),
                $sample['text'],
            ), $samples, array_keys($samples)))
            . "\n\nWrite the tone of voice guide.";

        $response = $this->ask('voice-analyst', $this->prompt('voice-analyst'), $prompt);

        return new TaggedResponse('', trim($response->text), $response->usage->input, $response->usage->output);
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    public function refineVoice(string $guide, array $history, string $request): TaggedResponse
    {
        $prompt = "<current_guide>\n{$guide}\n</current_guide>\n\nRequest: {$request}";

        $response = $this->ask('voice-editor', $this->prompt('voice-editor'), $prompt, $history);

        return TaggedResponse::parse($response->text, 'document', $response->usage->input, $response->usage->output);
    }

    /**
     * Work out what a section holds and what to ask before writing for it.
     *
     * @param array<int, int> $examples Entry IDs to model the type on; empty to use the section's newest.
     */
    public function analyseSection(Section $section, EntryType $entryType, ?string $title = null, array $examples = []): ContentType
    {
        $schema = (new SchemaReader())->read($entryType);
        $pattern = (new PatternFinder())->find($section->handle, $schema, $entryType->handle, [], $examples);

        $prompt = 'Section: ' . Craft::t('site', $section->name) . " ({$pattern['entries']} entries studied)\n\n"
            . ($title ? "The editors call this kind of content \"{$title}\". Use that as the title.\n\n" : '')
            . ($examples ? "The entries below were chosen by an editor as the model for this kind of content. Other entries in the section may look different; describe only these.\n\n" : '')
            . "## The fields\n\n" . (new SchemaDescriber())->describe($schema, $pattern) . "\n\n"
            . "## Existing entries\n\n" . $this->examples($pattern) . "\n\n"
            . 'Write the type.';

        $instructions = $this->prompt('type-analyst');
        $response = $this->ask('type-analyst', $instructions, $prompt);
        [$data, $problem] = $this->readType($response->text);

        // An answer that cannot be read gets one more chance, told what was wrong.
        if ($data === null) {
            Craft::warning("The type analysis for {$section->handle} could not be read ({$problem}):\n{$response->text}", 'ghostwriter');

            $response = $this->ask('type-analyst', $instructions, "Your answer could not be read: {$problem}. Reply again with the whole type, as one YAML document inside a <type> block and nothing else.", [
                ['role' => 'user', 'content' => $prompt],
                ['role' => 'assistant', 'content' => $response->text],
            ]);
            [$data, $problem] = $this->readType($response->text);
        }

        if ($data === null) {
            Craft::warning("The type analysis for {$section->handle} could not be read again ({$problem}):\n{$response->text}", 'ghostwriter');

            throw new InvalidArgumentException('The analysis came back in a form that could not be read. Try again.');
        }

        $types = Plugin::getInstance()->types;
        $handle = $types->handleFor($title ?: (string) ($data['title'] ?? ''), $section->handle);

        return ContentType::fromArray($handle, array_filter([
            'section' => $section->handle,
            'entryType' => count($section->getEntryTypes()) > 1 ? $entryType->handle : null,
            'examples' => $examples,
            'title' => $title,
        ]) + $data);
    }

    /**
     * Ideas for entries the site is missing, from what it has and what is
     * already planned.
     *
     * @param array<int, string> $sections Handles of the sections to plan for.
     * @param array<int, array<string, mixed>> $plan Ideas already on the plan, whatever their status.
     * @return array<int, array{title: string, section: string, type: ?string, why: string, notes: string}>
     */
    public function suggestIdeas(array $sections, array $plan, string $voice, string $steer = ''): array
    {
        $plugin = Plugin::getInstance();
        $described = [];

        foreach ($sections as $handle) {
            $section = Craft::$app->getEntries()->getSectionByHandle($handle);

            if (!$section) {
                continue;
            }

            $entries = Entry::find()->section($handle)->status(null)->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])->limit(150)->all();
            $lines = implode("\n", array_map(function(Entry $entry) use ($plugin) {
                $summary = trim((string) preg_replace('/\s+/u', ' ', mb_substr($plugin->prose->fromEntry($entry), 0, 160)));

                return '- ' . $entry->title . ($entry->getStatus() === Entry::STATUS_LIVE ? '' : ' (draft)') . ($summary !== '' ? ': ' . $summary : '');
            }, $entries));

            $kinds = implode("\n", array_map(fn(ContentType $type) => "- `{$type->handle}`: {$type->title}. {$type->description}", $plugin->types->forSection($handle)));

            $described[] = '### ' . Craft::t('site', $section->name) . " (`{$handle}`)\n\nKinds of content written here:\n" . ($kinds ?: '- none defined; leave `type` out') . "\n\nEntries:\n" . ($lines ?: '- none yet');
        }

        $planned = implode("\n", array_map(fn(array $idea) => "- {$idea['title']} ({$idea['section']}, {$idea['status']})", $plan));

        $instructions = strtr($this->prompt('planner'), [
            '{{ count }}' => (string) $plugin->getSettings()->planSuggestions,
            '{{ voice }}' => trim($voice) !== '' ? trim($voice) : 'No guide has been written yet. Judge the reader from the entries.',
            '{{ sections }}' => implode("\n\n", $described),
            '{{ plan }}' => $planned ?: 'Nothing yet.',
        ]);

        $response = $this->ask('planner', $instructions, trim($steer) !== '' ? "What I am looking for this time: {$steer}" : 'Suggest what is missing.');
        $block = TaggedResponse::parse($response->text, 'ideas')->document
            ?? throw new InvalidArgumentException('Ghostwriter did not come back with any ideas. Try again.');

        try {
            $ideas = (array) LenientYaml::parse($block);
        } catch (Throwable $exception) {
            // Kept in the log, so the next failure of this kind can be read.
            Craft::warning("The planner's ideas could not be read ({$exception->getMessage()}):\n{$block}", 'ghostwriter');

            throw new InvalidArgumentException('Ghostwriter did not come back with ideas it could read. Try again.');
        }

        $known = array_map(fn(array $idea) => mb_strtolower($idea['title']), $plan);
        $out = [];

        foreach ($ideas as $idea) {
            // The prompt asks for `collection`, its word from the Statamic
            // addon; `section` is taken too.
            $section = is_array($idea) ? (string) ($idea['section'] ?? $idea['collection'] ?? '') : '';

            if (!is_array($idea) || empty($idea['title']) || !in_array($section, $sections, true) || in_array(mb_strtolower(trim((string) $idea['title'])), $known, true)) {
                continue;
            }

            $type = $plugin->types->find((string) ($idea['type'] ?? ''));

            $out[] = [
                'title' => trim((string) $idea['title']),
                'section' => $section,
                'type' => $type && $type->section === $section ? $type->handle : null,
                'why' => trim((string) ($idea['why'] ?? '')),
                'notes' => trim((string) ($idea['notes'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Describe the style of one section's images from a spread of them.
     *
     * @param array<int, array{label: string, entry: string, image: Image}> $samples
     */
    public function analyseImagery(string $sectionName, array $samples): string
    {
        $list = implode("\n", array_map(fn(array $sample, int $i) => ($i + 1) . ". {$sample['label']}, on \"{$sample['entry']}\"", $samples, array_keys($samples)));

        $response = $this->ask('imagery-analyst', $this->prompt('imagery-analyst'), "Section: {$sectionName}\n\nThe attached images, in order:\n{$list}", images: array_column($samples, 'image'));

        return (string) (TaggedResponse::parse($response->text, 'document')->document ?? trim($response->text));
    }

    /**
     * Kinds of content a section holds, named as its editors would, each
     * with the entries that show it best. For a person to choose from.
     *
     * @return array<int, array{title: string, description: string, why: string, examples: array<int, int>, entryType: ?string}>
     */
    public function suggestKinds(Section $section): array
    {
        $plugin = Plugin::getInstance();
        $state = $plugin->kinds->get($section->handle);
        $taught = $plugin->types->forSection($section->handle);
        $entries = Entry::find()->section($section->handle)->status('live')->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])->limit(self::KIND_SAMPLE)->all();

        if (count($entries) < 2) {
            return [];
        }

        $reader = new SchemaReader();
        $data = new \nineteenninetyfour\ghostwriter\layouts\EntryData();
        $lines = [];

        foreach ($entries as $entry) {
            $schema = $reader->read($entry->getType());
            $builders = array_values(array_filter($schema, fn(array $spec) => $spec['kind'] === 'blocks'));
            $built = $builders ? array_map(fn($block) => $block['type'], array_filter((array) ($data->read($entry, [$builders[0]])[$builders[0]['handle']] ?? []), fn($block) => ($block['enabled'] ?? true) !== false)) : [];
            $opening = trim((string) preg_replace('/\s+/u', ' ', mb_substr($plugin->prose->fromEntry($entry), 0, 220)));

            $lines[] = sprintf(
                '- id %d · "%s"%s%s%s',
                $entry->id,
                $entry->title,
                count($section->getEntryTypes()) > 1 ? ' · entry type: ' . $entry->getType()->name : '',
                $entry->getParent() ? ' · under: ' . $entry->getParent()->title : '',
                ($built ? ' · built as: ' . implode(', ', array_unique($built)) : '') . ($opening !== '' ? ' · opens: "' . $opening . '"' : ''),
            );
        }

        $instructions = strtr($this->prompt('kind-finder'), [
            '{{ count }}' => '5',
            '{{ taught }}' => $taught ? implode("\n", array_map(fn(ContentType $type) => "- {$type->title}: {$type->description}", $taught)) : 'Nothing yet.',
            '{{ dismissed }}' => $state['dismissed'] ? '- ' . implode("\n- ", $state['dismissed']) : 'Nothing yet.',
        ]);

        $response = $this->ask('kind-finder', $instructions, 'Section: ' . Craft::t('site', $section->name) . "\n\nEntries, newest first:\n" . implode("\n", $lines));
        $block = TaggedResponse::parse($response->text, 'kinds')->document
            ?? throw new InvalidArgumentException('Ghostwriter did not come back with any kinds. Try again.');

        try {
            $kinds = (array) LenientYaml::parse($block);
        } catch (Throwable) {
            throw new InvalidArgumentException('Ghostwriter did not come back with kinds it could read. Try again.');
        }

        $byId = [];

        foreach ($entries as $entry) {
            $byId[(int) $entry->id] = $entry;
        }

        $known = array_map('mb_strtolower', [...array_map(fn(ContentType $type) => $type->title, $taught), ...$state['dismissed']]);
        $out = [];

        foreach ($kinds as $kind) {
            if (!is_array($kind) || trim((string) ($kind['title'] ?? '')) === '' || in_array(mb_strtolower(trim((string) $kind['title'])), $known, true)) {
                continue;
            }

            // Only entries really in this section, and at least two of them.
            $examples = array_values(array_unique(array_filter(array_map('intval', (array) ($kind['examples'] ?? [])), fn(int $id) => isset($byId[$id]))));

            if (count($examples) < 2) {
                continue;
            }

            $types = array_unique(array_map(fn(int $id) => $byId[$id]->getType()->handle, $examples));

            $out[] = [
                'title' => mb_substr(trim((string) $kind['title']), 0, 60),
                'description' => trim((string) ($kind['description'] ?? '')),
                'why' => trim((string) ($kind['why'] ?? '')),
                'examples' => array_slice($examples, 0, 6),
                'entryType' => count($types) === 1 ? reset($types) : null,
            ];
        }

        return $out;
    }

    /**
     * A content type from the analyst's answer, or why it could not be read.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function readType(string $text): array
    {
        $yaml = TaggedResponse::parse($text, 'type')->document;

        if ($yaml === null) {
            return [null, 'there was no <type> block'];
        }

        // Models sometimes put the YAML in a code fence inside the block.
        $yaml = (string) preg_replace('/\A```(?:yaml|yml)?\s*\n(.*?)\n?```\s*\z/s', '$1', trim($yaml));

        try {
            $data = LenientYaml::parse($yaml);
        } catch (Throwable $exception) {
            return [null, 'the YAML did not parse (' . $exception->getMessage() . ')'];
        }

        if (!is_array($data) || empty($data['questions']) || !is_array($data['questions'])) {
            return [null, 'it had no questions'];
        }

        return [$data, ''];
    }

    /**
     * A first attempt at a type's brief from a title and some notes, for a
     * person to correct. Answers are keyed by question handle.
     *
     * @return array<string, string>
     */
    public function draftBrief(ContentType $type, string $title, string $notes = ''): array
    {
        $questions = implode("\n", array_map(fn(array $question) => "- `{$question['handle']}`" . (($question['required'] ?? false) ? ' (required)' : ' (optional)') . ': ' . $question['label'] . (empty($question['instructions']) ? '' : ' ' . $question['instructions'])
            . (empty($question['options']) ? '' : ' One of: ' . implode(', ', array_keys($question['options'])) . '.'), $type->questions));

        $entries = Entry::find()->section($type->section)->status(null)->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])->limit(40)->all();
        $entries = implode("\n", array_map(fn(Entry $entry) => '- ' . $entry->title, $entries));

        $instructions = strtr($this->prompt('brief-writer'), [
            '{{ type_title }}' => $type->title,
            '{{ type_description }}' => $type->description,
            '{{ type_guidance }}' => $type->guidance,
            '{{ questions }}' => $questions,
            '{{ entries }}' => $entries !== '' ? $entries : 'None yet.',
        ]);

        $response = $this->ask('brief-writer', $instructions, "Working title: {$title}\n\nNotes:\n" . (trim($notes) !== '' ? trim($notes) : '(none)'));
        $block = TaggedResponse::parse($response->text, 'brief')->document
            ?? throw new InvalidArgumentException('Ghostwriter could not put a brief together from that. Try again, or fill it in by hand.');

        try {
            $answers = (array) LenientYaml::parse($block);
        } catch (Throwable) {
            throw new InvalidArgumentException('Ghostwriter could not put a brief together from that. Try again, or fill it in by hand.');
        }

        // Only the questions that were asked, as plain text.
        $out = [];

        foreach ($type->questions as $question) {
            $answer = $answers[$question['handle']] ?? null;
            $out[$question['handle']] = trim(is_scalar($answer) ? (string) $answer : '');
        }

        return $out;
    }

    /**
     * Run the next turn of a writing session. The session's last message is
     * the colleague's latest input; on the first turn that is the brief.
     */
    public function write(Session $session, ContentType $type, string $voice): TaggedResponse
    {
        $messages = array_map(fn(array $message) => ['role' => $message['role'], 'content' => $message['content']], $session->messages);
        $latest = array_pop($messages);

        $prompt = ($session->draft ? "<current_draft>\n{$session->draft}\n</current_draft>\n\n" : '') . ($latest['content'] ?? '');

        $response = $this->ask('writer', $this->writerInstructions($type, $voice), $prompt, $messages);

        return TaggedResponse::parse($response->text, 'draft', $response->usage->input, $response->usage->output);
    }

    /**
     * The questionnaire answers as the opening message of a session.
     */
    public function brief(ContentType $type, Session $session): string
    {
        $lines = ["Here is the brief for a new entry: {$type->title}.", ''];

        foreach ($type->questions as $question) {
            $answer = trim((string) ($session->answers[$question['handle']] ?? ''));

            $lines[] = '**' . $question['label'] . '**';
            $lines[] = $answer !== '' ? $answer : '(not answered)';
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    public function writerInstructions(ContentType $type, string $voice): string
    {
        $entryType = $type->craftEntryType()
            ?? throw new InvalidArgumentException("The section \"{$type->section}\" no longer exists.");

        $schema = (new SchemaReader())->read($entryType);
        $pattern = (new PatternFinder())->find($type->section, $schema, $type->entryType, $type->where, $type->examples);

        return strtr($this->prompt('writer'), [
            '{{ voice }}' => trim($voice) !== '' ? trim($voice) : 'No guide has been written yet. Write plainly and specifically, and match the existing entries shown below.',
            '{{ type_title }}' => $type->title,
            '{{ type_description }}' => $type->description,
            '{{ type_guidance }}' => $type->guidance,
            '{{ type_checklist }}' => $type->checklist ? '- ' . implode("\n- ", $type->checklist) : '- It reads like the existing entries.',
            '{{ fields }}' => (new SchemaDescriber())->describe($schema, $pattern),
            '{{ examples }}' => $this->examples($pattern),
            '{{ images }}' => $this->images($type),
        ]);
    }

    /**
     * What the writer is told about images. Finding and making them comes
     * with the image tools; until then image fields are a person's.
     */
    protected function images(ContentType $type): string
    {
        return 'This site has no image tools switched on. Leave image fields out of the draft; a person adds images afterwards. If asked for images, say so plainly.';
    }

    /**
     * @param array<string, mixed> $pattern
     */
    private function examples(array $pattern): string
    {
        if (empty($pattern['examples'])) {
            return 'Nothing has been published here yet, so there are no examples. Follow the fields and the guidance.';
        }

        return implode("\n\n", array_map(function(array $example, int $i) {
            $yaml = trim(Yaml::dump($example, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

            if (mb_strlen($yaml) > self::EXAMPLE_LIMIT) {
                $yaml = mb_substr($yaml, 0, self::EXAMPLE_LIMIT) . "\n# (example cut short)";
            }

            return '<example number="' . ($i + 1) . "\">\n{$yaml}\n</example>";
        }, $pattern['examples'], array_keys($pattern['examples'])));
    }

    /**
     * Ask a model. The room it has to answer and how hard it thinks come
     * from core's Agents unless given here; the model and timeout come from
     * the settings.
     *
     * @param array<int, array{role: string, content: string}> $history
     * @param Image[] $images
     * @throws ProviderException when the call fails, or Truncated when the answer is cut off even with more room.
     */
    public function ask(string $agent, string $instructions, string $prompt, array $history = [], array $images = [], ?int $maxTokens = null, Effort|string|null $effort = null): TextResponse
    {
        $limit = $maxTokens ?? Agents::maxTokens($agent);

        $request = new TextRequest(
            agent: $agent,
            instructions: $instructions,
            prompt: $prompt,
            history: Message::list($history),
            images: $images,
            maxTokens: $limit,
            effort: $effort ?? Agents::effort($agent),
        );
        $provider = Plugin::getInstance()->providers->text();
        $response = $provider->text($request);

        // Stopped at the length limit, not finished: a half-written draft
        // would be taken for a whole one. Ask once more with room to finish.
        if ($response->truncated() && $limit < self::MAX_TOKENS_CEILING) {
            $response = $provider->text($request->withMaxTokens(min($limit * 2, self::MAX_TOKENS_CEILING)));
        }

        if ($response->truncated()) {
            throw new Truncated('The answer ran past its length limit and was cut off before it finished. Try asking for something shorter.', $response->provider);
        }

        return $response;
    }

    public function prompt(string $name): string
    {
        return Plugin::getInstance()->paths->prompt($name);
    }
}
