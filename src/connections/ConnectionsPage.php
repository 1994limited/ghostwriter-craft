<?php

namespace nineteenninetyfour\ghostwriter\connections;

use Craft;
use craft\helpers\UrlHelper;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Connections;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Service;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Status;
use NineteenNinetyFour\Ghostwriter\Core\Connections\Strings;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
use nineteenninetyfour\ghostwriter\controllers\LibrariesController;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * What Settings → Connections shows: each group of cards, each card's
 * status (never a key: only its last four characters), how to sign in
 * where a service can, the environment, and the words in the person's
 * language (core's Connections\Strings).
 */
class ConnectionsPage
{
    public static function connections(): Connections
    {
        return Plugin::getInstance()->providers->connections();
    }

    public static function strings(): Strings
    {
        return Strings::for(Craft::$app->language);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $strings = self::strings();
        $groups = [];

        foreach (self::connections()->services()->grouped() as $group => $services) {
            $groups[] = [
                'id' => $group,
                'title' => $strings->get("group.{$group}"),
                'intro' => $strings->get("group.{$group}.intro"),
                'cards' => array_map(fn(Service $service) => self::card($service), $services),
            ];
        }

        return [
            'groups' => $groups,
            'strings' => $strings->all(),
            'environment' => self::environment($strings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function card(Service $service): array
    {
        $strings = self::strings();
        $status = self::connections()->status($service->id);
        $variable = $service->required()[0]->env ?? '';

        return $service->toArray($strings) + [
            'status' => $status->toArray($strings),
            'help' => match (true) {
                $status->state === Status::ENV && $status->broken => $strings->get('status.env.broken', ['service' => $service->name, 'variable' => $variable]),
                $status->state === Status::ENV => $strings->get($status->where === 'config' ? 'status.config.help' : 'status.env.help', ['variable' => $variable]),
                $status->state === Status::BROKEN => $strings->get('status.broken.help', ['service' => $service->name]),
                $status->state === Status::NO_KEY => $strings->get('status.no-key.help'),
                $status->via === 'connect' => $strings->get('status.via-connect', ['service' => $service->name]),
                default => null,
            },
            'oauth_links' => self::oauth($service, $status),
        ];
    }

    /**
     * "You're on local.": Craft's environment, in plain words where there are some.
     */
    private static function environment(Strings $strings): string
    {
        $env = strtolower((string) (Craft::$app->env ?? ''));
        $known = match ($env) {
            'dev', 'local', 'development' => 'local',
            'staging', 'stage', 'test', 'testing' => 'staging',
            'production', 'prod', 'live' => 'production',
            default => null,
        };

        return $known !== null ? $strings->get("environment.{$known}") : ($env !== '' ? $env : $strings->get('environment.production'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function oauth(Service $service, Status $status): ?array
    {
        if ($service->oauth === Service::OAUTH_KEY) {
            return $status->state === Status::ENV ? null : ['connect_url' => UrlHelper::cpUrl("ghostwriter/providers/{$service->id}/connect")];
        }

        if ($service->oauth !== Service::OAUTH_ACCOUNT) {
            return null;
        }

        $libraries = Plugin::getInstance()->stockLibraries;
        $libraries->reset();
        $library = $libraries->all()[$service->id] ?? null;

        if (!$library instanceof ConnectsAccount || !$library->available()) {
            return ['needs_key' => true];
        }

        return [
            'needs_key' => false,
            'connected' => $library->connected(),
            'connect_url' => UrlHelper::cpUrl("ghostwriter/libraries/{$service->id}/connect"),
            'disconnect_action' => "ghostwriter/libraries/disconnect",
            'callback' => LibrariesController::callbackHostAndPath($service->id),
        ];
    }
}
