# Release Notes for Ghostwriter

## 1.3.0 - 2026-10-16

Suggest edits and Content to revisit, which find what has gone stale on your live pages and help you fix it; a live preview of every draft through your own templates, with other layouts to choose from and comments you pin on the page; drafts that arrive linked to your other pages, with a search title and description; and **Connections**, one place to set up every key. Requires `1994/ghostwriter-core` ^1.10.

### Added
- **Suggest edits**, in the Ghostwriter menu beside **Edit with Ghostwriter** on an existing entry. Free checks look for candidates: a past year written as current ("New for 2024"), "this year" on an old page, a count or claim about you that may have changed ("our team of 6"), a sentence that runs on, a link to a deleted entry, an image with no alt text, SEO text over its limit or missing, a heading over 70 characters, and a long page with no links to your other pages. A confirm says what the review costs before anything is sent. The review keeps a candidate only if it's a real problem in its paragraph, adds its own suggestions for voice and clarity, and a second call checks every suggestion again before you see it. The guide shows each change in its sentence, with the reason and where it comes from, and **Accept**, **Edit**, **Another version**, **It's still right** and **Dismiss**, with Undo; category filters; **Accept all wording fixes**; links to your other pages; and alt text saved to the image, after a confirm that says how many pages use it. Accepted words go into your draft; nothing is saved but alt text. Entries nested in CKEditor fields are read and edited too. Decisions are shared and remembered. See [Suggest edits](docs/suggest-edits.md).
- **Content to revisit** (Ghostwriter → Content to revisit, and a tile on the Overview): every live page in the sections Ghostwriter writes for, ranked by free checks (no AI): past years written as current, a closing date that has passed, links to deleted entries or assets, images without alt text, empty fields that pages like it fill, unfinished markers, SEO text over its limit, no SEO description, no links to your other pages, heading levels, and age. Tiles filter the list; **Review** opens a page with Suggest edits ready to run; **Snooze for 90 days**; a section filter and a site switcher. Only the sections you can view are listed. It's kept up to date as entries are saved and deleted, and by `php craft ghostwriter/revisit/refresh`, the daily pass, from cron (a full pass weekly). Without cron, Craft's garbage collection runs it once it's over a day old.
- **A weekly check of links to other sites**, off by default: `php craft ghostwriter/revisit/check-links` does nothing unless an admin turns it on. Each address at most once a week, one request a second per site, and a link counts as broken after two failed checks in a row.
- Three settings, under **Suggest edits and Content to revisit**: **Check claims** (on), **Check links to other sites once a week** (off) and **Dated sections where age counts in full** (none). Each can be set in `config/ghostwriter.php` instead (`claimChecks`, `checkExternalLinks`, `ageInFullSections`).
- **The Preview tab.** The draft now has three tabs, **Preview | Blocks | Text**. Preview shows the unsaved draft rendered through your section's own template, at **Desktop** or **Phone** width, and renders again as the draft changes. Hovering outlines each block with its name. Nothing is written to show it: no draft, no element. A template that fails says why (with the error, for admins), with **Show blocks instead**. Preview pages get headers that keep them private and out of search engines and block third-party scripts. Templates can test `ghostwriterPreview`. New settings: `preview` and `previewScriptHosts`. See [Preview](docs/writing.md#preview).
- **Layouts.** With a first draft, Ghostwriter offers up to two other layouts of the same words, ranked against your section's own pages, with the most like them marked **Suggested**. They show as a row of names above the draft; **Compare layouts** shows them as cards with a thumbnail of each page. Each says what it changes ("Call to action added · Paragraphs as lists"), and choosing one scrolls to and outlines what changed. Only layouts that really differ are offered. The choice is shared with everyone on the piece. See [Layouts](docs/writing.md#layouts).
- **Extras.** The writer prepares numbers, FAQs, a pull quote, a call to action and the like, only for the block types your section has and only from what you gave it. The Text tab lists each with where it came from, and every part can be edited or deleted. See [Extras](docs/writing.md#extras).
- **Comments on the page.** Turn on **Comment** in the draft's toolbar (Alt+Shift+C), click a block or select words, and write a comment. Comments stay yours, in your browser, until you **Apply** them: one call revises only the commented blocks, and the reply says what happened to each (Changed, with **Before / after** and **Put it back**; Replied; Not applied; or Skipped because someone else changed the block). Comments can be resolved and reopened, follow their words between layouts, and work by keyboard. See [Comments](docs/writing.md#comments).
- **Gaps as chips in the draft.** In the Preview and the Text tab, a fact to add, a count to check and a link to choose show as small chips instead of raw markers. Click one to deal with it there: type the fact, confirm or change the count, or choose the page to link to. Under Plain Text fields and Table cells on the entry form, a row of chips names each gap as you type. The saved entry keeps its markers until they're dealt with.
- **Questions before a draft, one box each.** When Ghostwriter needs to ask, it lists at most four short questions, each with its own box (or choices), *(optional)* where it is, and **Skip**. **Send answers**, or **Just draft it with what you have**.
- **Model it on picks entries for you.** In a section with no learned kind, the brief now arrives with up to six live entries ticked, the closest in purpose and shape to the new piece. Your own ticks always win.
- **Links to your other pages in every first draft.** Ghostwriter links a new draft to the most relevant pages of your site (about one link per 250 words, two to five), and a second call checks each one. Links Ghostwriter added are marked in the Text and Blocks tabs, where you can see where each goes and **Remove link**. Every section with URLs, and category pages with enough text of their own, can be linked to; disabled, noindex and utility pages and other sites never are.
- **Suggest links** in Finish this page: **Link to your other pages** finds pages to link to on the entry as your form has it, in the background, and offers each as a step: "Link “…” to Contact us?", **Link it** or **Skip**.
- **A search title, description and address with the first draft.** Ghostwriter writes the page's SEO description, and a search title when the page title is too long, from what the page says. They're in a **Search** section at the foot of the Text tab, editable, with a live character count and **Try again**. **Use this draft** puts them into SEOmatic or your plain SEO fields, never over text someone wrote, and sets the slug on a new entry. Photo file names are made from the alt text.
- **Headings fit your template.** Drafts start at H2 under the page's H1, never skip a level, and use only the heading levels each CKEditor field allows. The Preview reads how your template prints headings, and the settings show a note when a template prints no H1, or several.
- **Finish this page** gains:
  - **counts to check**: a number Ghostwriter counted from a list you gave ("3 counties") is a step, with **Looks right**, **Change it** and **Remove it**, and stops publishing like a fact to add;
  - **Shorten a heading** for a heading over 70 characters (**Write it for me**, one small call);
  - **Link to your other pages** on a page of 300 words or more with no links to your site;
  - **Add a description for search** when the SEO description is empty or too short;
  - a prompt for an image the page looks like it needs (required, the hero, or one most entries in the section have).

  These suggestions never stop publishing, except counts to check.
- **Create entry** on a new entry's draft is checked before publishing, like Save on a live entry (Craft 5.9 and later).
- **Connections** (Ghostwriter → Connections), for admins: a card for every service Ghostwriter uses, under Writing (Anthropic, OpenAI, Gemini, OpenRouter), Images (Unsplash, Pexels, Pixabay, Openverse) and Stock photos (Shutterstock). **Set up** opens the page where the key is made and takes you through it; **Check & save** tests the key with one small call before keeping it. Each card says whether it's connected (showing only the key's last four characters), set in `.env`, or has stopped working, with **Replace key** and **Disconnect**. Connect with OpenRouter and Shutterstock's Connect account are on their cards. A key in `.env` always wins. See [Connections](docs/connections.md).
- Keys set up in Connections are kept in a new `ghostwriter_credentials` table, encrypted with your security key, never in project config. If `CRAFT_SECURITY_KEY` changes, set them up again.
- German, French, Dutch and Spanish for the new SEO and Connections wording.

### Changed
- **The publish guard only acts on what an editor saves in the control panel.** Console commands (`resave/entries`, migrations, imports), queued jobs and the front end are never refused or warned about.
- **Ghostwriter only reads entries you can view.** Learning or suggesting kinds, writing in a section, and the entries listed under **Model it on** need Craft's **View entries** for that section (and **View other users' entries** for theirs); sections you can't view aren't offered.
- Keeping a photo, a made picture or a stock preview in an Assets field needs **Upload files** in that volume.
- **Remove preview** on Stock images needs permission to delete the preview's asset.
- **One menu beside Edit with Ghostwriter.** The "things to finish" pill is replaced by one count on a menu holding **Finish this page**, **Review suggestions** and **Suggest edits**.
- **The writing panel works at every width.** Below about 900 px the conversation and the draft are one column, with **Conversation | Draft** at the top. The draft, its layouts and the Preview scroll as one column.
- **The writer keeps its own links to real pages** instead of turning them into links to choose. For a link it couldn't place, Finish this page suggests the page it most likely means first.
- **Better link suggestions**: pages are matched by word stems in English, German, French, Dutch and Spanish ("seed" meets "seedheads"), rare words count for more, key pages are always considered, and pages that link to each other or share a category rank higher.
- **Finish this page leaves required fields to Craft's own validation**; an empty required field is no longer a step.
- SEOmatic values are written with their override switch on, so a value the field offers can be written even when it was inherited. A description inherited from an empty field is written, as if empty.
- Addresses keep the whole title ("what-to-do-in-the-garden-in-march"); only a title over about 60 characters loses its little words.
- Suggest edits shows only what to act on, never how many things were found fine.
- The settings' API keys table is now a summary linking to Connections. The OpenRouter key and Shutterstock account move into Connections when you run `php craft up`.

### Fixed
- **Try again** is offered when the first draft fails right after **Looks right, start writing**.
- The Overview and Get started fit a phone.
- Ghostwriter's note to the writer when editing an entry no longer shows in the conversation as if you'd sent it.
- An SEOmatic description the page inherits is read as SEOmatic prints it (the field, then the section, then the global setting), including robots, instead of as an empty one Ghostwriter could write.
- An entry removed without an event is forgotten by the weekly pass.
- A busy server (502, 503, 504) is no longer reported as a page that refuses to show in a frame.
- "1 thing still to finish" always opens onto that thing.
- A link to choose whose hint CKEditor holds encoded is found and highlighted.
- The guide's mark points at the words, not the field, and its words never cover the page's text or run off the window. Long button labels are cut short inside the card.
- Editing writing with a link in the Text tab keeps the link as Craft's reference tag.
- In the Extras list, Enter finishes an item and Esc puts it back.
- Suggest edits' spinner sits beside its words.

### Upgrading
```bash
composer update 1994/ghostwriter-craft 1994/ghostwriter-core
php craft up
php craft ghostwriter/revisit/refresh --full
```

- `php craft up` adds the new tables and moves the OpenRouter key and Shutterstock account into Connections. Keys in `.env` keep working.
- `ghostwriter/revisit/refresh --full` builds Content to revisit and the link index straight away; otherwise they fill in over the daily pass. Add it to cron daily (see [Daily jobs](docs/installation.md#daily-jobs)).
- See [Upgrading from 1.2 to 1.3](docs/installation.md#upgrading-from-12-to-13).

## 1.2.0 - 2026-10-03

Requires `1994/ghostwriter-core` ^1.6.1, which no longer brackets quoted titles or figures you gave in a filled-in brief.

Requires `1994/ghostwriter-core` ^1.6.

### Added
- **OpenRouter, a fourth provider (`openrouter`).** Claude, GPT or Gemini through one OpenRouter account, for writing and for making images, from `OPENROUTER_API_KEY` or **Connect with OpenRouter**. See [OpenRouter](docs/api-keys.md#openrouter).
- **Connect with OpenRouter** in the settings (admins): sign in at openrouter.ai and come back with a key, kept encrypted with Craft's security component in `ghostwriter_state` (`provider-key:openrouter`). The state and PKCE verifier are kept together in the control panel session and used once. **Check connection** shows the credit left; **Disconnect** forgets the key. A key in `.env` always wins. New routes: `ghostwriter/providers/openrouter/connect`, `callback`, `disconnect` and `check`.
- **OpenRouter model for writing** and **OpenRouter model for quick jobs** (`openrouterModels`), and a privacy note when OpenRouter is chosen. `provider`, `imageProvider` and `baseUrls` take `openrouter`.

### Changed
- **The brief is in the conversation.** The separate brief screen is gone. After you choose what you're writing, Ghostwriter asks in one message for the quick details ("What’s it called, and what should it say? A line or two is plenty."), fills in the whole brief for that kind from your answer (one model call) and shows it in the conversation as an editable brief card: the working title, every question with its answer, and **Model it on** with the entries it will follow ticked. Anything only you know stays in `[square brackets]`; facts about your organisation are never made up. **Looks right, start writing** starts the writing; **Try again** fills it in again, keeping the answers you changed. The questions, **Just draft it with what you have**, the draft and changes work as before. Once agreed, the card folds away to **Show the brief** and stays editable (**Save the brief**). See [Writing a new entry](docs/writing.md#the-brief).
- **Draft this** on the content plan skips the question: the brief card arrives already filled in from the idea. Pieces carried on, and shared conversations, show the card in the thread. Pieces started from the old brief screen still show their brief behind **Show the brief**.
- The brief card is a labelled region, and its arrival is announced to screen readers. **⌘↵** in the card agrees to it.
- New control panel actions: `ghostwriter/sessions/open`, `from-idea`, `try-again`, `agree` and `edit-brief`, and a `FillBrief` queue job. `ghostwriter/sessions/brief` and `ghostwriter/sessions/start` are removed. Nothing is stored until you answer the first question, so choosing a kind and closing the panel leaves no empty piece behind.
- Core's English for the brief is copied into Craft's translations as `brief.*` keys, by `bin/sync-core-strings` (was `bin/sync-gap-strings`). The temporary "Finish this page" wording overrides (`src/translations/wording.php`) are gone now that core words them the same way.

## 1.1.1 - 2026-10-03

### Fixed
- Finish this page: **Link to …** on a Link field showed the chosen entry but saved nothing, so the field was empty after the next autosave. The guide now reports the choice the way Craft's own picker does, and the link is saved.
- A fix counts only when the field reads back different. Otherwise the guide says "That didn't change the field" and stays on the step. Craft's own pickers are checked the same way when they close.
- Cancelling a picker opened by the guide leaves the field as it was: no pending replacement, and a Link field switched to Entry goes back to URL.
- The guide and the flying mark step aside while a slideout is open, and come back with a fresh check when it closes.
- **Remove it** in CKEditor no longer leaves a double space.

## 1.1.0 - 2026-10-03

Stock photos, "Finish this page", and new entries that start unpublished, on `1994/ghostwriter-core` ^1.4.

> {warning} Ghostwriter now requires **Craft CMS 5.8 or later**. 1.0.0 said Craft 5.6, but it could never be installed on Craft 5.6 or 5.7: `1994/ghostwriter-core` needs `symfony/yaml` ^6.4, which Craft allows only from 5.8. After updating, run `php craft up` (or apply the update in the control panel): it adds the stock image ledger's two tables.

### Added
- **The stock image ledger:** a record of every stock photo Ghostwriter puts into the site, free or paid: its library and ID, its asset, its licence state, the credit and licence, who added it and an append-only history. Kept in two new tables, `ghostwriter_stock_images` and `ghostwriter_stock_usages` (schema 1.2.0). Records are never deleted: deleting an asset marks its record removed and keeps any licence.
- **Use this** on a free library's photo records it in the ledger as licensed, with the entry and field it went into.
- Where each ledger image is used is read from Craft's relations whenever an entry is saved, following drafts and Matrix and Neo blocks to the entry at the top.
- **Photo libraries beyond the free four:** paid libraries are searched once their keys are in `.env` and they are switched on. A **demo library**, "Demo stock (no charge)" (core's `FakeLibrary`), is offered in dev mode or with `stockDemo` in config, and never when `CRAFT_ENVIRONMENT` is production. Shutterstock is listed with its keys' status (`SHUTTERSTOCK_API_KEY`/`SHUTTERSTOCK_API_SECRET`); Getty Images (with iStock) is listed with its keys' status (`GETTY_API_KEY`/`GETTY_API_SECRET`) and marked as coming. Others can be added with `StockLibraries::EVENT_REGISTER_LIBRARIES`.
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
- **Shutterstock (API plan required)** (core's adapter), from `SHUTTERSTOCK_API_KEY` and `SHUTTERSTOCK_API_SECRET`: search with the app's key and secret; licensing needs a Shutterstock API plan and the account connected (a shutterstock.com web plan can't license through the API). Nothing of a preview is stored: editors see Shutterstock's own watermarked preview. **Shutterstock sandbox** (`shutterstockSandbox`) uses `api-sandbox.shutterstock.com`, by default in dev mode.
- **Connect account / Disconnect** in the settings for libraries that license with a person's own sign-in, following core's `docs/connecting-accounts.md`: three control panel routes (`ghostwriter/libraries/<id>/connect`, `/callback`, `/disconnect`), admins only, a random single-use `state` kept in the session and checked with `hash_equals()`, the same absolute callback (built from the site's own URL, never the request's host) in both calls, and the host-and-path to register shown on the row. Tokens are kept through core's `LibraryTokens`, encrypted with Craft's security component (`DbLibraryTokens`).
- **Docs:** [Stock photos](docs/stock-photos.md).
- **`ghostwriter:license` permission** ("License stock images"), given to nobody by default; admins have it.
- Uninstalling writes the ledger to `storage/ghostwriter-stock-ledger-<date>.json` before its tables are dropped, and says so.

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
- **Finish this page, the guide** (finish-this-page design §7 and §8.2, phases 6 to 8 and 10 for Craft), on every entry in a section Ghostwriter writes for, for anyone who may use Ghostwriter:
  - **A count beside Save** ("8 things to finish", then "Ready to publish"), shown on load only when something stops the page going live, so a new, empty entry isn't nagged.
  - **Highlights:** each field with a gap outlined (amber; purple for the current step; green once it had gaps and has none left), with a tag beside the field's name that jumps to its step: the current step and its kind ("7 · Oops"), or the field's steps ("4–7 · 4 to do"). Inside CKEditor the marker, the link's words or the template text itself is marked with CKEditor's own markers, so the highlight follows typing.
  - **The guide** (bottom right): each step's message, the fixes, and Back / Skip for now / Next, with a progress bar; things to finish first ("2 of 8"), then suggestions, numbered on their own ("Suggestion 1 of 1"). Fixes write into the form, never save: the fact typed into a box goes where the marker was (Enter or leaving the box; Esc leaves it), **Link to …** points the link at the matching entry (in CKEditor, or Craft's Link field, whose "Link to choose" label is cleared), **Choose an entry** and **Choose from Assets** open the field's own picker through its input (a full image field replaces its placeholder), **Remove the link**, **Remove it**, **Leave it empty**, **It's fine**, **Find a photo** (the image dialog for that field) and, for stock previews, the stock feature's own **License** dialog, **Request licence** and **Refresh preview**. **Write it for me** and **Write around it** ask Ghostwriter once each, and say so.
  - **One live list, as the Statamic addon keeps it:** the entry is checked again after each autosave of Craft's draft, and the steps are exactly the gaps found then. A gap that's gone is no longer a step; one a fix made (a placeholder swapped for a stock preview) is a new open step; the count, the "2 of 8" and the bar come from that one list. Gaps keep their identity when Craft gives a provisional draft's blocks new IDs, and blocks are found through Craft's `draftElementIds`.
  - **The flying Ghostwriter mark** goes to each field, just past its tag, with a short label, follows scrolling (in any pane), resizing and the form moving under it, and waits by the guide when its field is off screen. **Minimise** folds the guide into the mark in a round dock with the count (genie, wisps, a wave, a peek every seven seconds; the badge pulses when the count changes); restoring unfurls it. The choice is remembered for each person on every entry; after **Use this draft** the guide opens by itself (`finishOpenAfterDraft`).
  - **Matrix and Neo:** collapsed blocks are expanded and tabs opened when a step comes up; in Matrix's cards view the card is highlighted and **Open block** opens Craft's slideout, where its fields are highlighted too. The guide steps aside while one of Craft's modals is open.
  - **Keyboard and screen readers:** Alt+Shift+N / P / G, Esc inside the guide; a labelled region, a polite live region, tags as buttons, focus moved to the step and to the dock.
  - **Phones** (below 640px): a bottom sheet that folds to a bar, no flying mark. **Reduced motion**: nothing flies, bobs or folds.
  - The guide's words, and core's, are Craft translations (`Craft.t('ghostwriter', …)`, registered for the page).
  - Places in blocks are named once: "the Text block", or "Subheading (in the Hero block)", not "Text: Text", in the guide and in the publish guard's messages.
  - The guide's helpers have their own tests (`node --test tests/js/*.test.mjs`), run in CI.
- **Finish this page: fixes that stick** (an audit of every fix in a browser, checking what the field shows, that it is saved, and that the gap clears):
  - **Link to …** on Craft's Link field showed the entry but saved nothing: the field writes its stored value only when its picker reports a choice. The guide now reports it the same way, so `{entry:…}` is saved.
  - **A fix counts only when the field reads back different.** Otherwise the guide says "That didn't change the field. Try another fix, or change it yourself." and stays on the step. A choice made in Craft's own pickers (Choose an entry, Choose from Assets, Find a photo) is checked the same way once the picker closes.
  - **Cancelling a picker leaves the field as it was:** the replace Craft was holding is dropped, and a Link field switched to Entry to open its picker goes back to the type (and address) it had.
  - **The guide and the mark step aside while a slideout is open** (a block opened from cards view), as they do for Craft's modals, and come back, checking again, when it closes.
  - **Remove it** in CKEditor no longer leaves two spaces where the template text was.
- **"Include editorial images" says what it means:** an (i) with Craft's info icon (focusable, and opened by a tap) explains editorial photos and where they may be used, a hint under it reads "News and event photos. Not for advertising or promotion.", and it shows only when the source chosen in **Search in** can return editorial images (core's `Capabilities::$editorial`; never the free libraries). Each **Search in** option now says whether it can (`editorial`).
- **Docs:** [Finish this page](docs/finish-this-page.md).

### Changed
- **New entries start unpublished** (`draftsUnpublished`, on by default, matching Statamic's `drafts_unpublished`): when a draft goes into a new entry, its **Enabled** switch is turned off in the form (for the site too), so it can be saved straight away and an AI draft is never published by accident. The notification says "Ghostwriter drafts start unpublished. Switch on Enabled when you're ready." Existing entries are never changed.
- Requires `1994/ghostwriter-core` ^1.4.
- **Requires Craft CMS 5.8 or later** (was 5.6). Core needs `symfony/yaml` 6.4 or later, which Craft allows from 5.8, so Ghostwriter could never be installed on Craft 5.6 or 5.7.
- Schema version 1.2.0: one new migration, `m261003_000000_stock_ledger`, adds `ghostwriter_stock_images` and `ghostwriter_stock_usages`.
- Continuous integration: the test suite runs on GitHub Actions for every pull request, on PHP 8.2, 8.3 and 8.4, against the lowest Craft Composer will install and the newest.
- Images a stock library's terms keep from AI (Getty Images and iStock) never go to a model: not as reference images for finding or making a picture, nor as samples for the image style guide. Files named `GettyImages-*` or `iStock-*`, or whose embedded credit names Getty Images or iStock, are left out too.

### Fixed
- The minimum Craft version in `composer.json` and the docs: 1.0.0 claimed Craft 5.6, which Composer could never satisfy alongside core.
- Reading an entry's Matrix and Neo blocks and relations now uses what was posted with the form (the query's cached result) before what is saved.

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
