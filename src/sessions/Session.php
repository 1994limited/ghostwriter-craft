<?php

namespace nineteenninetyfour\ghostwriter\sessions;

use DateTime;

/**
 * One piece of content being written: the questionnaire answers it started
 * from, the conversation that refined it, the draft as it stands, and the
 * entry it is being written into.
 */
class Session
{
    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const FAILED = 'failed';

    /**
     * @param array<string, string> $answers
     * @param array<int, array<string, mixed>> $messages Each with role, content and at; the writer's may say whether it asks, and what it did to the draft.
     * @param array{input: int, output: int} $usage
     * @param array<int, int> $examples Entries this piece is modelled on, chosen with the brief.
     * @param int|null $elementId The entry (or its unpublished draft) the piece is written into.
     * @param int|null $source The existing entry being edited, when the session is not for something new.
     * @param string|null $entryType That entry's entry type, where the section has several.
     * @param string|null $appliedAt When the draft was last put into the entry's draft.
     * @param array<string, array<string, mixed>> $images Images chosen or made, keyed by the field each is for.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public array $answers = [],
        public array $messages = [],
        public ?string $draft = null,
        public string $status = self::IDLE,
        public ?string $error = null,
        public ?int $elementId = null,
        public ?int $siteId = null,
        public ?int $userId = null,
        public array $usage = ['input' => 0, 'output' => 0],
        public array $examples = [],
        public array $images = [],
        public ?int $source = null,
        public ?string $entryType = null,
        public ?string $appliedAt = null,
        public ?int $touchedBy = null,
        public ?int $runBy = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
        $this->createdAt ??= self::now();
        $this->updatedAt ??= $this->createdAt;
    }

    /**
     * @param array<string, string> $answers
     * @param array<int, int> $examples
     */
    public static function start(string $type, array $answers, ?int $userId = null, array $examples = []): self
    {
        return new self(id: bin2hex(random_bytes(13)), type: $type, answers: $answers, userId: $userId, examples: $examples);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $int = fn(string $key) => isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : null;

        return new self(
            id: (string) $data['id'],
            type: (string) $data['type'],
            answers: (array) ($data['answers'] ?? []),
            messages: (array) ($data['messages'] ?? []),
            draft: $data['draft'] ?? null,
            status: (string) ($data['status'] ?? self::IDLE),
            error: $data['error'] ?? null,
            elementId: $int('element_id'),
            siteId: $int('site_id'),
            userId: $int('user_id'),
            touchedBy: $int('touched_by'),
            runBy: $int('run_by'),
            usage: array_merge(['input' => 0, 'output' => 0], (array) ($data['usage'] ?? [])),
            examples: array_values(array_map('intval', (array) ($data['examples'] ?? []))),
            images: (array) ($data['images'] ?? []),
            source: $int('source'),
            entryType: $data['entry_type'] ?? null,
            appliedAt: $data['applied_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    /**
     * @param int|null $by Who sent it, for a person's message.
     */
    public function addMessage(string $role, string $content, ?int $by = null): void
    {
        $this->messages[] = ['role' => $role, 'content' => $content, 'at' => self::now()] + ($by !== null ? ['by' => $by] : []);
    }

    /**
     * Someone did something to the piece: asked for something, edited the
     * draft, or put it into the entry.
     */
    public function touch(?int $userId): void
    {
        if ($userId !== null) {
            $this->touchedBy = $userId;
        }
    }

    /**
     * Who the message being answered now came from: they are the one
     * waiting on Ghostwriter.
     */
    public function run(?int $userId): void
    {
        $this->status = self::WORKING;
        $this->error = null;
        $this->runBy = $userId;
        $this->touch($userId);
    }

    /**
     * The draft's title, for lists, before any entry exists.
     */
    public function title(): string
    {
        if ($this->draft && preg_match('/^title:\s*(.+)$/m', $this->draft, $m)) {
            return trim($m[1], " \t\"'");
        }

        foreach ($this->answers as $answer) {
            if (is_string($answer) && trim($answer) !== '') {
                return mb_strimwidth(trim(strtok($answer, "\n")), 0, 80, '…');
            }
        }

        return 'Untitled';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'answers' => $this->answers,
            'messages' => $this->messages,
            'draft' => $this->draft,
            'status' => $this->status,
            'error' => $this->error,
            'element_id' => $this->elementId,
            'site_id' => $this->siteId,
            'user_id' => $this->userId,
            'touched_by' => $this->touchedBy,
            'run_by' => $this->runBy,
            'usage' => $this->usage,
            'examples' => $this->examples,
            'images' => $this->images,
            'source' => $this->source,
            'entry_type' => $this->entryType,
            'applied_at' => $this->appliedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    public static function now(): string
    {
        return (new DateTime())->format(DATE_ATOM);
    }
}
