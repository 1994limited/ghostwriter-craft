# Privacy and data

## What is sent, and where

Ghostwriter only sends anything when someone in the control panel asks it to. It sends to the provider you chose, on your own account.

| When | Sent to | What |
| --- | --- | --- |
| Writing or editing | Your writing provider | The voice guide, the brief, the conversation, the current draft, the field layout, and excerpts from the example entries |
| Writing the voice guide | Your writing provider | Text from the newest published entries in the chosen sections |
| Learning or suggesting kinds | Your writing provider | Excerpts from the section's entries |
| Writing the image style guide | Your writing provider | Small copies of images from the section's entries |
| Content plan | Your writing provider | Titles and excerpts from the chosen sections, and the plan |
| Finding a photo | Your writing provider; the photo libraries | To the provider: words from the block and page, small copies of the images in the same place on other entries, and thumbnails of the search results. To the libraries: the search words only |
| Making an image | Your image provider | A description, the image style guide, words from the block and page, and up to three images from the same place on other entries |

## What is not sent

- API keys, except to the service each belongs to.
- User accounts, passwords or personal data from Craft.
- Anything, until someone asks.

## Each provider's terms

Each provider's own terms decide how they handle what you send. In particular, on **Gemini's free tier**, Google may use what you send to improve its products; the paid tier doesn't. For client sites, use a paid account. See [API keys](api-keys.md#google-gemini).

## What is kept, and where

- Conversations and drafts are JSON files in `storage/ghostwriter/sessions/`. Remove a piece from the dashboard to delete its conversation.
- Images made but not yet used are kept in `storage/ghostwriter/images/` for a day.
- Guides, kinds and the plan are files in `config/ghostwriter/`.
