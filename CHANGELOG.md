# Release Notes for Ghostwriter

## 1.0.0 - Unreleased

### Added
- Voice guide, written from a site's published entries, edited by hand or by asking.
- Writing for any section: plain fields, CKEditor or Redactor bodies, Matrix and Neo page builders, Tables.
- Kinds of content, suggested per section or taught by hand, each with its own questions and guidance.
- The writing panel on new entries, with the brief, a follow-up conversation, a block and text view of the draft that can be edited in place, and **Use this draft**, which writes into the entry's Craft draft.
- Editing existing entries in conversation, into the person's provisional draft, changing only the writing.
- House style: settings, links and heading markup that a section's pages agree on are carried into new pages; links to the page itself become links to the new page; links that cannot be decided point to `https://example.com` and are listed.
- The image button on Assets fields: find free photos (Openverse, Unsplash, Pexels, Pixabay), or make an image with OpenAI or Gemini.
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

### Changed
- The AI providers, the prompts and draft text handling now come from [ghostwriter-core](https://github.com/1994limited/ghostwriter-core), the package shared with the Statamic and Filament versions. No change is expected in how Ghostwriter writes or behaves. Prompts to copy for an override are now in `vendor/1994/ghostwriter-core/resources/prompts/`; overrides in `config/ghostwriter/prompts/` keep working.
- Each queue job is allowed three times the configured timeout plus a minute, to cover a busy provider being tried three times.
- Model calls (never their prompts, replies or keys) are logged to Craft's log under the `ghostwriter` category.
- Get started counts only the five steps setup needs, so its bar reaches the end; once they are done, a smaller **You're set up** card stays on the dashboard until Get started is hidden.
- Only admins can hide Get started or bring it back, since it is hidden for the whole site.
- The settings warn when the model named doesn't look like the chosen provider's, and show the provider's default model as the placeholder.
- Settings set in `config/ghostwriter.php` are shown locked on the settings page, with a note saying where they are set.
- The guide buttons say what they do: **Write the voice guide** and **Rescan and rewrite**; **Describe the images** and **Look again and rewrite**.
- A failed guide stays explained on its screen until the next try, rather than for one visit.
- **Teach it a kind** is now **Teach a kind**.
- **Learn all** asks first, saying it takes about a minute a kind.
- The kind editor no longer shows each question's handle; it is kept as the question is renamed.
- Ghostwriter's replies in the writing panel show markdown (lists, bold, links), with any HTML escaped.
- Text edited in place in the draft: **Esc** puts it back as it was, and **Enter** finishes a one-line piece.
- The image dialog is about 48rem wide and as tall as it needs, with photos in three columns. It shows the best three first, with **View N more**, and a photo can be chosen by clicking it.
- Photos are only marked **Best match** when the model compared them with the images already there; otherwise a line says they are in search order.
- Content plan: closing the suggestions box no longer throws the batch away. It waits, with a **N suggestions waiting** card at the top of the plan to reopen it; only **Drop them all** discards it. The add button counts what is ticked (**Add N to the plan**, or **Add none, dismiss the rest**).
- Content plan ideas are shown newest first within each section, and **Put back** is only offered on dismissed ideas, not finished ones.

### Removed
- Logo cards (a logo set on a flat or gradient ground) are gone from the image button, which now offers **Find a photo** and **Make one**, with no tabs when only one is available. To put a logo or product in a picture, add it as an image of your own under **Make one**.

### Fixed
- **Generate the guide** on the voice guide (and on Get started) with no section ticked no longer reads every section on the site: the button is off until a section is ticked, and the server refuses an empty choice.
- A kind of content that fails to save (no name, or no question) comes back with what was typed, rather than the stored version.
- Deleting a kind of content says so on the dashboard, and an error deleting it is shown.
- The dashboard widget without an API key now shows a one-line warning and keeps Get started and **Open Ghostwriter**, with **Write something** shown but switched off. It also names the next setup step.
- **Show Get started** at the foot of the dashboard is there whenever Get started is hidden, including once setup is complete.
- The notes after **Use this draft** (choices left, links to settle, placeholders) are one notification listing them all, which stays until it is closed, instead of a toast per note.
- Suggested kinds show why each is worth teaching and up to three example titles, on the dashboard and on Get started, as the docs said.
- The image button no longer appears when there is nothing it can do (no photo library and no image key).
- Counts read "1 suggestion", "1 idea", "1 entry" rather than "1 ideas", and keeping no suggestions says how many were dismissed rather than "0 ideas added".
