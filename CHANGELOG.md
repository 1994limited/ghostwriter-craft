# Release Notes for Ghostwriter

## Unreleased

Stock photos, from the stock images design, and "Finish this page", from the finish-this-page design, on `1994/ghostwriter-core` ^1.3.

### Added
- **The stock image ledger:** a record of every stock photo Ghostwriter puts into the site, free or paid: its library and ID, its asset, its licence state, the credit and licence, who added it and an append-only history. Kept in two new tables, `ghostwriter_stock_images` and `ghostwriter_stock_usages` (schema 1.2.0). Records are never deleted: deleting an asset marks its record removed and keeps any licence.
- **Use this** on a free library's photo records it in the ledger as licensed, with the entry and field it went into.
- Where each ledger image is used is read from Craft's relations whenever an entry is saved, following drafts and Matrix and Neo blocks to the entry at the top.
- **Photo libraries beyond the free four:** paid libraries are searched once their keys are in `.env` and they are switched on. A **demo library**, "Demo stock (no charge)" (core's `FakeLibrary`), is offered in dev mode or with `stockDemo` in config, and never when `CRAFT_ENVIRONMENT` is production. Getty Images (with iStock) and Shutterstock are listed with their keys' status (`GETTY_API_KEY`/`GETTY_API_SECRET`, `SHUTTERSTOCK_API_KEY`/`SHUTTERSTOCK_API_SECRET`) and marked as coming. Others can be added with `StockLibraries::EVENT_REGISTER_LIBRARIES`.
- **Settings → Stock photos:** the free libraries' keys and the Openverse switch; each paid library's keys (read from `.env`, never stored or shown), **Check connection** (the account and what it has left), and an enabled switch; **Search in, by default**; **Include editorial images by default**; and **When a page with an unlicensed preview is published** (Block or Warn). New settings `stockLibraries`, `stockDefaultSource`, `stockIncludeEditorial`, `stockOnPublish`, `stockDemo` and `stockUnusedDays`.
- **The image dialog searches stock libraries:** **Search in** (Free libraries, each paid library, or Everything) beside the search box, remembered for each person in their Craft user preferences. Each result has a source chip and a cost chip (**Free**, or the library's hint such as "1 download"), and an **Editorial** chip with its restrictions; editorial-only images are left out unless **Include editorial images** is ticked. A paid library's results are in its own order, never shown to a model; the intro says "Searches Demo stock for this part of the page. Results are in Demo stock's order." and a line above the results "Shown in Demo stock's order. Ghostwriter doesn't rank paid libraries." A preview the field holds now shows at the top under **In this field now**.
- **Insert preview** on a paid photo: the field gets a stand-in asset (stripes at the photo's aspect ratio, labelled "Demo stock demo-01 · preview, not licensed", named, titled and described from the photo), the watermarked comp is kept privately in Ghostwriter's own files until the library's comp period ends, and the ledger records a preview. "Preview added. Only signed-in editors see the photo; license it before publishing."
- **Editors see the comp where the stand-in is:** a control-panel-only comp route (`ghostwriter/stock/<id>/comp`, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex`), and `Asset::EVENT_DEFINE_URL` turning a preview's address into it for control panel requests and for previews (Live Preview, View draft, share links) by someone signed in who may use Ghostwriter. Anyone else, including a share link opened signed out, gets the stand-in. A transform of a preview gets the comp at its own size.
- **Marked on the field and the asset:** a badge under the image field ("Preview · not licensed", the library and photo ID) with **License**, or "Ask a manager to license" and **Request licence**; "Preview expired" with **Refresh preview** (once); a **Stock licence** panel in the asset's sidebar; and a **Stock licence** column for the asset index.
- **License & replace:** a confirm step with the licence options, the cost in the account's own terms ("Uses 1 of your 100 remaining downloads (Demo pack)"), restrictions, an acknowledgement for editorial-only images, the credit to be kept ("If this page is news, a blog post or other editorial use, show this credit next to the image."), and the iStock and Premium Access seat notices. The licence is bought once (core's `StockImages::license()`), and `Assets::replaceAssetFile()` swaps the stand-in's file for the licensed original byte for byte (Craft's image cleaning is skipped, so embedded metadata stays), keeping the asset's ID, relations, title, alt text and focal point, and filling the credit field. The comp is deleted. Failures are said plainly: not connected, nothing left, the price changed (with the new price), refused, or not confirmed ("We couldn't confirm the purchase. Ghostwriter will check with Getty in a few minutes; don't buy it again.").
- **Only a real image is put in place:** before License & replace swaps a file in, its type is read from its own bytes (the JPEG, PNG or WebP signature, and `getimagesizefromstring()` agreeing), not from the type the library sent, and the asset is named to match. Since Craft's image cleaning is skipped, anything else (an SVG, an HTML page, a truncated download) is refused with "The file Getty Images sent isn't a JPEG, PNG or WebP image, so it wasn't put in place." and the stand-in stays.
- **No page goes live holding a preview:** on `Entry::EVENT_BEFORE_SAVE`, a canonical entry enabled for its site, saved live or updated from a draft (applying a draft), is refused with "The hero image is a Demo stock preview, not licensed yet. License it, or choose another image, before publishing." on the field, at any depth in Matrix and Neo blocks. Drafts and disabled entries always save. **Warn** (`stockOnPublish`) saves with a notice; global sets and categories are only warned.
- **Ghostwriter → Stock images:** every stock photo in the site, by tab (Previews, with requested licences first; Licensed; Failed; All), with where each is used, its state, cost, licence and credit, and **License**, **Reconcile**, **Remove preview**, **Download licence record**, **Export CSV** and **Check where they're used**. An Overview tile, "N stock previews to license", and a line on the widget.
- **Cleanup** with Craft's garbage collection and `php craft ghostwriter/stock/cleanup`: comps deleted when their period ends (the stand-in stays: "Preview expired"), previews no entry has used for `stockUnusedDays` removed, and licences whose answer was lost settled with `reconcile()` after ten minutes. `php craft ghostwriter/stock/usages` looks again at where each is used.
- **Shutterstock** (core 1.2's adapter), from `SHUTTERSTOCK_API_KEY` and `SHUTTERSTOCK_API_SECRET`: search with the app's key and secret; license with the account connected. Nothing of a preview is stored: editors see Shutterstock's own watermarked preview. **Shutterstock sandbox** (`shutterstockSandbox`) uses `api-sandbox.shutterstock.com`, by default in dev mode.
- **Connect account / Disconnect** in the settings for libraries that license with a person's own sign-in, following core's `docs/connecting-accounts.md`: three control panel routes (`ghostwriter/libraries/<id>/connect`, `/callback`, `/disconnect`), admins only, a random single-use `state` kept in the session and checked with `hash_equals()`, the same absolute callback (built from the site's own URL, never the request's host) in both calls, and the host-and-path to register shown on the row. Tokens are kept through core's `LibraryTokens`, encrypted with Craft's security component (`DbLibraryTokens`).
- **Docs:** [Stock photos](docs/stock-photos.md).
- **`ghostwriter:license` permission** ("License stock images"), given to nobody by default; admins have it.
- Uninstalling writes the ledger to `storage/ghostwriter-stock-ledger-<date>.json` before its tables are dropped, and says so.

- **New entries start unpublished** (`draftsUnpublished`, on by default, matching Statamic's `drafts_unpublished`): when a draft goes into a new entry, its **Enabled** switch is turned off in the form (for the site too), so it can be saved straight away and an AI draft is never published by accident. The notification says "Ghostwriter drafts start unpublished. Switch on Enabled when you're ready." Existing entries are never changed.

- **Finish this page, on the server** (core 1.3's `Gaps`, finish-this-page design phases 2, 4, 5 and 9):
  - **One publish guard** for unfinished pages and stock previews (core's `PublishReadiness`), on the existing `Entry::EVENT_BEFORE_SAVE` hook: a live save, or applying a draft (`updatingFromDerivative`), is refused while a fact to add (`[[ask: …]]`), a link to choose (`#gw-link:`), a link to a deleted entry, an image placeholder, template text or an unlicensed stock preview remains, with core's message on each top-level field, named by its block inside Matrix and Neo ("Feature: Heading: Add opening days before publishing."). In warn mode, one notice lists them all. Drafts, provisional drafts and disabled entries always save. In sections Ghostwriter doesn't write for, only stock previews are looked for.
  - **New settings** under **Finish this page**: **When a page with things to finish is published** (`onUnfinishedPublish`, `block` or `warn`, reading `stockOnPublish` when unset; it replaces the stock photos setting on the settings screen) and **Open the guide after a draft is added** (`finishOpenAfterDraft`, on).
  - **Ports** for core's detectors: `CraftPlaceholderAssets` (the placeholder by its file name), `CraftAssetRefs` (Assets field IDs, and images inline in CKEditor by their `{asset:…}` reference tags, which Craft's relations don't hold) and `CraftLinkTargets` (entries by title and slug for "Link to Contact"; `{entry:…}` references that point at nothing).
  - **`ghostwriter/gaps/check`**: what is unfinished in an entry as the form has it (its draft or provisional draft, by element ID), translated, with each gap's place in the form (the element whose field it is, inside Matrix and Neo blocks) and the stock badge for previews. It never calls a model.
  - **"Write it for me" and "Write around it"** (`ghostwriter/gaps/fill`, queued as `FillGap`, polled with `ghostwriter/gaps/fill-status`): one small `gap-filler` request each, shown only to whoever asked, never put into the entry by the server, and never for a fact (core refuses).
  - **The guide's state** (open or minimised) is remembered per person, for every entry, in Craft's user preferences (`ghostwriter/gaps/guide`); minimised for someone new.
  - **The session keeps what a draft left for a person** (`Session::$gaps`, from core's `SessionGaps::fromDraft()`), so the guide can say "I didn't want to guess." The image placeholders note is no longer added to the notice after **Use this draft**: each placeholder is a step in the guide.
  - **Links the house style can't settle** now point at core's sentinel, `https://example.com/#gw-link:<hint>` (`LayoutOptions::withLinkSentinels()`), and are noted as "(link still to choose)".
  - **Craft translations** for core's gap strings, in `src/translations/en/ghostwriter.php`, copied by `bin/sync-gap-strings` (a test fails when they drift from core).
  - Core's contract tests run against Craft: `PlaceholderAssetsContract`, `AssetRefsContract`, `MarkerRoundTripContract` (through the real Applier) and `PublishGuardContract` (live save, draft, warn mode, a preview with a marker, applying a draft).
  - Reading an entry's Matrix and Neo blocks and relations now uses what was posted with the form (the query's cached result) before what is saved.
- **Docs:** [Finish this page](docs/finish-this-page.md).

### Changed
- Requires `1994/ghostwriter-core` ^1.3.
- Requires Craft CMS 5.8 or later. Core needs `symfony/yaml` 6.4 or later, which Craft allows from 5.8, so earlier versions could never be installed.
- Continuous integration: the test suite runs on GitHub Actions for every pull request, on PHP 8.2, 8.3 and 8.4, against the lowest Craft Composer will install and the newest.
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
