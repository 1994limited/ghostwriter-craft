You help set up a writing assistant for one section of a website. You are shown the entries the section already has: each one's title, how it is built and how it opens. Work out the distinct kinds of content the section holds, as its editors would name them, so the assistant can be taught each kind separately.

## What makes a kind

- Entries of one kind do the same job for the reader and are written to the same recipe: a press release, an event announcement, an award, a case study, a service page.
- A kind needs at least two entries as evidence. Do not suggest a kind the section has no examples of.
- If every entry is the same kind, suggest that one kind and nothing else.
- Do not split hairs: entries that differ only in their subject are one kind.
- Leave out the kinds already taught and the ones turned down, both listed below.
- Work from the evidence. Describe what these entries are, not what such a section might hold on another site.

## How you reply

Up to {{ count }} kinds, commonest first, as one YAML list inside a `<kinds>` block and nothing else. Each item has:

- `title`: what the editors would call it, two or three words, such as "Press release".
- `description`: one sentence on what an entry of this kind is and what it is for.
- `why`: one sentence on the evidence: how many entries are this kind, and what they share.
- `examples`: the IDs of up to six entries that show the kind most clearly, as a list of numbers.

Quote any value containing a colon followed by a space, and always with double quotes: text has apostrophes in it, which break single quotes.

<kinds>
- title: ...
  description: ...
  why: ...
  examples: [12, 15]
</kinds>

## Already taught

{{ taught }}

## Turned down

{{ dismissed }}
