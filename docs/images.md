# Images

On every image field in a section Ghostwriter writes for, a **Ghostwriter** button sits beside **Add an asset** and **Upload a file**. When the field is full, it sits just below the field instead.

It isn't shown on fields that only accept other kinds of file (for example PDFs), on fields with no upload location, or when no image tool is available (no photo library, no OpenAI or Gemini key, and no Imagick for logo cards).

Click it to choose an image for that one field.

## How it decides what fits

- **Words.** Ghostwriter reads the block the field is in first, then the rest of the page. A picture inside a "Charity partner" block is chosen for that partner, not for the page as a whole.
- **Style.** It looks at the images already in the **same place** on the section's other entries: the same field, in the same kind of block. If none use that exact block, it looks at the same field in a block of the same family, for example "Link Grid – Bottom Text" for "Link Grid – Center Text". The [image style guide](guides.md#the-image-style-guide) adds the words.
- **Shape.** Landscape, portrait or square, from those images.

## Find a photo

Searches free photo libraries: Openverse (no key needed) and Unsplash, Pexels and Pixabay when you've added their keys. See [API keys](api-keys.md#free-photo-libraries).

1. Type what the picture should show, or leave it empty and Ghostwriter chooses three searches from the block and page. Separate your own searches with semicolons.
2. **Search.** The model looks at the results beside the images already used there, and marks the best ones **Best match**. When there is nothing to compare with (no image there on other entries yet, or no writing model), nothing is marked, and a line says the results are in search order.
3. The best three show first; **View N more** shows the rest.
4. Click the photo, or **Use this**, on the one you want.

## Make one

Needs an OpenAI or Gemini key (see [API keys](api-keys.md)).

1. Optionally, say what it should show. Leave it blank and Ghostwriter decides from the block and page.
2. Optionally, add **an image of your own to put in it**, such as a product shot. It is used as it is, not redrawn.
3. **Make the picture.** It takes a minute or two, matching the style of the images already in that place.
4. **Use this**, or **Make another**.

Ghostwriter never draws a real company's logo from memory. For logos, use a logo card.

## Logo card

For partner, client or technology tiles: your logo, centred on a flat colour or a gradient, drawn in code so it comes out exactly as it went in. Needs the Imagick PHP extension.

1. Choose the **Logo**: a PNG, SVG or WebP with a transparent background. SVGs that refer to other files or contain scripts are refused.
2. **Colour**: leave blank to use the logo's own main colour, or give a hex value such as `#2B3A64`.
3. **Second colour, for a gradient** (optional).
4. **Make the logo white** is on by default, as on a coloured ground it usually should be.
5. **Make the card and use it.** The card is sized to match the images in that place.

## What happens when you choose one

- The image is saved as an asset in the **field's own upload location**, with a title.
- It goes straight into the field, as if you'd uploaded it. Save the entry as usual.
- If the field held a **striped placeholder**, the placeholder comes out. In a field that holds only one image, the new image replaces the old one.
- If the field is full, the image is saved to Assets and you're asked to make room.

### Credits

When the asset's volume has a plain text field whose handle includes `credit`, `attribution`, `copyright`, `caption` or `source`, Ghostwriter fills it with the photographer, library and licence, for example "Jane Doe on Unsplash, Unsplash licence". Add such a field to your volume's field layout to keep credits.

Openverse photos are public domain or CC0. Unsplash, Pexels and Pixabay photos are under their own free licences, which ask you to credit the photographer where you can.

## Placeholders

When a draft is put into a **new** entry, image fields it leaves empty get a striped "image to choose" placeholder, but only where the section's entries usually have an image there (or the field is required). Optional images, such as a background most entries leave empty, are left alone.

- The placeholder is one shared asset, `ghostwriter-image-placeholder.png`, in the field's volume.
- The notes above the form list every field that has one.
- Replace them with the image button before publishing.

Turn this off with **Mark images still to choose** in the settings.

## Requests are private

A search or a picture being made belongs to the person who started it. Made pictures waiting to be used are kept for a day, then cleared away.
