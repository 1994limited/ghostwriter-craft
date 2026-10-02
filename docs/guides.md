# The voice guide and image style guide

Both guides are plain markdown files in your project. Ghostwriter writes the first version from your site, and you correct it. Both are read every time Ghostwriter writes, or finds or makes an image.

## The voice guide

**Ghostwriter → Voice guide.** It describes how your site sounds, with real examples from your entries:

- who is talking, and to whom
- the attitude and the warmth
- how pieces are shaped: openings, headings, endings, length
- the words you use, and the ones you never do

### Writing it

Tick the sections to read, then **Write the voice guide** (the first time) or **Rescan and rewrite**. At least one section must be ticked. Ghostwriter reads the newest published entries in those sections, and writes the guide in a minute or so. Rescanning replaces the guide, including any edits you've made.

If writing the guide fails, the reason stays on the screen until the next try.

To control how much it reads, see `voiceSections` and the `voiceMax…` settings in [Configuration](configuration.md).

### Changing it

- **Edit** it directly: the editor has **Edit** and **Preview** tabs and a small toolbar for headings, lists, quotes and links. **Save** (or **⌘S**) when done.
- Or **Ask for a change** in plain words, for example "We never say solutions. Add that.", then **Update the guide**. Ghostwriter rewrites the guide with the change.

The guide is kept in the database. Each environment has its own; to share one, copy it between environments or the database table (see [Configuration](configuration.md#where-things-are-kept)).

## The image style guide

**Ghostwriter → Image style.** It describes what your pictures look like, section by section: photography or illustration, subjects, composition, light, colour, and what never appears.

### Writing it

Tick the sections to look at, then **Describe the images** (or **Look again and rewrite** once there is a guide). Ghostwriter looks at the images used by the newest published entries in each section, in every image field, including those inside page-builder blocks. It writes a `##` section for each. A section needs at least three images to describe.

The number of images looked at per section is the `imageGuideSamples` setting (10 by default).

### Changing it

Edit it in the same editor as the voice guide. Keep a `## Section name` heading for each section: that is how Ghostwriter finds the part that applies to an image.

It's kept in the database, like the voice guide.

### Where it is used

- Choosing what to **search for** when finding a photo.
- **Picking** the photos that best fit, from the search results.
- **Making** an image.

See [Images](images.md).
