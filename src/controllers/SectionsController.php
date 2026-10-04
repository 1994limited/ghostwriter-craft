<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Layout\FoundKind;
use nineteenninetyfour\ghostwriter\ai\StudioInputs;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\AnalyseSection;
use nineteenninetyfour\ghostwriter\jobs\SuggestKinds;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * What the Ghostwriter panel needs to know about a section, the request that
 * teaches it a new kind of content there, and starting a new entry with the
 * panel open.
 */
class SectionsController extends Controller
{
    public function actionShow(): Response
    {
        return $this->asJson($this->payload($this->section(), $this->request->getQueryParam('entryType')));
    }

    /**
     * Learn a kind of content: from the section's newest entries, or from the
     * entries named in `examples` when a section holds several kinds.
     */
    public function actionAnalyse(): Response
    {
        $this->requirePostRequest();

        $section = $this->section();
        $plugin = Plugin::getInstance();

        if ($refusal = $this->notConfigured()) {
            return $this->request->getAcceptsJson() ? $refusal : $this->back((string) $refusal->data['message']);
        }

        if ($plugin->types->analysis($section->handle)->isWorking()) {
            return $this->request->getAcceptsJson()
                ? $this->refuse('Ghostwriter is already learning this section.', 409)
                : $this->back('Ghostwriter is already learning this section.');
        }

        $title = trim((string) $this->request->getBodyParam('title'));
        $examples = array_slice(array_values(array_filter((array) $this->request->getBodyParam('examples'), 'is_numeric')), 0, 6);

        // Entries from another section cannot be the model.
        $examples = $examples ? Entry::find()->id($examples)->section($section->handle)->status(null)->fixedOrder()->ids() : [];

        $plugin->types->changeAnalysis($section->handle, fn(Analysis $analysis) => $analysis->begin());

        AnalyseSection::start(['section' => $section->handle, 'title' => $title !== '' ? mb_substr($title, 0, 60) : null, 'examples' => array_map('intval', $examples)]);

        if (!$this->request->getAcceptsJson()) {
            $this->setSuccessFlash(Craft::t('ghostwriter', 'Ghostwriter is reading the entries. The new kind appears here in a minute or so.'));

            return $this->redirect('ghostwriter');
        }

        return $this->asJson($this->payload($section));
    }

    /**
     * Look for kinds of content: in one section, or in every section
     * Ghostwriter writes for when none is given.
     *
     * With `due`, it is Get started looking by itself as its kinds step
     * opens: only when suggestKindsAutomatically is on, and only the
     * sections never looked at or with enough published since the last
     * look. Nowhere else looks without being asked.
     */
    public function actionSuggestKinds(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $due = (bool) $this->request->getBodyParam('due');

        if ($due && !$plugin->getSettings()->suggestKindsAutomatically) {
            return $this->asJson(['status' => 'idle', 'sections' => $this->kindStates()]);
        }

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        $sections = $this->request->getBodyParam('section') ? [$this->section()] : $plugin->types->sections();
        $handles = [];

        foreach ($sections as $section) {
            if ($due ? $plugin->types->due($section) : !$plugin->types->suggestions($section->handle)->isWorking()) {
                $plugin->types->changeSuggestions($section->handle, function(KindSuggestions $suggestions): void {
                    if (!$suggestions->isWorking()) {
                        $suggestions->begin();
                    }
                });
                $handles[] = $section->handle;
            }
        }

        if ($handles !== []) {
            SuggestKinds::start(['sections' => $handles]);
        }

        return $this->asJson(['status' => $due && $handles === [] ? 'idle' : 'working', 'sections' => $this->kindStates()]);
    }

    public function actionKinds(): Response
    {
        return $this->asJson(['sections' => $this->kindStates()]);
    }

    /**
     * Not this one: off the list, and not suggested again.
     */
    public function actionDismissKind(): Response
    {
        $this->requirePostRequest();

        $id = (string) $this->request->getRequiredBodyParam('id');

        Plugin::getInstance()->types->changeSuggestions($this->section()->handle, fn(KindSuggestions $suggestions) => $suggestions->remove($id, dismissed: true));

        return $this->asJson(['sections' => $this->kindStates()]);
    }

    /**
     * Learn a suggested kind: its name and the entries that show it go to
     * the same job as teaching one by hand.
     */
    public function actionLearnKind(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $section = $this->section();
        $suggestion = $plugin->types->suggestions($section->handle)->find((string) $this->request->getRequiredBodyParam('id'))
            ?? throw new NotFoundHttpException('That suggestion has gone.');

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        if ($plugin->types->analysis($section->handle)->isWorking()) {
            return $this->refuse('Ghostwriter is already learning a kind in this section. Try again in a minute.', 409);
        }

        $plugin->types->changeAnalysis($section->handle, fn(Analysis $analysis) => $analysis->begin());

        AnalyseSection::start(['section' => $section->handle, 'title' => $suggestion['title'], 'examples' => $suggestion['examples']]);

        $plugin->types->changeSuggestions($section->handle, fn(KindSuggestions $suggestions) => $suggestions->remove((string) $suggestion['id']));

        return $this->asJson(['status' => 'working', 'sections' => $this->kindStates()]);
    }

    /**
     * Learn every kind suggested for a section, one after another.
     */
    public function actionLearnAllKinds(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $section = $this->section();
        $suggestions = $plugin->types->suggestions($section->handle)->suggestions;

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        if ($suggestions === []) {
            return $this->refuse('There is nothing suggested to learn.');
        }

        if ($plugin->types->analysis($section->handle)->isWorking()) {
            return $this->refuse('Ghostwriter is already learning a kind in this section. Try again in a minute.', 409);
        }

        $plugin->types->changeAnalysis($section->handle, fn(Analysis $analysis) => $analysis->begin());

        AnalyseSection::start([
            'section' => $section->handle,
            'kinds' => array_map(fn(array $suggestion) => ['title' => $suggestion['title'], 'examples' => $suggestion['examples']], $suggestions),
        ]);

        $plugin->types->changeSuggestions($section->handle, function(KindSuggestions $state) use ($suggestions): void {
            foreach ($suggestions as $suggestion) {
                $state->remove((string) $suggestion['id']);
            }
        });

        return $this->asJson(['status' => 'working', 'sections' => $this->kindStates()]);
    }

    /**
     * Each section's suggestions and whether it is being looked at or learned.
     *
     * @return array<string, array<string, mixed>>
     */
    private function kindStates(): array
    {
        $plugin = Plugin::getInstance();
        $states = [];

        foreach ($plugin->types->sections() as $section) {
            $states[$section->handle] = $plugin->types->suggestions($section->handle)->toArray() + ['learning' => $plugin->types->analysis($section->handle)->toArray()];
        }

        return $states;
    }

    /**
     * Start a new entry with Ghostwriter open on it. Craft's own action makes
     * the draft, so sites, default status, authors and permissions behave
     * exactly as with its own New entry button.
     */
    public function actionNew(?string $section = null): Response
    {
        $section = $this->section($section);

        $response = Craft::$app->runAction('entries/create', ['section' => $section->handle]);

        $location = $response instanceof Response ? $response->getHeaders()->get('location') : null;

        if (!$location) {
            return $response;
        }

        return $this->redirect(UrlHelper::urlWithParams($location, array_filter([
            'ghostwriter' => 'new',
            'idea' => $this->request->getQueryParam('idea'),
        ])));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Section $section, ?string $entryType = null): array
    {
        $plugin = Plugin::getInstance();
        $types = $plugin->types->offeredFor($section->handle);
        $presenter = new Presenter();

        $craftType = null;

        foreach ($section->getEntryTypes() as $candidate) {
            if ($entryType === null || $candidate->handle === $entryType) {
                $craftType = $candidate;
                break;
            }
        }

        return [
            'configured' => $plugin->studio->configured(),
            'provider' => $plugin->studio->provider(),
            'keyName' => $plugin->providers::KEYS[$plugin->studio->provider()] ?? null,
            'hasVoice' => $plugin->domain->guide(Guide::VOICE)->exists(),
            'voiceUrl' => UrlHelper::cpUrl('ghostwriter/voice'),
            'section' => ['handle' => $section->handle, 'title' => Craft::t('site', $section->name)],
            'state' => $plugin->types->analysis($section->handle)->toArray(),
            // On a form for one entry type, only the types written for it.
            'types' => array_values(array_map(
                fn(ContentType $type) => $plugin->types->questionnaire($type),
                array_filter($types, fn(ContentType $type) => !$entryType || !$type->variant || $type->variant === $entryType),
            )),
            // Kinds of entry found by how the existing ones are built,
            // offered as ready-made models for something new.
            'kinds' => $craftType ? array_map(fn(FoundKind $kind) => $kind->toArray(), $plugin->layouts->kinds($section->handle, (new SchemaReader())->schema($craftType), count($section->getEntryTypes()) > 1 ? $craftType->handle : null)) : [],
            'entries' => $this->entries($section),
            // Ideas from the content plan waiting to be written here.
            'ideas' => array_values(array_map(
                fn(Idea $idea) => array_intersect_key(PlanController::idea($idea), array_flip(['id', 'title', 'type', 'why', 'notes'])),
                array_filter($plugin->domain->ideas(), fn(Idea $idea) => $idea->group === $section->handle && $idea->isOpen()),
            )),
            'sessions' => array_values(array_filter(array_map(
                fn($session) => $presenter->summary($session),
                array_filter($plugin->domain->sessions()->visible($plugin->domain->viewer()), fn($session) => isset($types[$session->kind]) && !$session->isEditing()),
            ), fn(array $summary) => !$summary['finished'])),
        ];
    }

    /**
     * The entries something new can be modelled on. A structure is listed in
     * its own order with each entry's depth, so the picker can show the
     * site's sections; any other section is listed newest first.
     *
     * @return array<int, array{id: int, title: string, live: bool, depth: int}>
     */
    private function entries(Section $section): array
    {
        $query = Entry::find()->section($section->handle)->status(null)->limit(200);

        if ($section->type === Section::TYPE_STRUCTURE) {
            $query->orderBy(['structureelements.lft' => SORT_ASC]);
        } else {
            $query->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC]);
        }

        $entries = $query->all();

        // The newest live entries, which the brief may tick, are always listed.
        if ($section->type === Section::TYPE_STRUCTURE) {
            $listed = array_map(fn(Entry $entry) => (int) $entry->id, $entries);
            $newest = Entry::find()->section($section->handle)->status(Entry::STATUS_LIVE)->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])->limit(StudioInputs::BRIEF_TITLES)->all();
            $entries = [...$entries, ...array_filter($newest, fn(Entry $entry) => !in_array((int) $entry->id, $listed, true))];
        }

        return array_map(fn(Entry $entry) => [
            'id' => (int) $entry->id,
            'title' => (string) $entry->title,
            'live' => $entry->getStatus() === Entry::STATUS_LIVE,
            'depth' => max(0, (int) $entry->level - 1),
        ], array_values($entries));
    }

    private function section(?string $handle = null): Section
    {
        $handle ??= (string) ($this->request->getParam('section') ?? '');
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);

        if (!$section || !Plugin::getInstance()->types->enabled($section->handle)) {
            throw new NotFoundHttpException('Ghostwriter does not write for that section.');
        }

        return $section;
    }

    private function back(string $message): Response
    {
        $this->setFailFlash($message);

        return $this->redirect('ghostwriter');
    }
}
