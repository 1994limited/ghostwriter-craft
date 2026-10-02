<?php

namespace nineteenninetyfour\ghostwriter\stock;

use Craft;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetReplacer;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ReplaceMeta;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use nineteenninetyfour\ghostwriter\images\ImagePicker;
use RuntimeException;

/**
 * "License & replace" in Craft: the stand-in's file is swapped for the
 * licensed original with `Assets::replaceAssetFile()`. The asset's ID,
 * so every relation to it, stays; so do its title, alt text and focal
 * point, which are put back if Craft changed them.
 *
 * The bytes are kept exactly as the library delivered them: Craft's
 * cleaning of uploaded images (which re-encodes and strips metadata) is
 * skipped, since the licences require the embedded copyright, name and
 * image ID to stay. A file of another type keeps the stand-in's name with
 * its own extension; it is never converted.
 */
class CraftAssetReplacer implements AssetReplacer
{
    /** How far the licensed file's aspect ratio may differ before the focal point is reset. */
    private const ASPECT_TOLERANCE = 0.01;

    public function replace(AssetRef $asset, PhotoFile $file, ReplaceMeta $meta): AssetRef
    {
        $element = is_numeric($asset->id) ? Asset::find()->id((int) $asset->id)->status(null)->one() : null;

        if (!$element instanceof Asset) {
            throw new RuntimeException(Craft::t('ghostwriter', 'The image is no longer in Assets.'));
        }

        $title = $element->title;
        $alt = $element->alt;
        $focal = $element->getHasFocalPoint() ? $element->getFocalPoint() : null;
        $before = $element->getWidth() && $element->getHeight() ? $element->getWidth() / $element->getHeight() : null;

        $extension = strtolower($file->extension === 'jpeg' ? 'jpg' : $file->extension);
        $current = strtolower((string) $element->getExtension());
        $filename = $extension === $current || ($extension === 'jpg' && $current === 'jpeg')
            ? $element->getFilename()
            : pathinfo($element->getFilename(), PATHINFO_FILENAME) . '.' . $extension;

        $path = Craft::$app->getPath()->getTempPath() . '/ghostwriter-licensed-' . bin2hex(random_bytes(6)) . '.' . $extension;
        FileHelper::writeToFile($path, $file->content);

        // Byte for byte: no re-encoding, so the embedded metadata stays.
        $element->sanitizeOnUpload = false;
        Craft::$app->getAssets()->replaceAssetFile($element, $path, $filename, $file->mime);

        if ($element->hasErrors()) {
            throw new RuntimeException(implode(' ', $element->getFirstErrors()));
        }

        $replaced = Asset::find()->id($element->id)->siteId($element->siteId)->status(null)->one() ?? $element;
        $after = $replaced->getWidth() && $replaced->getHeight() ? $replaced->getWidth() / $replaced->getHeight() : null;
        $changed = false;

        // The focal point was set on the stand-in, at the photo's aspect
        // ratio: it still holds unless the licensed file's shape differs.
        if ($focal !== null && $before !== null && $after !== null && abs($after - $before) / $before <= self::ASPECT_TOLERANCE) {
            if (!$replaced->getHasFocalPoint() || $replaced->getFocalPoint() != $focal) {
                $replaced->setFocalPoint($focal);
                $changed = true;
            }
        } elseif ($focal !== null && $replaced->getHasFocalPoint()) {
            $replaced->setFocalPoint(null);
            $changed = true;
        }

        // Title and alt text are the editor's: never changed by licensing.
        if ($replaced->title !== $title || $replaced->alt !== $alt) {
            $replaced->title = $title;
            $replaced->alt = $alt;
            $changed = true;
        }

        // The credit goes in a field made for it, where the volume has one.
        if (($meta->creditLine ?? '') !== '' && ($handle = ImagePicker::creditFieldOf($replaced))) {
            $replaced->setFieldValue($handle, $meta->creditLine . ($meta->creditUrl ? " ({$meta->creditUrl})" : ''));
            $changed = true;
        }

        if ($changed) {
            $replaced->setScenario(Asset::SCENARIO_DEFAULT);
            Craft::$app->getElements()->saveElement($replaced, false);
        }

        return ImagePicker::ref($replaced);
    }
}
