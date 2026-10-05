# Suggest edits and Content to revisit

**Suggest edits** reads an existing page against your voice guide and the rest of the site, and suggests small changes for you to step through: accept, edit, try another version or dismiss each one. **Content to revisit** ranks your live pages by checks that need no AI (dates, links, alt text, empty fields and age), so you know which pages are worth a review, and why.

## Suggest edits

On an existing entry, open the Ghostwriter menu beside **Edit with Ghostwriter** and choose **Suggest edits**. A confirm says what it does and what it costs before anything is sent:

- **Two calls to your AI provider** for a page: one reviews the page, a second double-checks every suggestion before you see it. A long page is read in parts, and the confirm says how many calls that makes.

The review reads the page as your form has it: your provisional draft, unsaved changes and all. That includes the entries nested in a CKEditor field (a pull quote, a call to action), each read as a Matrix entry is. It runs on the queue. While it runs, the menu beside **Edit with Ghostwriter** has **Review suggestions** (which opens the guide) and the guide shows its progress ("Reviewing… then double-checking"). You can keep working on the page; a decision made meanwhile is sent once the review is ready.

### What it suggests

Free checks find candidates for nothing: a past year written as current ("New for 2024"), "this year" on an old page, a count or claim about you that may have changed ("our team of 6 designers"), a sentence that runs on, a link to a deleted entry, an image with no alt text, and an SEO title or description over its limit. **None of these reaches you on its own.** The review reads each one in its paragraph, under its heading, with the page's title, kind and date and your voice guide, and keeps it only if it's a real problem there ("New for 2023" in a 2023 journal post is history, so it's dropped). It adds its own suggestions for voice and clarity on the same terms. A second call then checks every suggestion again: that it's real, that the new words read naturally in their sentence, that they add no facts, and that they're in your voice. Things judged fine are remembered, and aren't suggested again until their sentence changes.

| Category | What you can do |
| --- | --- |
| **Out of date** | The whole sentence rewritten, with up to two other versions (one without the dated words). **Accept**, **Edit**, **Another version**, **It's still right** (not asked again for 12 months, or until the sentence is edited), **Dismiss**. |
| **Voice**, **Clarity**, **SEO**, **Duplicate** | **Accept**, **Edit**, **Another version**, **Dismiss**. Once the two free versions are used, **Write another** asks for more (one small call, and it says so). An SEOmatic description that comes from another field can't be written here: **Copy the new text**, and set it in SEOmatic's settings for the field. |
| **Fact to check** | Ghostwriter never supplies a fact. Type the answer ("8") and **Use it**, or **It's still right**, **Remove the number**, **Dismiss**. "8 or 9?" is refused: type the number only. |
| **Link** | **Link to it** (the entry it found), **Choose an entry** (Craft's entry picker), **Remove the link** (the words stay), **Dismiss**. |
| **Accessibility** (alt text) | **Save to the image**, after a confirm: alt text belongs to the asset, so it's saved now, not when you save the page, and shows wherever the image is used ("2 pages"). **Undo** writes the old alt text back. It needs permission to save the volume's assets; without it, you get the text to **Copy**. |

Each step shows the change **in its sentence**, the old words struck through and the new ones below, with the reason and where it comes from ("Voice guide: “What this voice never does”").

![Suggested edits on a journal entry: the Body field outlined in indigo with its tag "1 · Clarity", the words underlined in CKEditor with the Ghostwriter mark above them, and the guide showing "Speak as the studio, to the reader." with the old words struck through, the new ones below, Accept, Edit, Write another and Dismiss, and Accept all wording fixes (2)](images/suggest-edits.png)

### Stepping through

The guide is Finish this page's: the flying mark, the dock and the highlights, with its count on the menu beside **Edit with Ghostwriter** (added to Finish this page's), in indigo so a suggestion never looks like something unfinished (amber). When both are minimised, their docks sit side by side; opening one minimises the other, and the keyboard shortcuts belong to the one opened last. Fields with suggestions are outlined and tagged ("2 · Voice", "3–4"); inside CKEditor the words have a dotted underline, the current ones filled.

- **Filters**: All, then one per category, with how many are open.
- **Accept all wording fixes** accepts every open Voice, Clarity and SEO suggestion in the filter (never facts, links, alt text or dates). **Undo all** puts them back.
- **Undo** after any decision puts the words back.
- Everything goes **into the form**: CKEditor through its own editor (bold, italic and links kept, so ⌘Z works too), text fields as if typed. Craft autosaves your provisional draft as it does for any typing; **nothing is saved to the entry until you save it** (alt text aside). A suggestion in an entry nested in a CKEditor field (or a Matrix entry shown as cards) opens that entry's slideout and goes in there: **Save** in the slideout puts it into your draft, as any edit there does. When you do, the suggestions whose words are in the page are marked done, and the page's row on Content to revisit is checked again.
- Decisions are shared with everyone who can edit the page (as conversations are, with **Shared conversations** on) and kept as the page's history. Suggestions nobody acted on expire after 14 days; the decisions stay.
- A review opened later shows its count on the menu and the guide minimised (**Review suggestions** in the menu opens it). If you edit text a suggestion was about, it's marked "This text has changed since the review." Accepted but never saved, it's open again.
- **Keyboard**: Alt+Shift+N and P step through, Alt+Shift+G opens or minimises, Esc minimises from inside the guide, Enter puts in an answer or your edit and Esc puts it back. Changes are announced to screen readers.
- Below 640 px the guide is a sheet at the bottom of the screen.

**Who can use it**: anyone with **Use Ghostwriter** who can save the entry. Reviews are kept in `{{%ghostwriter_edit_reviews}}`. Craft's control panel actions are `ghostwriter/suggest/guide`, `start`, `decide`, `another`, `alt` and `unalt`.

## Content to revisit

### What it checks

Every live entry in a section Ghostwriter writes for is checked, for nothing: no model is ever asked. The entries nested in its CKEditor fields are checked with it.

| Reason | What it means |
| --- | --- |
| **“New for 2024”** | A past year written as if it were current. History ("since 2015", "in 2019 we won") is left alone. |
| **“this year” = 2023** | "this year", "next spring", "currently", "coming soon" on a page last saved a year or more ago. |
| **closing date passed** | A date after a closing word ("applications close 31 January 2025"), or a date field such as `closingDate` or `deadline`, now past. |
| **1 broken link** | A link to an entry or asset that has been deleted, in CKEditor or a Link field. |
| **1 link to another site failed** | Only when the weekly check of links to other sites is on (below). |
| **no alt text** | An image whose asset has no alt text, where its volume's field layout shows **Alternative Text**. |
| **empty fields** | Fields most pages like it fill, left empty. |
| **unfinished** | A fact to add, a link to choose or a placeholder left from [Finish this page](finish-this-page.md). |
| **SEO text too long** | An SEO title over 60 characters or a description over 160 (or a Plain Text field's own character limit). |
| **2 years old** | Age, from the entry's last save. |

The phrase checks read English, German, French, Dutch and Spanish, in each site's own language. A site in another language gets the checks that need no words: links, alt text, SEO length, empty fields and age.

**Dated sections.** In a channel (news, a journal), an old post is meant to be old, so age and past years count a quarter as much. An admin can make any channel count them in full (below).

**SEO fields.** Ghostwriter reads each SEO title and description the way SEOmatic does, so it checks the text the page really prints:

- **The page's own**, where its SEO Settings field overrides the value (the field offers the setting, its switch is on, and it isn't empty). Only this can be written from Suggest edits.
- **Otherwise the section's**, then the site's, from SEOmatic's own settings. A value taken from another field (**From Field**, or SEOmatic's `extractTextFromField()` on the excerpt) is that field's text, checked and shown as coming from it. A fixed default is checked as it is; a template Ghostwriter can't work out, or a value that's switched off, isn't.

An inherited value that fits is left alone: an entry whose description comes from its excerpt isn't told its description is empty. One that's empty or too long is still found, and says where it comes from, so you know where to change it. Plain Text fields called `seoTitle`, `metaTitle`, `seoDescription` or `metaDescription` are checked too, with their own character limit.

### Keeping it current

- **On save.** Saving an entry (the entry itself, not a draft or a revision) queues its row again, once however often it's saved while the job waits. Disabling it takes it off the list.
- **On delete.** Deleting an entry checks again every page that linked to it, so the broken link shows at once. Its suggestions' history goes when it's deleted for good, not while it's in the trash.
- **Daily**: `php craft ghostwriter/revisit/refresh` reads entries saved since the last run and the pages whose day has come (the day after a closing date, 1 January for "New for 2026"). Once a week, and the first time, it reads every page. `--full` reads every page now; `--site=2` one site. It also expires Suggest edits' suggestions nobody acted on for 14 days (decisions are kept). And it keeps the list of pages a first draft can [link to](writing.md#links-to-your-other-pages) up to date: every page with an address, in every section, and categories with pages of their own. Saving or deleting a page queues its update at once; a change to a whole section or category group, or a page moved in a structure, is picked up on the next daily pass.
- **Weekly**: `php craft ghostwriter/revisit/check-links`, which does nothing unless the check of links to other sites is on.

Craft has no scheduler, so add them to cron:

```
0 3 * * * php /path/to/craft ghostwriter/revisit/refresh
0 4 * * 0 php /path/to/craft ghostwriter/revisit/check-links
```

Without cron, Craft's garbage collection, and opening the list, queue the daily pass once it's over a day old. The list and the settings say when it last ran.

Rows are kept in `{{%ghostwriter_revisit}}`, the links each page holds in `{{%ghostwriter_revisit_links}}` (indexed on what they point at), and each entry's title, address, summary and paragraph fingerprints, for the review's duplicate check and its list of related pages, in `{{%ghostwriter_entry_index}}`.

### Links to other sites

**Check links to other sites once a week** is off by default, and only an admin can turn it on. When it's on, once a week Ghostwriter asks each site your pages link to whether the page is still there:

- a HEAD request (a one-byte GET if the site refuses HEAD), saying it's Ghostwriter's link check, through Craft's own HTTP client (so `config/guzzle.php` applies);
- each address at most once a week, however many pages link to it, and at most 500 a run;
- one request at a time, at least a second apart on any one site, with a 10-second timeout.

A link counts as broken only after it fails twice in a row (404, 410, or a name that no longer resolves). Anything else (a timeout, 401, 403, 429, a server error) says nothing about the page and is never shown. Links to your own pages are always checked, with no request.

### The list

**Ghostwriter → Content to revisit**, and a **Content to revisit** tile on the [Overview](dashboard.md#the-tiles) with how many pages are worth a look and the top three, each with its main reason.

![Content to revisit: tiles for 1 page worth a look, 2 mentioning a past year as current, 0 broken links and 0 images without alt text, a section filter, and two pages with their reasons (closing date passed, “New for 2024”), priority bars marked Medium and Low, and Review](images/content-to-revisit.png)

- **Tiles**: pages worth a look (a priority of Medium or High), pages mentioning a past year as current, broken links, images without alt text. Each filters the list; click it again for everything.
- **The list**, highest priority first, 25 a page, for the site you're on (a site switcher on a multi-site install), and only the sections whose entries you can view: the page and its section, when it was last updated, why (the most important reasons first), and its priority as a bar and a word (High, Medium, Low). A section filter narrows it.
- **Review** opens the page with Suggest edits ready to run: the confirm, with its cost, or a review that still fits the page.
- **⌄**: **Snooze for 90 days** (off the list for everyone; needs permission to save the page) and **Open without reviewing**.
- Opened before the daily pass has ever run, every page is read once in the background; when the list is over a day old it says when it last ran and the cron line that keeps it daily.
- On a phone, each page is a card.

## Settings

In **Settings → Plugins → Ghostwriter**, under **Suggest edits and Content to revisit**. Each can be set in `config/ghostwriter.php` instead, which locks it on the screen.

| Setting | Default | Config |
| --- | --- | --- |
| **Check claims**: counts and prices about you ("a team of 6", "from £450") on pages a year old or more are asked about as Facts to check, and the review may flag claims of its own | On | `claimChecks` |
| **Check links to other sites once a week** | Off | `checkExternalLinks` |
| **Dated sections where age counts in full** (channels only) | None | `ageInFullSections` (section handles) |
