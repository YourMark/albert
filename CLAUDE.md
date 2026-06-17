# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## About This Plugin

Albert is a WordPress plugin that exposes WordPress functionality to AI assistants through the MCP (Model Context Protocol). It provides:

- **Abilities API**: Register and expose WordPress operations as AI-callable tools
- **OAuth 2.0 Server**: Full OAuth implementation for secure AI assistant authentication
- **MCP Integration**: Connect AI assistants (Claude Desktop, etc.) to WordPress

## System Requirements

- **PHP**: 8.1+ (8.3+ recommended)
- **WordPress**: 6.9+
- **Database**: MySQL 8.0+ or MariaDB 10.5+
- **HTTPS**: Required for OAuth
- **WooCommerce**: 10.4+ (optional, for WooCommerce abilities)

## Directory Structure

```
albert-ai-butler/
├── albert-ai-butler.php             # Main plugin bootstrap
├── composer.json                       # PSR-4 autoloading & dependencies
├── CLAUDE.md                           # This file
├── README.md                           # GitHub documentation
├── readme.txt                          # WordPress.org format
├── DEVELOPER_GUIDE.md                  # Developer documentation
│
├── src/                                # Source code (Albert\)
│   ├── Abstracts/
│   │   └── BaseAbility.php             # Base class for all abilities
│   │
│   ├── Contracts/
│   │   └── Interfaces/
│   │       ├── Ability.php             # Ability interface
│   │       └── Hookable.php            # Hook registration interface
│   │
│   ├── Core/
│   │   ├── Plugin.php                  # Main singleton, bootstraps everything
│   │   ├── AbilitiesManager.php        # Registers abilities with WordPress
│   │   ├── AbilitiesRegistry.php       # Supplier map, category grouping, source lookup
│   │   └── AnnotationPresenter.php     # Annotation → chip DTO mapping for the admin UI
│   │
│   ├── Admin/
│   │   ├── AbilitiesPage.php           # Unified flat-list abilities page (Core/ACF/Woo merged)
│   │   ├── Connections.php             # Allowed users & active connections
│   │   ├── Settings.php                # Plugin settings page
│   │   └── UserSessions.php            # OAuth sessions management
│   │
│   ├── Abilities/
│   │   ├── WooCommerce/
│   │   │   ├── FindProducts.php        # albert/woo-find-products
│   │   │   ├── ViewProduct.php         # albert/woo-view-product
│   │   │   ├── FindOrders.php          # albert/woo-find-orders
│   │   │   ├── ViewOrder.php           # albert/woo-view-order
│   │   │   ├── FindCustomers.php       # albert/woo-find-customers
│   │   │   └── ViewCustomer.php        # albert/woo-view-customer
│   │   └── WordPress/
│   │       ├── Posts/
│   │       │   ├── FindPosts.php       # albert/find-posts
│   │       │   ├── ViewPost.php        # albert/view-post
│   │       │   ├── Create.php          # albert/create-post
│   │       │   ├── Update.php          # albert/update-post
│   │       │   └── Delete.php          # albert/delete-post
│   │       ├── Pages/
│   │       │   ├── FindPages.php       # albert/find-pages
│   │       │   ├── ViewPage.php        # albert/view-page
│   │       │   ├── Create.php          # albert/create-page
│   │       │   ├── Update.php          # albert/update-page
│   │       │   └── Delete.php          # albert/delete-page
│   │       ├── Users/
│   │       │   ├── FindUsers.php       # albert/find-users
│   │       │   ├── ViewUser.php        # albert/view-user
│   │       │   ├── Create.php          # albert/create-user
│   │       │   ├── Update.php          # albert/update-user
│   │       │   └── Delete.php          # albert/delete-user
│   │       ├── Media/
│   │       │   ├── FindMedia.php       # albert/find-media
│   │       │   ├── ViewMedia.php       # albert/view-media
│   │       │   ├── UploadMedia.php     # albert/upload-media
│   │       │   └── SetFeaturedImage.php # albert/set-featured-image
│   │       └── Taxonomies/
│   │           ├── FindTaxonomies.php  # albert/find-taxonomies
│   │           ├── FindTerms.php       # albert/find-terms
│   │           ├── ViewTerm.php        # albert/view-term
│   │           ├── CreateTerm.php      # albert/create-term
│   │           ├── UpdateTerm.php      # albert/update-term
│   │           └── DeleteTerm.php      # albert/delete-term
│   │
│   ├── MCP/
│   │   └── Server.php                  # MCP protocol handler
│   │
│   ├── OAuth/
│   │   ├── Database/
│   │   │   └── Installer.php           # Creates OAuth database tables
│   │   ├── Endpoints/
│   │   │   ├── OAuthController.php     # /oauth/authorize, /oauth/token
│   │   │   ├── OAuthDiscovery.php      # .well-known endpoints
│   │   │   ├── AuthorizationPage.php   # User consent UI
│   │   │   ├── ClientRegistration.php  # Dynamic client registration
│   │   │   └── Psr7Bridge.php          # PSR-7 ↔ WordPress conversion
│   │   ├── Entities/
│   │   │   ├── AccessTokenEntity.php
│   │   │   ├── AuthCodeEntity.php
│   │   │   ├── ClientEntity.php
│   │   │   ├── RefreshTokenEntity.php
│   │   │   ├── ScopeEntity.php
│   │   │   └── UserEntity.php
│   │   ├── Repositories/
│   │   │   ├── AccessTokenRepository.php
│   │   │   ├── AuthCodeRepository.php
│   │   │   ├── ClientRepository.php
│   │   │   ├── RefreshTokenRepository.php
│   │   │   └── ScopeRepository.php
│   │   └── Server/
│   │       ├── AuthorizationServerFactory.php
│   │       ├── ResourceServerFactory.php
│   │       ├── KeyManager.php          # RSA key management
│   │       └── TokenValidator.php      # Validates Bearer tokens
│
├── assets/
│   ├── css/
│   │   └── admin-settings.css
│   └── js/
│       └── admin-settings.js
│
├── tests/
│   ├── bootstrap.php
│   ├── bootstrap-unit.php
│   ├── TestCase.php
│   ├── Unit/
│   │   └── SampleTest.php
│   └── Integration/
│       ├── PluginTest.php
│       └── AbilitiesManagerTest.php
│
├── .claude/
│   └── media-upload-discussion.md      # Ongoing discussion notes
│
└── vendor/                             # Composer dependencies (gitignored)
```

## Architecture Overview

### Core Components

#### 1. Plugin Bootstrap (`src/Core/Plugin.php`)
Singleton that initializes all components:
- Registers admin pages (Abilities, Settings, Sessions)
- Initializes OAuth endpoints
- Registers MCP server
- Registers abilities on `init` hook

#### 2. Abilities System
Abilities are WordPress operations exposed to AI assistants.

**BaseAbility** (`src/Abstracts/BaseAbility.php`):
- Abstract class all abilities extend
- Defines: `$id`, `$label`, `$description`, `$input_schema`, `$output_schema`
- Implements `register_ability()` to register with WordPress
- Abstract `execute(array $args)` method for implementation

**AbilitiesManager** (`src/Core/AbilitiesManager.php`):
- Collects and registers all abilities
- Calls `wp_register_ability()` for each enabled ability

**Creating a New Ability:**
```php
namespace Albert\Abilities\WordPress\Example;

use Albert\Abstracts\BaseAbility;
use WP_Error;

class MyAbility extends BaseAbility {
    public function __construct() {
        $this->id          = 'albert/my-ability';
        $this->label       = __( 'My Ability', 'albert' );
        $this->description = __( 'Description of what it does.', 'albert' );
        $this->category    = 'core';
        $this->group       = 'example';

        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'param1' => [
                    'type'        => 'string',
                    'description' => 'Parameter description',
                ],
            ],
            'required'   => [ 'param1' ],
        ];

        $this->meta = [
            'mcp' => [ 'public' => true ],
        ];

        parent::__construct();
    }

    public function check_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    public function execute( array $args ): array|WP_Error {
        // Implementation
        return [ 'result' => 'success' ];
    }
}
```

Then register in `Plugin::register_abilities()`:
```php
$this->abilities_manager->add_ability( new MyAbility() );
```

### Extensibility Hooks

Albert provides hooks for addon plugins or themes to register custom abilities, add admin pages, and observe ability execution.

#### Registering Custom Abilities (`albert/abilities/register`)

**Action** — Fires after built-in abilities are registered on the `init` hook. Addons (or themes via `functions.php`) hook here to register their own abilities by extending `BaseAbility` directly — the same pattern built-in abilities use.

```php
// In an addon plugin or theme functions.php:
add_action( 'albert/abilities/register', function ( $manager ) {
    $manager->add_ability( new MyCustomAbility() );
} );
```

The `$manager` parameter is the `AbilitiesManager` instance. Custom abilities extend `Albert\Abstracts\BaseAbility` and implement `execute()` and `check_permission()`. They flow through the same admin UI, enabled/disabled toggle, and `guarded_execute()` pipeline as built-in abilities.

This works from any context that loads before `init`:
- **Addon plugins** — The recommended approach for distributing abilities.
- **Theme `functions.php`** — Works because themes load before the `init` hook fires.
- **Must-use plugins** — Also supported.

#### Execution Hooks

All execution hooks are wrapped in try/catch — observer errors never break ability execution.

**`albert/abilities/before_execute`** (action) — Fires before any ability executes. Useful for logging, rate limiting, or audit trails.

```php
add_action( 'albert/abilities/before_execute', function ( string $ability_id, array $args, int $user_id ) {
    // Log, validate, track, etc.
}, 10, 3 );
```

**`albert/abilities/before_execute/{ability_id}`** (action) — Fires before a specific ability executes. The ability ID is appended to the hook name (e.g. `albert/abilities/before_execute/albert/create-post`).

```php
add_action( 'albert/abilities/before_execute/albert/create-post', function ( array $args, int $user_id ) {
    // Runs only before the albert/create-post ability.
}, 10, 2 );
```

**`albert/abilities/after_execute`** (action) — Fires after any ability executes. Receives the result (array or WP_Error).

```php
add_action( 'albert/abilities/after_execute', function ( string $ability_id, array $args, $result, int $user_id ) {
    // Log result, send notifications, etc.
}, 10, 4 );
```

**`albert/abilities/after_execute/{ability_id}`** (action) — Fires after a specific ability executes. The ability ID is appended to the hook name (e.g. `albert/abilities/after_execute/albert/woo-find-products`).

```php
add_action( 'albert/abilities/after_execute/albert/create-post', function ( array $args, $result, int $user_id ) {
    // Runs only after the albert/create-post ability.
}, 10, 3 );
```

#### Admin Submenu Pages (`albert/admin/submenu_pages`)

**Filter** — Addon plugins can add pages to the Albert admin menu. Fires at `admin_menu` priority 15 (after abilities pages, before Settings at priority 20).

```php
add_filter( 'albert/admin/submenu_pages', function ( array $pages ) {
    $pages[] = [
        'slug'       => 'my-addon-settings',  // Required.
        'callback'   => 'render_my_page',      // Required, callable.
        'page_title' => 'My Addon',            // Optional (defaults to slug).
        'menu_title' => 'My Addon',            // Optional (defaults to slug).
        'capability' => 'manage_options',       // Optional (default: manage_options).
        'position'   => 100,                   // Optional (default: 100).
    ];
    return $pages;
} );
```

#### Unified AbilitiesPage (1.1+)

Since 1.1, the Core / ACF / WooCommerce admin pages are merged into a single `Albert → Abilities` page rendered by `src/Admin/AbilitiesPage.php`. Every registered ability appears as a row in a flat, filterable list. Filtering (text search, category, supplier) is entirely client-side; pagination and view-mode (list vs paginated) are server-rendered on every request to avoid a flash of content. Toggles save instantly via `wp_ajax_albert_toggle_ability` — there is no Save Changes button. Custom abilities registered via `albert/abilities/register` appear in the same list automatically.

#### Supplier Registry (`albert/abilities/suppliers`)

The filter dropdown's supplier labels come from a curated prefix→label map in `AbilitiesRegistry::get_suppliers()`. Built-in entries cover `core` → "WordPress core", `albert` → "Albert", `woo` → "WooCommerce", and `acf` → "ACF". Addons can register their own prefix under a branded name via the `albert/abilities/suppliers` filter:

```php
add_filter( 'albert/abilities/suppliers', function ( array $suppliers ): array {
    $suppliers['mycompany'] = 'My Company';
    return $suppliers;
} );
```

Unknown prefixes fall back to a prettified version of the prefix itself, so every ability always has a sensible supplier label.

#### 3. OAuth 2.0 Server
Full OAuth 2.0 implementation using `league/oauth2-server`.

**Endpoints:**
| Endpoint | Purpose |
|----------|---------|
| `GET /wp-json/albert/v1/oauth/authorize` | Authorization request |
| `POST /wp-json/albert/v1/oauth/authorize` | User consent submission |
| `POST /wp-json/albert/v1/oauth/token` | Token exchange |
| `POST /wp-json/albert/v1/oauth/register` | Dynamic client registration |
| `GET /.well-known/oauth-authorization-server` | Server metadata (RFC 8414) |
| `GET /wp-json/albert/v1/oauth/metadata` | Alternative metadata endpoint |

**Token Validation:**
```php
use Albert\OAuth\Server\TokenValidator;

// In a REST endpoint permission callback:
$user = TokenValidator::validate_request( $request );
if ( is_wp_error( $user ) ) {
    return $user;
}
wp_set_current_user( $user->ID );
```

#### 4. MCP Server (`src/MCP/Server.php`)
Handles MCP protocol communication with AI assistants. Authenticated via OAuth.

#### 5. Logging (`src/Logging/`)
Minimal ability execution logging for the Free tier. When Premium is active it takes over the same shared table with richer columns and time-based retention.

**Hook used:** `albert/abilities/after_execute` (Albert hook, fires on both success and failure, covers WP_Error results). `ObservabilityHandler` also captures MCP-level errors that never reach a BaseAbility.

**Filter:** `albert/logging/enabled` (bool, default `true`) — means "Free's DB writers are active". Premium returns `false` from this filter to suppress Free's writes and take over logging itself. Returning `false` does **not** disable logging globally — Premium's own writers still run. The `albert/logging/ability_failed` notification action fires regardless of this value so Premium always receives failure signals.

**Schema (DB_VERSION 1.2.0, option `albert_logging_db_version`):**
| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `ability_name` | `VARCHAR(191)` | Ability identifier |
| `user_id` | `BIGINT UNSIGNED` | `get_current_user_id()`, 0 if unauthenticated |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |
| `status` | `VARCHAR(20)` | `'success'` or `'error'` |
| `error_code` | `VARCHAR(100)` | WP_Error code, NULL on success |
| `error_message` | `LONGTEXT` | WP_Error message (1.2.0+), NULL on success. Captured by both Free and Premium loggers; rides in the `$context` array, surfaced in Premium's Activity Log expandable error detail |
| `duration_ms` | `INT UNSIGNED` | Execution ms; NULL unless Premium populates |
| `ip_address` | `VARCHAR(45)` | Client IP; NULL unless Premium populates |
| `user_agent` | `TEXT` | Client UA; NULL unless Premium populates |
| `referrer` | `TEXT` | HTTP Referer; NULL unless Premium populates |
| `request_id` | `VARCHAR(36)` | UUID; NULL unless Premium populates |
| `input` | `LONGTEXT` | JSON input payload; capped by default; NULL unless Premium populates |
| `output` | `LONGTEXT` | JSON success result payload (1.2.0+); capped by default; NULL on error / unless Premium populates |
| `client_id` | `VARCHAR(80)` | OAuth client id of the calling connection (1.2.0+); NULL for non-MCP calls / unless Premium populates |
| `client_name` | `VARCHAR(255)` | Snapshotted OAuth client name at call time (1.2.0+); NULL for non-MCP calls / unless Premium populates |

**Indexes:** `ability_created (ability_name, created_at)`, `ability_status (ability_name, status, created_at)`

**Retention:** Free hard-codes 2 records per `(ability_name, status)` partition, pruned on insert — only when `albert/logging/enabled` is true (i.e. Free is the writer). Premium uses time-based retention (default 90 days) via `Cron/LogCleanup`.

**Components:**
- `Installer.php` — Creates/upgrades `{$wpdb->prefix}albert_ability_log` table (dbDelta, idempotent)
- `Repository.php` — CRUD operations, bulk fetch, auto-prune; `insert()` accepts optional `$context` array for the rich columns
- `Logger.php` — Hooks `albert/abilities/after_execute`; gated by `albert/logging/enabled` filter; fires `albert/logging/ability_failed` before the gate
- `ObservabilityHandler.php` — MCP-level error recorder; gated by same filter
- `ExecutionLogMarker.php` — request-scoped dedup marker; set by the loggers when they write a row, checked by the observers so a single call never logs twice

**Failure capture (1.2.0+):** Failures that happen *before* the ability runs are now logged too.
Input rejected by the WordPress Abilities API (`WP_Ability::execute()` validates `input_schema`
*before* the registered callback, so `guarded_execute`/`after_execute` never fire) is caught by
`MCP/ToolCallObserver` on the adapter's `mcp_adapter_tool_call_result` filter. It (1) rewrites the
verbose `ability_invalid_input` error into an actionable message for the LLM — e.g. *"Missing
required parameter: `title`."* — and never returns a blank/"unknown error"; and (2) fires
`albert/abilities/after_execute` for the failure so it logs through the normal path (status `error`,
`error_code`, `error_message`, `input`, connection identity). The `ExecutionLogMarker` keeps an
ability that *did* execute from being logged twice. `ObservabilityHandler` (Free + Premium) now also
captures the error message (from the adapter's `failure_reason` tag) and is dedup-guarded by the same
marker, covering permission/transport/unknown failures the adapter surfaces via `record_event`.

**Connection identity (1.2.0+):** `OAuth/Server/ConnectionContext` is a request-scoped holder set by `TokenValidator::validate_request()` when a Bearer token is validated. It records the OAuth `client_id` (and lazily resolves a snapshot `client_name`) so Premium's logger can attribute each row to the connection that made the call. Public accessors `ConnectionContext::client_id()` / `client_name()` are how add-ons read it — true end-user IPs are not obtainable for MCP calls (requests originate from the assistant's servers), so the OAuth connection is the meaningful "who" signal.

**Payload capture (Premium, 1.2.0+):** Premium captures `input` and `output` (success result only). Both are byte-capped by default (`Logger::DEFAULT_PAYLOAD_LIMIT`, 65535) with a `…[truncated, N more characters]` marker; truncated payloads render raw. Filters: `albert/premium/logging/full_capture` (bool, default false — store uncapped; the Activity Log page shows a warning when active) and `albert/premium/logging/payload_limit` (int bytes).

**Admin surfaces:**
- Dashboard widget: "Recent Activity" showing most recent executions with status pills
- Abilities page: "Last run" line in each ability's expanded details with status pill + upsell when Premium is not active

### Current Abilities

| ID | Description | Group |
|----|-------------|-------|
| `albert/find-posts` | Find posts with filters | posts |
| `albert/view-post` | View a single post | posts |
| `albert/create-post` | Create a new post | posts |
| `albert/update-post` | Update existing post | posts |
| `albert/delete-post` | Delete a post | posts |
| `albert/edit-post-block` | Replace one block in a post by path | posts |
| `albert/add-post-block` | Insert one block into a post (before/after/inside) | posts |
| `albert/remove-post-block` | Delete one block from a post by path | posts |
| `albert/move-post-block` | Reorder one block within a post by path | posts |
| `albert/find-pages` | Find pages | pages |
| `albert/view-page` | View a single page | pages |
| `albert/create-page` | Create a page | pages |
| `albert/update-page` | Update a page | pages |
| `albert/delete-page` | Delete a page | pages |
| `albert/edit-page-block` | Replace one block in a page by path | pages |
| `albert/add-page-block` | Insert one block into a page (before/after/inside) | pages |
| `albert/remove-page-block` | Delete one block from a page by path | pages |
| `albert/move-page-block` | Reorder one block within a page by path | pages |
| `albert/find-users` | Find users | users |
| `albert/view-user` | View a single user | users |
| `albert/create-user` | Create a user | users |
| `albert/update-user` | Update a user | users |
| `albert/delete-user` | Delete a user | users |
| `albert/find-media` | Find media items | media |
| `albert/view-media` | View a single media item | media |
| `albert/upload-media` | Sideload media from URL | media |
| `albert/set-featured-image` | Set post featured image | media |
| `albert/find-taxonomies` | Find taxonomies | taxonomies |
| `albert/find-terms` | Find taxonomy terms | terms |
| `albert/view-term` | View a single term | terms |
| `albert/create-term` | Create a term | terms |
| `albert/update-term` | Update a term | terms |
| `albert/delete-term` | Delete a term | terms |
| `albert/woo-find-products` | Search/list WooCommerce products | products |
| `albert/woo-view-product` | View a single product | products |
| `albert/woo-find-orders` | Search/list WooCommerce orders | orders |
| `albert/woo-view-order` | View a single order | orders |
| `albert/woo-find-customers` | Search/list WooCommerce customers | customers |
| `albert/woo-view-customer` | View a single customer | customers |

## Development Commands

```bash
# Install dependencies
composer install

# Check coding standards
composer phpcs

# Auto-fix coding standards
composer phpcbf

# Run tests
composer test

# Regenerate the Mozart-scoped MCP adapter (run after bumping wordpress/mcp-adapter)
composer mozart

# Activate plugin
wp plugin activate albert
```

## Bundled MCP adapter (Mozart scoping)

`wordpress/mcp-adapter` (and its dep `wordpress/php-mcp-schema`) ship the `WP\MCP\*`
namespace. **WooCommerce bundles its own, older copy of the same package**, and whichever
plugin's autoloader registers first wins — so when WC is active, Albert's code would silently
run WooCommerce's `0.1.0` instead of its own `0.5.0` (a hard-to-spot bug: "unknown error" to the
LLM, failures not logged).

The fix is **dependency scoping with Mozart** (`coenjacobs/mozart`), set up the standard way:

- Both packages are in **`require-dev`** — they exist only as the *source* to be scoped, and are
  never shipped unscoped.
- Mozart copies them into **`vendor-prefixed/`** (the WP-ecosystem convention), rewritten under the
  **`Albert\Vendor\`** prefix (`Albert\Vendor\WP\MCP\…`), and deletes the originals from `vendor/`
  (`delete_vendor_directories: true`). Config lives in `composer.json`'s `extra.mozart`.
- **Generated, not committed.** `vendor-prefixed/` is **gitignored** and regenerated automatically by
  the `post-install-cmd` / `post-update-cmd` Composer hooks (which run `mozart compose` +
  `composer dump-autoload`, guarded so they no-op on `--no-dev`). `composer.lock` **is** committed, so
  every environment resolves identical versions — which also pins what Mozart generates.
- Autoloaded via the `Albert\\Vendor\\ => vendor-prefixed/` PSR-4 entry (Mozart 1.1.x has no
  `generate_autoloader`, so a Composer PSR-4 entry is the documented method).
- Albert's own code references `Albert\Vendor\WP\MCP\…` (never bare `WP\MCP\…`), so it always runs its
  own copy regardless of WooCommerce. Verify:
  `wp eval 'echo Albert\Vendor\WP\MCP\Core\McpAdapter::VERSION;'` → `0.5.0`.
- `vendor-prefixed/` is outside `src/` so the gates skip it naturally; it's also excluded in
  `phpcs.xml.dist` / `phpstan.neon` for the bare-invocation case.
- **Bumping `wordpress/mcp-adapter`:** `composer update wordpress/mcp-adapter` — the post-update hook
  regenerates `vendor-prefixed/` and the lock pins it. Nothing to hand-commit (it's gitignored).
- **Release/CI:** `release.yml` installs with dev (hook generates `vendor-prefixed/`), then
  `--no-dev` (strips dev + the unscoped packages), and ships `vendor-prefixed/`.
- **Caveat (not handled by scoping):** the adapter still fires the global hooks `mcp_adapter_init` /
  `wp_mcp_init`. Harmless while WooCommerce's MCP *feature* is disabled; if it's ever enabled
  alongside Albert, those hook names need prefixing too.

## Development Guidelines

### Code Standards
- Follow WordPress Coding Standards (enforced by PHPCS)
- Use PHP 7.4+ type declarations
- Implement `Hookable` interface for components with hooks

### JavaScript
- **Never use jQuery** - use vanilla ES6+ JavaScript
- Use module pattern for organization
- Use `fetch` API for HTTP requests

### Security
- Validate and sanitize all input
- Use capability checks (`current_user_can()`)
- Use nonces for form submissions
- OAuth tokens for API authentication

### Version Control
- **Never commit without explicit request**
- **Never bump version without approval**
- **Version bumps only happen in release branches** — never on `development`, feature branches, or `main`
- Run `composer phpcs` before committing

## Known Compatibility Issues

### WooCommerce mcp-adapter timing bug (admin pages)

**Affected versions:** WooCommerce 10.4+ (ships `wordpress/mcp-adapter`)

**Symptom:** `_doing_it_wrong` notices for `mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, and `mcp-adapter/execute-ability` on Albert admin pages when WooCommerce is active.

**Root cause:** The mcp-adapter's `DefaultServerFactory` hooks `register_default_abilities()` on `wp_abilities_api_init` — a one-shot action. On Albert admin pages, `wp_get_abilities()` fires that action during page render (before `rest_api_init`). WooCommerce then preloads REST data via `Settings::add_component_settings()` → `rest_preload_api_request()`, which triggers `rest_api_init` → `McpAdapter::init()` → `mcp_adapter_init` → `DefaultServerFactory::create()`. The factory calls `wp_get_ability()` for its three tools, but they were never registered because `wp_abilities_api_init` already fired. The upstream fix would be for `maybe_create_default_server()` to check `did_action('wp_abilities_api_init')` and call `register_default_abilities()` directly if the action already fired.

**Our fix:** `Plugin::init()` only calls `McpAdapter::instance()` when `! is_admin()`. REST API requests (`/wp-json/...`) have `is_admin() === false`, so the adapter initializes normally. Admin pages skip initialization entirely, avoiding the timing conflict. The adapter is not needed on admin pages — Albert only needs it for serving MCP REST endpoints.

**If this breaks in the future:** The fix relies on `is_admin()` being `false` for REST API requests. If WordPress changes this behavior, `McpAdapter::instance()` may need to be deferred differently. Check `wp-includes/load.php` for `is_admin()` definition.

## Ongoing Work
