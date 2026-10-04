# Suggest edits and Content to revisit

**Suggest edits** reads an existing page against your voice guide and the rest of the site, and suggests small changes for you to step through: accept, edit, try another version or dismiss each one. **Content to revisit** ranks your live pages by checks that need no AI (dates, links, alt text, empty fields and age), so you know which pages are worth a review, and why.

## Content to revisit

### What it checks

Every live entry in a section Ghostwriter writes for is checked, for nothing: no model is ever asked.

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

**SEO fields.** SEOmatic's SEO Settings field: a description from **Custom Text** is checked as the page's own; one **From Field** is that field's text, checked and shown as coming from it; anything else (the section's defaults, a template, an asset) isn't checked. Plain Text fields called `seoTitle`, `metaTitle`, `seoDescription` or `metaDescription` are checked too, with their own character limit.

### Keeping it current

- **On save.** Saving an entry (the entry itself, not a draft or a revision) queues its row again, once however often it's saved while the job waits. Disabling it takes it off the list.
- **On delete.** Deleting an entry checks again every page that linked to it, so the broken link shows at once. Its suggestions' history goes when it's deleted for good, not while it's in the trash.
- **Daily**: `php craft ghostwriter/revisit/refresh` reads entries saved since the last run and the pages whose day has come (the day after a closing date, 1 January for "New for 2026"). Once a week, and the first time, it reads every page. `--full` reads every page now; `--site=2` one site. It also expires Suggest edits' suggestions nobody acted on for 14 days (decisions are kept).
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

## Settings

In **Settings → Plugins → Ghostwriter**, under **Suggest edits and Content to revisit**. Each can be set in `config/ghostwriter.php` instead, which locks it on the screen.

| Setting | Default | Config |
| --- | --- | --- |
| **Check claims**: counts and prices about you ("a team of 6", "from £450") on pages a year old or more are asked about as Facts to check, and the review may flag claims of its own | On | `claimChecks` |
| **Check links to other sites once a week** | Off | `checkExternalLinks` |
| **Dated sections where age counts in full** (channels only) | None | `ageInFullSections` (section handles) |
