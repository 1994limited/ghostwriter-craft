<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\base\ElementInterface;
use craft\fields\Assets;
use craft\helpers\Html;
use craft\helpers\Json;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\images\LogoCard;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;

/**
 * The Ghostwriter button beside "Add an asset" and "Upload a file" on an
 * image field, in sections Ghostwriter writes for. It opens a modal to find
 * a photograph, have a picture made, or set a logo on a ground; the one
 * chosen is put into the field as if it had been uploaded.
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
        $view = Craft::$app->getView();
        $view->registerAssetBundle(GhostwriterAsset::class);

        $config = [
            'fieldId' => (int) $field->id,
            'elementId' => (int) $element->id,
            'siteId' => (int) $element->siteId,
            'label' => $slot->label(),
            'canFind' => $plugin->imagePicker->canFind(),
            'canMake' => $plugin->imagePicker->canMake(),
            'canLogo' => LogoCard::available(),
            'icon' => (string) file_get_contents(__DIR__ . '/mark.svg'),
        ];

        return Html::tag('div', '', [
            'class' => 'gw-image-button hidden',
            'data' => ['ghostwriter-image' => Json::encode($config)],
        ]);
    }
}
