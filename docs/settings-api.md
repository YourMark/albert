# Albert Settings API

Albert ships a single **Settings** admin screen that Free and every add-on
contribute to. Free renders one `<form>`, validates one nonce, runs one save
loop, and persists each field to `wp_options`.

There are two APIs:

1. **`albert_register_setting()`** — the simple, public API add-ons should use.
   Registers a single field on the shared Albert Settings card.
2. **`albert_register_settings_section()`** — the advanced API for add-ons that
   need a whole card of their own (custom render callbacks, conditional
   visibility, etc.). Free uses it internally for the Licenses card.

## Public API: `albert_register_setting()`

Hook `albert/settings/register` and register each setting as a flat array:

```php
add_action( 'albert/settings/register', static function (): void {
    if ( ! function_exists( 'albert_register_setting' ) ) {
        return;
    }

    albert_register_setting( [
        'title'       => __( 'Retention (days)', 'albert-premium-service' ),
        'option_name' => 'premium_activity_log_retention_days',
        'type'        => 'number',
        'description' => __( 'How long to keep activity log entries before pruning.', 'albert-premium-service' ),
        'default'     => 30,
        'attributes'  => [ 'min' => 1, 'max' => 365, 'step' => 1 ],
        'badge'       => __( 'Premium', 'albert-premium-service' ),
    ] );
} );
```

Read the saved value anywhere with:

```php
$retention = (int) get_option( 'premium_activity_log_retention_days', 30 );
```

### Schema

| Key | Type | Required | Notes |
|-----|------|----------|-------|
| `title` | string | **yes** | Visible label above the input. |
| `option_name` | string | **yes** | Exact `wp_options` key used for storage. |
| `type` | string | **yes** | One of `text`, `url`, `number`, `textarea`, `select`, `checkbox`. |
| `description` | string | no | Help text rendered with the label. |
| `default` | mixed | no | Returned when no value is stored. |
| `options` | array | **yes for `select`** | `value => label` pairs. |
| `attributes` | array | no | Extra HTML attributes (e.g. `placeholder`, `min`, `max`, `step`). Reserved keys (`name`, `id`, `type`, `value`, `checked`) are ignored. |
| `badge` | string | no | Small pill rendered next to the label (e.g. `"Premium"`). |

The first call to `albert_register_setting()` lazily creates a shared
`albert/settings` card on the Settings page and appends the field to it.
Every subsequent call in the same request appends to the same card.

Missing a required key — or registering a `select` without `options` — logs a
`_doing_it_wrong()` notice and skips the field.

### Built-in field types

| Type | Renders | Default sanitizer |
|------|---------|-------------------|
| `text` | `<input type="text">` | `sanitize_text_field` |
| `url` | `<input type="url">` | `esc_url_raw` then `rtrim($v, '/')` |
| `number` | `<input type="number">` | `absint` (or `floatval` when `attributes.step` contains `.`) |
| `textarea` | `<textarea>` | `sanitize_textarea_field` |
| `select` | `<select>` | Validates against `options` keys; falls back to `default`. |
| `checkbox` | hidden `0` + visible `1` | Bool — `true` only when raw value is `'1' / 1 / true`. |

The checkbox renders a paired hidden input so unchecked submissions still
post a `0`. Without it WordPress would never know the user unchecked the box.

## MCP external URL filter

The MCP endpoint URL is rendered on the **Connections** screen. There is no
admin input for overriding it — sites that need a different host (tunnels,
reverse proxies, local development) hook the `albert/mcp/external_url`
filter:

```php
add_filter( 'albert/mcp/external_url', static function (): string {
    if ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) {
        return 'https://albert.test';
    }
    return '';
} );
```

The filter must return a fully-qualified URL including the scheme
(`https://…` or `http://…`). Return an empty string to disable the override
and use `rest_url()` as normal.

Returned values are validated with `wp_http_validate_url()`. If the filter
returns a non-empty string that fails validation, Albert logs a
`_doing_it_wrong()` notice, falls back to the default endpoint, and the
Connections screen displays a warning explaining what is wrong.

## Advanced API: `albert_register_settings_section()`

Use this when a single field isn't enough — for example an activity log card
that includes a table plus two controls, or a feature toggle that pulls in a
custom render callback.

```php
add_action( 'albert/settings/register', static function (): void {
    if ( ! function_exists( 'albert_register_settings_section' ) ) {
        return;
    }

    albert_register_settings_section( [
        'id'         => 'premium/activity-log',
        'title'      => __( 'Activity Log', 'albert-premium-service' ),
        'priority'   => 20,
        'icon'       => 'list-view',
        'badge'      => __( 'Premium', 'albert-premium-service' ),
        'show_if'    => static fn (): bool => albert_has_valid_license( 'albert-premium-service' ),
        'capability' => 'manage_options',
        'fields'     => [
            [
                'id'      => 'enabled',
                'type'    => 'checkbox',
                'label'   => __( 'Enable activity log', 'albert-premium-service' ),
                'default' => true,
            ],
            [
                'id'          => 'retention_days',
                'type'        => 'number',
                'label'       => __( 'Retention (days)', 'albert-premium-service' ),
                'default'     => 30,
                'attributes'  => [ 'min' => 1, 'max' => 365, 'step' => 1 ],
                'show_if'     => static fn (): bool => (bool) get_option( 'premium_activity_log_enabled', true ),
            ],
        ],
    ] );
} );
```

### Section schema

| Key | Type | Required | Default | Notes |
|-----|------|----------|---------|-------|
| `id` | string | yes | — | Must contain `/` (namespace prefix). Reused id replaces the previous section. |
| `title` | string | yes | — | Heading shown in the section card. |
| `description` | string | no | `''` | Short paragraph below the title. |
| `priority` | int | no | `10` | Lower runs earlier. Free uses 50 for the shared Settings card and 9000 for Licenses. |
| `show_if` | callable | no | always-true | Returning `false` skips the section for both render and save. |
| `icon` | string | no | `''` | Dashicon slug **without** the `dashicons-` prefix. |
| `badge` | string | no | `''` | Pill rendered next to the title. |
| `capability` | string | no | `'manage_options'` | Required capability to view and save the section. |
| `fields` | array | yes | — | Indexed list of field arrays (see below). |

### Section-field schema

| Key | Type | Required | Notes |
|-----|------|----------|-------|
| `id` | string | yes | Combined with the section id to form the option name (slashes → underscores). |
| `type` | string | yes | One of `text`, `url`, `number`, `textarea`, `select`, `checkbox`, `custom`. |
| `label` | string | yes (except `custom`) | Visible label above the input. Custom fields may pass `''`. |
| `description` | string | no | Help text. |
| `default` | mixed | no | Returned when no value is stored. |
| `badge` | string | no | Pill rendered next to the label. |
| `show_if` | callable | no | Field-level conditional (same semantics as section `show_if`). |
| `render_callback` | callable | yes for `custom` | `function(array $field, mixed $current_value): void` — echo input HTML only. |
| `sanitize_callback` | callable | yes for `custom`, optional override otherwise | `function(mixed $raw): mixed`. Use `'__return_null'` to mark a custom field read-only. |
| `options` | array | yes for `select` | `value => label` pairs. |
| `attributes` | array | no | Extra HTML attributes (e.g. `placeholder`, `min`, `step`). |
| `option_name` | string | no | Use a literal `wp_options` key instead of the auto-generated one. |

### Option name resolution

By default the option name is `{section_id_with_slashes_replaced}_{field_id}`:

| Section id | Field id | Option name |
|------------|----------|-------------|
| `premium/activity-log` | `retention_days` | `premium_activity_log_retention_days` |

Pass an explicit `option_name` to override the auto-generated name.

### Custom fields

Custom fields are the escape hatch for inputs the built-ins can't model
(license tables, OAuth wizards, copy-to-clipboard widgets). Two callbacks
are required:

```php
'render_callback' => static function ( array $field, mixed $current_value ): void {
    // Echo the input HTML only. No <form>, no submit button, no <label>
    // wrapper (the renderer already added one if `label` is non-empty).
},

'sanitize_callback' => static fn ( $raw ) => is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '',
```

For a read-only custom field (display only, never saved), pass
`'__return_null'` as the sanitize callback. The save loop skips persistence
for that field.

If a render callback throws, the renderer catches the error, writes a line
to `error_log`, and prints an inline admin notice in place of the field.

## Hooks reference

| Hook | Type | Fires |
|------|------|-------|
| `albert/settings/register` | action | Before sections are collected. Add-ons hook here. |
| `albert/settings/sections` | filter | Final pass after the registry returns its list. |
| `albert/settings/saved` | action | After a successful save. Receives `array<option_name, sanitized_value>`. |
| `albert/mcp/external_url` | filter | Return a fully-qualified URL to override the MCP endpoint host. Empty string disables the override. Invalid URLs are ignored. |

## Versioning / feature detection

The functions live in Free. Add-ons that may load against an older Free
version should guard their calls:

```php
if ( ! function_exists( 'albert_register_setting' ) ) {
    return;
}
```
