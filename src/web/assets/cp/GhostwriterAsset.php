<?php

namespace nineteenninetyfour\ghostwriter\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The control panel scripts and styles. Plain JavaScript on Craft's own
 * Garnish and Craft.* helpers, so there is no build step and the screens
 * behave like the rest of the control panel.
 */
class GhostwriterAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        // preview.js loads locator.js (core's, copied as it is) as a module
        // from beside itself, so it is published here but not registered.
        $this->js = ['ghostwriter.js', 'finish.js', 'preview.js', 'layouts.js'];
        $this->css = ['ghostwriter.css', 'finish.css'];

        parent::init();
    }
}
