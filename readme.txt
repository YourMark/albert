=== Albert - The AI Butler ===
Contributors: albertai, mark-jansen
Tags: ai, mcp, claude, chatgpt, ai assistant
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT and other AI assistants to your WordPress site via MCP. Let them write posts, manage media and more, securely.

== Description ==

## Albert: the AI assistant connector for WordPress

Want Claude or ChatGPT to actually *do* things on your site instead of just talking about it? Albert exposes your site through the Model Context Protocol (MCP), so an AI assistant can write posts, organise media, manage products and handle daily tasks. All under your control.

Every well-run site deserves a proper butler. Albert stands at the door, welcomes assistants like Claude and ChatGPT, checks their credentials and puts them to work. No custom code, no config files.

---

### Connect in three steps

Copy the endpoint URL, paste it into your assistant, authorise. That is the whole setup, and it is the same for every client.

* **Works with any MCP client**: Claude Desktop, claude.ai, Claude Code, ChatGPT, Cursor, VS Code, or anything else that speaks MCP.
* **Step-by-step setup per assistant**, built into the Connections screen.
* **No passwords are ever shared.** Connections use OAuth 2.0 with short-lived, automatically refreshing tokens.

---

### You decide what an assistant may do

Not every guest needs access to every room.

* **Every ability listed and labelled** Read, Write or Delete, with a toggle each. Changes save instantly.
* **Write and delete are off by default.** You switch on exactly what you want.
* **WordPress capabilities still apply.** An assistant can never do more than the person who authorised it.
* **Revoke any connection at any time**, from the Connections screen.

---

### Personal data stays private

Albert hides your visitors' and customers' details, names, email addresses, phone numbers and postal addresses, before anything reaches an assistant.

* **Three modes**: Strict, Balanced or Off. New sites start on Strict.
* **Reveal the real details only when you ask**, gated by your own capabilities.

---

### Your assistant knows what your site is

Write your instructions once and Albert sends them with every conversation, alongside your site's language, timezone, theme colours and fonts, content types and shop settings.

* **No more guessing.** Assistants stop inventing brand colours that appear nowhere on your site.
* **See exactly what is sent**, and switch off anything an assistant does not need.
* **Your text is sent as information, never as orders.** Content and instructions from your site can describe subject matter and tone, but can never change what an assistant is allowed to do.

---

### See everything that happened

* **Every call is recorded**: which ability, which assistant, which user, and how long it took.
* **Blocked is not Failed.** A call your rules refused reads differently from one that broke.
* **Needs your attention** on the dashboard surfaces standing problems, not noise.

---

### What your assistant can do

* **Content**: create, edit, find and delete posts and pages, in the block editor or the classic editor.
* **Blocks**: change, add, move or remove a single block without rewriting the page.
* **Media**: browse the library, set featured images, and receive files an assistant sends directly.
* **Users and taxonomies**: find and manage users, categories, tags and custom terms.
* **WooCommerce**: look up products, orders and customers when WooCommerce is active.
* **Your own abilities**: register anything with the WordPress Abilities API.

== Installation ==

= From WordPress.org =

1. Install Albert through the WordPress plugin directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Albert > Connections** and add yourself as an allowed user
4. Copy the MCP endpoint URL from the dashboard
5. Add the URL to Claude Desktop, ChatGPT, or another MCP-compatible assistant
6. Authorize when prompted, and you're connected

= Manual Installation =

1. Download the plugin files
2. Upload the `albert-ai-butler` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Follow the setup steps above

= Connecting Claude Desktop =

1. In WordPress, go to **Albert > Connections** and add yourself as an allowed user
2. Copy the MCP endpoint URL from the Albert dashboard
3. In Claude Desktop, go to Settings > MCP Servers and add a new server
4. Paste the endpoint URL and save
5. Claude will open a browser window to authorize. Log in and approve
6. Claude Desktop can now work with your WordPress site

= Connecting ChatGPT =

1. In WordPress, go to **Albert > Connections** and add yourself as an allowed user
2. Copy the MCP endpoint URL from the Albert dashboard
3. In ChatGPT, connect to the MCP endpoint using the URL
4. Authorize when prompted
5. ChatGPT can now work with your WordPress site

Full setup guide available at [Documentation](https://albertwp.com/docs/)

== Frequently Asked Questions ==

= What is MCP? =

MCP, the Model Context Protocol, is an open standard that lets AI assistants connect to external tools and data. Albert turns your WordPress site into an MCP server, so any MCP-compatible AI assistant, like Claude or ChatGPT, can work with it.

= What AI assistants are supported? =

Albert works with **Claude Desktop**, **ChatGPT**, and any AI assistant that supports the Model Context Protocol (MCP). The connection process is the same for all: copy the endpoint URL, paste it into your assistant, and authorize.

= How hard is it to set up? =

Three steps: add yourself as an allowed user, copy the MCP endpoint URL, and paste it into your AI assistant. No technical knowledge required.

= Is my data secure? =

Yes. Albert uses OAuth 2.0, the same standard used by Google and GitHub. Your assistant receives a short-lived access token that refreshes automatically, and no passwords are shared. Every action respects WordPress capabilities, and you control which abilities are enabled.

= Do AI assistants see my visitors' and customers' personal data? =

Not by default. Albert hides personal details (names, email addresses, phone numbers and postal addresses) before anything is sent. Choose Strict, Balanced or Off, and reveal the real details on request when you genuinely need them.

= What abilities are included? =

Albert ships with 35+ abilities covering WordPress core:

* **Posts and pages**: find, view, create, update and delete
* **Blocks**: edit, add, move and remove individual blocks
* **Users**: find, view, create, update and delete
* **Media**: find, view, upload, set featured images, and receive files an assistant sends directly
* **Taxonomies**: find taxonomies, and find, view, create, update and delete terms
* **Skills**: read the task guides that tell an assistant how to handle a job on your site

When WooCommerce is active, additional abilities are available for products, orders, and customers.

= Can I control what my AI assistant is allowed to do? =

Yes. The Abilities screen lists every action an assistant can perform, labelled Read, Write or Delete, with a toggle each. Changes save instantly. Write and delete are off by default, so you switch on exactly what you want. Every action also respects WordPress capabilities, so an assistant can never do more than the person who authorised it.

= Do I need WooCommerce? =

No. Albert works with WordPress core out of the box. WooCommerce abilities appear automatically when WooCommerce is active.

= Can I add custom abilities? =

Yes. Developers can register custom abilities using the WordPress Abilities API to expose any functionality to AI assistants. See the documentation at [albertwp.com/docs](https://albertwp.com/docs/).

= Does this work with multisite? =

Albert is designed for single-site installations. Multisite support is on the roadmap.

= What are the system requirements? =

* WordPress 6.9 or higher
* PHP 8.1 or higher (8.3+ recommended)
* MySQL 8.0+ or MariaDB 10.5+
* HTTPS (required for OAuth 2.0)

= Where can I get support? =

* Documentation: [albertwp.com/docs](https://albertwp.com/docs/)
* Support Forum: [WordPress.org support forums](https://wordpress.org/support/plugin/albert/)
* Website: [albertwp.com](https://albertwp.com)

== Screenshots ==

1. The Albert dashboard, with setup status, recent activity and anything needing attention
2. The Abilities screen: every action an assistant can take, labelled Read, Write or Delete, with instant-save toggles
3. The Connections screen: the MCP endpoint, who is connected, and setup steps per assistant
4. An active MCP connection with Claude Desktop

== Changelog ==

= 1.4.0 =

Release date: 2026-09-03

Albert 1.4.0 tells connected assistants what your site actually is, so they stop guessing. Assistants can also send you files directly, and every admin screen has been rebuilt on one design system. Day-one support for WordPress 7.1.

#### Features

* New **Albert &rarr; Context** screen. Write instructions for connected assistants, and send your site's language, timezone, theme colours and fonts, content types and shop settings with every conversation.
* Preview exactly what is sent, and switch off any section an assistant does not need.
* Text from your site (your instructions, post content, product descriptions) is now marked as information rather than orders, so it can never change what an assistant is allowed to do.
* Assistants can send you a file directly. Where Albert could previously only fetch media from a web address, an assistant that already has the file now requests a single-use upload link and posts the bytes straight to your media library.
* New size limit for those uploads under **Albert &rarr; Settings &rarr; Uploads**.
* New **Albert &rarr; Skills** screen, listing the task guides Albert and its add-ons ship. Guides tell an assistant how to handle a job on your site, such as writing blocks correctly. Open one to read the exact guidance an assistant follows.
* Assistants now read those guides themselves. Each is offered by name at the start of a conversation, and only when it applies to your site.
* New Site Health check that reports when the MCP endpoint is not registered, instead of failing with a 401 that looks like an authentication problem.

#### Enhancements

* Every Albert screen has been rebuilt: Dashboard, Settings, Connections, Abilities and the two new screens now share one design system, one set of controls and one visual language.
* Albert picks up the admin colour scheme from your WordPress profile instead of always being blue.
* A row of links across the top of every Albert screen replaces trips back to the sidebar.
* The Connections screen now shows the endpoint, who is connected, who may connect, and setup steps per assistant. You can name or rename a connection inline.
* Choosing who may connect is a searchable picker rather than a dropdown listing every user, so it stays fast on large sites.
* You can name a connection at the moment you approve it.
* Invitations nobody accepts now expire, connections approved but never used are dropped, and idle connections can be expired on a schedule. All configurable, all logged.
* A call Albert refused now reads as **Blocked** rather than Failed. A tool that ran and truthfully answered "there is no such post" counts as a success.
* An assistant that uses a wrong parameter name is now told which name it used and which names the tool takes. Previously the wrong name was silently discarded and the assistant could get a successful answer that ignored half its request.
* An expired session now tells the client its token expired, instead of a bare refusal indistinguishable from never having connected.
* A failed connection records which of six reasons it actually was, rather than one generic message for all of them.
* Many tools carry a short note about the mistake that is easiest to make with them. Assistants read these only when they use the tool.
* New installs start on Strict privacy. Existing sites keep their setting.
* Settings are now sanitised on the way into the database, so a value written by code or WP-CLI is checked exactly like one typed into the form.
* A setting fixed in code shows as read-only and names what owns it, rather than appearing editable and being silently overwritten.
* Accessibility pass across the admin: contrast on faint borders and switches, keyboard reachability, focus after dismissing an item, touch target sizes and screen reader announcements.
* On WordPress 7.1, server-only details are stripped from tool descriptions before they reach an assistant. This covers tools added by other plugins too.

#### Bugfixes

* Fixes assistants being unable to read product categories, or any category or tag set without an explicit REST base. Albert asked the wrong question and reported them as unavailable.
* Fixes `albert/find-taxonomies` and `albert/find-terms` failing every call on WordPress 7.1, which made taxonomy discovery impossible.
* Fixes Albert's MCP endpoint failing to register when another plugin bundles its own copy of the MCP library. Albert now shares one copy, so both endpoints work.
* Fixes data migrations being skipped after a deactivate, update, reactivate cycle, which left the update permanently marked as done without having run.
* Fixes the dashboard reporting that all abilities were enabled, because the total and the enabled figure were the same number counted twice.
* Fixes privacy mode not saving, because the screen and the save routine disagreed about the option name.
* Fixes a privacy mode set in code showing as editable.
* Fixes the nightly cleanup hiding connections that still worked. A connection idle overnight disappeared from the admin while continuing to call the site, so it could not be revoked.
* Fixes connections being counted elsewhere by a looser rule with no expiry check.
* Fixes declared minimums and maximums not being enforced outside the browser, and negative numbers losing their sign instead of being clamped.
* Fixes the activity list fade permanently covering its last row.
* Fixes "ability not found" notices on the dashboard.
* Fixes the `.well-known` discovery document not advertising the authentication method Albert issues desktop clients with.
* Fixes a rejected endpoint override filter falling back silently. The endpoint card now says where the address comes from.

#### Security

* `albert/upload-media` now checks file types against the current user's own allowed types instead of the site-wide default. An `unfiltered_upload` capability can no longer widen what it accepts.
* Failed database writes when issuing OAuth authorisation codes, access tokens and refresh tokens are no longer ignored. All three now fail immediately instead of surfacing later as an unrelated error.
* `WWW-Authenticate` is now sent on every 401 from the MCP endpoint. Previously an expired token skipped the header entirely.

#### Developer

* New `Albert\Context` module, with filters `albert/context/enabled`, `albert/context/instructions`, `albert/context/sections`, `albert/context/site` and `albert/context/skills`.
* New `albert/skills/registry` filter registers a skill as data, with declared preconditions. A skill is offered only when its preconditions hold, and an unrecognised condition fails closed.
* New `albert/get-skill` ability returns a guide's Markdown body by slug, gated on `edit_posts`.
* New `albert/create-upload-link` ability and `POST|PUT /albert/v1/media/uploads` endpoint, backed by the reusable `Core\Tokens\TokenService`. New `albert/media/upload_link_max_bytes` filter.
* Abilities' "Supplier" is renamed to "Source": `AbilitiesRegistry::get_sources()` and `albert/abilities/sources`. The old method, filter and payload keys still work, deprecated rather than removed.
* New `albert/abilities/invoked` action, relayed from WordPress 7.1's `wp_ability_invoked`. Fires for every invocation whatever the outcome, including denied and short-circuited calls. Inert below 7.1.
* New `Albert\Logging\Outcome` classifies every logged outcome as `success`, `warning` or `error`. The ability log gains `failure_stage` and `privacy_mode`, and drops `ip_address`, `referrer` and `request_id`.
* New `albert/logging/api_surface_codes` filter, so an add-on's own not-found codes classify correctly.
* Settings API: add-ons can name their own card, attach a unit with `suffix`, add detail with `info`, and declare `min` and `max` once to drive both the control and the sanitiser. New filters `albert/settings/value/{option}`, `albert/settings/value_source/{option}` and `albert/settings/validator/{option}`. `show_in_rest` now works.
* New dashboard filters: `albert/dashboard/stats`, `albert/dashboard/attention`, `albert/dashboard/suggestions`, `albert/dashboard/recommendations` and `albert/dashboard/show_resources`.
* New OAuth filters `albert/oauth/access_token_ttl`, `albert/oauth/refresh_token_ttl` and `albert/oauth/auth_code_ttl`, plus an `albert/oauth/token_request_failed` action carrying the specific failure reason.
* Every object-typed input schema now registers with `additionalProperties => false`, set once in `BaseAbility::prepare_input_schema()`, so add-ons inherit it.
* New design system. `albert-tokens.css` holds colour, spacing, type, radius and motion in `oklch()`; `albert-primitives.css` holds shared components. Add-ons declare `albert-primitives` as a stylesheet dependency.
* **Breaking for add-ons.** The 56 value-named custom properties in `admin-settings.css` (`--albert-primary`, `--albert-font-lg`) are replaced by role-named ones (`--albert-color-accent`, `--albert-font-size-section-title`) with no alias layer. Albert Premium Service ships the matching migration and must be updated alongside this release.
* The bundled MCP adapter moves to 0.6.1 and now loads unscoped through Jetpack Autoloader instead of being prefixed with Mozart. Albert, WooCommerce and the standalone MCP Adapter plugin share one adapter and each keep their own server. Mozart, `vendor-prefixed/` and the `Albert\Vendor\` namespace are gone.
* WordPress 7.1 support is feature-detected in `Albert\Support\WpCompat`. Tool schemas pass through `wp_prepare_json_schema_for_client()`, `is_mcp_public()` adopts the `meta.mcp.public ?? meta.public` precedence, and `get_all_raw()` reads the registry directly so a third-party filter cannot make Albert lose track of a registered ability.
* Object-typed input schemas no longer claim their default is an array.
* Stylesheets and scripts are versioned on file modification time, so a change during a release cycle is never served stale.
* CI compiles and lints the admin screens on every pull request, and adds WordPress 7.0 and trunk rows.

#### Credits

* [Marinus Klasen](https://github.com/mklasen) for the idea behind direct file uploads.
* [Jonathan de Jong](https://github.com/jonathan-dejong) for reporting the taxonomy failures, the output schema failures and the MCP adapter conflict.

= 1.3.1 =
A security update for how AI assistants connect.

**Security**

* Connecting an assistant now requires the exact web address it will return you to. The old catch-all that accepted any address is gone, and every connection request is matched against that exact address.
* The approval screen now shows where the assistant will send you, and how recently the app was set up, so an unexpected request is easier to spot.
* Apps that connect through their own link (such as some desktop assistants) are checked more strictly and secured without relying on a shared secret.
* Stricter checking of return addresses, a limit on how many a single app can register, and a cap on the total number of connected apps.

**Developer**

* New `albert/oauth/allowed_redirect_schemes` filter to restrict redirect URI schemes to an explicit allowlist.

= 1.3.0 =
Two headline changes: the Abilities screen has been rebuilt from scratch, and personal data is now kept private from AI assistants automatically.

**Features**

* Rebuilt Abilities screen — a fast, modern list with search, filter by category or supplier, sort, and pagination, matching the rest of the WordPress admin.
* Switch abilities on or off instantly, one at a time or in bulk — no Save button.
* A detail panel on every ability showing its inputs, the permission it needs, and when it last ran.
* Privacy mode — personal data (names, email addresses, phone numbers, and postal addresses) is redacted from AI results before it leaves your site. Choose Strict, Balanced, or Off to control how much is hidden.
* Reveal the real personal details only when you explicitly ask, gated by your own WordPress capabilities.

**Improvements**

* The Abilities screen was rebuilt for accessibility throughout — full keyboard and screen-reader support, with clearer focus states and contrast.
* The three core tools an assistant uses to discover and run abilities can no longer be switched off, and repair themselves on load, so a connection never breaks after an update.

**Developer**

* New extension points on the Abilities screen let add-ons plug straight in — this powers Albert Premium's per-role and per-user permission rules.
* New `albert/privacy/*` filters let add-ons protect their own fields — the WooCommerce add-on uses these to strip payment and card data.

= 1.2.0 =
Albert now understands the WordPress block editor — a big step up in how AI assistants read and write your content.

**Features**

* Block editor support — assistants work with real WordPress blocks instead of raw HTML. They read a post as a clean, structured outline and compose new content with proper blocks: headings, paragraphs, lists, quotes, images, buttons, columns, and groups.
* Edit one block at a time — change, add, move, or remove a single block without disturbing the rest of the page. No more rewriting a whole post to fix one paragraph.
* Classic editor support — content on classic-editor sites is read and saved as HTML, so Albert works whichever editor you use.
* Built-in guidance — Albert ships a playbook and reference data that teach connected assistants how your site's blocks work, so they produce better content out of the box.

**Improvements**

* Cleaner content, fewer errors — Albert validates blocks as they're written and steers the assistant to correct mistakes, so you avoid the block editor's "this block contains unexpected content" warnings.
* Assistants only use the blocks your site, and the connected user, are actually allowed to use.
* Long posts are paged automatically, so big content is never cut off mid-way when an assistant reads it.
* Safer updates — abilities added by a plugin update now start switched off. An update will never silently expand what an AI assistant can do on a site you've already set up.

= 1.1.1 =
A bug-fix release.

**Fixes**

* OAuth discovery endpoints (`/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`) are now reachable when the request arrives with a trailing slash. Some hosts add one at the edge, after which WordPress's canonical redirect would strip it again, producing a redirect loop or a 404. The endpoints now respond identically with or without the slash.

**Credits**

* Reported by [Marinus Klasen](https://profiles.wordpress.org/mklasen/).

= 1.1.0 =
A major admin redesign, new activity logging, and a stack of reliability fixes.

**Features**

* Unified abilities page — one filterable list of every registered ability from WordPress core, WooCommerce, and any other plugin. Search, filter by category or supplier, and see read/write/delete at a glance.
* Instant save — toggle an ability on or off and it saves immediately. No more Save Changes button or lost progress.
* Activity logging — a new dashboard widget shows the most recent ability execution, and every ability shows its "Last run" time in the expanded details.
* Plain-language labels — each ability is tagged Read, Write, or Delete (replacing the developer-facing "Destructive / Idempotent / Readonly" terms). Hover or keyboard-focus a label for a full explanation.
* Supplier filtering — the filter dropdown shows branded names like "WordPress core", "Albert", and "WooCommerce" instead of raw prefixes.
* List / paginated view — switch between one long list and 25-per-page pagination. Your choice is persisted on the server, so there is no flash of the wrong view on load.

**Fixes**

* Ability categories now register at the default hook priority, preventing collisions with WordPress core's built-in categories on WP 6.9+.
* Restored a missing 'user' category that Users abilities depend on, so abilities register reliably on fresh installs.
* The `password` field on the Create User ability is now correctly flagged as required, so AI assistants get a clear validation error when it is missing instead of a vague failure.
* OAuth endpoints, MCP, and discovery metadata now share one consistent REST namespace reference.

**Improvements**

* Accessibility — keyboard-reachable tooltips on every annotation chip, WCAG 2.2 AA contrast on all chip colours, debounced aria-live stats announcements during search, and visible focus indicators on pagination buttons and filter selects.

**Developer**

* New `albert/abilities/suppliers` filter so third-party plugins can register their own branded supplier name.
* Comprehensive automated test suite covering input validation, output schema, permissions, and per-parameter behaviour on every ability.
* Continuous integration now runs against PHP 8.1–8.4, WordPress 6.9 and latest, and WooCommerce 10.5 and latest.
* Removed redundant manual input validation from every ability — WordPress core validates the schema before the ability runs.
* Unified internal settings API for cleaner state management.

= 1.0.1 =
A bug-fix release.

**Fixes**

* OAuth endpoints used a different REST namespace (`albert-ai-butler/v1`) than the MCP server and discovery metadata (`albert/v1`), causing connection failures when clients followed the OAuth discovery spec. All endpoints now use `albert/v1` consistently.

**Developer**

* New `albert/rest_namespace` filter lets sites with a namespace collision override the REST namespace.

= 1.0.0 =
Initial release.

**Features**

* MCP server — turns your WordPress site into an MCP endpoint. Copy the URL, paste it into Claude Desktop, ChatGPT, or any MCP-compatible assistant, authorize, and you're connected. No configuration files or developer setup needed.
* OAuth 2.0 server — a full authentication server with PKCE support, RSA-signed access tokens, automatic token refresh, and sessions that persist up to 30 days.
* Abilities Manager — an admin interface to toggle read and write permissions per content type. Write abilities are disabled by default, and all actions respect WordPress capabilities.
* 25+ WordPress abilities — Posts, Pages, Users, Media, and Taxonomies with find, view, create, update, and delete operations.
* WooCommerce abilities — Products, Orders, and Customers when WooCommerce is active.
* Connections management — control which users can connect AI assistants. View active connections, disconnect individual sessions, or end entire sessions with token revocation.
* Dashboard — a setup checklist, status overview, active connection count, and recent activity feed.

**Developer**

* Register custom abilities with the WordPress Abilities API. Hookable architecture with filters and actions throughout.

== Upgrade Notice ==

= 1.4.0 =
Assistants now know what your site is, can send you files directly, and every admin screen has been rebuilt. Fixes taxonomy reads that were failing outright. Multisite: reconnect your assistants once after updating.

= 1.3.1 =
A security update. Connecting an AI assistant now requires an exact return address, the approval screen shows where you are being sent, and app connections are checked more strictly. Recommended for all sites.

= 1.3.0 =
The Abilities screen has been rebuilt from scratch — search, filter, instant and bulk on/off, and a detail panel for every ability. And personal data (names, emails, phone numbers, addresses) is now hidden from AI assistants automatically, with a setting to control how strict that is.

= 1.2.0 =
Adds full block-editor support (read, write, and edit individual blocks), classic-editor handling, automatic paging for long posts, and safer defaults — newly added abilities now start switched off. Your existing enabled/disabled settings are preserved.

= 1.1.1 =
Fixes OAuth discovery endpoints when the request URL has a trailing slash. Recommended for sites where the host or CDN adds a trailing slash to .well-known URLs.

= 1.1.0 =
Redesigned abilities page, new activity logging, and several reliability fixes. Existing enabled / disabled settings are preserved; no migration needed.

= 1.0.1 =
Fixes a connection failure caused by mismatched OAuth endpoint namespaces. Recommended for all users.

= 1.0.0 =
Initial release. Connect Claude Desktop, ChatGPT, and other MCP-compatible AI assistants to your WordPress site.

== Privacy Policy ==

Albert does not collect, store, or transmit any user data to external servers. All authentication tokens are stored locally in your WordPress database. When you authorize an AI assistant, that assistant will have access to perform actions on your WordPress site according to the permissions you grant. You control which abilities are enabled and can revoke any session at any time.

== Credits ==

Developed by Mark Jansen - Your Mark Media
Website: https://yourmark.nl
Plugin URL: https://wordpress.org/plugins/albert/

Built with:
* league/oauth2-server for OAuth 2.0 implementation
* Model Context Protocol (MCP) for AI assistant connectivity
* WordPress Coding Standards
