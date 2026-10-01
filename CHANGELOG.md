# Release Notes for Ghostwriter

## 1.0.0 - Unreleased

### Added
- Voice guide, written from a site's published entries, edited by hand or by asking.
- Writing for any section: plain fields, CKEditor or Redactor bodies, Matrix and Neo page builders, Tables.
- Kinds of content, suggested per section or taught by hand, each with its own questions and guidance.
- The writing panel on new entries, with the brief, a follow-up conversation, a block and text view of the draft that can be edited in place, and **Use this draft**, which writes into the entry's Craft draft.
- Editing existing entries in conversation, into the person's provisional draft, changing only the writing.
- House style: settings, links and heading markup that a section's pages agree on are carried into new pages; links to the page itself become links to the new page; links that cannot be decided point to `https://example.com` and are listed.
- The image button on Assets fields: find free photos (Openverse, Unsplash, Pexels, Pixabay), make an image with OpenAI or Gemini, or set a logo on a flat or gradient ground.
- Striped placeholders in image fields a draft leaves empty, where the section's pages usually have an image.
- Image style guide.
- Content plan, with ideas suggested from what each section has and lacks.
- Get started, a step-by-step setup guide, which can be hidden and brought back.
- A Ghostwriter widget for Craft's dashboard.
- Claude, ChatGPT and Gemini, with keys read from the environment and never stored.
- Everything kept in the database, so it works on read-only and load-balanced hosts; early builds' files are imported on update.
- Each conversation is private to the person who started it.
- Requests to providers that are rate limited, overloaded or briefly down are tried again, up to three times.
- An answer cut off at its length limit is asked for again with more room, and never used half-finished.
- Work whose job was stopped by a server time limit is shown as failed, so it can be tried again.
