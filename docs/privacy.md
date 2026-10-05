# Privacy

This page covers what Ghostwriter sends to the model and the photo libraries, what it never sends, and what it keeps in your database.

## What is sent, and where

Nothing is sent to a model until someone in the control panel asks for it: by starting a piece, writing a guide, asking for kinds or ideas, asking for a review, or using the image button. Finish this page and Content to revisit work without a model. Ghostwriter sends no analytics and doesn't phone home.

**To the writing provider** (Anthropic, OpenAI or Google, whichever is chosen, on your own account, or the [gateway](api-keys.md#gateways-and-proxies) you set):

| When | What is sent |
| --- | --- |
| Writing or editing a piece | The voice guide, the kind's brief, guidance and checklist, the answers to the brief, the conversation, the current draft, the field layout, and excerpts from the example entries |
| Filling in a brief | The kind's questions, a working title and notes, and the titles and summaries of the section's newest live entries, to choose which to model it on |
| Other layouts of a first draft | The draft, the section's blocks and the extras |
| Links to your other pages | The draft, and the titles, addresses, types and summaries of the pages it could link to (only live pages with an address) |
| Comments on the page | The comments, the blocks they're on, the draft and the voice guide |
| **Write it for me**, **Write around it** | The gap's sentence or field, and words from the page |
| Suggest edits | The page's text as your form has it, its title, kind and dates, the voice guide, and the titles and summaries of related pages; then each suggestion, to be checked again |
| Writing the voice guide | Text from the newest published entries in the chosen sections |
| Changing the voice guide | The guide and your request |
| Suggesting or learning kinds | Excerpts from the section's entries; for learning, the chosen examples |
| The content plan | Titles and excerpts from the chosen sections, the voice guide, the kinds and the plan |
| The image style guide | Small copies of up to ten images per section |
| Finding photos | Words from the block and page, small copies of the images in the same place on other entries, and thumbnails of the photos found |
| Making an image | A description, the image style guide, words from the block and page, up to three images from the same place on other entries, and any image of your own you add (to the image provider) |

**To the photo libraries:** search words go to Openverse, and to Unsplash, Pexels or Pixabay when their keys are set, and to a paid library when it's switched on. Choosing a photo downloads it and, for Unsplash, tells Unsplash it was used. Only the search words are sent, never entry content.

**To other websites**, only if an admin turns on **Check links to other sites once a week**: a HEAD request (or a one-byte GET) to each address your pages link to, at most once a week, saying it's Ghostwriter's link check. Nothing from your site is sent. See [Links to other sites](suggest-edits.md#links-to-other-sites).

## What is not sent

- API keys, except each to its own service
- entries beyond the samples listed above
- user accounts, passwords or other personal data from Craft. A shared conversation's "Started by" names are shown in the control panel, not sent.

## Each provider's terms

How long a provider keeps what it's sent, and whether it may train on it, depends on its terms and your account. On paid API plans, Anthropic, OpenAI and Google don't train on API data by default. **Gemini's free tier is different**: Google may use what's sent to improve its products. For client sites, use a paid account. See [Google (Gemini, and images)](api-keys.md#google-gemini-and-images).

**With OpenRouter**, requests, including images, pass through OpenRouter on their way to the model's company: the instructions, the prompt and any images (photo ranking, alt text, references for image making). OpenRouter's own [privacy policy](https://openrouter.ai/privacy) and data settings apply, as well as those of the company whose model answers. A key from **Connect with OpenRouter** is kept in `ghostwriter_state`, encrypted with your site's security key.

## What is kept, and where

Everything is kept in your database, in Ghostwriter's own tables (see [Where things are kept](configuration.md#where-things-are-kept)):

- **Conversations** in `ghostwriter_sessions`: the brief, every message with who sent it, the draft, and who started and last changed each piece. By default they're shared with everyone who has **Use Ghostwriter**; with `sharedConversations` off, each belongs to the person who started it. **Remove** on the Overview deletes a piece's conversation.
- **Photo requests** in `ghostwriter_state`, each belonging to the person who made it, and pictures made or uploaded but not yet used in `ghostwriter_files`. Both are cleared after a day.
- **The guides, kinds and plan** in `ghostwriter_documents`.
- **Suggest edits' reviews**, with each decision and who made it, in `ghostwriter_edit_reviews`, kept as the page's history until the entry is deleted for good.
- **Content to revisit and the index of your pages** (titles, addresses, summaries and paragraph fingerprints, never whole texts) in `ghostwriter_revisit`, `ghostwriter_revisit_links`, `ghostwriter_entry_index` and `ghostwriter_index_stems`.
- **Comments not yet applied** stay in your own browser until you apply them.

Logs hold the provider, model, tokens and time of each call, and any retries and failures, never the words sent or the keys (see [Logging](configuration.md#logging)). A model's reply is only logged if you turn on **Log replies that can't be read**, and then only a reply Ghostwriter couldn't read.

API keys are read from the environment when they're needed, and never shown. Your keys stay on your site. Ghostwriter sends them only to the provider you chose, never to us.

Keys set up in **Connections** are kept in the `ghostwriter_credentials` table, encrypted with your security key, never in project config, and never shown again (only their last four characters). See [Connections](connections.md).
