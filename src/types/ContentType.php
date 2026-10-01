<?php

namespace nineteenninetyfour\ghostwriter\types;

use Craft;
use craft\models\EntryType;
use craft\models\Section;
use nineteenninetyfour\ghostwriter\sessions\Session;

/**
 * One kind of content written into a section: the questions asked before
 * writing, and guidance on what a good one looks like. A section can have
 * several (an article and a guide, say). The fields themselves are not stored
 * here; they are read from the entry type each time, so a change to the
 * field layout is picked up at once.
 */
class ContentType
{
    /** Handle prefix of the built-in type every section has. */
    public const GENERIC = 'any:';

    /**
     * @param array<int, array{handle: string, label: string, type?: string, instructions?: string, required?: bool, options?: array<int|string, string>}> $questions
     * @param array<int, string> $checklist
     * @param string|null $entryType Handle of the entry type written; null for the section's first.
     * @param array<string, mixed> $where Narrows which existing entries this type learns from.
     * @param array<string, mixed> $defaults Field values set on everything written as this type.
     * @param array<int, int> $examples IDs of entries to learn from, instead of the section's newest.
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $title,
        public readonly string $description,
        public readonly string $section,
        public readonly array $questions,
        public readonly string $guidance,
        public readonly array $checklist = [],
        public readonly ?string $entryType = null,
        public readonly array $where = [],
        public readonly array $defaults = [],
        public readonly array $examples = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $handle, array $data): self
    {
        $questions = [];

        foreach ((array) ($data['questions'] ?? []) as $question) {
            if (is_array($question) && isset($question['handle'], $question['label'])) {
                $questions[] = $question;
            }
        }

        return new self(
            handle: $handle,
            title: (string) ($data['title'] ?? ucfirst(str_replace(['-', '_'], ' ', $handle))),
            description: trim((string) ($data['description'] ?? '')),
            section: (string) ($data['section'] ?? ''),
            questions: $questions,
            guidance: trim((string) ($data['guidance'] ?? '')),
            checklist: array_values(array_filter((array) ($data['checklist'] ?? []), 'is_string')),
            entryType: ($data['entryType'] ?? null) ?: null,
            where: (array) ($data['where'] ?? []),
            defaults: (array) ($data['defaults'] ?? []),
            examples: array_values(array_map('intval', array_filter((array) ($data['examples'] ?? []), 'is_numeric'))),
        );
    }

    /**
     * The type every section has without being taught anything: a general
     * brief, and no fixed recipe. What is written follows the entries chosen
     * as models when the piece is started, or the brief alone.
     */
    public static function generic(Section $section): self
    {
        return new self(
            handle: self::GENERIC . $section->handle,
            title: 'Something new',
            description: 'A general brief for anything in ' . Craft::t('site', $section->name) . '. Pick entries to model it on, or describe what you want and let Ghostwriter choose the shape.',
            section: $section->handle,
            questions: [
                ['handle' => 'subject', 'label' => 'What is this about?', 'instructions' => 'The subject, and what the entry is for.', 'type' => 'textarea', 'required' => true],
                ['handle' => 'reader', 'label' => 'Who is it for, and what should they do after reading?', 'type' => 'textarea', 'required' => true],
                ['handle' => 'points', 'label' => 'What must it say?', 'instructions' => 'The facts, figures, names and points to make. Nothing beyond these will be claimed.', 'type' => 'textarea', 'required' => true],
                ['handle' => 'shape', 'label' => 'Anything about its shape or length?', 'instructions' => 'Leave blank to follow the entries it is modelled on.', 'type' => 'text'],
                ['handle' => 'must_not_appear', 'label' => 'What must not appear?', 'type' => 'textarea'],
            ],
            guidance: "There is no set recipe for this entry.\n\nIf example entries are shown, they were chosen as the model: follow their structure block for block and match their length. Keep unchanged any block that is identical across the examples, such as process steps or testimonials, and never reword a quotation.\n\nIf the brief asks for a different shape, the brief wins. With no examples to follow, choose the fields and blocks that suit what the brief asks for, preferring those this section already uses.",
            checklist: ['Every fact comes from the brief or the conversation.', 'Its structure follows the chosen examples, or suits the brief where there are none.'],
        );
    }

    public function isGeneric(): bool
    {
        return str_starts_with($this->handle, self::GENERIC);
    }

    /**
     * The same type, modelled on different entries: those picked for one
     * particular piece rather than for the type as a whole.
     *
     * @param array<int, int> $examples
     */
    public function modelledOn(array $examples): self
    {
        if ($examples === []) {
            return $this;
        }

        return new self($this->handle, $this->title, $this->description, $this->section, $this->questions, $this->guidance, $this->checklist, $this->entryType, [], $this->defaults, $examples);
    }

    /**
     * The type as one session uses it: modelled on the entries picked for
     * that piece, and on the entry type of the entry being written into.
     */
    public function forSession(Session $session): self
    {
        $type = $this->modelledOn($session->examples);

        if (!$session->entryType || $session->entryType === $type->entryType) {
            return $type;
        }

        return new self($type->handle, $type->title, $type->description, $type->section, $type->questions, $type->guidance, $type->checklist, $session->entryType, $type->where, $type->defaults, $type->examples);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'section' => $this->section,
            'entryType' => $this->entryType,
            'where' => $this->where ?: null,
            'defaults' => $this->defaults ?: null,
            'examples' => $this->examples ?: null,
            'questions' => $this->questions,
            'guidance' => $this->guidance,
            'checklist' => $this->checklist,
        ], fn($value) => $value !== null);
    }

    /**
     * What the questionnaire needs.
     *
     * @return array<string, mixed>
     */
    public function forQuestionnaire(): array
    {
        return [
            'handle' => $this->handle,
            'title' => $this->title,
            'description' => $this->description,
            'questions' => $this->questions,
            'examples' => $this->examples,
            'entryType' => $this->entryType,
            'generic' => $this->isGeneric(),
        ];
    }

    public function craftSection(): ?Section
    {
        return Craft::$app->getEntries()->getSectionByHandle($this->section);
    }

    /**
     * The entry type written: the type's own, or the section's first.
     */
    public function craftEntryType(): ?EntryType
    {
        $types = $this->craftSection()?->getEntryTypes() ?? [];

        foreach ($types as $type) {
            if ($this->entryType === null || $type->handle === $this->entryType) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Answers that are missing for required questions, by handle.
     *
     * @param array<string, mixed> $answers
     * @return array<string, string>
     */
    public function missing(array $answers): array
    {
        $errors = [];

        foreach ($this->questions as $question) {
            $answer = $answers[$question['handle']] ?? null;

            if (($question['required'] ?? false) && (!is_scalar($answer) || trim((string) $answer) === '')) {
                $errors[$question['handle']] = 'This needs an answer.';
            } elseif (is_scalar($answer) && mb_strlen((string) $answer) > 20000) {
                $errors[$question['handle']] = 'Keep this under 20,000 characters.';
            }
        }

        return $errors;
    }
}
