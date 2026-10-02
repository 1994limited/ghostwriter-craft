# Installation

This page covers installing Ghostwriter on a Craft site, the queue it runs on, and updating and uninstalling it.

## Requirements

- PHP 8.2 or later
- Craft CMS 5.6 or later
- An API key for one writing provider: Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini). See [API keys](api-keys.md).

Optional:

- An OpenAI or Gemini key, to make images. Claude doesn't make images.
- The **Imagick** PHP extension, for sending smaller copies of images to the model. Without it, images are sent at their original size, up to 1 MB.

## Install the plugin

Install Ghostwriter from the Plugin Store in the control panel, or from your project's root with Composer:

```bash
composer require 1994/ghostwriter-craft
php craft plugin/install ghostwriter
```

Composer also installs `1994/ghostwriter-core` from Packagist, the package Ghostwriter shares with its Statamic and Filament versions. It holds the providers, the prompts, the draft handling, the layout algorithms and the rules for pieces of writing, the content plan and images. No extra repository is needed.

Once it's required, you can also install it from **Settings → Plugins**.

## Add your API key

Add the key for your writing provider to `.env`, for example `ANTHROPIC_API_KEY=sk-ant-...`. See [API keys](api-keys.md) for every key Ghostwriter can use.

## Who can use it

People need the **Use Ghostwriter** permission, and admins manage it. See [Permissions](permissions.md).

## The queue

Writing a draft or a guide can take a minute or more, which is longer than a web request should be held open. So each model call runs as a job in Craft's queue, and the screen checks back until the answer is ready.

- **No setup needed.** By default Craft runs its queue from control panel requests, so jobs start as soon as you ask.
- **With a queue worker.** If your site sets `runQueueAutomatically` to `false` and runs a worker (`php craft queue/listen`, Supervisor, or your host's daemon), the jobs run there instead. Make sure the worker is running, or nothing will happen.
- **The job time limit is set for you.** Each job is allowed the [time limit](configuration.md#the-time-limit) × 3 + 60 seconds: 960 seconds with the default 300. That's because a busy provider is tried up to three times (see [Busy providers and retries](api-keys.md#busy-providers-and-retries)). A worker with its own, shorter limit stops jobs early.

## Updating

```bash
composer update 1994/ghostwriter-craft
php craft up
```

`php craft up` runs Ghostwriter's migrations. See [CHANGELOG.md](../CHANGELOG.md) for what changed. Updating from an early build, which kept its guides and plan in files, imports them into the database (see [Configuration](configuration.md#updating-from-an-early-build)).

## Uninstalling

Uninstall from **Settings → Plugins**, or:

```bash
php craft plugin/uninstall ghostwriter
composer remove 1994/ghostwriter-craft
```

Uninstalling drops Ghostwriter's tables, with its guides, kinds, plan and conversations. Prompt overrides in `config/ghostwriter/prompts/` stay until you delete them. Assets it saved (photos, made images, the striped placeholder) stay in your volumes.

Next: [Get started](getting-started.md).
