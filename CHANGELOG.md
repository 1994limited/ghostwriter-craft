# Release Notes for Ghostwriter

## Unreleased

Stock photos, from the stock images design, on `1994/ghostwriter-core` ^1.1.

### Added
- **The stock image ledger:** a record of every stock photo Ghostwriter puts into the site, free or paid: its library and ID, its asset, its licence state, the credit and licence, who added it and an append-only history. Kept in two new tables, `ghostwriter_stock_images` and `ghostwriter_stock_usages` (schema 1.2.0). Records are never deleted: deleting an asset marks its record removed and keeps any licence.
- **Use this** on a free library's photo records it in the ledger as licensed, with the entry and field it went into.
- Where each ledger image is used is read from Craft's relations whenever an entry is saved, following drafts and Matrix and Neo blocks to the entry at the top.
- **Photo libraries beyond the free four:** paid libraries are searched once their keys are in `.env` and they are switched on. A **demo library**, "Demo stock (no charge)" (core's `FakeLibrary`), is offered in dev mode or with `stockDemo` in config, and never when `CRAFT_ENVIRONMENT` is production. Getty Images (with iStock) and Shutterstock are listed with their keys' status (`GETTY_API_KEY`/`GETTY_API_SECRET`, `SHUTTERSTOCK_API_KEY`/`SHUTTERSTOCK_API_SECRET`) and marked as coming. Others can be added with `StockLibraries::EVENT_REGISTER_LIBRARIES`.
- **Settings → Stock photos:** the free libraries' keys and the Openverse switch; each paid library's keys (read from `.env`, never stored or shown), **Check connection** (the account and what it has left), and an enabled switch; **Search in, by default**; **Include editorial images by default**; and **When a page with an unlicensed preview is published** (Block or Warn). New settings `stockLibraries`, `stockDefaultSource`, `stockIncludeEditorial`, `stockOnPublish`, `stockDemo` and `stockUnusedDays`.
- Uninstalling writes the ledger to `storage/ghostwriter-stock-ledger-<date>.json` before its tables are dropped, and says so.

- **New entries start unpublished** (`draftsUnpublished`, on by default, matching Statamic's `drafts_unpublished`): when a draft goes into a new entry, its **Enabled** switch is turned off in the form (for the site too), so it can be saved straight away and an AI draft is never published by accident. The notification says "Ghostwriter drafts start unpublished. Switch on Enabled when you're ready." Existing entries are never changed.

### Changed
- Requires `1994/ghostwriter-core` ^1.1.
- Images a stock library's terms keep from AI (Getty Images and iStock) never go to a model: not as reference images for finding or making a picture, nor as samples for the image style guide. Files named `GettyImages-*` or `iStock-*`, or whose embedded credit names Getty Images or iStock, are left out too.

## 1.0.0 - 2026-10-02

First release. Ghostwriter learns how your site writes and what its pictures look like, then drafts new entries and edits existing ones in that voice, in a panel beside the entry form. Get started walks through setup, the Overview shows what is in progress, and a widget sits on Craft's dashboard.

### Writing
- **Write with Ghostwriter** on a section's new entries, beside **New entry** on the entry index, and from the Overview, the widget and the content plan.
- Choose a kind you taught it, something like what is already here, or a general brief that works on every section with no setup.
- The brief: answer its questions, or give a working title and notes and have it fill them in. **Model it on** up to six entries.
- A follow-up conversation: Ghostwriter asks at most three questions when it needs facts only you know, then takes changes in plain words. It never invents figures, quotes or client names.
- The draft in **Blocks** and **Text** views. Click any writing (or Tab to it) to change it in place; **Edit YAML** for the whole draft.
- **Use this draft** writes into the entry's Craft draft, to check and save. Nothing is saved or published for you.
- **Edit with Ghostwriter** on an existing entry changes its writing in conversation, into your provisional draft, keeping images, relations, links and settings.
- Plain text, CKEditor and Redactor fields, Matrix and Neo page builders, and Tables.

### Learning the site
- **Voice guide**, written from your published entries, edited by hand or by asking for a change.
- **Image style guide**, written from the images your entries use, one part per section.
- **Kinds of content**: suggested per section with examples and why each is worth teaching, learned on request, or taught by hand with their own questions, guidance, checklist and examples.
- Everything is kept in the database, so it works on read-only and load-balanced hosts.

### House style
- New entries copy what your entries agree on: block order, settings, links and heading markup. Links Ghostwriter can't decide point to `https://example.com` and are listed for you to set.

### Images
- A Ghostwriter button on Assets fields: **Find a photo** on Openverse, Unsplash, Pexels and Pixabay, ranked by the model against the page's words and the images already in that place, with a second round of searches when nothing fits.
- Found photos are named and titled from the library's description, which also fills Craft's own alt text, with the credit kept in a credit field where the volume has one.
- **Make the picture** with OpenAI or Gemini, in the style of the images already there, optionally with your own image in it.
- Photos offered for every image field under a draft, and striped placeholders where a new entry is still missing an image (**Mark images still to choose**).

### Content plan
- Ideas for entries each section is missing, reviewed before they join the plan, optionally steered. **Draft this** opens a new entry with the brief filled in.

### Teams
- Conversations are shared with everyone who may use Ghostwriter, showing who sent each message and who started and last changed each piece. Turn this off with **Share conversations** (`sharedConversations`).
- One permission, **Use Ghostwriter**. Writing into an entry still needs Craft's own permission to save it. Admins manage Ghostwriter.

### Providers
- Writing with Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini); images with OpenAI or Gemini. Defaults: `claude-opus-5-5`, `gpt-6.1-sol` and `gemini-3.8-flash` for writing; `gpt-image-2.5-sunburst` and `gemini-3.1-flash-image` for images.
- Keys are read from `.env` (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`) and never stored or shown.
- `baseUrls` for a gateway or proxy that speaks a provider's own API.
- A busy or rate-limited provider is tried again, up to three attempts in all, following its `retry-after`.
- A reply cut off at its length limit is asked for once more with twice the room; a draft or the voice guide is never saved half-written.
- Each call is logged to Craft's log with the provider, model, tokens and time, never the words.

### Requirements
- PHP 8.2 or later; Craft CMS 5.6 or later.
- An API key for Anthropic, OpenAI or Gemini.
- Craft's queue, which runs by itself from the control panel unless your site uses a worker.
