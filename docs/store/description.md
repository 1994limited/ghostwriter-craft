**Ghostwriter writes new entries in your site's voice and builds them into your own page layouts: the right blocks, in the right order, with your house style. It edits existing entries the same way, right beside the entry form.**

Tell it what you're writing in a line or two. It fills in the brief, asks for anything only you know, writes the draft and puts it into the entry for you to check and save. Nothing is ever published for you.

It runs on Claude, ChatGPT or Gemini with your own API key, or connect an OpenRouter account in one click instead. It works with whatever your sections already use: plain fields, CKEditor or Redactor, Tables, and Matrix or Neo page builders.

## Drafts that fit the page

Ghostwriter reads each section's field layout and the entries already in it, and learns how pages there are really built:

- Which blocks are used, in what order, and which settings never change
- **House style**, place by place: the spacer sizes in each position, breadcrumbs, how headings are dressed
- A link from a page to itself becomes a link to the new page. A link it can't decide is clearly marked for you to set.
- Image fields a page usually fills get a striped placeholder, so you can see where pictures go

The writer only writes words. Everything else comes from your own pages, so a draft looks like it belongs.

## The brief, in the conversation

Choose what you're writing, and Ghostwriter asks: "What's it called, and what should it say?" From your reply it fills in the whole brief for that kind of content, as a card you can edit: every question answered, and the entries to model it on ticked. Anything it can't know stays in [square brackets] for you. **Looks right, start writing**, or **Try again**.

## See the page before anything is saved

The draft shows in a **Preview**, rendered by the section's own template, so you see the page it would make. Nothing is saved.

- **Layouts to choose from.** A first draft comes laid out up to three ways, with the same words. Each says what it changes ("Quote moved up", "Call to action added"), and switching scrolls to the change.
- **Comment on the page.** Click a block, or select some words, and say what should change. **Apply** sends your comments together, and only those parts are revised.
- **Links to your other pages.** A first draft links to a few of your existing pages where they help the reader, each checked to be live and to read naturally. Every one is marked for you to keep or remove.
- **Headings that fit.** Headings start below the page's own H1, never skip a level, and use only the levels each CKEditor field offers.

## Your voice, written down

Ghostwriter reads a sample of your published entries and writes a **voice guide**: who is talking and to whom, how pieces are shaped, the words you use and the ones you never do, with real examples from your site. Edit it by hand, or ask for changes in plain words: "We never say solutions."

## Kinds of content

Ghostwriter suggests the kinds of content you write in each section, such as "Studio page", "Case study" or "Charity partner profile". Learn one, and it gets its own questions and guidance, modelled on the entries you pick.

## Edit what's already there

**Edit with Ghostwriter** on an existing entry opens the same conversation, with the entry as the draft. Ask for changes, and they go into your own unsaved Craft draft. Only the writing changes: images, links and settings stay as they were, and the live entry is untouched until you save.

## Pictures that look like yours

A Ghostwriter button sits beside **Add an asset** on image fields. It reads the block the field is in, and the page around it, then:

- **Finds a photo** from free libraries (Openverse with no key; Unsplash, Pexels and Pixabay with yours). The model ranks the results against the page's words and the images you already use there, and marks the best matches.
- **Makes one** in your site's style, with an OpenAI or Gemini key, with your own product shot in it if you like

The image you choose is saved to the field's upload folder and dropped straight into the field. A found photo gets a name, title and Craft alt text from the library's own description of it, and its credit is kept in your volume's credit field.

An **image style guide** describes what your pictures look like, section by section, so every search and every new image is made to match.

## Stock photos, licensed properly

Search **Shutterstock (API plan required)** from the same dialog as the free libraries. Getty Images and iStock are coming. A demo library shows the whole flow on a test site, without an account or any charge.

- **See it on the page first.** Insert a watermarked preview where the photo will go. Only signed-in editors see it, in the control panel and in Live Preview, in place; everyone else sees a striped stand-in.
- **License & replace** buys the photo once, through your own connected account, and swaps the full image into the same asset. Every entry using it shows the licensed photo, and its alt text and focal point stay as they were.
- **No preview goes live.** An entry can't be published while it holds one. Prefer a warning? Choose that in the settings.
- **Every licence on record.** The **Stock images** screen lists every stock photo on the site: where it's used, who licensed it and when, and the credit line to show. Download a licence record, or export the lot as CSV.

Licensing has its own permission, so only the people you choose can buy photos. Anyone else can send a **Request licence**.

## Finish this page

Ghostwriter never makes up a price, a date or a name. Where a draft needs a fact it doesn't have, it marks the place, and a link it can't settle points nowhere until you choose.

Every gap is highlighted in the entry form, and a guide with the Ghostwriter mark walks you through them one by one: type in the fact, link to the right page, swap the placeholder image, license the preview. **The page can't go live while one of them remains.** Prefer a warning? Choose that in the settings.

## Keep existing pages current

**Suggest edits** reads an existing page against your voice guide and suggests small changes: a date written as current that has passed, a sentence that runs on, a missing link, alt text. Each suggestion is checked twice before you see it, and you step through them on the form: accept, edit, try another version, or dismiss. Nothing is saved until you save.

**Content to revisit** ranks your live pages by checks that need no AI: past years written as current, closing dates gone by, broken links, images without alt text, empty fields and age. **Review** opens a page with Suggest edits ready.

## Know what to write next

The **content plan** reads what each section has and what it lacks, and suggests what's missing. **Draft this** opens a new entry with the brief already filled in from the idea.

## Written together

Conversations are shared with everyone who can use Ghostwriter, so a colleague can pick up a piece where you left it. Each message shows who sent it, and Ghostwriter answers one request at a time. Prefer each person's own? Turn sharing off in the settings.

## Up and running in minutes

**Get started** walks you through setup step by step. Connect a model, choose your sections, learn your voice, teach it your kinds of content, and write. The **Overview** and a widget for Craft's Dashboard keep what's in progress in view.

## Built to be trusted

- **Your keys stay on your site.** Ghostwriter sends them only to the provider you chose, never to us. A gateway or proxy can be set per provider.
- **No invented facts.** The writer uses only what's in the brief and the conversation: no made-up figures, quotes or client names.
- **Nothing published for you.** Drafts go into Craft's own drafts, for a person to check and save, and new entries Ghostwriter fills start disabled (a setting, on by default).
- **Nothing sent until you ask.** Content goes to your chosen provider only when someone in the control panel starts something.
- **Works on any host.** Everything is kept in the database, so it works on read-only and load-balanced hosts, including Craft Cloud, and survives deploys. Every prompt can be overridden in your project.
- **Two permissions:** *Use Ghostwriter*, and *License stock images* for whoever may buy stock photos. Writing into an entry still needs Craft's own permission to save it.

## Requirements

- Craft CMS 5.8 or later
- PHP 8.2 or later
- An API key for Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini), or an OpenRouter account. Usage is billed to your own account by that provider.
- Optional: an OpenAI or Gemini key to make images
- Optional: the Imagick PHP extension, to send smaller copies of images to the model
- Optional: a Shutterstock API plan, to license stock photos (a shutterstock.com web plan can't license through the API)
