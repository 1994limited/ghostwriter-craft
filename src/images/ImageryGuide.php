<?php

namespace nineteenninetyfour\ghostwriter\images;

use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\voice\VoiceGuide;

/**
 * The site's image style guide: what its pictures look like, section by
 * section, in words. It is to images what the voice guide is to writing, and
 * is read whenever search words are chosen, photographs are ranked or an
 * image is made. One markdown file in the project, with a `##` heading for
 * each section.
 */
class ImageryGuide extends VoiceGuide
{
    public function path(): string
    {
        return Plugin::getInstance()->paths->guides('imagery.md');
    }

    /**
     * What the guide says about one section, or the whole guide where it is
     * not divided that way. Empty when there is no guide.
     */
    public function for(string $sectionName): string
    {
        $guide = $this->get();

        if (preg_match('/^##\s+' . preg_quote($sectionName, '/') . '\s*$(.*?)(?=^##\s|\z)/imsu', $guide, $m)) {
            return trim($m[1]);
        }

        return str_contains($guide, "\n## ") ? '' : trim($guide);
    }
}
