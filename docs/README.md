# Ghostwriter for Craft CMS: documentation

Ghostwriter learns how your site writes, then drafts new entries and edits existing ones in that voice, in a panel beside the entry form. It asks a short set of questions, follows up on anything it still needs, and puts the draft into the entry for you to check and save.

## Setting up

1. [Installation](installation.md): install the plugin, add your key, the queue, updating and uninstalling.
2. [Get started](getting-started.md): the seven steps from a fresh install to the first draft.
3. [API keys](api-keys.md): getting a key for each service, including the free options; gateways; retries.
4. [Permissions](permissions.md): who can use and manage Ghostwriter, shared conversations, deleting a piece.
5. [Configuration](configuration.md): settings, `config/ghostwriter.php`, where things are kept, prompts, logging.

## Using Ghostwriter

- [Writing a new entry](writing.md)
- [Editing an existing entry](editing.md)
- [Voice guide and image style](guides.md)
- [Kinds of content](kinds.md)
- [Images](images.md)
- [Stock photos](stock-photos.md): paid libraries, previews, licensing, the ledger. Getty Images, iStock and Shutterstock are coming.
- [Content plan](content-plan.md)
- [The Overview and the widget](dashboard.md)

## Reference

- [How Ghostwriter reads your fields](fields.md): field types, Matrix and Neo, house style.
- [Privacy](privacy.md): what is sent where, and what is kept.
- [Troubleshooting](troubleshooting.md)

## Requirements

- PHP 8.2 or later, Craft CMS 5.6 or later
- An API key for Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini)
- Craft's queue (it runs from the control panel by default)
