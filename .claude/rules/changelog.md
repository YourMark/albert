# Changelog & readme conventions

How the `readme.txt` and `README.md` changelogs must be written. This is a hard
rule, not a suggestion. A changelog is a product surface: WordPress.org renders
the `readme.txt` changelog on the plugin's **Changelog** tab, and it is the first
thing a cautious site owner reads before updating.

Grounded in two standards:

- **Keep a Changelog 1.1.0** — https://keepachangelog.com/en/1.1.0/
- **WordPress plugin readme.txt spec** — https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/

## Principles (from Keep a Changelog)

1. Changelogs are **for humans**, not machines. Write for a site owner, not a committer.
2. **Every released version gets an entry.** Newest first.
3. **Group changes by type.** Never mix types in one bullet list.
4. **Never paste commit logs.** Merge commits and terse subjects are noise.
5. Every bullet must be **true and verifiable against the actual diff.** See
   `../../memory` note on verifying claims — do not describe intent, describe what shipped.
6. Follow Semantic Versioning for the version numbers themselves.

## Audience and language

Write for the person who reads the surface, not for yourself. Getting the audience
wrong is as bad as getting the category wrong.

- **The changelog, the Upgrade Notice, the WordPress.org listing, and any release
  announcement (tweets, blog posts, emails) are read by WordPress site owners —
  non-developers.** Write in plain, everyday English. Say what changed *for them*
  and why it matters, in words they already use.
- **No jargon in the user-facing sections** (`Features`, `Improvements`, `Fixes`,
  `Security`) or the Upgrade Notice. Do not name protocols, hooks, classes,
  endpoints, database columns, or internal mechanisms (e.g. OAuth, PKCE, DCR, REST
  namespace, `wp_abilities_api_init`, `redirect_uri`). If a technical concept has
  to be referenced, translate it to its *effect* — "secured without relying on a
  shared secret", not "enforced PKCE"; "the exact web address it returns you to",
  not "an exact `redirect_uri`".
- **Developer detail lives only in the `Developer` section** (and in `README.md`'s
  Developer notes, `DEVELOPER_GUIDE.md`, and code comments — those genuinely have a
  technical audience). That is the one place hook names, class names, and protocol
  terms belong.
- **Lead with the benefit or impact, then the detail.** "You can now edit one block
  at a time" before any mention of how.
- **Plain, not marketing.** Honest and specific beats breathless. Drop filler like
  "revolutionary", "seamless", "powerful". Every claim must still be true to the diff.
- **Match each surface to its audience:** site owners for `readme.txt`, WordPress.org,
  the Upgrade Notice, and social/marketing posts; developers for `README.md`
  Developer sections, `DEVELOPER_GUIDE.md`, code comments, and PR/commit bodies.
  When in doubt, read it back as if you were a non-technical site owner — if a word
  would make them stop and Google, rewrite it.

## The category vocabulary (STRICT)

Use **only** these section headings, spelled exactly like this, in **exactly this
order**. Omit any section that has no entries for a release. Never invent a new
heading (no "Housekeeping", "Under the hood", "New features", "Misc", "Other").

| Heading (exact) | Use for | Keep a Changelog equivalent |
|---|---|---|
| `**Features**` | New, user-facing capabilities. | Added |
| `**Improvements**` | Enhancements to things that already exist: performance, UX, accessibility, safer defaults, clearer copy, reliability. | Changed |
| `**Fixes**` | Bug fixes — something was broken and now works. | Fixed |
| `**Security**` | Vulnerability fixes and security hardening. | Security |
| `**Developer**` | Hooks/filters/APIs, deprecations, removals, build/tooling/CI, internal refactors. The stuff a site owner can ignore. | Deprecated + Removed + internal |
| `**Credits**` | Acknowledge people who reported or contributed (reporters of security/bugs especially). Always last. | — |

Order rationale: most relevant to a site owner first (new things), down to the
plumbing they can ignore last. Security prominence for a security-led release comes
from the one-line summary (below), not from reordering — the order stays fixed so
the changelog is predictable.

Mapping rules:
- **Accessibility** changes go under `**Improvements**` (or `**Fixes**` if they fix a real a11y bug). No separate Accessibility heading.
- **Deprecations and removals of public hooks/APIs** go under `**Developer**`, each bullet prefixed `Deprecated:` / `Removed:` so extenders can scan for them.
- **New hooks/filters** go under `**Developer**`, even when a `**Features**` bullet already mentions the feature they power.
- Don't list roadmap teasers or internal groundwork that a user/extender can't yet use. If a column/hook shipped and is usable, it can go under `**Developer**`; if it's just "prep for later", leave it out.

## Per-version format

```
= X.Y.Z =
One short summary sentence — the headline for this release (optional but preferred).

**Features**
* One change per bullet. Lead with the benefit, then the detail.

**Improvements**
* ...

**Fixes**
* ...
```

- The `= X.Y.Z =` header syntax is required by WordPress (`readme.txt`). In `README.md` use `### X.Y.Z`.
- Bullets: `*` in `readme.txt`, `-` in `README.md`. One discrete change each.
- Bold (`**...**`), inline code (`` `...` ``), and links are allowed in the changelog body. Keep formatting light.
- Write in plain, specific, present-tense language a non-developer understands. Prefer "you"/"your site". Avoid marketing fluff and avoid developer jargon in the first three sections.

## WordPress readme.txt hard constraints

- **Changelog: newest version first**, under `== Changelog ==`.
- **Upgrade Notice** (`== Upgrade Notice ==`): **plain text, no markup, ≤ 300 characters** per version. It is a short "why update" nudge, not a second changelog. Not every version needs one — add it when there's a real reason to prioritise updating (security, data, a fix for a widespread issue).
- **Short description ≤ 150 characters.**
- **Keep `readme.txt` under ~10,000 characters.** Over that, WordPress.org parsing gets flaky. When the changelog pushes the file past ~10k, **move older releases into a `changelog.txt`** in the plugin root, keeping the most recent 2–4 releases in `readme.txt`. Note: WordPress.org only renders `readme.txt` — `changelog.txt` is a repo/SVN archive, so splitting hides old entries from the plugin page (an accepted trade-off). `changelog.txt` is plain text (no markdown parsing).
- The release workflow (`.github/workflows/release.yml`) extracts the released version's section from `readme.txt` for the GitHub release notes — so the version being released must always have its section in `readme.txt`, formatted as above.

## Keep the two changelogs in sync

`readme.txt` (WordPress.org) and `README.md` (GitHub) must describe the same
releases with the same categories and the same facts. Update both in the same change.
