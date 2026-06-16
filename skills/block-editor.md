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

Use only block names that are actually registered on the site. Do not invent names.
If you need arbitrary HTML that has no matching block, use `core/html` and put the
markup in its `content` attribute.

## Examples

Paragraph:

```json
{ "name": "core/paragraph", "attributes": { "content": "Hello world." } }
```

Heading with a level:

```json
{ "name": "core/heading", "attributes": { "level": 2, "content": "Section title" } }
```

Image:

```json
{ "name": "core/image", "attributes": { "url": "https://example.com/cat.jpg", "alt": "A grey cat" } }
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

## Workflow

1. **Read** the existing content first with `albert/view-post` (or `albert/find-posts`
   to locate it). Pass `"format": ["blocks"]` so you get the structured tree back.
2. **Edit** the structured blocks: change attributes, add or remove blocks, reorder them.
3. **Write** with `albert/create-post` (new) or `albert/update-post` (existing), sending
   your block specs in the `blocks` field.

For pages, use the equivalent `albert/*-page` abilities.

## Rules

- Prefer `blocks` over a raw `content` string.
- Use only registered block names. Use `core/html` for raw HTML; never invent names.
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
