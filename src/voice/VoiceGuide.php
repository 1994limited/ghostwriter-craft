<?php

namespace nineteenninetyfour\ghostwriter\voice;

use DateTime;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * The site's tone of voice guide: markdown, read into every writing prompt
 * and edited either by hand or through the refine chat. Kept in the
 * database, so it is the same on every server and survives deploys.
 */
class VoiceGuide extends Component
{
    public function handle(): string
    {
        return 'voice';
    }

    public function exists(): bool
    {
        return trim($this->get()) !== '';
    }

    public function get(): string
    {
        return (string) Plugin::getInstance()->store->document('guide', $this->handle());
    }

    public function save(string $markdown): void
    {
        Plugin::getInstance()->store->putDocument('guide', $this->handle(), rtrim($markdown) . "\n");
    }

    public function updatedAt(): ?DateTime
    {
        return $this->exists() ? Plugin::getInstance()->store->documentUpdatedAt('guide', $this->handle()) : null;
    }
}
