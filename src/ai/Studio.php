<?php

namespace nineteenninetyfour\ghostwriter\ai;

use craft\models\EntryType;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Truncated;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio as CoreStudio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Studio\SuggestedIdea;
use NineteenNinetyFour\Ghostwriter\Core\Studio\SuggestedKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\sessions\Session;
use nineteenninetyfour\ghostwriter\types\ContentType;
use Psr\Log\LoggerInterface;
use yii\base\Component;

/**
 * Every call Ghostwriter makes to a model goes through here. Core's Studio
 * builds the prompts, makes the call and reads the answer; this side turns
 * Craft's sections, entries, types and sessions into its inputs
 * (StudioInputs) and its results back into Ghostwriter's own types and ideas.
 *
 * A reply that can't be read throws UnreadableReply (an
 * InvalidArgumentException); a draft or guide still cut off after a retry
 * with more room throws Truncated; anything else cut off is kept.
 */
class Studio extends Component
{
    /** Where core's Studio logs; Craft's log (CraftLogger) unless set. For tests. */
    public ?LoggerInterface $logger = null;

    private ?StudioInputs $inputs = null;

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
     * Core's Studio, with Craft's prompts, providers and log. Made each time,
     * so a change to the settings or a faked provider applies at once.
     */
    public function core(): CoreStudio
    {
        $plugin = Plugin::getInstance();

        return new CoreStudio(
            $plugin->providers->registry(),
            $plugin->paths->prompts(),
            $this->logger ?? new CraftLogger(),
            StudioOptions::craft(logReplies: $plugin->getSettings()->logsReplies()),
        );
    }

    public function inputs(): StudioInputs
    {
        return $this->inputs ??= new StudioInputs();
    }

    /**
     * @param array<int, array{title: string, section: string, url: ?string, text: string}> $samples
     * @throws ProviderException
     */
    public function analyseVoice(array $samples): TaggedResponse
    {
        return $this->core()->analyseVoice($this->inputs()->voiceSamples($samples));
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @throws ProviderException
     */
    public function refineVoice(string $guide, array $history, string $request): TaggedResponse
    {
        return $this->core()->refineVoice($guide, $history, $request);
    }

    /**
     * Work out what a section holds and what to ask before writing for it.
     *
     * @param array<int, int> $examples Entry IDs to model the type on; empty to use the section's newest.
     * @throws UnreadableReply|ProviderException
     */
    public function analyseSection(Section $section, EntryType $entryType, ?string $title = null, array $examples = []): ContentType
    {
        $data = $this->core()->analyseType($this->inputs()->typeSurvey($section, $entryType, $title, $examples))->value;
        $handle = Plugin::getInstance()->types->handleFor($title ?: (string) ($data['title'] ?? ''), $section->handle);

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
     * @throws UnreadableReply|ProviderException
     */
    public function suggestIdeas(array $sections, array $plan, string $voice, string $steer = ''): array
    {
        $ideas = $this->core()->suggestIdeas($this->inputs()->planContext($sections, $plan, $voice, $steer))->value;

        return array_map(fn(SuggestedIdea $idea) => $idea->toArray('section', 'type'), $ideas);
    }

    /**
     * Describe the style of one section's images from a spread of them.
     *
     * @param array<int, array{label: string, entry: string, image: Image}> $samples
     * @throws ProviderException
     */
    public function analyseImagery(string $sectionName, array $samples): string
    {
        return $this->core()->analyseImagery($sectionName, $this->inputs()->imagerySamples($samples))->value;
    }

    /**
     * Kinds of content a section holds, named as its editors would, each
     * with the entries that show it best. For a person to choose from.
     *
     * @return array<int, array{title: string, description: string, why: string, examples: array<int, int>, entryType: ?string}>
     * @throws UnreadableReply|ProviderException
     */
    public function suggestKinds(Section $section): array
    {
        $kinds = $this->core()->suggestKinds($this->inputs()->kindSurvey($section))->value;

        return array_map(fn(SuggestedKind $kind) => $kind->toArray('entryType'), $kinds);
    }

    /**
     * A first attempt at a type's brief from a title and some notes, for a
     * person to correct. Answers are keyed by question handle.
     *
     * @return array<string, string>
     * @throws UnreadableReply|ProviderException
     */
    public function draftBrief(ContentType $type, string $title, string $notes = ''): array
    {
        return $this->core()->draftBrief($this->inputs()->kind($type), $title, $notes, $this->inputs()->briefTitles($type))->value;
    }

    /**
     * Run the next turn of a writing session. The session's last message is
     * the colleague's latest input; on the first turn that is the brief.
     *
     * @throws Truncated|ProviderException
     */
    public function write(Session $session, ContentType $type, string $voice): TaggedResponse
    {
        return $this->core()->write($this->inputs()->conversation($session), $this->inputs()->writerContext($type, $voice, $this->images($type)));
    }

    /**
     * The questionnaire answers as the opening message of a session.
     */
    public function brief(ContentType $type, Session $session): string
    {
        return $this->core()->brief($this->inputs()->kind($type), $session->answers);
    }

    public function writerInstructions(ContentType $type, string $voice): string
    {
        return $this->core()->writerInstructions($this->inputs()->writerContext($type, $voice, $this->images($type)));
    }

    /**
     * What the writer is told about images. Finding and making them comes
     * with the image tools; until then image fields are a person's.
     */
    protected function images(ContentType $type): string
    {
        return 'This site has no image tools switched on. Leave image fields out of the draft; a person adds images afterwards. If asked for images, say so plainly.';
    }
}
