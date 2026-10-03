# Stock photos

This page covers finding photos in stock libraries, free and paid: putting a paid photo in as a preview, licensing it from your own account, what stops a page going live with a preview, and the record Ghostwriter keeps of every stock photo it puts into your site.

> Paid libraries: **Shutterstock** works now, with your own API plan and your account connected. **Getty Images and iStock are coming**: their keys can already go in `.env`, and the settings show whether they are set, but Ghostwriter can't search or license with them yet. A **demo library** shows the whole flow on a test site, without an account or any charge. Free libraries work as before.

## How it works

1. In the image dialog, **Search in** chooses where to look: the free libraries, a paid library, or everything.
2. A free photo is used as before, with **Use this**.
3. A paid photo goes in as a **preview** with **Insert preview**. The field gets a stand-in image, and editors see the watermarked photo (the provider's "comp") in its place.
4. Someone allowed to license presses **License**, checks what it costs, and confirms. The stand-in's file is swapped for the licensed photo. The asset, every entry that uses it, its alt text and its focal point stay as they were.
5. Until then, an entry holding a preview can't be published.

Every stock photo, free or paid, is recorded in the [ledger](#the-stock-images-screen).

## Libraries

| Library | Needs | |
| --- | --- | --- |
| Openverse, Unsplash, Pexels, Pixabay | as on [Images](images.md#find-a-photo) | Free. **Use this** saves the photo. |
| Demo stock (no charge) | dev mode, or `stockDemo` | A pretend paid library for trying the flow. It calls nobody and charges nothing. |
| Getty Images and iStock | `GETTY_API_KEY`, `GETTY_API_SECRET` | **Coming.** A key and secret from your own Getty Images or iStock account rep, under your own agreement. An iStock key works here too. |
| Shutterstock | `SHUTTERSTOCK_API_KEY`, `SHUTTERSTOCK_API_SECRET` | Your own app's consumer key and secret. Searching needs only those; licensing needs a Shutterstock **API plan** (a shutterstock.com web plan can't license through the API) and your account **connected** in the settings. See [Shutterstock](#shutterstock). |

You always license from **your own account**, with your own key and secret. Ghostwriter never licenses on your behalf, never routes calls through 1994's servers, and keys are read from `.env` each time, never stored or shown.

### Shutterstock

1. Create an app at **shutterstock.com/account/developers/apps** and put its consumer key and secret in `.env` as `SHUTTERSTOCK_API_KEY` and `SHUTTERSTOCK_API_SECRET`.
2. In the app's **Callback URL** field, add the host name and path the settings show, such as `cms.example.com/admin/ghostwriter/libraries/shutterstock/callback`. Shutterstock takes host names and paths here, not whole URLs.
3. In **Settings → Plugins → Ghostwriter → Stock photos**, click **Connect account** and sign in to Shutterstock. You come back to the settings with "Shutterstock is connected." **Disconnect** forgets the connection (Shutterstock has no way to revoke it from here; delete the app to do that). Licences already bought stay in the ledger.

Only admins can connect or disconnect. The connection belongs to the site, not to whoever clicked: the licences are your company's. Its tokens are kept encrypted with your site's security key, last an hour and are renewed as needed. If the connection is lost (a changed password, a deleted app), licensing says so; connect again.

Shutterstock's terms give still images no comp licence, so nothing of a preview is stored: editors see Shutterstock's own watermarked preview, and a 30-day limit applies to the preview as for the other libraries.

**The sandbox.** Shutterstock's sandbox (`api-sandbox.shutterstock.com`) charges nothing for licences and gives a watermarked file. Ghostwriter uses it in dev mode by default, and the library shows as "Shutterstock (sandbox)". Set **Shutterstock sandbox** (`shutterstockSandbox`) to **Always** or **Never** to choose. Sign-in always goes to Shutterstock itself.

### The demo library

"Demo stock (no charge)" is offered when Craft's `devMode` is on, or when `stockDemo` is set (to `true`, or an environment variable such as `$GHOSTWRITER_STOCK_DEMO`). It's **never** offered when `CRAFT_ENVIRONMENT` is `production`, whatever the setting says. Its photos are drawn stripes with a "PREVIEW" watermark, two of them editorial-only, and each has a standard and an extended licence. Licensing one costs nothing.

## Finding a photo

Open the image dialog with the **Ghostwriter** button on an Assets field (see [Images](images.md)). Once a paid library is set up, the **Find a photo** tab has:

- **Search in:** *Free libraries*, each paid library, or *Everything*. It starts at the site's default, then remembers your last choice (in your Craft user preferences).
- **Include editorial images:** editorial-only images (news, events, public figures) can't be used to advertise or promote anything, so they're left out unless you tick this.

Each result has a source chip (bottom left: "Unsplash", "Demo stock") and a cost chip (bottom right): **Free**, or what the library says before licensing, such as "1 download" or "3 credits", or "Paid" when it says nothing. An **Editorial** chip marks editorial-only images; hover it for the restrictions.

Paid libraries' results are in their own order. Ghostwriter never shows a paid library's photos, titles or captions to a model: Getty's and iStock's licences forbid any AI use of their content and metadata. The tab's intro says so ("Searches Getty Images for this part of the page. Results are in Getty Images's order."), and so does a line above the results: "Shown in Getty Images's order. Ghostwriter doesn't rank paid libraries." With *Everything*, the free photos come first, judged as before, and the paid ones after.

If the field already holds a preview, it's shown at the top of the dialog under **In this field now**, with its badge.

## Previews

**Insert preview** on a paid photo:

- puts a **stand-in** in the field: grey stripes at the photo's shape, labelled with the library and photo ID, "preview, not licensed". It holds nothing of the provider's, so it's safe at a public address;
- names, titles and describes the asset from the photo, as the licensed file will be (for example `rocks-at-dusk-getty-1234567.jpg`);
- keeps the watermarked comp in Ghostwriter's own storage (its database), **never as an asset** and never at a public address, for the provider's comp period: 30 days for Getty and iStock.

"Preview added. Only signed-in editors see the photo; license it before publishing."

**Where you see the comp:** wherever the control panel shows the image (the field, the asset editor) and in **Live Preview** and **View draft**, for anyone signed in with **Use Ghostwriter**. Ghostwriter does this by pointing the stand-in's address at a control-panel-only route, `admin/ghostwriter/stock/<id>/comp`, which is never cached. A share link opened by someone who isn't signed in shows the stand-in, as does the live site.

**Under the field**, a badge says "Preview · not licensed", with the library and photo ID, and:

- **License**, for someone with the **License stock images** permission;
- "Ask a manager to license" and **Request licence**, for everyone else. A request puts the photo at the top of the **Previews** tab on [Stock images](#the-stock-images-screen). (No email is sent yet.)

When the comp period ends, the comp is deleted and the badge says "Preview expired". **Refresh preview** downloads it again, once.

The asset's own page has a **Stock licence** panel in its sidebar, and the asset index can show a **Stock licence** column (choose it under **View**).

## Licensing

**License** opens **License & replace**:

- the photo, its library and ID;
- **Licence**: the options your account can buy, such as standard or extended;
- the cost, in your account's own terms, for example "Uses 1 of your 742 remaining downloads (Premium Access, resets 1 Nov)". When the library can't say before licensing, it says so;
- any restrictions, and for an editorial-only image a box to tick to confirm editorial use;
- the credit Ghostwriter will keep, with "If this page is news, a blog post or other editorial use, show this credit next to the image.";
- for an iStock standard licence or Getty Premium Access, a notice about seats and storage.

**License & replace** buys the licence once, then swaps the stand-in's file for the licensed photo with Craft's **Replace file**:

- the asset keeps its ID, so every entry using it shows the licensed photo;
- its title, alt text and focal point stay as they were (editors may have changed them since the preview went in);
- the file is kept exactly as the library sent it, never re-encoded, so its embedded copyright and image ID stay, as the licences require;
- because nothing cleans it, the file must really be a JPEG, PNG or WebP image. Ghostwriter reads its type from the file itself, not from what the library said it was, and names it to match. Anything else (an SVG, a web page, a broken download) is refused and the preview stays where it is;
- the credit goes into the volume's credit field, if it has one (see [Names, alt text and credits](images.md#names-alt-text-and-credits)).

"Licensed. The preview has been replaced with the full image."

If it goes wrong:

| What happened | What you see |
| --- | --- |
| The library isn't connected | "Ghostwriter isn't connected to Getty Images. Check its key in Settings, then try again." |
| Nothing left to license with | "Your Getty Images account has nothing left to license this with. Nothing was charged." |
| The price changed | "The price has changed: it is now 3 credits. Check it and license again." The new price is shown. |
| The library refused | Its reason, and "Nothing was charged." |
| The answer was lost | "We couldn't confirm the purchase. Ghostwriter will check with Getty Images in a few minutes; don't buy it again." Ghostwriter asks the library which licences it has for the photo, and never buys it twice. |
| Licensed, but the file couldn't be put in place | **Download again and replace** fetches it again without buying it again. If the library sent something other than an image: "The file Getty Images sent isn't a JPEG, PNG or WebP image, so it wasn't put in place." |

### Credits

Getty Images and iStock need a credit next to an image whenever a page uses it for **editorial purposes** (news, a blog post), whatever the image's label. Ghostwriter keeps the credit line for every licence, in the ledger and in the volume's credit field. Print it beside the image in your templates on editorial pages.

## Publishing

An entry holding a preview can't go live. Saving the live entry, or applying a draft to it, is refused with a message on the field:

> This is a Getty Images preview, not licensed yet. License it, or choose another image, before publishing.

Inside a Matrix or Neo block, the message names the block and field ("Hero: Image: This is a Getty Images preview…"). The same check, with the same one message, also stops a page going live with anything else still to finish: see [Finish this page](finish-this-page.md).

License the photo, or choose another, then save. **Drafts always save**, provisional drafts included, so you can keep working with the preview in place. A disabled entry saves too; new entries Ghostwriter writes start disabled (see [Use this draft](writing.md#use-this-draft)).

To warn instead of refusing, set **When a page with things to finish is published** to **Warn** (`onUnfinishedPublish` = `warn`; the older `stockOnPublish` is still read when it isn't set). The entry saves, with a notice. Global sets and categories are only ever warned.

## The Stock images screen

**Ghostwriter → Stock images** lists every stock photo Ghostwriter has put into the site, free or paid, newest first:

- **Previews** (requested licences first), **Licensed**, **Failed** and **All**;
- each with its thumbnail, library and ID, where it's used (with links), its state, what it cost, who licensed it and when, its credit line and restrictions;
- actions: **License**, **Reconcile** (check with the library on a licence whose answer was lost), **Remove preview** (deletes the stand-in, which comes out of any entry it was in) and **Download licence record** (the whole record, with its history, as JSON);
- **Export CSV**, for finance and audits;
- **Check where they're used**, to look at every entry again.

The **Overview** has a tile, "N stock previews to license", while there are any, and the widget has a line too.

Licence records are kept for good. Deleting an asset marks its record removed and keeps the licence. Uninstalling Ghostwriter first writes the whole ledger to `storage/ghostwriter-stock-ledger-<date>.json`, and says so.

## Cleanup

Craft's garbage collection, and `php craft ghostwriter/stock/cleanup`:

- deletes a comp when its period ends (Getty and iStock: 30 days after it was downloaded), even if the preview is still in use; the stand-in stays;
- removes a preview no entry has used for 30 days (`stockUnusedDays`), stand-in and all; one in use is never deleted;
- settles a licence whose answer was lost, once it's ten minutes old, from the library's own records.

`php craft ghostwriter/stock/usages` looks again at where every stock photo is used.

## AI and stock photos

Getty's and iStock's licences forbid any AI or machine-learning use of their images **and their metadata**, licensed images included. So Ghostwriter never sends a Getty or iStock image (preview, stand-in or licensed file), or its title or caption, to a model: not to rank photos, not as an example of the images already used in a place, not to describe your images for the image style guide. It also leaves out any file named `GettyImages-…` or `iStock-…`, and any whose embedded credit names Getty Images or iStock, however it got onto the site.

If your site uses other AI tools (for alt text, say), set them to skip these images too.

## Settings

**Settings → Plugins → Ghostwriter → Stock photos**, for admins:

| Setting | Config key | |
| --- | --- | --- |
| Free libraries | | Each key's status, and **Search Openverse**. |
| Paid libraries | `stockLibraries` | Each library's keys (**Set** or **Not set**), **Check connection** (who it's connected as and what's left), an **Enabled** switch, and for Shutterstock **Connect account** / **Disconnect** with the callback to register. |
| **Shutterstock sandbox** | `shutterstockSandbox` | **In dev mode** (the default), **Always** or **Never**. |
| **Search in, by default** | `stockDefaultSource` | `free`, `everything` or a library's ID, such as `demo`. |
| **Include editorial images by default** | `stockIncludeEditorial` | Off. |
| **When a page with things to finish is published** (under **Finish this page**) | `onUnfinishedPublish` | `block` (the default) or `warn`. Covers previews and every other gap; `stockOnPublish` is read when it isn't set. |
| | `stockDemo` | Offer the demo library outside dev mode. Never in production. |
| | `stockUnusedDays` | Days before an unused preview is cleaned up. 30. |

## Permissions

**License stock images** (`ghostwriter:license`) is a permission of its own, because licensing spends money. Nobody has it by default; admins do. Inserting a preview needs only **Use Ghostwriter** and permission to upload to the field's volume, as before. See [Permissions](permissions.md).
