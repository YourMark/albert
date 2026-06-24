# Abilities DataViews admin screen — implementation plan (v1.3.0)

**Target version:** 1.3.0 (feature)
**Branch:** `feature/abilities-dataviews` (off `development`, GitFlow)
**Status:** Approved approach; execution is **phase-gated** — each phase is summarized and
approved before it starts. No coding has begun.

---

## Context

The Albert **Abilities** admin screen (`Albert → Abilities`) currently renders abilities as a flat,
server-rendered PHP list (`src/Admin/AbilitiesPage.php` + vanilla ES6 `assets/js/admin-settings.js`),
with client-side search/filter/pagination and instant toggle saves over admin-ajax.

A high-fidelity design handoff (`design_handoff_abilities_dataviews/`) redesigns the screen around
the WordPress **DataViews** pattern — a compact table/grid with search, filters, sort, density,
table↔grid switch, bulk actions, pagination, and a right-docked **fly-in detail panel** per ability
(schema, required capability, annotations, and — in Premium — an advanced permission-rule builder).

This plan recreates the design in wp-admin using the real `@wordpress/dataviews` +
`@wordpress/components` packages, backed by the live WordPress Abilities API registry. It is the
first React surface in an otherwise vanilla plugin and introduces a JS build step.

### Confirmed decisions
1. **Build:** Adopt `@wordpress/scripts` + React + a real build step (DataViews).
2. **Advanced permission manager:** OUT of scope — ships in **Albert Premium Service**. Free exposes
   a documented JS extension seam and never builds the rule builder (Core→Addon boundary).
3. **Operation types:** 3-way **Read / Write / Delete** (from `meta.annotations`), not the
   prototype's 4-way read/create/update/delete.
4. **Required capability:** **best-effort derivation** (heuristic + override filter).

### Verified environment facts (checked live)
- WordPress **7.0**, PHP 8.4; `~/bin/wp`; dev site under Herd at `https://albert-dev.test`.
- `wp-dataviews` is **not** a registered core script handle; `wp-components`, `wp-element`,
  `wp-data`, `wp-hooks`, `wp-i18n`, `wp-compose`, `react`/`react-dom` **are**.
- `@wordpress/dependency-extraction-webpack-plugin` (bundled by wp-scripts ^32.4.1) lists
  `@wordpress/dataviews` in `BUNDLED_PACKAGES` → DataViews is **bundled into our JS**, its CSS into
  `build/style-index.css`. Do **not** depend on a `wp-dataviews` handle.
- `@wordpress/dataviews@16.0.1` exports **`filterSortAndPaginate`** and ships `build-style/style.css`.
- ~40 abilities registered (`wp_get_abilities()`); small dataset → client-side data mode.

### Overall approach
Keep `AbilitiesPage` as the page registrar (menu, `manage_options`, slug `albert-abilities`, the
existing `wp_ajax_albert_toggle_ability` handler + nonce). Swap its **rendering** from PHP-HTML to a
React mount node, enqueue a wp-scripts build, and inline the abilities dataset for first paint. The
React app uses `<DataViews>` in controlled-data mode with `filterSortAndPaginate`, plus a custom
right-docked fly-in. Toggles persist via the existing admin-ajax endpoint (single source of truth =
`albert_disabled_abilities` option), so `is_executable()`, the Premium logger, and default-disabled
logic keep working unchanged.

---

## Phase plan (approval-gated)

> Each phase: I post a short summary + scope, you approve, I implement, then I report results and the
> verification before the next phase. Quality gates (`composer phpcs`/`phpstan`, `npm run lint:js`)
> run once near the end (Phase 5), not per edit.

### Phase 1 — Build tooling & scaffolding ✅ DONE
**Goal:** a working JS build pipeline + clean asset structure, with the React root mounting an empty
placeholder behind an opt-in guard (legacy screen untouched).
**Asset structure (decided after review):** webpack compiles **JS only** (`assets/src/` →
`assets/build/js/`); CSS is authored plain in `assets/css/` and never imported from JS; the DataViews
vendor stylesheet is copied to `assets/build/css/`. Compiled output (`assets/build/`) is gitignored
and rebuilt in CI.
- `package.json` (root): `devDependencies` `@wordpress/scripts ^32.4.1`; `dependencies`
  `@wordpress/dataviews ^16.0.1`; scripts `build`/`start`/`lint:js`/`format:js` (plain `wp-scripts`).
  Externalized `@wordpress/*` packages not listed.
- `webpack.config.js`: spreads the wp-scripts default, entry `assets/src/abilities/index.js`,
  `output.path` → `assets/build/js`. No CSS imports → JS bundle + `abilities.asset.php` only. A
  `CopyWebpackPlugin` (bundled with wp-scripts) file-copies the DataViews vendor stylesheet →
  `assets/build/css/dataviews.css` (plain copy, not routed through JS), so a single `wp-scripts build`
  produces everything.
- Source: `assets/src/abilities/index.js` (JS-only, mounts placeholder). Authored styles:
  `assets/css/admin-abilities.css`.
- Packaging: `assets/build/` gitignored; `package-lock.json` committed (for `npm ci`); `assets/src/`,
  `webpack.config.js`, `package*.json` excluded via `.distignore`; `release.yml` runs
  `npm ci && npm run build` in **both** jobs before packaging.
- Enqueue: `AbilitiesPage::enqueue_dataviews_assets()` (handles `albert-dataviews` → `albert-abilities`
  → `albert-abilities-app`) + guarded mount node via `use_dataviews()` (filter
  `albert/abilities/use_dataviews`, or `?dataviews=1`).
**Verified:** `npm run build` succeeds; `assets/build/js/abilities.asset.php` deps =
`react-jsx-runtime`/`wp-element`/`wp-i18n` (no `wp-dataviews`); `dataviews.css` copied; `php -l` clean;
all script/style handles register in WP 7.0.

### Phase 2 — PHP data layer (REST API)
**Goal:** the real abilities dataset + toggle persistence reach React via a proper REST API
(`@wordpress/api-fetch`), not admin-ajax.
- `build_dataviews_payload()` extending `collect_abilities()` (`AbilitiesPage.php:670`): adds
  `enabled` (from `get_disabled_abilities()`), `operation` (existing `annotation_slug`), `inputs`
  (from `WP_Ability::get_input_schema()` properties — verify getter on WP 7.0), `output`
  (`get_output_schema()`), `cap` (below), annotation chips (`AnnotationPresenter::chips_for()`).
- Best-effort capability resolver `AbilitiesRegistry::resolve_required_capability()` —
  optional declared `meta['capability']` → heuristic by supplier/category/operation → filterable via
  new `albert/abilities/required_capability`.
- **New REST controller** `Albert\Admin\Rest\AbilitiesController` (`src/Admin/Rest/`, registered on
  `rest_api_init`), namespace `albert/v1`, every route `permission_callback` =
  `current_user_can('manage_options')`, args sanitized/validated:
  - `GET /abilities` → `{ abilities, categories, suppliers, counts }` (the payload above).
  - `POST /abilities/(?P<namespace>[\w-]+)/(?P<name>[\w-]+)` body `{ enabled: bool }` → toggles one
    ability (id reconstructed `namespace/name` to handle the slash in ability ids), returns the
    updated row.
- **Refactor** the disabled-option mutation out of `ajax_toggle_ability()` into a shared
  `set_ability_enabled( string $id, bool $enabled )` so the REST controller and the legacy admin-ajax
  handler write the same `albert_disabled_abilities` option identically (single source of truth).
- Enqueue: add `@wordpress/api-fetch` (externalizes to `wp-api-fetch`); inline only the REST root +
  `wp_rest` nonce + i18n (no dataset, no admin-ajax). React fetches the list via `apiFetch` on mount.
**Verify:** `GET /wp-json/albert/v1/abilities` returns all abilities (count == `wp_get_abilities()`);
toggle POST persists and round-trips; data renders in the React root (temporary count dump).

### Phase 3 — DataViews list (replaces the old list)
**Goal:** full list parity + the DataViews UX.
- **Import from `@wordpress/dataviews/wp`** (NOT `@wordpress/dataviews`). Required for plugins built
  with `@wordpress/scripts`: the `/wp` build externalizes DataViews' nested `@wordpress/*` deps to the
  shared `wp.*` globals, so it reuses core's React/components/private-APIs. The default entry bundles
  relative copies → duplicate React + broken `@wordpress/private-apis` lock/unlock. (Verified in the
  installed 16.0.1 package + official docs.)
- `<DataViews>` in data mode: `view` state + `filterSortAndPaginate(items, view, fields)`; `perPage:9`.
- Fields: `label` (label + mono id + 1-line desc, `enableGlobalSearch`), `id`/`description`
  (search only), `category` (sort + `elements`), `operation` (sort + read/write/delete badge),
  `supplier` (`elements` core/acf/woo), `status` (enabled/disabled + in-row instant-save toggle).
- Filters: Category, Operation, Status, Supplier. Sort: Name/Category/Operation. Density + table|grid
  via `defaultLayouts={table, grid}`.
- Actions: primary `details` (opens fly-in) + bulk `enable`/`disable` (per-id `apiFetch` POST to
  `albert/v1/abilities/{namespace}/{name}`). Toggle/checkbox `stopPropagation`.
- Swap `render_page()` to the mount node; remove the feature guard. Keep `ajax_toggle_ability()`.
**Verify:** search/filter/sort/density/grid/pagination/bulk all work; toggle persists across reload
(`albert_disabled_abilities` updated); browser console clean.

### Phase 4 — Fly-in detail panel + Premium seam
**Goal:** the right-docked detail panel and the Premium extension point.
- Custom drawer (`panel/FlyInPanel.jsx`): backdrop + 452px panel, slide+fade gated by
  `@media (prefers-reduced-motion: no-preference)`; `role="dialog" aria-modal`, focus trap /
  focus-on-mount / focus-return via `@wordpress/compose`, Escape to close.
- Sections in order: Enabled (`ToggleControl`, instant-save) · Description · Category + Required
  capability · **[Premium seam]** · Input schema · Returns · Annotations. Footer = single **Close**.
- Premium seam: `applyFilters('albert.abilities.panel_sections', [], ability, api)` rendering
  `{ id, priority, render({ability, api}) }` fills; Premium enqueues on the page hook depending on
  `['albert-abilities','wp-hooks']`. (Mechanism choice — see Open Questions #1.)
**Verify:** panel opens with slide+fade; instant-save toggle; sections + required cap render; seam
empty in Free; keyboard a11y (trap/Escape/return); reduced-motion respected.

### Phase 5 — Docs, cleanup, gates
**Goal:** ship-ready.
- Update `.claude/rules/code-style.md` (sanctioned build-step/React/SCSS exception for admin React).
- Add build commands to `CLAUDE.md` / `.claude/CLAUDE.md`; document the
  `albert.abilities.panel_sections` JS contract and the `albert/abilities/required_capability` filter.
- Remove now-dead abilities-list code in `admin-settings.js` / `admin-settings.css` **after** verifying
  no other admin page depends on it.
- Bump version to **1.3.0** (only when cutting the release branch, per project rules — not on the
  feature branch).
- Run `composer phpcs`, `composer phpstan`, `npm run lint:js`; full browser verification incl. Premium
  seam with `albert-premium-service` active.

---

## Open questions / decisions
1. **Premium seam mechanism:** `@wordpress/hooks` `applyFilters` (recommended) vs `@wordpress/components`
   SlotFill. Reversible; affects the documented Premium contract.
2. **Old vanilla assets:** remove the dead abilities-list JS/CSS in Phase 5, or keep (verify no other
   admin page references first).
3. **Packaging:** confirm build `build/` in CI and ship the artifact (vs committing it).

## Risks (verify in-phase, before coding configs)
- DataViews 16.0.1 API surface — read `node_modules/@wordpress/dataviews/build-module/types.d.ts`
  post-install to lock `Field`/`View`/`Action`/`<DataViews>` props + `filterSortAndPaginate` return shape.
- `WP_Ability` getters on WP 7.0 (`get_input_schema`/`get_output_schema`) — confirm names.
- DataViews CSS import specifier under sass-loader — confirm `@wordpress/dataviews/build-style/style.css`.
- Best-effort cap accuracy — heuristic; the override filter is the escape hatch.

## Key files
- `src/Admin/AbilitiesPage.php` — enqueue rewrite, mount node, payload builder, reuse of toggle ajax.
- `src/Core/AbilitiesRegistry.php` — `resolve_required_capability()` + filter.
- `package.json`, `webpack.config.js` (new).
- `assets/src/abilities/AbilitiesApp.jsx`, `panel/FlyInPanel.jsx`, supporting modules (new).
- `assets/js/albert-admin-utils.js` — reuse `Albert.ajax` POST helper.
- `release.yml`, `.gitignore`, `.distignore` — packaging.
</content>
</invoke>
