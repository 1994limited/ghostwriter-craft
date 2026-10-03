# Finish this page

Some things in an entry only a person can finish. Ghostwriter finds them, and it won't let the page go live until they're done.

- **Facts to add.** Ghostwriter never invents a price, a date, a duration, a name or a quote. Where a draft needs one it doesn't have, it marks the place: `Tickets cost [[ask: adult ticket price]] for adults.`
- **Links to choose.** A link whose page Ghostwriter doesn't know keeps its words, and points at `#gw-link:` with a hint: `[Talk to us](#gw-link:contact-page)`. A Link or Hyper field it can't settle holds `https://example.com/#gw-link:<hint>` with the text "Link to choose".
- **Images to choose.** The striped placeholder in an image field, and a stock photo preview not licensed yet.

It also notices links to entries that have been deleted, template text such as `[[item]]` left in a sentence, required fields left empty, and placeholder words such as "TBC".

Finding them never calls a model. It works on any entry in a section Ghostwriter writes for, whoever wrote it.

## What counts

| What | How it's found | Stops publishing |
| --- | --- | --- |
| A fact to add | `[[ask: …]]` in any text | Yes |
| A fact for a number or date field | The draft asked for it, and the field is still empty | Yes, if the field is required |
| A link to choose | A link to `#gw-link:…`, or a Link or Hyper field holding `https://example.com/#gw-link:…` | Yes |
| A link to a deleted entry | Its `{entry:…}` reference points at nothing | Yes |
| An image placeholder | The striped `ghostwriter-image-placeholder.png` in an image field, or inline in CKEditor | Yes |
| A stock photo preview | The stock ledger says it isn't licensed. See [Stock photos](stock-photos.md#publishing) | Yes |
| Template text | `[[item]]`, a word in double brackets with no colon | Yes |
| A required field left empty | Craft's own **Required** | Craft stops it |
| A field pages like this usually fill | Filled on at least half the section's live entries | No, a suggestion |
| Placeholder words | `TBC`, `TBD`, `TBA`, `TODO`, `XXX`, `[insert …]`, `[add …]`, `[check …]`, `lorem ipsum` | No, a suggestion |

In sections Ghostwriter doesn't write for, only stock photo previews are looked for.

## Publishing

While anything that stops publishing is left, saving the live entry is refused, with a message on each field:

> Add adult ticket price before publishing.

Inside a Matrix or Neo block, the message names the block and field: "Hero: Intro: Add adult ticket price before publishing."

- Applying a draft to a live entry is checked the same way.
- **Drafts always save**, provisional drafts included, and so does a disabled entry. New entries Ghostwriter writes start disabled (see [Use this draft](writing.md#use-this-draft)).
- Ghostwriter never takes a marker out for you. Removing `[[ask: …]]` from "Tickets cost [[ask: adult ticket price]] for adults." would leave "Tickets cost for adults."

To publish anyway and be told what's left, set **Settings → Ghostwriter → Finish this page → When a page with things to finish is published** to **Warn** (`onUnfinishedPublish` = `warn`). The entry saves with one notice listing everything. Anything still marked goes live as it is: a `[[ask: …]]` shows as written, and a `#gw-link:` link goes nowhere.

The older `stockOnPublish` setting is still read when `onUnfinishedPublish` isn't set.

## Settings

| Setting | Config | |
| --- | --- | --- |
| **When a page with things to finish is published** | `onUnfinishedPublish` | `block` (the default) or `warn`. |
| **Open the guide after a draft is added** | `finishOpenAfterDraft` | On. |
