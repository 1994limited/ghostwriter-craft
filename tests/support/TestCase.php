<?php

namespace nineteenninetyfour\ghostwriter\tests\support;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\test\TestCase as CraftTestCase;
use craft\web\TemplateResponseBehavior;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use nineteenninetyfour\ghostwriter\ai\FakeProvider;
use nineteenninetyfour\ghostwriter\Plugin;
use RuntimeException;

/**
 * Runs against a real Craft install in a throwaway database. Every model call
 * goes to a fake, every outside HTTP request to a mock handler that fails
 * the test unless a response was queued for it, and everything the plugin
 * writes to disk goes to a temporary folder.
 */
abstract class TestCase extends CraftTestCase
{
    protected Plugin $plugin;

    protected FakeProvider $fake;

    protected MockHandler $http;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    protected array $sent = [];

    protected string $workspace;

    protected function _before(): void
    {
        parent::_before();

        $this->plugin = Plugin::getInstance();
        $this->forgetNeo();
        $this->workspace = sys_get_temp_dir() . '/ghostwriter-tests-' . StringHelper::randomString(8);

        $settings = $this->plugin->getSettings();
        $settings->guidesPath = $this->workspace . '/guides';
        $settings->storagePath = $this->workspace . '/storage';
        $settings->provider = 'anthropic';
        $settings->model = null;
        $settings->sections = [];
        $settings->voiceSections = [];
        $settings->imageProvider = null;

        $this->http = new MockHandler();
        $this->sent = [];
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->sent));

        $providers = $this->plugin->providers;
        $providers->handler = $stack;
        $providers->keys = array_fill_keys(array_keys($providers::KEYS), null) + [];
        $providers->keys['anthropic'] = 'test-key';
        $this->fake = $providers->fake();
    }

    protected function _after(): void
    {
        FileHelper::removeDirectory($this->workspace);

        parent::_after();
    }

    /**
     * Send model calls through the real providers (and so the mock HTTP
     * handler) rather than the fake.
     */
    protected function unfake(): void
    {
        (function () {
            $this->fake = null;
        })->call($this->plugin->providers);
    }

    protected function makeField(string $class, string $handle, array $config = []): FieldInterface
    {
        $field = Craft::$app->getFields()->createField(['type' => $class, 'name' => ucfirst($handle), 'handle' => $handle] + $config);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new RuntimeException("Could not save the {$handle} field: " . json_encode($field->getErrors()));
        }

        return $field;
    }

    /**
     * @param FieldInterface[] $fields
     */
    protected function makeEntryType(string $handle, array $fields = [], bool $hasTitle = true): EntryType
    {
        $type = new EntryType(['name' => ucfirst(str_replace('_', ' ', $handle)), 'handle' => $handle, 'hasTitleField' => $hasTitle]);

        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([
            ...($hasTitle ? [new EntryTitleField()] : []),
            ...array_map(fn(FieldInterface $field) => new CustomField($field), $fields),
        ]);
        $layout->setTabs([$tab]);
        $type->setFieldLayout($layout);

        if (!$hasTitle) {
            $type->titleFormat = '{dateCreated|date}';
        }

        if (!Craft::$app->getEntries()->saveEntryType($type)) {
            throw new RuntimeException("Could not save the {$handle} entry type: " . json_encode($type->getErrors()));
        }

        return $type;
    }

    /**
     * @param EntryType[] $types
     */
    protected function makeMatrix(string $handle, array $types): Matrix
    {
        /** @var Matrix */
        return $this->makeField(Matrix::class, $handle, ['entryTypes' => $types]);
    }

    /**
     * A Neo field. Each block type is ['handle' => ..., 'fields' => [...],
     * 'topLevel' => bool, 'children' => [handles]].
     *
     * @param array<int, array<string, mixed>> $types
     */
    protected function makeNeo(string $handle, array $types): FieldInterface
    {
        $blockTypes = [];

        foreach ($types as $i => $config) {
            $type = new \benf\neo\models\BlockType([
                'name' => ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $config['handle'])),
                'handle' => $config['handle'],
                'topLevel' => $config['topLevel'] ?? true,
                'childBlocks' => $config['children'] ?? null,
                'sortOrder' => $i + 1,
            ]);

            $layout = new FieldLayout(['type' => \benf\neo\elements\Block::class]);
            $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
            $tab->setElements(array_map(fn(FieldInterface $field) => new CustomField($field, ['required' => in_array($field->handle, $config['required'] ?? [], true)]), $config['fields'] ?? []));
            $layout->setTabs([$tab]);
            $type->setFieldLayout($layout);

            $blockTypes['new' . ($i + 1)] = $type;
        }

        $field = new \benf\neo\Field(['name' => ucfirst($handle), 'handle' => $handle]);
        $field->setBlockTypes($blockTypes);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new RuntimeException("Could not save the {$handle} Neo field: " . json_encode($field->getErrors()));
        }

        // The saved block types have IDs; the copies Neo keeps on the field
        // object, and in its own memo, are the ones handed in, which do not.
        \Closure::bind(fn(\benf\neo\Field $neo) => $neo->_blockTypes = null, null, \benf\neo\Field::class)($field);
        $this->forgetNeo();

        return $field;
    }

    /**
     * Neo memoises block types in static properties, which outlive a test;
     * and field IDs are reused once a test's changes are rolled back.
     */
    protected function forgetNeo(): void
    {
        if (!class_exists(\benf\neo\helpers\Memoize::class)) {
            return;
        }

        foreach ((new \ReflectionClass(\benf\neo\helpers\Memoize::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            if ($property->getType()?->getName() === 'array' || is_array($property->getValue())) {
                $property->setValue(null, []);
            }
        }
    }

    /**
     * @param EntryType[] $types
     */
    protected function makeSection(string $handle, array $types, string $kind = Section::TYPE_CHANNEL): Section
    {
        $section = new Section([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'type' => $kind,
            'siteSettings' => [new Section_SiteSettings([
                'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
                'hasUrls' => true,
                'uriFormat' => $handle . '/{slug}',
                'template' => '_entry',
            ])],
        ]);
        $section->setEntryTypes($types);

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException("Could not save the {$handle} section: " . json_encode($section->getErrors()));
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $fields Values as a form would post them; Matrix as ['new1' => ['type' => ..., 'fields' => [...]]].
     */
    protected function makeEntry(Section $section, string $title, array $fields = [], bool $live = true, ?EntryType $type = null, ?string $postDate = null): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => ($type ?? $section->getEntryTypes()[0])->id,
            'title' => $title,
            'enabled' => $live,
            'authorId' => 1,
        ]);

        if ($postDate) {
            $entry->postDate = new \DateTime($postDate);
        }

        $entry->setFieldValues($fields);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException("Could not save \"{$title}\": " . json_encode($entry->getErrors()));
        }

        return $entry;
    }

    /**
     * Sign in as someone who may use Ghostwriter, or someone who may not.
     *
     * @param string[] $extra Further permissions.
     */
    protected function signIn(bool $permitted = true, array $extra = [], bool $admin = false): User
    {
        $user = new User(['username' => 'writer-' . StringHelper::randomString(6), 'email' => StringHelper::randomString(6) . '@example.com']);
        $user->active = true;
        $user->admin = $admin;
        Craft::$app->getElements()->saveElement($user, false);

        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, array_merge(['accesscp'], $permitted ? [Plugin::PERMISSION] : [], $extra));
        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }

    /**
     * A volume on a local folder in the workspace.
     */
    protected function makeVolume(string $handle = 'images'): \craft\models\Volume
    {
        $fs = new \craft\fs\Local(['name' => ucfirst($handle), 'handle' => $handle, 'hasUrls' => true, 'url' => '/' . $handle, 'path' => $this->workspace . '/' . $handle]);
        FileHelper::createDirectory($this->workspace . '/' . $handle);

        if (!Craft::$app->getFs()->saveFilesystem($fs)) {
            throw new RuntimeException('Could not save the filesystem: ' . json_encode($fs->getErrors()));
        }

        $volume = new \craft\models\Volume(['name' => ucfirst($handle), 'handle' => $handle, 'fsHandle' => $handle]);

        if (!Craft::$app->getVolumes()->saveVolume($volume)) {
            throw new RuntimeException('Could not save the volume: ' . json_encode($volume->getErrors()));
        }

        return $volume;
    }

    /**
     * An image in a volume: a flat colour, at the size asked for.
     */
    protected function makeAsset(\craft\models\Volume $volume, string $filename, int $width = 800, int $height = 600, string $colour = '#888888'): \craft\elements\Asset
    {
        $image = imagecreatetruecolor($width, $height);
        [$r, $g, $b] = sscanf($colour, '#%02x%02x%02x');
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        $path = $this->workspace . '/upload-' . $filename;
        FileHelper::createDirectory($this->workspace);
        imagepng($image, $path);

        $asset = new \craft\elements\Asset();
        $asset->tempFilePath = $path;
        $asset->filename = $filename;
        $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id;
        $asset->volumeId = $volume->id;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(\craft\elements\Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new RuntimeException("Could not save {$filename}: " . json_encode($asset->getErrors()));
        }

        return $asset;
    }

    /**
     * A new entry as Craft makes one when its create screen opens: an
     * unpublished draft belonging to whoever is signed in.
     */
    protected function newDraft(Section $section, ?EntryType $type = null): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => ($type ?? $section->getEntryTypes()[0])->id,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ]);
        $entry->setAuthorIds([Craft::$app->getUser()->getId() ?? 1]);
        $entry->setScenario(\craft\base\Element::SCENARIO_ESSENTIALS);

        if (!Craft::$app->getDrafts()->saveElementAsDraft($entry, Craft::$app->getUser()->getId() ?? 1, markAsSaved: false)) {
            throw new RuntimeException('Could not make a draft: ' . json_encode($entry->getErrors()));
        }

        return $entry;
    }

    /**
     * Run an action the way the control panel would, and give back its JSON.
     *
     * @param array<string, mixed> $body
     * @return array{status: int, data: array<string, mixed>}
     */
    protected function action(string $route, array $body = [], string $method = 'POST', bool $json = true): array
    {
        $request = Craft::$app->getRequest();
        $request->setIsCpRequest(true);
        // The control panel sends a CSRF token with every request; there is no browser here.
        $request->enableCsrfValidation = false;
        $request->headers->set('Accept', $json ? 'application/json' : 'text/html');
        $request->setBodyParams($body);
        $_SERVER['REQUEST_METHOD'] = $method;
        $request->setQueryParams($method === 'GET' ? $body : []);

        $response = Craft::$app->getResponse();
        $response->clear();
        $response->detachBehavior(TemplateResponseBehavior::NAME);

        // Run from the command line, Craft points plugins at their console controllers.
        $this->plugin->controllerNamespace = 'nineteenninetyfour\\ghostwriter\\controllers';

        try {
            $result = Craft::$app->runAction($route);
        } catch (\yii\web\HttpException $exception) {
            return ['status' => $exception->statusCode, 'data' => ['message' => $exception->getMessage()]];
        }

        $status = $result instanceof \yii\web\Response ? $result->getStatusCode() : $response->getStatusCode();
        $data = $result instanceof \yii\web\Response ? $result->data : $result;

        // A screen: hand back what it would render, for the test to render.
        if ($result instanceof \yii\web\Response && ($screen = $result->getBehavior(TemplateResponseBehavior::NAME))) {
            $data = ['template' => $screen->template, 'variables' => $screen->variables];
        }

        return ['status' => $status, 'data' => is_array($data) ? $data : []];
    }

    /**
     * Run every job waiting in the queue, in this process.
     */
    protected function runQueue(): void
    {
        Craft::$app->getQueue()->run();
    }

    /**
     * @return array<int, object> The jobs waiting in the queue.
     */
    protected function queued(string $class): array
    {
        $jobs = [];

        foreach ((new \craft\db\Query())->from('{{%queue}}')->where(['fail' => false])->all() as $row) {
            $job = Craft::$app->getQueue()->serializer->unserialize(is_resource($row['job']) ? stream_get_contents($row['job']) : $row['job']);

            if ($job instanceof $class) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }
}
