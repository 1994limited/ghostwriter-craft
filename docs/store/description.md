**Ghostwriter learns how your site writes, then drafts new entries in that voice, right beside the entry form.**

Give it a short brief. It asks for anything it still needs, writes the draft, and puts it into the entry for you to check and save. Nothing is ever published for you.

It runs on Claude, ChatGPT or Gemini with your own API key, and works with whatever your sections already use: plain fields, CKEditor or Redactor, Tables, and Matrix or Neo page builders.

## Your voice, written down

Ghostwriter reads a sample of your published entries and writes a **voice guide**: who is talking and to whom, how pieces are shaped, the words you use and the ones you never do, with real examples from your site. It's a markdown file in your project. Edit it by hand, or ask for changes in plain words: "We never say solutions."

## Drafts that fit the page

Ghostwriter reads each section's field layout and the entries already in it, and learns how pages there are really built:

- Which blocks are used, in what order, and which settings never change
- **House style**, place by place: the spacer sizes in each position, breadcrumbs, how headings are dressed
- A link from a page to itself becomes a link to the new page. A link it can't decide is clearly marked for you to set.
- Image fields a page usually fills get a striped placeholder, so you can see where pictures go

The writer only writes words. Everything else comes from your own pages, so a draft looks like it belongs.

## Kinds of content

Ghostwriter looks through each section and suggests the kinds of content you write there, such as "Studio page", "Case study" or "Charity partner profile". Learn one, and it gets its own questions and guidance, modelled on the entries you pick.

## Edit what's already there

**Edit with Ghostwriter** on an existing entry opens the same conversation, with the entry as the draft. Ask for changes, and they go into your own unsaved Craft draft. Only the writing changes: images, links and settings stay as they were, and the live entry is untouched until you save.

## Pictures that look like yours

A Ghostwriter button sits beside **Add an asset** on image fields. It reads the block the field is in, and the page around it, then:

- **Finds a photo** from free libraries (Openverse with no key; Unsplash, Pexels and Pixabay with yours), with the best matches for the images you already use picked out
- **Makes one** in your site's style, with an OpenAI or Gemini key
- **Makes a logo card**: your logo, exactly as it is, on a flat colour or gradient

The image you choose is saved to the field's upload folder, with its credit, and dropped straight into the field.

An **image style guide** describes what your pictures look like, section by section, so every search and every new image is made to match.

## Know what to write next

The **content plan** reads what each section has and what it lacks, and suggests what's missing. Turn any idea into a new entry in one click.

## Up and running in minutes

**Get started** walks you through setup step by step. Connect a model, choose your sections, learn your voice, teach it your kinds of content, and write. A dashboard widget keeps what's in progress in view.

## Built to be trusted

- **Your keys stay yours.** They're read from your `.env` and never stored.
- **No invented facts.** The writer uses only what's in the brief and the conversation: no made-up figures, quotes or client names.
- **Nothing published for you.** Drafts go into Craft's own drafts, for a person to check and save.
- **Nothing sent until you ask.** Content goes to your chosen provider only when someone in the control panel starts something.
- **Works on any host.** Everything is kept in the database, so it works on read-only and load-balanced hosts, including Craft Cloud, and survives deploys. Every prompt can be overridden in your project.
- **One permission:** *Use Ghostwriter*. Writing into an entry still needs Craft's own permission to save it.

## Requirements

- Craft CMS 5.6 or later
- PHP 8.2 or later
- An API key for Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini)
- Optional: an OpenAI or Gemini key to make images
- Optional: the Imagick PHP extension, for logo cards
