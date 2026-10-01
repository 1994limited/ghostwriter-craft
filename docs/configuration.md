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
| **Model** | Leave blank for the provider's default. |
| **Image provider** | OpenAI or Gemini, for making images. Blank uses whichever has a key. |
| **Image model** | Leave blank for the provider's default. |
| **Mark images still to choose** | Striped placeholders in empty image fields on new entries. |
| **Search Openverse** | Free public-domain and CC0 photos, with no key. |

The settings page also shows which API keys are set, never the keys themselves.

## config/ghostwriter.php

To set any of these in code, or differently per environment, copy `vendor/1994/ghostwriter-craft/src/config.php` to `config/ghostwriter.php`. Values there override the settings page, which then shows a note beside the setting.

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
| `model` | provider's default | `claude-opus-5-5`, `gpt-5` or `gemini-2.5-pro` |
| `timeout` | `300` | Seconds to wait for one response (30–1800) |
| `sections` | `[]` (all) | Section handles to write for |
| `voiceSections` | `[]` (all) | Section handles read for the voice guide |
| `imageProvider` | `null` | `openai` or `gemini`; `null` uses whichever has a key |
| `imageModel` | provider's default | `gpt-image-1` or `gemini-2.5-flash-image` |
| `openverse` | `true` | Search Openverse |
| `placeholderImages` | `true` | Striped placeholders in empty image fields |
| `suggestKindsAutomatically` | `true` | Look for kinds without being asked |
| `voiceMaxEntries` | `24` | Entries read for the voice guide |
| `voiceMaxCharsPerEntry` | `6000` | Characters read from each |
| `voiceMaxChars` | `90000` | Characters read in all |
| `imageGuideSamples` | `10` | Images looked at per section for the image style guide |
| `planSuggestions` | `8` | Ideas asked for each time the plan looks for gaps |
| `guidesPath` | `@config/ghostwriter` | Guides, kinds, plan and prompt overrides |
| `storagePath` | `@storage/ghostwriter` | Working state |

API keys are never set here. See [API keys](api-keys.md).

## Where things are kept

| What | Where | Commit it? |
| --- | --- | --- |
| Voice guide | `config/ghostwriter/voice.md` | Yes |
| Image style guide | `config/ghostwriter/imagery.md` | Yes |
| Kinds of content | `config/ghostwriter/types/*.yaml` | Yes |
| Content plan | `config/ghostwriter/ideas.yaml` | Yes |
| Prompt overrides | `config/ghostwriter/prompts/*.md` | Yes |
| Conversations, drafts, job status, images waiting to be used | `storage/ghostwriter/` | No |

Ghostwriter adds no database tables. The guides, kinds and plan are project files, so they move between environments with your code. If editors change them on production, pull the changes back into your repository.

## Overriding prompts

Every prompt Ghostwriter uses is a markdown file in `vendor/1994/ghostwriter-craft/src/prompts/`:

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

To change one for your project, copy it to `config/ghostwriter/prompts/` with the same name and edit the copy. Ghostwriter uses your copy from then on. Keep any `{{ placeholders }}` that are in the original.

## Permissions

One permission, **Use Ghostwriter**. Writing into an entry also needs Craft's own permission to save entries in that section. See [Installation](installation.md#permissions).
