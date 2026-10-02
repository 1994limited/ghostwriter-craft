<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\types\ContentType;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Editing a content type: its name, the questions asked before writing, the
 * guidance given to the writer and the entries it is modelled on. Also the
 * screen for teaching Ghostwriter a new one.
 */
class TypesController extends Controller
{
    /**
     * @param ContentType|null $type The kind as it was posted, when saving it failed.
     */
    public function actionEdit(string $handle, ?ContentType $type = null): Response
    {
        $type ??= $this->type($handle);

        return $this->renderTemplate('ghostwriter/types/_edit', [
            'type' => $type,
            'sectionName' => Craft::t('site', (string) $type->craftSection()?->name),
            'sectionUid' => $type->craftSection()?->uid,
            'examples' => $type->examples ? Entry::find()->id($type->examples)->status(null)->fixedOrder()->all() : [],
            'questions' => array_map(fn(array $question) => [
                'label' => $question['label'],
                'handle' => $question['handle'],
                'instructions' => $question['instructions'] ?? '',
                'type' => $question['type'] ?? 'textarea',
                'required' => (bool) ($question['required'] ?? false),
            ], $type->questions),
        ]);
    }

    public function actionTeach(string $section): Response
    {
        $found = Craft::$app->getEntries()->getSectionByHandle($section);

        if (!$found || !Plugin::getInstance()->types->enabled($section)) {
            throw new NotFoundHttpException('Ghostwriter does not write for that section.');
        }

        $this->view->registerAssetBundle(GhostwriterAsset::class);

        return $this->renderTemplate('ghostwriter/types/_teach', [
            'section' => $found,
            'configured' => Plugin::getInstance()->studio->configured(),
            'provider' => Plugin::getInstance()->studio->provider(),
            'keyName' => Plugin::getInstance()->providers::KEYS[Plugin::getInstance()->studio->provider()] ?? null,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $type = $this->type((string) $this->request->getRequiredBodyParam('handle'));
        $title = trim((string) $this->request->getBodyParam('title'));

        $questions = [];

        foreach ((array) $this->request->getBodyParam('questions') as $row) {
            if (!is_array($row) || trim((string) ($row['label'] ?? '')) === '') {
                continue;
            }

            $handle = trim((string) ($row['handle'] ?? '')) ?: (string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $row['label'])));

            $questions[] = array_filter([
                'handle' => trim($handle, '_'),
                'label' => trim((string) $row['label']),
                'instructions' => trim((string) ($row['instructions'] ?? '')) ?: null,
                'type' => in_array($row['type'] ?? '', ['text', 'textarea'], true) ? $row['type'] : 'textarea',
                'required' => (bool) ($row['required'] ?? false),
            ], fn($value) => $value !== null);
        }

        $posted = ContentType::fromArray($type->handle, [
            'title' => $title,
            'description' => trim((string) $this->request->getBodyParam('description')),
            'questions' => $questions,
            'guidance' => trim((string) $this->request->getBodyParam('guidance')),
            'checklist' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->request->getBodyParam('checklist')) ?: []))),
            'examples' => array_slice(array_values(array_filter((array) $this->request->getBodyParam('examples'), 'is_numeric')), 0, 6),
            // Not editable on this screen; kept exactly as they were.
            'section' => $type->section,
            'entryType' => $type->entryType,
            'where' => $type->where,
            'defaults' => $type->defaults,
        ]);

        if ($title === '' || $questions === []) {
            $this->setFailFlash(Craft::t('ghostwriter', 'A kind of content needs a name and at least one question.'));

            // The form again, with what was typed into it.
            Craft::$app->getUrlManager()->setRouteParams(['type' => $posted]);

            return null;
        }

        Plugin::getInstance()->types->save($posted);

        $this->setSuccessFlash(Craft::t('ghostwriter', 'Saved.'));

        return $this->redirectToPostedUrl();
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->types->delete($this->type((string) $this->request->getRequiredBodyParam('handle')));

        // Shown on the dashboard, where the screen goes next, either way.
        $this->setSuccessFlash(Craft::t('ghostwriter', 'Kind deleted. Entries already written are not affected.'));

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['deleted' => true]);
        }

        return $this->redirect('ghostwriter');
    }

    private function type(string $handle): ContentType
    {
        $plugin = Plugin::getInstance();
        $type = $plugin->types->find($handle);

        // The built-in general type has nothing to edit.
        if (!$type || $type->isGeneric() || !$plugin->types->enabled($type->section)) {
            throw new NotFoundHttpException('No such kind of content.');
        }

        return $type;
    }
}
