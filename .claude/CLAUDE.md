# Albert

WordPress plugin that exposes WordPress functionality to AI assistants via MCP (Model Context Protocol).

**Stack:** PHP 8.1+ | WordPress 6.9+ | OAuth 2.0 (league/oauth2-server) | PSR-4 autoloading

## Rules

- [Code Style](rules/code-style.md) - PHP brace rules, naming conventions, DocBlocks, JS/CSS
- [Testing](rules/testing.md) - Unit vs integration tests, TDD guidance
- [Development Methodology](rules/development-methodology.md) - DDD bounded contexts, ubiquitous language, workflow
- [Patterns](rules/patterns.md) - Albert-specific class patterns, bounded contexts, testing stubs
- [Changelog](rules/changelog.md) - Strict changelog categories, per-version format, readme.txt/Upgrade Notice constraints

## Commands

```bash
composer install          # Install dependencies
composer phpcs            # Check coding standards (WordPress CS)
composer phpcbf           # Auto-fix coding standards
composer phpstan          # Static analysis (level 7)
composer test             # Run unit tests
composer test:integration # Run integration tests (requires WP test suite)
```

## Directory Structure

```
src/
  Abstracts/       # BaseAbility (all abilities extend this)
  Abilities/       # Ability implementations (WordPress/, WooCommerce/)
  Admin/           # Admin pages (abilities toggles, settings, connections)
                   #   Assets  — registers the shared token + primitive stylesheets
                   #   Menu    — submenu ordering constants + the page navigation
  Contracts/       # Interfaces (Ability, Hookable)
  Core/            # Plugin bootstrap, AbilitiesManager, AbilitiesRegistry
                   #   InvocationRelay — WP 7.1 wp_ability_invoked -> albert/abilities/invoked
  Support/         # WpCompat — WordPress version-capability detection (7.1 feature probes)
  MCP/             # MCP protocol server
  OAuth/           # Full OAuth 2.0 server (entities, repos, endpoints)
  Utilities/       # Standalone helpers (BlockConverter)
tests/
  Unit/            # PHPUnit tests (no WordPress dependency)
  Integration/     # WP_UnitTestCase tests
assets/            # CSS and JS for admin UI
```

## Ecosystem

Free is the **core**. All add-ons depend on it. The core never depends on add-ons.

```
Addons → Core    (allowed)
Core   → Addons  (NEVER)
Addon  → Addon   (NEVER — use Core hooks as mediator)
```

### Known add-ons

| Plugin | Folder |
|---|---|
| Albert Premium Service | `albert-premium-service` |
| Albert WooCommerce | `albert-woocommerce` |

### Legacy ability ID note

Free WooCommerce read-only abilities predate the naming convention and use
`albert/woo-find-products` style IDs. All new abilities use `{namespace}/{resource}/{action}`.
Never rename the legacy IDs — they are part of the public API.

## Critical Warnings

- **NEVER use alternative PHP syntax** (`: endif`, `: endforeach`). ALWAYS use `{ }` braces.
- **NEVER use jQuery.** Vanilla ES6+ only.
- **NEVER commit without explicit request.** Run `composer phpcs` and `composer phpstan` first.
- **NEVER bump version without approval.**
- **Version bumps only happen in release branches** — never on `development`, feature branches, or `main`.
- **PR titles are plain, human-readable sentences** — NEVER use conventional-commit prefixes like `chore(deps):`, `feat(logging):`, `fix:`. Those belong in commit messages, not PR titles. Example: "Upgrade MCP adapter to 0.5.0", not "chore(deps): upgrade...".
- The root `CLAUDE.md` is the canonical project reference (checked into git). This file supplements it.

## WooCommerce mcp-adapter Timing Bug

`Plugin::init()` skips `McpAdapter::instance()` when `is_admin()` to avoid a timing conflict where WooCommerce's REST preloading triggers `wp_get_ability()` for tools that aren't registered yet. See root `CLAUDE.md` for full details.

## Extensibility Hooks

All hooks follow `albert/{location}/{hook_name}` convention:

| Hook | Type | Purpose |
|------|------|---------|
| `albert/abilities/register` | action | Register custom abilities |
| `albert/abilities/payload_row` | filter | Augment a normalized ability row before it reaches the Abilities screen (e.g. append `badges`). Fires on both the bulk build and single-row paths. See `docs/extending-the-abilities-screen.md` |
| `albert/abilities/required_capability` | filter | Override the best-effort capability shown on the Abilities screen |
| `albert/abilities/before_execute` | action | Before any ability runs |
| `albert/abilities/after_execute` | action | After any ability runs |
| `albert/abilities/before_execute/{id}` | action | Before a specific ability |
| `albert/abilities/after_execute/{id}` | action | After a specific ability |
| `albert/abilities/invoked` | action | Relayed from WP 7.1's `wp_ability_invoked`. Fires for EVERY invocation whatever the outcome — denied, invalid, short-circuited — and for abilities Albert does not own, which is wider than `after_execute` can see. Inert below 7.1; ask `WpCompat::supports_execution_lifecycle()`. Observer Throwables are swallowed |
| `albert/admin/submenu_pages` | filter | Add addon admin pages |
| `albert/abilities_icons` | filter | Customize category icons |
| `albert/developer_mode` | filter | Toggle developer mode |
| `albert/logging/enabled` | filter | Disable Free's ability log (Premium uses this) |
| `albert/blocks/read_block_limit` | filter | Default top-level blocks per read window (default 200; 0 = unlimited) |
| `albert/blocks/read_max_bytes` | filter | Per-field byte cap on read text representations (default 50000) |
| `albert/mcp/hide_unauthorized_tools` | filter | Hide MCP tools the connected user can't execute from `tools/list`, so discovery matches what's callable (default true; false = list all, deny on call) |
| `albert/privacy/mode` | filter | Override the active PII privacy mode (`strict`/`balanced`/`off`); return `null` to defer to the `albert_privacy_mode` option/default (`balanced`). See `PrivacyMode::resolve()` |
| `albert/privacy/pii_fields` | filter | Extend the anonymiser's PII allow-list, grouped by rule (`email`/`phone`/`name`/`context_name`/`postcode`/`empty`/`strip`/`redact`). A returned category REPLACES that category — read the incoming value and append to extend. See `Anonymizer` |
| `albert/privacy/payment_keys` | filter | Register payment/card keys hard-removed from every result at any depth and in every mode. Shape `[ 'keys' => [], 'prefixes' => [] ]`; empty in Free (add-ons own gateway keys). See `Anonymizer` |
| `albert/privacy/reveal_capability` | filter | Capability gating a `reveal_personal_data` request (default `manage_options`). Consulted only when an ability passes no explicit `reveal_capability` option. See `PiiPolicy` |
| `albert/activated` | action | Plugin activated |
| `albert/deactivated` | action | Plugin deactivated |

### Admin asset and menu contracts (1.4.0+)

Add-ons that render a screen under the Albert menu depend on two published
handles and one set of constants. All three are part of the public contract.

| Handle / constant | What it is |
|---|---|
| `albert-tokens` (`Admin\Assets::TOKENS_HANDLE`) | The design tokens. Colour, spacing, type, radius, motion. |
| `albert-primitives` (`Admin\Assets::PRIMITIVES_HANDLE`) | The shared components. Depends on the tokens, so naming this one alone is enough. |
| `Admin\Menu::POSITION_*` | `admin_menu` priorities that fix submenu order. Add-ons use `POSITION_ADDONS` or later so a future core screen cannot be pushed below third-party entries. |

```php
// In an add-on. Literal strings, not the class constants, so an older Albert
// without those classes cannot fatal the add-on.
wp_enqueue_style( 'my-screen', $url, [ 'albert-primitives' ], $version );
```

Naming a handle that does not exist is not a soft failure — WordPress drops the
dependent stylesheet from the queue entirely. That is the intended degradation
for an add-on running against an Albert too old to have the tokens: the screen
falls back to core admin styling rather than rendering half-painted. Do **not**
express the same requirement as a plugin-wide version floor; `ALBERT_VERSION`
reads the *previous* release on `development` (version bumps happen only in
release branches), so a floor naming the upcoming version stops the add-on
booting against the very branch it is developed against.

Albert renders its page navigation on every screen under its menu, add-on pages
included, and enqueues the primitives there itself — so an add-on page is
styled whether or not it declares the dependency. Declare it anyway; that is
what guarantees load order for the add-on's own stylesheet.

Full token and primitive reference: `docs/design-system.md`.

JS (client-side) seams for the Abilities DataViews screen (`@wordpress/hooks` filters):
`albert.abilities.permissions_section` replaces the fly-in's Permissions section (its `api` exposes
`capabilityContent` to reuse and `registerCloseGuard` to confirm before close), and
`albert.abilities.panel_sections` appends generic sections. Full contract for every Abilities-screen
seam: `docs/extending-the-abilities-screen.md`.
