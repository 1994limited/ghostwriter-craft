<?php

namespace nineteenninetyfour\ghostwriter\domain;

use craft\helpers\Json;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanStore;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;

/**
 * The content plan: each idea an `idea` document (JSON), and the plan
 * screen's working state as the `plan` state.
 */
class DbPlanStore implements PlanStore
{
    public function ideas(): array
    {
        $ideas = [];

        foreach ($this->store()->documents('idea') as $id => $json) {
            if ($idea = $this->idea((string) $id, (string) $json)) {
                $ideas[] = $idea;
            }
        }

        return $ideas;
    }

    public function find(int|string $id): ?Idea
    {
        $id = (string) $id;

        if (!preg_match('/^[0-9a-z_-]{1,64}$/i', $id)) {
            return null;
        }

        $json = $this->store()->document('idea', $id);

        return $json === null ? null : $this->idea($id, $json);
    }

    public function save(Idea $idea): Idea
    {
        $idea->id ??= bin2hex(random_bytes(8));

        $this->store()->putDocument('idea', (string) $idea->id, Json::encode($idea->toArray()));

        return $idea;
    }

    public function delete(int|string $id): void
    {
        $this->store()->deleteDocument('idea', (string) $id);
    }

    public function state(): PlanState
    {
        $value = $this->store()->state('plan');
        $state = $value === [] ? PlanState::empty(Format::Craft) : PlanState::fromArray($value, Format::Craft);
        $state->changedAt = $this->store()->stateUpdatedAt('plan');

        return $state;
    }

    public function saveState(PlanState $state): void
    {
        $this->store()->putState('plan', $state->toArray());
    }

    /**
     * An idea needs a title and a section; anything else is left alone.
     */
    private function idea(string $id, string $json): ?Idea
    {
        $data = Json::decodeIfJson($json);

        if (!is_array($data) || empty($data['title']) || empty($data['section'])) {
            return null;
        }

        return Idea::fromArray(['id' => $id] + $data, Format::Craft);
    }

    private function store(): Store
    {
        return Plugin::getInstance()->store;
    }
}
