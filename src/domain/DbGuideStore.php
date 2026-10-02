<?php

namespace nineteenninetyfour\ghostwriter\domain;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;

/**
 * The voice and image style guides as `guide` documents (markdown), and
 * their screens' working state as the `voice` and `imagery` state.
 */
class DbGuideStore implements GuideStore
{
    public function guide(string $kind): Guide
    {
        $body = (string) $this->store()->document('guide', $kind);

        return new Guide($kind, $body, trim($body) !== '' ? $this->store()->documentUpdatedAt('guide', $kind) : null);
    }

    public function saveGuide(Guide $guide): Guide
    {
        $this->store()->putDocument('guide', $guide->kind, Guide::normalise($guide->body));

        return $this->guide($guide->kind);
    }

    public function state(string $kind): GuideState
    {
        $value = $this->store()->state($kind);
        $state = $value === [] ? GuideState::empty(Format::Craft) : GuideState::fromArray($value, Format::Craft);
        $state->changedAt = $this->store()->stateUpdatedAt($kind);

        return $state;
    }

    public function saveState(string $kind, GuideState $state): void
    {
        $this->store()->putState($kind, $state->toArray());
    }

    private function store(): Store
    {
        return Plugin::getInstance()->store;
    }
}
