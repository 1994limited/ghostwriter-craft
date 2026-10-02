# Installation

## Requirements

- Craft CMS 5.6 or later
- PHP 8.2 or later
- An API key for one AI provider: Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini). See [API keys](api-keys.md).

Optional:

- An OpenAI or Gemini key, to make images. Claude does not make images.
- The **Imagick** PHP extension, for sending smaller copies of images to the model. Without it, images are sent at their original size up to 1 MB.
- The **GD** extension, which Craft already requires, draws the striped image placeholders.

## Install the plugin

From your project's root:

```bash
composer require 1994/ghostwriter-craft
php craft plugin/install ghostwriter
```

Or, once it is required, install it from **Settings → Plugins** in the control panel.

## Add your API key

Add the key for your chosen provider to your project's `.env` file:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
```

Ghostwriter reads keys from the environment each time it needs one. It never stores them, and they never appear in project config. See [API keys](api-keys.md) for every key it can use.

On a server, add the same variable wherever your host keeps environment variables (Laravel Forge, Ploi, Servd and Craft Cloud all have a screen for this).

## Permissions

Ghostwriter adds one permission under **Settings → Users → User Groups**: **Use Ghostwriter**.

- People with it see Ghostwriter in the navigation, the **Write with Ghostwriter** and **Edit with Ghostwriter** buttons, the image button on image fields, and the dashboard widget.
- Writing into an entry also needs Craft's own permission to save entries in that section. Ghostwriter never lets anyone change an entry they could not change by hand.
- Ghostwriter's settings page is for admins, on environments where admin changes are allowed.

## The queue

Writing a draft or a guide can take a minute or more, which is longer than a web request should be held open. So each model call runs as a job in Craft's queue, and the screen checks back until the answer is ready.

- **No setup needed.** By default Craft runs its queue from control panel requests, so jobs start as soon as you ask.
- **With a queue worker.** If your site sets `runQueueAutomatically` to `false` and runs a worker (`php craft queue/listen`, Supervisor, or your host's daemon), the jobs run there instead. Make sure the worker is running, or nothing will happen.
- **Job time limit.** Each job is allowed three times the configured timeout plus a minute (960 seconds with the default 300), because a busy or rate-limited provider is tried up to three times.

## Updating

```bash
composer update 1994/ghostwriter-craft
php craft up
```

## Uninstalling

Uninstall from **Settings → Plugins**, or:

```bash
php craft plugin/uninstall ghostwriter
composer remove 1994/ghostwriter-craft
```

Uninstalling drops Ghostwriter's tables, with its guides, kinds, plan and conversations. Prompt overrides in `config/ghostwriter/prompts/` stay until you delete them. Assets it saved (photos, made images, the striped placeholder) stay in your volumes.

Next: [API keys](api-keys.md).
