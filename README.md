# Albert

![Requires PHP](https://img.shields.io/badge/Requires%20PHP-8.1+-blue)
![Requires WordPress](https://img.shields.io/badge/Requires%20WordPress-6.9+-blue)
![Tested up to](https://img.shields.io/badge/Tested%20up%20to-6.9-blue)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)

**Connect your WordPress site to AI assistants with secure OAuth 2.0 authentication and the Model Context Protocol (MCP).**

## Description

Albert provides a powerful API that exposes WordPress functionality to AI assistants through the Model Context Protocol (MCP). This plugin acts as a secure bridge between your WordPress site and AI-powered tools like Claude, enabling them to interact with and control various aspects of your website through a standardized interface.

Think of abilities as superpowers that you can grant to AI assistants - from managing content and products to handling complex workflows. The abilities API provides a standardized way for AI assistants to:

- Discover available actions they can perform on your site
- Execute those actions with proper authentication and authorization
- Receive structured responses they can understand and act upon
- Extend WordPress and WooCommerce functionality in AI-friendly ways

## Requirements

- **WordPress**: 6.9 or higher
- **PHP**: 8.1 or higher (8.3+ recommended)
- **WooCommerce**: 10.4 or higher (if WooCommerce integration is used)
- **MySQL**: 8.0+ or MariaDB 10.5+

## Installation

### Via WordPress Plugin Directory

1. Install through the WordPress plugin directory
2. Activate the plugin through the 'Plugins' menu in WordPress

### Manual Installation

1. Download the plugin files
2. Upload the `albert-ai-butler` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress

## Development

### Setup Development Environment

1. Clone this repository
2. Navigate to the plugin directory:
   ```bash
   cd wp-content/plugins/albert-ai-butler
   ```
3. Install dependencies:
   ```bash
   composer install
   ```

### Code Standards

This plugin follows WordPress Coding Standards. Check your code:

```bash
composer phpcs
```

Automatically fix code standards issues:

```bash
composer phpcbf
```

### Running Tests

```bash
composer test
```

## Architecture

The plugin uses a modern, modular architecture:

- **Singleton Pattern**: Main Plugin class ensures single instance
- **Hookable Interface**: Clean hook registration pattern
- **Component System**: Modular, extensible components
- **PSR-4 Autoloading**: Composer-based autoloading
- **Type Safety**: Full PHP type declarations

See `CLAUDE.md` for detailed architectural documentation.

## License

This plugin is licensed under GPL v2 or later.

## Credits

Developed by Mark Jansen - Your Mark Media
Website: https://yourmark.nl

## Changelog

### 1.3.1

A security update for how AI assistants connect.

**Security**
- Connecting an assistant now requires the exact web address it will return you to. The old catch-all that accepted any address is gone, and every connection request is matched against that exact address.
- The approval screen now shows where the assistant will send you, and how recently the app was set up, so an unexpected request is easier to spot.
- Apps that connect through their own link (such as some desktop assistants) are checked more strictly and secured without relying on a shared secret.
- Stricter checking of return addresses, a limit on how many a single app can register, and a cap on the total number of connected apps.

**Developer**
- New `albert/oauth/allowed_redirect_schemes` filter to restrict redirect URI schemes to an explicit allowlist.

### 1.3.0

Two headline changes: the Abilities screen has been rebuilt from scratch, and personal data is now kept private from AI assistants automatically.

**Features**
- Rebuilt Abilities screen — a fast, modern list with search, filter by category or supplier, sort, and pagination, matching the rest of the WordPress admin.
- Switch abilities on or off instantly, one at a time or in bulk — no Save button.
- A detail panel on every ability showing its inputs, the permission it needs, and when it last ran.
- Privacy mode — personal data (names, email addresses, phone numbers, postal addresses) is redacted from AI results before it leaves your site. Choose Strict, Balanced, or Off.
- Reveal the real personal details only when you explicitly ask, gated by your own capabilities.

**Improvements**
- The Abilities screen was rebuilt for accessibility throughout — full keyboard and screen-reader support, with clearer focus and contrast.
- The three core tools an assistant uses to discover and run abilities can no longer be switched off and repair themselves on load, so a connection never breaks after an update.

**Developer**
- New extension points on the Abilities screen let add-ons plug straight in — this powers Albert Premium's per-role and per-user permission rules.
- New `albert/privacy/*` filters let add-ons protect their own fields — the WooCommerce add-on uses these to strip payment and card data.

### 1.2.0

Albert now understands the WordPress block editor — a big step up in how AI assistants read and write your content.

**Features**
- Block editor support — assistants work with real WordPress blocks instead of raw HTML, reading a post as a clean, structured outline and composing new content with proper blocks (headings, paragraphs, lists, quotes, images, buttons, columns, groups).
- Edit one block at a time — change, add, move, or remove a single block without disturbing the rest of the page.
- Classic editor support — classic-editor sites, posts, and pages are read and saved as HTML.
- Built-in guidance — ships a playbook and reference data that teach connected assistants how your site's blocks work.

**Improvements**
- Cleaner content, fewer errors — Albert validates blocks as they're written and steers the assistant to fix mistakes, avoiding the block editor's "unexpected content" warnings.
- Assistants only use the blocks your site (and the connected user) are allowed to use.
- Long posts are paged automatically, so big content is never cut off mid-way when an assistant reads it.
- Safer updates — abilities added by a plugin update now start switched off; you decide what to turn on.

### 1.1.1

A bug-fix release.

**Fixes**
- OAuth discovery endpoints (`/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`) are now reachable when the request arrives with a trailing slash, avoiding a redirect loop or 404 on hosts that add a trailing slash at the edge.

**Credits**
- Reported by [Marinus Klasen](https://profiles.wordpress.org/mklasen/).

### 1.1.0

A major admin redesign, new activity logging, and a stack of reliability fixes.

**Features**
- Unified abilities page — one filterable list of every registered ability, whether it comes from WordPress core, WooCommerce, or any third-party plugin. Search, filter by category or supplier, read/write/delete at a glance.
- Instant per-row save — toggles save immediately. No Save Changes button, no lost work.
- Activity logging — a dashboard widget showing the most recent ability execution, and a "Last run" line on every ability.
- Plain-language annotation chips — each ability is labelled Read, Write, or Delete with accessible tooltips (replaces the developer-facing "Destructive / Idempotent / Readonly" terms).
- List / paginated view toggle — preference persisted server-side, no flash of content on load.

**Fixes**
- Ability categories now register at the default hook priority, preventing collisions with WordPress core's built-in categories on WP 6.9+.
- Restored a missing `user` category that Users abilities depend on.
- The `password` field on `core/users/create` is now correctly flagged as required in its input schema.
- Fixed `Logger::log_execution` visibility so extenders can hook it cleanly.
- Consolidated REST namespace handling to a single `Plugin::REST_NAMESPACE` constant.

**Improvements**
- Accessibility — keyboard-reachable chip tooltips, WCAG 2.2 AA contrast on all chip tones, debounced aria-live stats announcements during search, and focus indicators on pagination buttons and filter selects.

**Developer**
- New `albert/abilities/suppliers` filter so third-party plugins can register branded supplier names for the filter dropdown.
- Comprehensive automated test suite — input validation, output schema, permission, and per-parameter coverage on every ability. Auto-discovers abilities from `src/Abilities/` so new abilities are tested without touching the test files.
- CI matrix now covers PHP 8.1–8.4, WordPress 6.9 and latest, and WooCommerce 10.5/10.6/latest.
- Removed redundant manual `empty()` input validation from every ability — WordPress core's `WP_Ability::validate_input()` already enforces the schema.
- Unified internal settings API.
- Removed: the deferred-save bulk form, category-grouped card layout, per-category subpages, and the "CORE" / "ALBERT" uppercase source badges.

### 1.0.1

A bug-fix release.

**Fixes**
- OAuth route namespace mismatch (`albert-ai-butler/v1` vs `albert/v1`) that caused connection failures when clients followed the discovery spec. All endpoints now use `albert/v1`, consolidated to a single `Plugin::REST_NAMESPACE` constant.

**Developer**
- New `albert/rest_namespace` filter for sites with namespace collisions.

### 1.0.0 - 2025-01-23

Initial release.

**Features**
- Full OAuth 2.0 server implementation.
- MCP (Model Context Protocol) integration.
- Core WordPress abilities (Posts, Pages, Users, Media, Taxonomies).
- Admin interface for managing abilities and OAuth sessions.
- Secure authentication and authorization.

**Developer**
- Extensible ability system built on the WordPress Abilities API.
