# Extending the Abilities screen

The `Albert → Abilities` screen (the `@wordpress/dataviews` admin app) exposes extension points so
add-ons — notably **Albert Premium Service** — can add UI to the detail fly-in and enforce custom
access rules, without Core depending on the add-on.

There are three seams:

| Seam | Type | Where it runs |
|---|---|---|
| `albert.abilities.panel_sections` | JS filter (`@wordpress/hooks`) | The detail fly-in, client-side |
| `albert/abilities/check_permission` | PHP filter | Every ability's `permission_callback` |
| `albert/abilities/required_capability` | PHP filter | When the screen resolves an ability's capability |

---

## 1. Inject UI into the detail fly-in — `albert.abilities.panel_sections`

The fly-in renders any sections returned by this JS filter, between the "Required capability" cards
and the "Input schema" section.

```js
import { addFilter } from '@wordpress/hooks';
import { createElement } from '@wordpress/element';

addFilter(
	'albert.abilities.panel_sections',
	'albert-premium/advanced-permissions',
	( sections, ability, api ) => [
		...sections,
		{
			id: 'advanced-permissions',
			priority: 10, // optional; sections render in ascending priority (default 10)
			render: ( { ability, api } ) =>
				createElement( AdvancedPermissions, { ability, roles: api.roles } ),
		},
	]
);
```

- **`ability`** — the normalized row: `{ id, label, description, category, categoryLabel, supplier,
  supplierLabel, operation, enabled, capability, capabilityRoles, inputs, output, annotations,
  lastUsed }`.
- **`api`** — `{ ability, roles }`. `roles` is `[ { value: <slug>, label } ]` for every site role
  (use it for a role picker). For per-user pickers, query users via core REST `/wp/v2/users`.
- Each entry is `{ id: string, priority?: number, render: ({ ability, api }) => Element }`.

### Enqueuing the add-on script

Enqueue on the abilities page hook, depending on Core's app bundle and `wp-hooks` so the filter is
registered before the app renders:

```php
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'albert_page_albert-abilities' !== $hook ) {
		return;
	}
	$asset = require PREMIUM_DIR . 'build/abilities-ext.asset.php';
	wp_enqueue_script(
		'albert-premium-abilities',
		PREMIUM_URL . 'build/abilities-ext.js',
		array_merge( $asset['dependencies'], [ 'albert-abilities-app', 'wp-hooks' ] ),
		$asset['version'],
		true
	);
}, 20 );
```

### Saving

Rules **auto-save** (matching the panel's instant-save Enabled toggle) — persist on change via the
add-on's own REST route/option. There is no Save button and no footer seam.

---

## 2. Enforce custom access — `albert/abilities/check_permission`

Every registered ability's `permission_callback` is wrapped so this filter runs after the ability's
own capability check. It applies to **all** abilities (Albert, WooCommerce, ACF, third-party).

```php
add_filter(
	'albert/abilities/check_permission',
	function ( $result, string $ability_id, int $user_id ) {
		// $result is the baseline (true, or a WP_Error from the capability check).
		// Return true to allow, or a WP_Error to deny. Any other value = denied.
		if ( /* a stored rule denies $ability_id for $user_id */ false ) {
			return new WP_Error(
				'albert_permission_rule',
				__( 'Access denied by a permission rule.', 'albert-premium' ),
				[ 'status' => 403 ]
			);
		}
		return $result;
	},
	10,
	3
);
```

- Runs inside `WP_Ability::check_permissions()`, with the **connected user already set** (the OAuth
  user for MCP calls, via `OAuth\Server\TokenValidator`), so `get_current_user_id()` and its roles
  are the meaningful "who".
- WordPress treats the callback as **denied unless it returns exactly `true`** — so return `true`
  to allow, a `WP_Error` to deny.
- Gates both discovery and execution. It's per-user, so the ability stays in the registry but is
  denied for that user (different from the global enable/disable toggle, which unregisters).

### Suggested rule model (add-on owns storage + evaluation)

Store per ability `{ mode: 'capability' | 'custom', rules: [ … ] }`, where each rule is
`{ action: 'enable' | 'disable', subject: 'role' | 'user', op: 'is' | 'is_not', value }`, evaluated
top-to-bottom. In `mode: 'capability'` the filter returns `$result` unchanged.

---

## 3. Correct the displayed capability — `albert/abilities/required_capability`

The screen shows a best-effort "Required capability". Override it precisely:

```php
add_filter(
	'albert/abilities/required_capability',
	function ( string $cap, string $ability_id, \WP_Ability $ability ) {
		return $ability_id === 'my/ability' ? 'manage_my_thing' : $cap;
	},
	10,
	3
);
```
