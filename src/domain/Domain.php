<?php

namespace nineteenninetyfour\ghostwriter\domain;

use Craft;
use craft\elements\User;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequests;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\Waiting;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionAccess;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * Core's domain rules over this plugin's stores: sessions and who may see,
 * carry on and delete them; the content plan; image requests; the guides'
 * working state; placeholders. Built afresh on each call, so a change to
 * the settings (sharing, the time limit) applies at once.
 */
class Domain extends Component
{
    public function options(): DomainOptions
    {
        $settings = Plugin::getInstance()->getSettings();

        return DomainOptions::craft(shared: (bool) $settings->sharedConversations, jobTimeout: max(1, (int) $settings->timeout));
    }

    /**
     * The person asking: the signed-in user unless another is given.
     * Managing Ghostwriter is an admin's call (Plugin::canManage()).
     */
    public function viewer(?User $user = null): Viewer
    {
        $user ??= Craft::$app->getUser()->getIdentity();

        return $user ? new Viewer((int) $user->id, manager: Plugin::canManage($user)) : Viewer::nobody();
    }

    public function sessions(): SessionGuard
    {
        return new SessionGuard(Plugin::getInstance()->sessions, Plugin::getInstance()->lock, $this->options());
    }

    public function access(): SessionAccess
    {
        return new SessionAccess($this->options());
    }

    public function plan(): Plan
    {
        return new Plan(Plugin::getInstance()->plans, Plugin::getInstance()->lock, $this->options()->format);
    }

    /**
     * Every idea on the plan, with any whose piece has gone open again.
     *
     * @return array<int, Idea> Oldest first.
     */
    public function ideas(): array
    {
        $sessions = Plugin::getInstance()->sessions;

        return $this->plan()->ideas(fn(int|string $id) => $sessions->find((string) $id) !== null);
    }

    public function images(): ImageRequests
    {
        return new ImageRequests(Plugin::getInstance()->imageStore, Plugin::getInstance()->lock, $this->options());
    }

    /**
     * Craft runs its own queue from control panel requests, so nothing is
     * ever left waiting for a worker to be started.
     */
    public function waiting(): Waiting
    {
        return new Waiting(Plugin::getInstance()->waitingStore, $this->options(), runsItself: true);
    }

    /**
     * @param array<string, float> $rates How often each field is filled, from the layout pattern.
     */
    public function placeholders(array $rates = []): Placeholders
    {
        return new Placeholders(new VolumeAssetSink(), $rates);
    }

    /**
     * The voice guide (Guide::VOICE) or the image style guide (Guide::IMAGERY).
     */
    public function guide(string $kind): Guide
    {
        return Plugin::getInstance()->guides->guide($kind);
    }

    public function saveGuide(string $kind, string $markdown): Guide
    {
        return Plugin::getInstance()->guides->saveGuide(new Guide($kind, $markdown));
    }

    /**
     * A guide screen's working state, with work that stopped without
     * saying so shown as failed.
     */
    public function guideState(string $kind): GuideState
    {
        $state = Plugin::getInstance()->guides->state($kind);
        $state->recoverIfStale($this->options());

        return $state;
    }

    /**
     * Change a guide screen's state from what it is now, with nobody else
     * changing it in between: a job and a request can both be at it.
     *
     * @param callable(GuideState): void $change
     */
    public function changeGuideState(string $kind, callable $change): GuideState
    {
        $guides = Plugin::getInstance()->guides;

        return Plugin::getInstance()->lock->run("state:{$kind}", function() use ($guides, $kind, $change) {
            $state = $guides->state($kind);
            $state->recoverIfStale($this->options());
            $change($state);
            $guides->saveState($kind, $state);

            return $state;
        });
    }
}
