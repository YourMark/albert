---
name: block-editor
description: How to read and write WordPress block-editor content with Albert's structured block format. Read this before creating or editing posts and pages.
---

# Working with the WordPress block editor through Albert

Albert lets you edit WordPress posts and pages as **structured blocks** instead of
raw HTML. WordPress content is made of blocks (paragraphs, headings, images, columns,
and so on). When you send Albert a list of block specs, Albert serializes them into
correct block-editor markup for you — you never hand-write block comments or inner HTML.

Always prefer the structured `blocks` field over a raw `content` string. The exception
is the classic editor (see "Classic-editor sites" at the end).

## Know which blocks you may use

Before composing blocks, call `albert/list-block-types` to see the block types you
may use on this site — the list reflects your permissions, so it only contains blocks
you are allowed to save. Use `albert/get-block-type` to inspect a single block's
attributes. Composing a block that is not in your allowed set returns a
`block_validation_failed` error naming it, and nothing is saved.

## Block spec shape

Every block is an object with three keys:

```json
{
  "name": "core/paragraph",
  "attributes": { },
  "innerBlocks": [ ]
}
```

- `name` — the registered block type, e.g. `core/paragraph`. Always namespaced.
- `attributes` — the block's settings (heading level, image URL, etc.). Optional.
- `innerBlocks` — child blocks, for container blocks like columns or lists. Optional.

Use only block names that `albert/list-block-types` reports as available to you — that
is how you know what is registered and allowed on this site. Do not invent names, and do
not use a block missing from that list: either case comes back as a
`block_validation_failed` error naming the block, so you can fix it and retry. If you need
arbitrary HTML that has no matching block, use `core/html` and put the markup in its
`html` attribute.

Text attributes (`content`, a button's `text`, a quote's `citation`) accept inline HTML —
`<strong>`, `<em>`, `<a href="…">`, `<code>` — which Albert sanitizes. Use them for inline
emphasis and for links inside text.

## Examples

Paragraph:

```json
{ "name": "core/paragraph", "attributes": { "content": "Hello world." } }
```

Heading with a level:

```json
{ "name": "core/heading", "attributes": { "level": 2, "content": "Section title" } }
```

Image (a media library reference — include the attachment `id`, its `url`, and `alt`):

```json
{ "name": "core/image", "attributes": { "id": 123, "url": "https://your-site.com/wp-content/uploads/2026/06/cat.jpg", "alt": "A grey cat" } }
```

Button (buttons live inside a `core/buttons` container):

```json
{
  "name": "core/buttons",
  "innerBlocks": [
    { "name": "core/button", "attributes": { "text": "Read more", "url": "https://example.com" } }
  ]
}
```

List (a `core/list` wraps `core/list-item` children):

```json
{
  "name": "core/list",
  "innerBlocks": [
    { "name": "core/list-item", "attributes": { "content": "First item" } },
    { "name": "core/list-item", "attributes": { "content": "Second item" } }
  ]
}
```

For a numbered list, add `"ordered": true` to the `core/list` attributes (it renders as `<ol>`).

Nested layout — two columns, each holding a paragraph:

```json
{
  "name": "core/columns",
  "innerBlocks": [
    {
      "name": "core/column",
      "innerBlocks": [
        { "name": "core/paragraph", "attributes": { "content": "Left side." } }
      ]
    },
    {
      "name": "core/column",
      "innerBlocks": [
        { "name": "core/paragraph", "attributes": { "content": "Right side." } }
      ]
    }
  ]
}
```

## Images

`core/image` is a reference to the **media library**, not an arbitrary URL. An image
block needs both the attachment `id` and its `url` (plus `alt`):

- To use or change an image, find it with `albert/find-media` (search by title,
  filename, caption, or description) and use the `id` and `url` it returns.
- **When updating a post, never change the `id` of an existing image.** Read the current
  blocks first and keep each image block's `id` and `url` exactly as they are, unless the
  user explicitly asks for a different image.
- A bare external `url` with no `id` works but is discouraged — it is not linked to the
  media library.

## Links and buttons

Links use a plain **URL** — there is no id-based link reference (unlike images). This applies
to `core/button` (its `url` attribute) and to `<a href>` links inside text attributes.

- For an **internal** page or post, use its real **permalink** as the URL. Look it up with
  `albert/find-posts` / `albert/view-post` (or the `*-page` abilities) — they return a
  `permalink`; use that. Never guess a slug or put a post id in a link.
- For an **external** link, use the full absolute URL.

## Workflow

1. **Read** the existing content first with `albert/view-post` (or `albert/find-posts`
   to locate it). Pass `"format": ["blocks"]` so you get the structured tree back.
2. **Check** which blocks you may use with `albert/list-block-types` before composing
   anything new (use `albert/get-block-type` for a block's attributes).
3. **Edit** the structured blocks: change attributes, add or remove blocks, reorder them.
4. **Write** with `albert/create-post` (new) or `albert/update-post` (existing), sending
   your block specs in the `blocks` field.

For pages, use the equivalent `albert/*-page` abilities.

## Rules

- Prefer `blocks` over a raw `content` string.
- Use only block names from `albert/list-block-types`; a block you are not allowed to use
  returns a `block_validation_failed` error. Use `core/html` for raw HTML; never invent names.
- Do not hand-write block comments (`<!-- wp:... -->`) or inner HTML. Send attributes
  and `innerBlocks` and let Albert serialize the markup.
- Put child blocks in `innerBlocks`, not in attributes.

## Self-correction

A write may report problems:

- **`block_issues`** — a list of non-fatal warnings (the content still saved). Read each
  message, decide whether it matters, fix the offending block specs, and write again if needed.
- **`block_validation_failed`** — a fatal error; nothing was saved. Read the messages, correct
  the block specs they point to (usually an unknown block name or a malformed attribute), and retry.

Do not ignore these. Read the message text and fix the specific block it names.

## Reading formats

`format` is an **array** of format names, so you can request several at once — for
example `"format": ["blocks", "plaintext"]`. A scalar string is ignored and the
default format is returned instead, so always send an array, e.g. `"format": ["blocks"]`
or `"format": ["markdown"]`.

Choose the format(s) that fit the task:

- `["blocks"]` — the structured tree. Use this when you intend to edit and write back.
- `["content"]` — the raw stored block markup (block comments included). Use only when you
  need the exact stored string.
- `["plaintext"]` — text with all markup stripped. Use for summarizing or analyzing wording.
- `["html"]` — rendered front-end HTML. Use to see what a visitor sees.
- `["markdown"]` — a Markdown rendering. Use for a compact, readable overview.

## Classic-editor sites

If the site or post uses the **classic editor** (not the block editor), block specs do
not apply — send plain HTML in the `content` field instead of `blocks`. (A machine-readable
signal for the active editor arrives in a later release; for now, treat the classic editor
as the exception and default to `blocks` everywhere else.)
