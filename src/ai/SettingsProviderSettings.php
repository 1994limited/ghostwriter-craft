<?php

namespace nineteenninetyfour\ghostwriter\ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ModelTiers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderSettings;
use nineteenninetyfour\ghostwriter\models\Settings;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * The provider choices from the plugin's settings (and config/ghostwriter.php),
 * read each time so a change applies at once.
 */
final class SettingsProviderSettings implements ProviderSettings, ModelTiers
{
    public function textProvider(): string
    {
        return $this->settings()->provider;
    }

    public function textModel(): ?string
    {
        return $this->settings()->model ?: null;
    }

    public function imageProvider(): ?string
    {
        return $this->settings()->imageProvider ?: null;
    }

    public function imageModel(): ?string
    {
        return $this->settings()->imageModel ?: null;
    }

    public function timeout(): int
    {
        return $this->settings()->timeout;
    }

    /**
     * The gateway set for this provider in the settings (or config), with any
     * environment variable read; null for the provider's own address. Core
     * checks it again before sending a key there.
     */
    public function baseUrl(string $provider): ?string
    {
        return $this->settings()->baseUrl($provider);
    }

    /**
     * With OpenRouter, the model chosen for a tier of work in the settings.
     */
    public function tierModel(string $provider, string $tier): ?string
    {
        return $provider === 'openrouter' ? $this->settings()->openrouterModel($tier) : null;
    }

    public function anthropicFallbacks(): bool
    {
        return true;
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
