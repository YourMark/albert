# Albert admin design system

One visual language for every Albert screen, in the free plugin and in every
add-on. This document is the reference: if you are building a new Albert screen,
everything you need is named here.

**Source of truth is the code, not this file.** `assets/css/albert-tokens.css`
holds the values; `assets/css/albert-primitives.css` holds the components. Every
number below was measured against the shipped stylesheet, not transcribed from a
design file.

## How to use it

Declare the primitives handle as a dependency. It pulls the tokens in ahead of
itself, so naming one handle is enough:

```php
wp_enqueue_style( 'my-screen', $url, [ 'albert-primitives' ], $version );
```

In the free plugin, use the constants: `Albert\Admin\Assets::PRIMITIVES_HANDLE`
and `Albert\Admin\Assets::TOKENS_HANDLE`. In an add-on, use the literal strings
`'albert-primitives'` / `'albert-tokens'`, so an older Albert release without
those classes cannot fatal your plugin.

Any page registered under the Albert menu also gets the primitives enqueued
automatically, because the page navigation renders on every Albert screen and
has to be styled wherever it appears.

### Two rules

1. **Consume tokens; declare no raw colour.** If a value you need is missing, add
   it to the token sheet rather than inlining it. A hex in a component stylesheet
   is a value that cannot follow the admin colour scheme and cannot follow dark
   mode.
2. **Every accessibility claim carries its measurement.** "AA compliant" is not a
   claim. "`text-faint` on `surface` = 4.92:1, SC 1.4.3 requires 4.5:1" is. The
   tables below are generated from the stylesheet so they cannot drift from it.

## Buttons: use WordPress's

There is deliberately no Albert button. WordPress ships `.button`,
`.button-primary` and `.button-secondary`; they already follow the admin colour
scheme, they are maintained by core, and they make Albert look like the rest of
wp-admin. Use them.

## Colour

The palette is WordPress's own wherever WordPress has one — 17 of 21 values are
literal core colours. Four deviate, each for a stated reason:

| Token | Value | Why not core's |
|---|---|---|
| `success` | `#0a7a3b` | Core's greens are for dots and icons, not text. `#00a32a` measures 3.35:1 on white and fails SC 1.4.3; our pills put text in this colour. |
| `warning` | `#9a5b00` | Same reason, worse: core's `#dba617` is 2.22:1 and `#bd8600` is 3.19:1. |
| `surface-sunken` | `#fbfbfc` | A near-white a shade lighter than core's `#f6f7f7`, so a sunken area reads as recessed next to `surface` rather than as a separate card. |
| `text-faint` | `#6c7177` | A third grey tier below `text-muted`, for micro labels and ids. |

Everything is `oklch()` in both modes, so a lightness change is only a lightness
change and hues stay put. Values converted from the source design's hex
round-trip to that hex exactly.

### The accent comes from the site

`--albert-color-accent` is `var(--wp-admin-theme-color, …)` and is declared on
`body`, **not** on `:root`. This matters and is easy to get wrong: WordPress sets
the scheme colour on the body class (`body.admin-color-modern { … }`) while
`:root` carries only a base default. Resolving the `var()` at `:root` captures
the default instead of the scheme the user chose, and because custom properties
inherit, that wrong value then reaches every element. Everything derived from the
accent — the tints and the focus ring — is declared alongside it for the same
reason.

All nine admin colour schemes WordPress ships clear 4.5:1 against
`accent-contrast`; the tightest is "light" at 4.57:1. A custom scheme registered
by another plugin is not covered by that guarantee.

### Six corrections to the source design

Each failed a WCAG success criterion as specified, and was corrected by moving
OKLCh lightness only — hue and chroma stay as designed, and the value moves the
least distance that clears the threshold.

| Token | Was | Measured | Now | Criterion |
|---|---|---|---|---|
| `border-input` (light) | `#dcdcde` | 1.37:1 | `#949494` (core's own input border) | 1.4.11 |
| `border-input` (dark) | `oklch(0.37 …)` | 1.53:1 | `oklch(0.54 …)` | 1.4.11 |
| `switch-off` (light) | `#a7aaad` | 2.33:1 | `oklch(0.66 0.01 265)` | 1.4.11 |
| `switch-off` (dark) | `oklch(0.42 …)` | 1.89:1 | `oklch(0.54 0.01 265)` | 1.4.11 |
| `notice-accent` (light) | `#72aee6` | 2.36:1 | `oklch(0.66 0.08 245)` | 1.4.11 |
| `*-contrast` | did not exist | white on dark accent = 2.24:1 | new tokens, flip with the mode | 1.4.3 |

The `*-contrast` tokens are the label colour on any filled control. The source
design specified "accent surface, white text" but shipped no token for the label,
so in dark mode the accent inverted to a light blue and left white text on it.

Three dark values also fell outside the sRGB gamut (`accent`, `danger`, `info`).
Browsers clip them, so the rendered colour was not the specified one and any
ratio derived from the nominal value was fiction. Their chroma is trimmed until
each is genuinely in gamut.

### Tints

Tints are derived with `color-mix(in oklab, …)` off the semantic tokens rather
than declared as a second set of literals, so a tint cannot drift from the colour
it tints. Mixed in oklab because sRGB mixing darkens and desaturates through the
middle of the range.

Tints are backgrounds only. None is ever a text colour, so they carry no contrast
obligation of their own — the text that sits on them does.

## Measured contrast

Generated from `assets/css/albert-tokens.css`. Text pairs are held to SC 1.4.3
(4.5:1); control boundaries, switch tracks and meter fills to SC 1.4.11 (3:1).

#### Light

| Pair | Measured | Required | SC | Used for |
|---|---|---|---|---|
| `text` on `surface` | **16.67:1** | 4.5:1 | 1.4.3 | Body text on a card |
| `text` on `canvas` | **14.62:1** | 4.5:1 | 1.4.3 | Text on the page background |
| `text` on `surface-sunken` | **16.10:1** | 4.5:1 | 1.4.3 | Text on a sunken surface |
| `text-muted` on `surface` | **5.53:1** | 4.5:1 | 1.4.3 | Descriptions |
| `text-muted` on `canvas` | **4.85:1** | 4.5:1 | 1.4.3 | Descriptions on canvas |
| `text-faint` on `surface` | **4.93:1** | 4.5:1 | 1.4.3 | Micro labels, mono ids |
| `text-faint` on `surface-sunken` | **4.76:1** | 4.5:1 | 1.4.3 | Table head labels |
| `link` on `surface` | **5.16:1** | 4.5:1 | 1.4.3 | Links, ability names |
| `accent-contrast` on `accent` | **5.60:1** | 4.5:1 | 1.4.3 | Label on the primary button |
| `danger-contrast` on `danger` | **6.31:1** | 4.5:1 | 1.4.3 | Label on a destructive button |
| `success-contrast` on `success` | **5.45:1** | 4.5:1 | 1.4.3 | Label on a success fill |
| `success` on `success-surface` | **4.80:1** | 4.5:1 | 1.4.3 | Success pill |
| `warning` on `warning-surface` | **4.84:1** | 4.5:1 | 1.4.3 | Warning pill |
| `danger` on `danger-surface` | **5.51:1** | 4.5:1 | 1.4.3 | Danger pill |
| `info` on `info-surface` | **5.96:1** | 4.5:1 | 1.4.3 | Info pill |
| `neutral` on `neutral-surface` | **6.41:1** | 4.5:1 | 1.4.3 | Neutral pill |
| `accent` on `surface` | **5.60:1** | 3.0:1 | 1.4.11 | Focus ring, switch on, meter fill |
| `border-input` on `surface` | **3.03:1** | 3.0:1 | 1.4.11 | Input and control boundary |
| `switch-off` on `surface` | **3.11:1** | 3.0:1 | 1.4.11 | Switch track, off state |
| `notice-accent` on `surface` | **3.08:1** | 3.0:1 | 1.4.11 | Calm notice left border |
| `success-dot` on `surface` | **3.47:1** | 3.0:1 | 1.4.11 | Status dot |

#### Dark

| Pair | Measured | Required | SC | Used for |
|---|---|---|---|---|
| `text` on `surface` | **14.25:1** | 4.5:1 | 1.4.3 | Body text on a card |
| `text` on `canvas` | **16.12:1** | 4.5:1 | 1.4.3 | Text on the page background |
| `text` on `surface-sunken` | **15.04:1** | 4.5:1 | 1.4.3 | Text on a sunken surface |
| `text-muted` on `surface` | **8.56:1** | 4.5:1 | 1.4.3 | Descriptions |
| `text-muted` on `canvas` | **9.69:1** | 4.5:1 | 1.4.3 | Descriptions on canvas |
| `text-faint` on `surface` | **6.22:1** | 4.5:1 | 1.4.3 | Micro labels, mono ids |
| `text-faint` on `surface-sunken` | **6.56:1** | 4.5:1 | 1.4.3 | Table head labels |
| `link` on `surface` | **8.65:1** | 4.5:1 | 1.4.3 | Links, ability names |
| `accent-contrast` on `accent` | **8.08:1** | 4.5:1 | 1.4.3 | Label on the primary button |
| `danger-contrast` on `danger` | **7.95:1** | 4.5:1 | 1.4.3 | Label on a destructive button |
| `success-contrast` on `success` | **10.25:1** | 4.5:1 | 1.4.3 | Label on a success fill |
| `success` on `success-surface` | **7.03:1** | 4.5:1 | 1.4.3 | Success pill |
| `warning` on `warning-surface` | **7.68:1** | 4.5:1 | 1.4.3 | Warning pill |
| `danger` on `danger-surface` | **5.71:1** | 4.5:1 | 1.4.3 | Danger pill |
| `info` on `info-surface` | **8.61:1** | 4.5:1 | 1.4.3 | Info pill |
| `neutral` on `neutral-surface` | **8.06:1** | 4.5:1 | 1.4.3 | Neutral pill |
| `accent` on `surface` | **7.14:1** | 3.0:1 | 1.4.11 | Focus ring, switch on, meter fill |
| `border-input` on `surface` | **3.16:1** | 3.0:1 | 1.4.11 | Input and control boundary |
| `switch-off` on `surface` | **3.16:1** | 3.0:1 | 1.4.11 | Switch track, off state |
| `notice-accent` on `surface` | **6.04:1** | 3.0:1 | 1.4.11 | Calm notice left border |
| `success-dot` on `surface` | **9.06:1** | 3.0:1 | 1.4.11 | Status dot |

## Scales

**Spacing** `--albert-space-100` … `600` — 4, 8, 12, 16, 20, 24px.

**Radius** `--albert-radius-sm` 3px (small controls), `--albert-radius-md` 4px
(cards, inputs, code blocks), `--albert-radius-pill` 11px (badges, meters).
There is deliberately no 6px or 8px step — earlier drafts used softer radii and
they were dropped for core's 4px.

**Type** — the wp-admin system stack, never a webfont. Sizes are named by role,
not by size, so a screen asks for `--albert-font-size-card-title` rather than
guessing which of two 14px tokens was meant.

**Motion** `--albert-duration-fast` 150ms (hover, switch, meter fill),
`--albert-duration-panel` 220ms with `--albert-easing-panel` (fly-in). Every
consumer wraps motion in `@media (prefers-reduced-motion: no-preference)`.

**Focus** — one ring for everything interactive: `--albert-focus-width` 2px solid
`--albert-focus-color` at `--albert-focus-offset`. Switches, rows that open a
panel, and accordion summaries included; those are the ones most often missed.

## Primitives

| Primitive | Class root | Notes |
|---|---|---|
| Page shell | `.albert-page` | Header (title, description, optional actions) then body. Card screens cap at 1280px; add `--wide` for table screens. |
| Card | `.albert-card` | Optional `__header`; `__body--flush` when the body is a row list that manages its own dividers. |
| Toggle row | `.albert-toggle-row` | Label, description, optional mono `__peek`. Switched-off state uses `inert`, never `pointer-events: none`. |
| Switch | `.albert-switch` | Render as `<button role="switch" aria-checked>` with a name that says what it switches — "Enable Create post", not "Enable". |
| Badge | `.albert-badge` | One definition, replacing the two shapes shipped before. Tones: neutral, info, success, warning, danger, outline. |
| Inline hint | `.albert-hint` | Scoped to what it explains. Never a full-width banner — that is what WordPress notices are for. |
| Save state | `.albert-savestate` | Put it inside `aria-live="polite"`. Replaces a submit button on instant-save screens. |
| Table | `.albert-table` | Wrap wide tables in `.albert-table-scroll` so they scroll inside their container, never the page. |
| Meter | `.albert-meter` | Warns, never blocks. The written-out value is the accessible source of truth; the bar echoes it. |
| Payload preview | `.albert-preview` | Mono, `pre-wrap`, capped height. `__highlight` marks the region the site owner contributed. |
| Swatch | `.albert-swatch` | Give it a `title` with the value it shows. |
| Navigation | `.albert-nav` | Rendered for you on every Albert screen. Real links with `aria-current="page"` — never `role="tab"`. |

### Why the navigation is links, not tabs

Each entry is a separate admin page load. `role="tablist"` promises in-page
panels and arrow-key navigation; announcing tabs and then navigating away is
worse for a screen-reader user than plain links, which is what these are.

The strip renders on `in_admin_header`, which is what lets it run edge to edge
without negative margins fighting `.wrap`'s gutters. It reads the registered
submenu rather than a hardcoded list, so an add-on page appears automatically,
and it runs a capability check per entry so a page the user cannot reach never
shows.

## Dark mode

Declared, not triggered. WordPress has no dark admin scheme, so there is no
honest trigger to commit to yet. Every dark value lives in the token sheet and
nowhere else — component stylesheets must never carry a dark-specific rule. When
core ships a scheme, or Albert adds a setting, the only edit is the selector.

The trigger attribute must go on `<body>`, not `<html>`: the accent is declared
on `body`, so an `html`-level dark block would set the accent on a parent and
have it overwritten — dark would get every token except the one carrying the
brand.

## Adding a token

1. Add it to `albert-tokens.css`, in both the light block and the dark block.
2. If it can carry text or is a control boundary, measure it against every
   surface it will sit on and record the pair here.
3. Check it is inside the sRGB gamut. An `oklch()` value the browser clips is not
   the value you specified.
