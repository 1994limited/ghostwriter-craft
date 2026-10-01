<?php

namespace nineteenninetyfour\ghostwriter\voice;

use DateTime;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * The site's tone of voice guide: one markdown file in the project, read into
 * every writing prompt and edited either by hand or through the refine chat.
 */
class VoiceGuide extends Component
{
    public function path(): string
    {
        return Plugin::getInstance()->paths->guides('voice.md');
    }

    public function exists(): bool
    {
        return is_file($this->path()) && trim((string) file_get_contents($this->path())) !== '';
    }

    public function get(): string
    {
        return $this->exists() ? (string) file_get_contents($this->path()) : '';
    }

    public function save(string $markdown): void
    {
        Plugin::getInstance()->paths->write($this->path(), rtrim($markdown) . "\n");
    }

    public function updatedAt(): ?DateTime
    {
        clearstatcache(true, $this->path());

        return $this->exists() ? (new DateTime())->setTimestamp((int) filemtime($this->path())) : null;
    }
}
