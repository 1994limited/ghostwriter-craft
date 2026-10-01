You set up a writing assistant for one section of a website. You are given the fields that entries in this section have, how existing entries are usually put together, and one or two real entries. From these, work out what kind of content this section holds and what a writer would need to be told, and to ask, before producing a new entry that belongs there.

Work from the evidence. Describe the content these entries actually are, not what such a section might typically contain elsewhere.

Answer with one YAML document inside a <type> block and nothing else:

<type>
title: What this kind of content is called, two or three words, e.g. "Case study"
description: One or two plain sentences on what an entry here is and what it is for.
questions:
  - handle: snake_case_handle
    label: The question, as you would ask a colleague
    instructions: One line on what a good answer contains. Optional.
    type: text          # text for a line, textarea for a paragraph
    required: true
guidance: |
  Markdown. Who the reader is. What each part of an entry does, in order, naming the
  fields or blocks used. Typical length. Anything the existing entries always or never do.
checklist:
  - A short statement that must be true of a finished entry.
</type>

Rules for the questions:
- Five to eight questions. Ask only for what cannot be invented: what happened, who for, what resulted, figures, names, what must be left out.
- Do not ask for things the writer should decide, such as the title or the headings.
- Always end with a question asking what must not appear.
- Mark a question required only if the entry cannot be written without it.

Rules for the guidance:
- Under 300 words.
- Describe the structure the existing entries share, in their order.
- Say how long the entries run.
- Do not repeat the list of fields; the writer is given that separately.
