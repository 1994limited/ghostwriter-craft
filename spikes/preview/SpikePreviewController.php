<?php
// Approach A, step 2 (`ghostwriter/preview/render` in §7.3), registered as
// `gwspike/render` for the spike. Rebuilds an UNSAVED copy of the entry with
// the draft's values, makes it the placeholder element, then re-routes the
// request exactly as craft\controllers\PreviewController::actionPreview().
use craft\ckeditor\data\FieldData;
use craft\elements\Entry;
use craft\web\Controller;
use yii\web\Response;

class SpikePreviewController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;
    public $enableCsrfValidation = false;
    public static ?array $headers = null;

    public function actionRender(string $key): Response
    {
        $this->requireToken();
        $p = Craft::$app->getCache()->get('gwspike:'.$key) ?: throw new \yii\web\NotFoundHttpException('Preview expired');

        $element = Entry::find()->id($p['id'])->siteId($p['siteId'])->status(null)->drafts(null)->provisionalDrafts(null)->one();
        $element->title = $p['title'];
        $element->slug = $p['slug'];
        $element->setFieldValues($p['values']);                 // never saved
        Craft::$app->getElements()->setElementUri($element);

        if ($p['nested']) {
            $this->swapInNestedEntries($element, $p['nested']);
        }

        if (! $element->lft && $element->getIsDerivative()) {     // as actionPreview(): structure data from the canonical
            $canonical = $element->getCanonical(true);
            foreach (['structureId', 'root', 'lft', 'rgt', 'level'] as $a) $element->$a = $canonical->$a;
        }
        // The Ghostwriter preview flag for templates: a page-template variable set only on these renders.
        // (Twig::addGlobal() is too late here: Twig is already initialised by the time an action runs.)
        yii\base\Event::on(craft\web\View::class, craft\web\View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, fn ($e) => $e->variables['ghostwriterPreview'] = true);
        $element->previewing = true;
        Craft::$app->getElements()->setPlaceholderElement($element);

        $this->response->setNoCacheHeaders();
        foreach (self::$headers ?? [] as $name => $value) $this->response->getHeaders()->set($name, $value);

        $this->request->checkIfActionRequest(true, false);
        $urlManager = Craft::$app->getUrlManager();
        $urlManager->checkToken = false;
        $urlManager->setRouteParams([], false);
        $urlManager->setMatchedElement(null);
        return Craft::$app->handleRequest($this->request, true);
    }

    /**
     * CKEditor renders a nested entry by fetching it by id (FieldData::loadEntries()).
     * An unsaved one has none, so: parse, let it load (placeholder ids find
     * nothing), then hand each placeholder chunk the in-memory entry.
     */
    private function swapInNestedEntries(Entry $owner, array $nested): void
    {
        foreach ($owner->getFieldLayout()->getCustomFields() as $field) {
            $this->patch($owner, $field, $nested);
        }
        // and inside Matrix blocks (CKEditor fields of nested entries)
        foreach ($owner->getFieldLayout()->getCustomFields() as $field) {
            if ($field instanceof craft\fields\Matrix) {
                foreach ($owner->getFieldValue($field->handle)->all() as $block) {
                    foreach ($block->getFieldLayout()->getCustomFields() as $f) $this->patch($block, $f, $nested);
                }
            }
        }
    }

    private function patch(craft\base\ElementInterface $el, $field, array $nested): void
    {
        if (! $field instanceof craft\ckeditor\Field) return;
        $value = $el->getFieldValue($field->handle);
        if (! $value instanceof FieldData) return;
        $chunks = $value->getChunks(false);                   // parse
        $value->loadEntries();                                // one query; placeholders come back null
        foreach ($chunks as $chunk) {
            if ($chunk instanceof craft\ckeditor\data\Entry && isset($nested[$chunk->entryId])) {
                $spec = $nested[$chunk->entryId];
                $type = Craft::$app->getEntries()->getEntryTypeByHandle($spec['type']);
                $entry = new Entry(['typeId' => $type->id, 'fieldId' => $field->id, 'ownerId' => $el->id, 'siteId' => $el->siteId, 'enabled' => true, 'postDate' => new DateTime()]);
                $entry->setFieldValues($spec['fields']);
                $chunk->setEntry($entry);
            }
        }
    }
}
