<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\base\ElementInterface;
use craft\fields\Assets;
use craft\helpers\Html;
use craft\helpers\Json;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\stock\StockView;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;

/**
 * The Ghostwriter button beside "Add an asset" and "Upload a file" on an
 * image field, in sections Ghostwriter writes for. It opens a modal to find
 * a photograph or have a picture made; the one chosen is put into the field
 * as if it had been uploaded.
 */
class ImageButton
{
    public static function htmlFor(Assets $field, ?ElementInterface $element, bool $inline): string
    {
        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();

        // Not in element index cells, nor on an element with no ID yet.
        if ($inline || !$element || !$element->id || !$request->getIsCpRequest() || !$user) {
            return '';
        }

        $slot = ImageSlot::for($field, $element, $user);

        if (!$slot) {
            return '';
        }

        // A field with nowhere to upload to cannot take a new picture.
        try {
            $slot->folderId();
        } catch (\Throwable) {
            return '';
        }

        $plugin = Plugin::getInstance();
        $tools = [
            'canFind' => $plugin->imagePicker->canFind(),
            'canMake' => $plugin->imagePicker->canMake(),
        ];

        // No photo library and no image model: there is nothing the button
        // could do, so there is no button.
        if (!in_array(true, $tools, true)) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(GhostwriterAsset::class);

        $config = $tools + [
            // "Search in": free libraries, each paid one, or everything;
            // this person's last choice, else the site's default.
            'sources' => $plugin->stockLibraries->sourceOptions(Plugin::canManage($user)),
            'source' => \nineteenninetyfour\ghostwriter\controllers\ImagesController::rememberedSource(),
            'editorial' => (bool) $plugin->getSettings()->stockIncludeEditorial,
            // Previews in the field now: "Preview · not licensed", with License or Request licence.
            'stock' => StockView::badges(self::assetIds($element, $field), $user),
            'fieldId' => (int) $field->id,
            'elementId' => (int) $element->id,
            'siteId' => (int) $element->siteId,
            'label' => $slot->label(),
            'icon' => (string) file_get_contents(__DIR__ . '/mark.svg'),
        ];

        return Html::tag('div', '', [
            'class' => 'gw-image-button hidden',
            'data' => ['ghostwriter-image' => Json::encode($config)],
        ]);
    }

    /**
     * The assets the field holds on the element now.
     *
     * @return array<int, int>
     */
    private static function assetIds(ElementInterface $element, Assets $field): array
    {
        try {
            $value = $element->getFieldValue($field->handle);
        } catch (\Throwable) {
            return [];
        }

        if ($value instanceof \craft\elements\db\AssetQuery) {
            return array_map('intval', (clone $value)->status(null)->ids());
        }

        return $value instanceof \Illuminate\Support\Collection ? array_map(fn($asset) => (int) $asset->id, $value->all()) : [];
    }
}
