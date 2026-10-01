<?php

namespace nineteenninetyfour\ghostwriter\ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ProviderSettings;
use nineteenninetyfour\ghostwriter\models\Settings;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * The provider choices from the plugin's settings (and config/ghostwriter.php),
 * read each time so a change applies at once.
 */
final class SettingsProviderSettings implements ProviderSettings
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
     * Craft has no gateway setting: every provider is called at its own address.
     */
    public function baseUrl(string $provider): ?string
    {
        return null;
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
