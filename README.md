<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/images/ghostwriter-horizontal-reversed.svg">
    <img src="docs/images/ghostwriter-horizontal-colour.svg" alt="Ghostwriter" width="320">
  </picture>
</p>

# Ghostwriter for Craft CMS

Ghostwriter learns how your site writes, then drafts new entries and edits existing ones in that voice, in a panel beside the entry form.

![The writing panel over a new entry, with the conversation on the left and the draft in Blocks view on the right](docs/images/writing-draft.png)

## What it does

- **Voice guide.** Ghostwriter reads your published entries and writes a guide to how you sound. Edit it by hand, or ask for changes in plain words.
- **Kinds of content.** It suggests the kinds of content each section holds, such as "Case study" or "Service page". Each kind you teach it gets its own questions and guidance.
- **Writing and editing in conversation.** **Write with Ghostwriter** asks a short brief, follows up on anything it needs, and puts the draft into the entry with **Use this draft**. **Edit with Ghostwriter** changes an existing entry's writing and nothing else. Nothing is saved or published for you.
- **House style.** Block order, and settings your entries agree on, are copied into new entries. Links it can't decide are marked for you to set.
- **Photo search.** A button on Assets fields finds free photos, ranked by the model against the page's words and the images already there, and fills in Craft's alt text from the photo library's description.
- **Stock photos.** Search paid libraries from the same dialog and put a photo in as a preview: editors see the watermarked photo, the page can't be published until someone licenses it from your own account, and License & replace swaps the file in place. Every stock photo is kept in a ledger. Shutterstock works with your own API plan; Getty Images and iStock are coming; a demo library shows the flow without an account. See [Stock photos](docs/stock-photos.md).
- **Make an image.** With an OpenAI or Gemini key, it makes a new picture in the style of your images.
- **Content plan.** Ghostwriter suggests what is missing from each section. Each idea opens a new entry with Ghostwriter beside it.
- **Shared conversations.** Everyone who can use Ghostwriter sees the pieces in progress, and can pick one up where a colleague left it.

## Requirements

- PHP 8.2 or later
- Craft CMS 5.8 or later
- An API key for Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini)
- Craft's queue, which runs by itself from the control panel unless your site uses a worker

## Installation

Install it from the Plugin Store in the control panel, or with Composer:

```bash
composer require 1994/ghostwriter-craft
php craft plugin/install ghostwriter
```

Then add your key to `.env`:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
```

The [installation guide](docs/installation.md) covers each step in full.

## Quick start

1. Open **Ghostwriter → Get started** in the control panel.
2. Check that **Connect a model** names your provider, then click **Write the voice guide** on **Learn your voice**.
3. Learn one or two of the suggested kinds of content.
4. Open a section's new entry screen and click **Write with Ghostwriter**. Choose what you're writing, answer the brief, and click **Start writing**.
5. Click **Use this draft**, check the entry, and save it.

## Documentation

Everything else is in the [documentation](docs/README.md), including [Stock photos](docs/stock-photos.md).

## Providers and privacy

Ghostwriter writes with Claude, ChatGPT or Gemini, on your own account, and finds photos on Openverse, Unsplash, Pexels and Pixabay. Keys are read from `.env` and never stored. Nothing is sent until someone in the control panel asks for something, and then only to the provider you chose. See [API keys](docs/api-keys.md) and [Privacy](docs/privacy.md).

## Support

Report issues on [GitHub](https://github.com/1994limited/ghostwriter-craft/issues), or contact [1994](https://1994.co.uk).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Licence

Ghostwriter for Craft CMS is commercial software. See [LICENSE.md](LICENSE.md).

## Development

The providers, prompts, draft text handling, layout algorithms and the rules for pieces, the content plan, kinds, guides and images come from [ghostwriter-core](https://github.com/1994limited/ghostwriter-core), which Composer installs from Packagist.

```bash
composer install
cp tests/.env.example tests/.env   # point it at an empty MySQL database
vendor/bin/codecept run unit
```

The tests fake every model and HTTP call, so they need no API key.
