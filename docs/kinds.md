# Kinds of content

Every section can be written for straight away, with a general brief. A **kind** is something you write often in a section, such as a case study, a press release or a studio page, with a brief of its own:

- **Questions** asked before anything is written, about the facts that can't be invented.
- **Guidance** for the writer: who the reader is, what each part does and in what order, how long it runs.
- **A checklist** of things that must be true of a finished draft.
- **Examples**: entries the kind is modelled on, for structure, length and how blocks are used.

## Suggested kinds

Ghostwriter looks at a section's entries and suggests the kinds of content in it. Each suggestion has a line on why it is worth teaching, and the titles of up to three entries it was seen in.

- It looks **by itself** only in [Get started](getting-started.md): when the **Teach it your kinds of content** step opens, at each section never looked at, or with ten or more entries published since the last look. Each look is one model call. Turn this off with **Suggest kinds of content automatically** in the settings.
- Anywhere else, nothing is looked at until you ask: opening the dashboard never calls the model. **Suggest kinds** in a section's **Kinds** menu on the dashboard looks at that section now. **Suggest kinds everywhere** does every section.

Suggestions appear under the section as **N suggested kinds to review**. Open it, then:

- **Learn this** writes the brief for that kind from the entries it was suggested from. It takes about a minute.
- **Not this** dismisses it. Dismissed kinds aren't suggested again.
- **Learn all N** learns every suggestion in the section, one after another. It asks first, since each takes about a minute.

## Teaching a kind yourself

**Teach a kind** (in a section's **Kinds** menu, or on **What are you writing?** in the writing panel) teaches one by hand:

1. **What is this kind of content called?** For example "Case study". Leave it blank and Ghostwriter names it.
2. **Model it on**: choose up to six entries that are good examples, or leave it empty to use the newest published entries.

Ghostwriter writes the questions, guidance and checklist from those entries.

## Editing a kind

Click a kind on the dashboard to open it. You can change:

- **Name** and **Description** (shown when choosing what to write).
- **The brief**: the questions, each with a hint, whether the answer is **One line** or a **Paragraph**, and whether it must be answered. Ask only for what can't be invented: what happened, who for, what resulted, what must be left out. A kind needs at least one question.
- **Guidance for the writer**, in markdown. The fields themselves are read from the entry type, so the guidance doesn't need to list them.
- **Check before handing over**: one statement per line.
- **Modelled on**: the example entries.

**Delete** removes the kind; entries already written with it are not affected.

## Where kinds are kept

Kinds are kept in the database and edited on their screen. Each is stored as YAML, like this:

```yaml
title: Studio page
description: A short landing page for one of the studios.
section: builder
entryType: page              # optional, for sections with several entry types
examples: [1504, 9330]       # optional: model the kind on these entries
questions:
  - handle: lead
    label: Who leads the studio?
    type: text               # text or textarea
    required: true
guidance: |
  Markdown: who the reader is, what each part does and in what order, how long it runs.
checklist:
  - Every fact comes from the brief.
```
