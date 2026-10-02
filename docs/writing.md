# Writing a new entry

## Starting

Open a section's **New entry** screen and click **Write with Ghostwriter** at the top. The button appears on new entries in the sections Ghostwriter writes for, for people with the **Use Ghostwriter** permission.

Or click **Write with Ghostwriter** beside **New entry** on the section's entry list: it starts a new entry with Ghostwriter open. It's there while a section Ghostwriter writes for is chosen.

You can also start from Ghostwriter's own screens: **Write** beside a section on the [dashboard](dashboard.md), **Write something** on the [widget](dashboard.md#the-dashboard-widget), or **Draft this** on an idea in the [content plan](content-plan.md). Each opens a new entry with Ghostwriter already open.

Ghostwriter opens in a large panel over the entry form. The form stays where it is underneath.

## What are you writing?

Choose what kind of entry this is:

- **A kind you taught it**, such as "Case study". It asks that kind's own questions, and models the entry on that kind's examples. See [Kinds of content](kinds.md).
- **Something like what is already here**: kinds Ghostwriter suggests from how the section's entries are built.
- **Something else**: a general brief for anything. Pick entries to **model it on**, or leave them unticked and describe the shape you want.

## The brief

Answer the questions. Short answers are fine; Ghostwriter asks for anything it still needs before it writes.

**Quick brief.** If you'd rather not fill in every question, give a working title and a few notes, then **Fill in the brief**. Ghostwriter fills in the questions from them. Check them over: anything in `[square brackets]` needs you.

Then **Start writing**.

> Ghostwriter uses only facts from the brief and the conversation. It doesn't invent figures, quotes or client names. Where it needs something it doesn't have, it asks, or leaves a `[note in square brackets]` for you.

## The conversation

If Ghostwriter needs more before it can write, it asks. Answer in the box at the bottom (short is fine; number the answers if it helps), or click **Just draft it with what you have** to have it write now and mark the gaps.

Once there is a draft, ask for changes in plain words:

- "Make the opening shorter."
- "Add a section on cost, after the process."
- "Less formal."

Press **⌘↵** (or **Ctrl+↵**) to send. A draft usually takes a minute or two. Ghostwriter's replies show lists, bold and links as they are meant to read.

If a turn fails (the provider was busy, say), the panel says **That didn’t work** with the reason, and **Try again** sends the same message again, so nothing has to be typed twice.

## The draft

The draft sits on the right, laid out the way the entry is built: its fields, and its page-builder blocks in order.

- **Blocks / Text** switches between the full layout and just the words. Ghostwriter remembers your choice.
- **Click any text to change it.** Headings, lines and rich text are edited where they are shown, and saved as you leave each piece. **Esc** puts a piece back as it was; **Enter** finishes a one-line piece, such as a heading.
- **Edit YAML** opens the whole draft as text, to add, move or remove blocks. Most people never need it.

The word count is shown at the top.

## Use this draft

**Use this draft** puts the draft into the entry, then reloads the form so you can see it.

- It goes into the entry's own Craft draft. **Nothing is published.** Check it over, then save the entry as you normally would, or discard it.
- Using it again replaces what is in the form.

Once the form has reloaded, one notification says the draft is in, and lists anything still for you to do. When there is something on the list, the notification stays until you close it. For example:

- **Choices it couldn't make**: entries to relate, categories, dates.
- **Links it couldn't settle**, which point to `https://example.com` for now. See [house style](fields.md#house-style).
- **Image fields marked with a striped placeholder**, where the section's entries usually have a picture. Replace them using the [image button](images.md).

## Carrying on later

The conversation is saved. Reopen the entry, or click the piece under **In progress** on the dashboard, and Ghostwriter picks up where you left off, including after a page reload.

**Start over** goes back to **What are you writing?** to begin a new piece on the same entry. The earlier conversation isn't deleted; it is offered under **Or carry on with**, and stays on the dashboard until you remove it.

A piece leaves **In progress** once its entry has been saved.

## Sharing conversations

Conversations are shared with everyone who has the **Use Ghostwriter** permission: from the dashboard, the widget, the content plan and the entry itself, anyone can open a piece and carry it on.

- Each message says who sent it: **You**, or the person's name.
- Pieces show who started them and who last changed them ("Started by Ann · last changed by you").
- Ghostwriter answers one request at a time. While it works on someone else's message, the panel says "Ann is waiting on Ghostwriter", and sending is switched off until the reply arrives.

To keep each conversation to the person who started it, turn off **Share conversations** in the settings, or set `'sharedConversations' => false` in [`config/ghostwriter.php`](configuration.md).
