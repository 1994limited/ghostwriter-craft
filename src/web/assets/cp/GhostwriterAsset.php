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
        $this->js = ['ghostwriter.js', 'finish.js'];
        $this->css = ['ghostwriter.css', 'finish.css'];

        parent::init();
    }
}
