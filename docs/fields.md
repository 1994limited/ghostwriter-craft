# How Ghostwriter reads your fields

Ghostwriter works with any section because it reads the section's field layout, and the entries already in it, rather than assuming a shape.

## Field types

Each field is reduced to a kind. The writer fills in the writing; everything else is left for a person or filled from the house style.

| Kind | Field types | The writer produces |
| --- | --- | --- |
| text, long text | Plain Text, Color | plain text |
| rich text | CKEditor, Redactor, HTML field | markdown, stored as HTML |
| choice, choices | Dropdown, Radio Buttons, Button Group; Checkboxes, Multi-select | one or more of the field's options |
| toggle, number | Lightswitch; Number, Money, Range | the value |
| blocks | Matrix, Neo (including child blocks) | a list of blocks, each with its own fields |
| rows | Table | rows sharing the same columns |
| reference | Assets, Entries, Categories, Users, Link, Hyper, dates and others | nothing; left for a person, or filled from the house style |

A Matrix or Neo field with nothing in it to write, such as a gallery of images, is treated as a reference.

## Learning from your entries

Without any setup, Ghostwriter reads a section's entries and learns:

- which page-builder blocks are used, how often, and in what order
- which fields are ever filled in
- which settings are the same on nearly every entry (these become house defaults)

A kind of content uses its own example entries for this; otherwise the newest published entries are used.

## House style

When a draft goes into a **new** entry, Ghostwriter also copies what the example entries agree on, **place by place**:

- **Settings by position.** If the first spacer on every page is 45/65 and the last is 60/100, the new page gets the same. A setting is copied when more than half the examples agree.
- **Nested items.** If every page has three breadcrumbs, the new page gets three.
- **Links.** A link is copied when nearly every example has the same one (at least 80%), such as breadcrumbs to Home and to the section's landing page.
- **Heading markup.** If a hero heading is always centred, white and uppercase, the writer's plain heading gets the same markup. The writer only writes words.

### Links to the page itself

A link from a page to itself, such as the last breadcrumb, is recognised as one. If two or more examples link to themselves in the same place, and none links anywhere else there, the new entry links to itself, under its own title.

### Links it can't decide

If a block should have a link (the field is required, or that kind of block usually has one), but the examples don't agree on where it goes, Ghostwriter points it at `https://example.com` with the text "Link to choose". The page still works, and the gap is easy to spot. The notes above the form list each one as "(links to example.com for now)". Set them before publishing.

This works for Hyper and Craft's Link field. Fields that can only hold entries can't take a web address, so they're simply listed as still to set.

House style is never applied when [editing](editing.md) an existing entry, which keeps its own.
