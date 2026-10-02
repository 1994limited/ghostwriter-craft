# Configuration

## Settings

**Settings → Plugins → Ghostwriter** (admins only):

| Setting | What it does |
| --- | --- |
| **Write for these sections** | Sections that get **Write with Ghostwriter**. None ticked means all. |
| **Learn the voice from these sections** | Sections read for the voice guide. None ticked means all. |
| **Show Get started** | The setup steps on the dashboard and in the menu. |
| **Suggest kinds of content automatically** | Look at each section for kinds without being asked. |
| **Provider** | Anthropic, OpenAI or Gemini, for writing. |
| **Model** | Leave blank for the provider's default, shown as the placeholder. A model name that doesn't look like the chosen provider's (a `gpt-…` model with Claude, say) gets a warning; it is still saved. |
| **Image provider** | OpenAI or Gemini, for making images. Blank uses whichever has a key. |
| **Image model** | Leave blank for the provider's default. |
| **Mark images still to choose** | Striped placeholders in empty image fields on new entries. |
| **Search Openverse** | Free public-domain and CC0 photos, with no key. |

The settings page also shows which API keys are set, never the keys themselves.

## config/ghostwriter.php

To set any of these in code, or differently per environment, copy `vendor/1994/ghostwriter-craft/src/config.php` to `config/ghostwriter.php`. Values there win over the settings page. A setting set there is shown locked on the settings page, with a note saying where it is set.

```php
<?php

return [
    '*' => [
        'provider' => 'anthropic',
        'sections' => ['news', 'guides'],
    ],
    'dev' => [
        'model' => 'claude-haiku-4-5',
    ],
];
```

| Key | Default | |
| --- | --- | --- |
| `provider` | `anthropic` | `anthropic`, `openai` or `gemini` |
| `model` | provider's default | `claude-opus-5-5`, `gpt-6.1-sol` or `gemini-3.8-flash` |
| `timeout` | `300` | Seconds to wait for one response (30–1800) |
| `sections` | `[]` (all) | Section handles to write for |
| `voiceSections` | `[]` (all) | Section handles read for the voice guide |
| `imageProvider` | `null` | `openai` or `gemini`; `null` uses whichever has a key |
| `imageModel` | provider's default | `gpt-image-2.5-sunburst` or `gemini-3.1-flash-image` |
| `openverse` | `true` | Search Openverse |
| `placeholderImages` | `true` | Striped placeholders in empty image fields |
| `suggestKindsAutomatically` | `true` | Look for kinds without being asked |
| `voiceMaxEntries` | `24` | Entries read for the voice guide |
| `voiceMaxCharsPerEntry` | `6000` | Characters read from each |
| `voiceMaxChars` | `90000` | Characters read in all |
| `imageGuideSamples` | `10` | Images looked at per section for the image style guide |
| `planSuggestions` | `8` | Ideas asked for each time the plan looks for gaps |
| `guidesPath` | `@config/ghostwriter` | Where prompt overrides are read from, under `prompts/` |

API keys are never set here. See [API keys](api-keys.md).

## Where things are kept

Everything Ghostwriter writes is kept in the database, in its own tables, so it works the same on every server, on read-only and load-balanced hosts, and survives deploys:

| What | Table |
| --- | --- |
| Voice guide, image style guide, kinds of content, content plan | `ghostwriter_documents` |
| Conversations and drafts | `ghostwriter_sessions` |
| Working state: suggestions, jobs in hand, image searches | `ghostwriter_state` |
| Images made or uploaded, waiting to be used (cleared after a day) | `ghostwriter_files` |

The only files Ghostwriter reads from your project are [prompt overrides](#overriding-prompts), in `config/ghostwriter/prompts/`. They're code, so commit them.

Guides, kinds and the plan are content, like your entries: they live in each environment's database. To copy them between environments, copy the database tables, as you would for entries.

### Updating from an early build

Early builds kept guides, kinds and the plan as files in `config/ghostwriter/`, and conversations and working state in `storage/ghostwriter/`. Running `php craft migrate/all` (or `php craft up`) after updating imports them into the database. Nothing already in the database is overwritten. The old files are left where they are: check the import, then delete them, keeping `config/ghostwriter/prompts/` if you have one.

## Overriding prompts

Every prompt Ghostwriter uses is a markdown file in `vendor/1994/ghostwriter-core/resources/prompts/`. They come with ghostwriter-core, the package Ghostwriter shares with its Statamic and Filament versions:

| Prompt | Used for |
| --- | --- |
| `writer.md` | Writing and revising drafts |
| `brief-writer.md` | Filling in a brief from a title and notes |
| `voice-analyst.md`, `voice-editor.md` | Writing and changing the voice guide |
| `type-analyst.md` | Learning a kind of content |
| `kind-finder.md` | Suggesting kinds of content |
| `imagery-analyst.md` | Writing the image style guide |
| `planner.md` | Suggesting content plan ideas |
| `photo-researcher.md`, `photo-picker.md` | Choosing photo searches, and picking the best results |
| `image.md` | Making an image |

To change one for your project, copy it from `vendor/1994/ghostwriter-core/resources/prompts/` to `config/ghostwriter/prompts/` with the same name and edit the copy. Ghostwriter uses your copy from then on. Keep any `{{ placeholders }}` that are in the original.

The originals also have `[[...]]` placeholders, such as `[[items]]` and `[[place]]`. Ghostwriter fills these in with Craft's words (entries, website), in your copy as well, so you can leave them or write the words out yourself.

## Permissions

One permission, **Use Ghostwriter**. Writing into an entry also needs Craft's own permission to save entries in that section. See [Installation](installation.md#permissions).
