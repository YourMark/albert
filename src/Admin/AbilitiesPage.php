<?php
/**
 * Abilities Admin Page
 *
 * Single unified page that lists every registered ability in a flat,
 * client-filterable list. Replaces the category-grouped Core/ACF/WooCommerce
 * pages from 1.0.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.1.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\Core\AnnotationPresenter;

/**
 * AbilitiesPage class
 *
 * Renders the Albert → Abilities admin page: a sticky filter toolbar
 * (search + category + supplier + view toggle) followed by a flat list
 * of every registered ability with per-row toggle and expandable details.
 *
 * All filtering, pagination, and row expansion happens client-side in
 * assets/js/admin-settings.js; the server just renders every row once
 * and relies on the standard WordPress Settings API for persistence.
 *
 * @since 1.1.0
 */
class AbilitiesPage implements Hookable {

	/**
	 * Option name for storing disabled abilities (blocklist).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OPTION_NAME = 'albert_disabled_abilities';

	/**
	 * Settings API option group.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const OPTION_GROUP = 'albert_settings';

	/**
	 * Admin page slug.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const PAGE_SLUG = 'albert-abilities';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the submenu page under Albert.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'albert',
			__( 'Abilities', 'albert-ai-butler' ),
			__( 'Abilities', 'albert-ai-butler' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register the disabled-abilities setting.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		// WP 6.9+ Abilities API required.
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			?>
			<div class="wrap albert-wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'WordPress 6.9+ Required', 'albert-ai-butler' ); ?></strong>
						<?php esc_html_e( 'The Abilities API requires WordPress 6.9 or later. Please update WordPress to use this feature.', 'albert-ai-butler' ); ?>
					</p>
				</div>
			</div>
			<?php
			return;
		}

		$abilities          = self::collect_abilities();
		$disabled_abilities = self::get_disabled_abilities();
		$categories         = self::collect_category_options( $abilities );
		$suppliers          = self::collect_supplier_options( $abilities );
		$enabled_count      = 0;
		foreach ( $abilities as $row ) {
			if ( ! in_array( $row['id'], $disabled_abilities, true ) ) {
				++$enabled_count;
			}
		}
		$total_count = count( $abilities );
		?>
		<div class="wrap albert-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors(); ?>

			<div class="albert-abilities-page">
				<header class="albert-abilities-header">
					<p class="albert-abilities-intro">
						<?php esc_html_e( 'Enable or disable the abilities AI assistants can call. Each row is labelled with what it can do — read data, make changes, or delete data — so you can decide at a glance which to allow.', 'albert-ai-butler' ); ?>
					</p>
				</header>

				<?php
				/*
				 * Toolbar sits OUTSIDE the form on purpose. Its inputs are
				 * client-side filters, not settings fields; leaving them
				 * inside <form action="options.php"> caused Enter in the
				 * search box to submit the settings form unexpectedly.
				 */
				$this->render_toolbar( $categories, $suppliers, $enabled_count, $total_count );
				?>

				<form method="post" action="options.php" id="albert-form" class="albert-abilities-form">
					<?php settings_fields( self::OPTION_GROUP ); ?>
					<input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>" value="" />

					<div class="albert-abilities-list" id="albert-abilities-list" role="list">
						<?php foreach ( $abilities as $row ) { ?>
							<?php $this->render_ability_row( $row, $disabled_abilities ); ?>
						<?php } ?>

						<p class="albert-abilities-empty" hidden>
							<?php esc_html_e( 'No abilities match your filters.', 'albert-ai-butler' ); ?>
						</p>
					</div>

					<nav class="albert-abilities-pagination" aria-label="<?php esc_attr_e( 'Abilities pagination', 'albert-ai-butler' ); ?>" hidden>
						<button type="button" class="button albert-pagination-prev" data-direction="prev">
							<?php esc_html_e( 'Previous', 'albert-ai-butler' ); ?>
						</button>
						<span class="albert-pagination-pages" aria-live="polite"></span>
						<button type="button" class="button albert-pagination-next" data-direction="next">
							<?php esc_html_e( 'Next', 'albert-ai-butler' ); ?>
						</button>
					</nav>

					<div class="albert-abilities-submit">
						<?php submit_button( __( 'Save Changes', 'albert-ai-butler' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the sticky filter toolbar.
	 *
	 * @param array<string, string> $categories    Category slug => label.
	 * @param array<string, string> $suppliers     Supplier slug => label.
	 * @param int                   $enabled_count Number of currently-enabled abilities.
	 * @param int                   $total_count   Total ability count.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function render_toolbar( array $categories, array $suppliers, int $enabled_count, int $total_count ): void {
		?>
		<div class="albert-abilities-toolbar" role="region" aria-label="<?php esc_attr_e( 'Filter abilities', 'albert-ai-butler' ); ?>">
			<div class="albert-toolbar-filters">
				<label class="albert-toolbar-field albert-toolbar-field--search">
					<span class="albert-toolbar-label"><?php esc_html_e( 'Search', 'albert-ai-butler' ); ?></span>
					<input
						type="search"
						id="albert-abilities-search"
						class="albert-search"
						placeholder="<?php esc_attr_e( 'Search by name, description, or ID', 'albert-ai-butler' ); ?>"
						aria-controls="albert-abilities-list"
						autocomplete="off"
					/>
				</label>

				<label class="albert-toolbar-field">
					<span class="albert-toolbar-label"><?php esc_html_e( 'Category', 'albert-ai-butler' ); ?></span>
					<select id="albert-abilities-filter-category" class="albert-filter-category">
						<option value=""><?php esc_html_e( 'All categories', 'albert-ai-butler' ); ?></option>
						<?php foreach ( $categories as $slug => $label ) { ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php } ?>
					</select>
				</label>

				<label class="albert-toolbar-field">
					<span class="albert-toolbar-label"><?php esc_html_e( 'Supplier', 'albert-ai-butler' ); ?></span>
					<select id="albert-abilities-filter-supplier" class="albert-filter-supplier">
						<option value=""><?php esc_html_e( 'All suppliers', 'albert-ai-butler' ); ?></option>
						<?php foreach ( $suppliers as $slug => $label ) { ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php } ?>
					</select>
				</label>
			</div>

			<div class="albert-toolbar-meta">
				<div class="albert-view-toggle" role="group" aria-label="<?php esc_attr_e( 'View mode', 'albert-ai-butler' ); ?>">
					<button type="button" class="albert-view-toggle-btn is-active" data-view="list" aria-pressed="true">
						<?php esc_html_e( 'List', 'albert-ai-butler' ); ?>
					</button>
					<button type="button" class="albert-view-toggle-btn" data-view="paginated" aria-pressed="false">
						<?php esc_html_e( 'Paginated', 'albert-ai-butler' ); ?>
					</button>
				</div>
				<p
					class="albert-toolbar-stats"
					id="albert-abilities-stats"
					aria-live="polite"
					<?php /* translators: 1: visible count, 2: total count, 3: enabled count. */ ?>
					data-template-all="<?php esc_attr_e( 'Showing %1$s of %2$s · %3$s enabled', 'albert-ai-butler' ); ?>"
					data-total="<?php echo esc_attr( (string) $total_count ); ?>"
					data-enabled="<?php echo esc_attr( (string) $enabled_count ); ?>"
				>
					<?php
					printf(
						/* translators: 1: visible count, 2: total count, 3: enabled count. */
						esc_html__( 'Showing %1$s of %2$s · %3$s enabled', 'albert-ai-butler' ),
						esc_html( (string) $total_count ),
						esc_html( (string) $total_count ),
						esc_html( (string) $enabled_count )
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single ability row.
	 *
	 * @param array<string, mixed> $row                Normalized ability row data.
	 * @param array<string>        $disabled_abilities List of disabled ability ids.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function render_ability_row( array $row, array $disabled_abilities ): void {
		$id            = (string) $row['id'];
		$label         = (string) $row['label'];
		$description   = (string) $row['description'];
		$category_slug = (string) $row['category_slug'];
		$category_lbl  = (string) $row['category_label'];
		$supplier_slug = (string) $row['supplier_slug'];
		$supplier_lbl  = (string) $row['supplier_label'];
		$annotations   = (array) $row['annotations'];
		$chips         = AnnotationPresenter::chips_for( $annotations, $id );
		$is_destruct   = AnnotationPresenter::is_destructive( $annotations, $id );
		$is_enabled    = ! in_array( $id, $disabled_abilities, true );

		$dom_id     = 'albert-ability-' . sanitize_html_class( str_replace( '/', '-', $id ) );
		$details_id = $dom_id . '-details';
		$toggle_id  = $dom_id . '-toggle';

		$search_haystack = strtolower( $label . ' ' . $description . ' ' . $id );
		?>
		<div
			class="ability-row"
			role="listitem"
			data-ability-id="<?php echo esc_attr( $id ); ?>"
			data-category="<?php echo esc_attr( $category_slug ); ?>"
			data-supplier="<?php echo esc_attr( $supplier_slug ); ?>"
			data-search="<?php echo esc_attr( $search_haystack ); ?>"
			data-destructive="<?php echo $is_destruct ? '1' : '0'; ?>"
		>
			<input type="hidden" name="albert_presented_abilities[]" value="<?php echo esc_attr( $id ); ?>" />

			<div class="ability-row-main">
				<button
					type="button"
					class="ability-row-expand"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $details_id ); ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ability label. */ __( 'Show details for %s', 'albert-ai-butler' ), $label ) ); ?>"
				>
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</button>

				<div class="ability-row-body">
					<label for="<?php echo esc_attr( $toggle_id ); ?>" class="ability-row-label"><?php echo esc_html( $label ); ?></label>
					<?php if ( '' !== $description ) { ?>
						<p class="ability-row-description"><?php echo esc_html( $description ); ?></p>
					<?php } ?>

					<?php if ( ! empty( $chips ) ) { ?>
						<ul class="ability-row-annotations" aria-label="<?php esc_attr_e( 'What this ability does', 'albert-ai-butler' ); ?>">
							<?php foreach ( $chips as $chip ) { ?>
								<?php $desc_id = $dom_id . '-chip-' . $chip['key'] . '-desc'; ?>
								<li
									class="ability-chip ability-chip--<?php echo esc_attr( $chip['tone'] ); ?>"
									tabindex="0"
									aria-describedby="<?php echo esc_attr( $desc_id ); ?>"
								>
									<?php if ( 'danger' === $chip['tone'] ) { ?>
										<span class="screen-reader-text"><?php esc_html_e( 'Warning: ', 'albert-ai-butler' ); ?></span>
									<?php } ?>
									<span class="dashicons <?php echo esc_attr( $chip['icon'] ); ?>" aria-hidden="true"></span>
									<span class="ability-chip-label"><?php echo esc_html( $chip['label'] ); ?></span>
									<span
										class="ability-chip-desc"
										id="<?php echo esc_attr( $desc_id ); ?>"
										role="tooltip"
									><?php echo esc_html( $chip['description'] ); ?></span>
								</li>
							<?php } ?>
						</ul>
					<?php } ?>
				</div>

				<div class="ability-row-toggle">
					<label class="albert-toggle" for="<?php echo esc_attr( $toggle_id ); ?>">
						<input
							type="checkbox"
							id="<?php echo esc_attr( $toggle_id ); ?>"
							class="ability-row-checkbox"
							name="albert_enabled_on_page[]"
							value="<?php echo esc_attr( $id ); ?>"
							<?php checked( $is_enabled ); ?>
						/>
						<span class="albert-toggle-slider" aria-hidden="true"></span>
						<span class="screen-reader-text">
							<?php echo esc_html( sprintf( /* translators: %s: ability label. */ __( 'Enable %s', 'albert-ai-butler' ), $label ) ); ?>
						</span>
					</label>
				</div>
			</div>

			<div class="ability-row-details" id="<?php echo esc_attr( $details_id ); ?>" hidden>
				<dl class="ability-row-details-grid">
					<dt><?php esc_html_e( 'Ability ID', 'albert-ai-butler' ); ?></dt>
					<dd>
						<code class="ability-row-id"><?php echo esc_html( $id ); ?></code>
					</dd>

					<dt><?php esc_html_e( 'Supplier', 'albert-ai-butler' ); ?></dt>
					<dd><?php echo esc_html( $supplier_lbl ); ?></dd>

					<?php if ( '' !== $category_lbl ) { ?>
						<dt><?php esc_html_e( 'Category', 'albert-ai-butler' ); ?></dt>
						<dd><?php echo esc_html( $category_lbl ); ?></dd>
					<?php } ?>
				</dl>
			</div>
		</div>
		<?php
	}

	/**
	 * Collect every registered ability into normalized row data.
	 *
	 * Sorted by category label, then ability label.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.1.0
	 */
	private static function collect_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		$all        = wp_get_abilities();
		$categories = function_exists( 'wp_get_ability_categories' ) ? wp_get_ability_categories() : [];
		$rows       = [];

		foreach ( $all as $ability ) {
			$id    = method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
			$label = method_exists( $ability, 'get_label' ) ? $ability->get_label() : $id;
			$desc  = method_exists( $ability, 'get_description' ) ? $ability->get_description() : '';
			$cat   = method_exists( $ability, 'get_category' ) ? $ability->get_category() : '';
			$meta  = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : [];

			$category_label = self::resolve_category_label( $cat, $categories );
			$source         = AbilitiesRegistry::get_ability_source( $id );
			$annotations    = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];

			$rows[] = [
				'id'             => (string) $id,
				'label'          => (string) $label,
				'description'    => (string) $desc,
				'category_slug'  => (string) $cat,
				'category_label' => $category_label,
				'supplier_slug'  => (string) $source['slug'],
				'supplier_label' => (string) $source['label'],
				'annotations'    => $annotations,
			];
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$cat = strcasecmp( $a['category_label'], $b['category_label'] );
				if ( 0 !== $cat ) {
					return $cat;
				}
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $rows;
	}

	/**
	 * Build the category filter dropdown options.
	 *
	 * @param array<int, array<string, mixed>> $abilities Collected rows.
	 *
	 * @return array<string, string>
	 * @since 1.1.0
	 */
	private static function collect_category_options( array $abilities ): array {
		$options = [];
		foreach ( $abilities as $row ) {
			$slug = (string) $row['category_slug'];
			if ( '' === $slug ) {
				continue;
			}
			if ( ! isset( $options[ $slug ] ) ) {
				$options[ $slug ] = (string) $row['category_label'];
			}
		}
		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );
		return $options;
	}

	/**
	 * Build the supplier filter dropdown options.
	 *
	 * @param array<int, array<string, mixed>> $abilities Collected rows.
	 *
	 * @return array<string, string>
	 * @since 1.1.0
	 */
	private static function collect_supplier_options( array $abilities ): array {
		$options = [];
		foreach ( $abilities as $row ) {
			$slug = (string) $row['supplier_slug'];
			if ( '' === $slug ) {
				continue;
			}
			if ( ! isset( $options[ $slug ] ) ) {
				$options[ $slug ] = (string) $row['supplier_label'];
			}
		}
		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );
		return $options;
	}

	/**
	 * Resolve a category slug to its human label.
	 *
	 * @param string               $slug       Category slug.
	 * @param array<string, mixed> $categories Map from wp_get_ability_categories().
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private static function resolve_category_label( string $slug, array $categories ): string {
		if ( '' === $slug ) {
			return '';
		}
		if ( isset( $categories[ $slug ] ) ) {
			$category = $categories[ $slug ];
			if ( is_object( $category ) && method_exists( $category, 'get_label' ) ) {
				return (string) $category->get_label();
			}
			if ( is_array( $category ) && isset( $category['label'] ) ) {
				return (string) $category['label'];
			}
		}
		return ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );
	}

	/**
	 * Sanitize settings on save.
	 *
	 * Computes the new disabled list from the hidden
	 * albert_presented_abilities[] / albert_enabled_on_page[] arrays, preserving
	 * any abilities that were not rendered on this request (future-proofing for
	 * addon pages that re-use the same option).
	 *
	 * @param mixed $input Raw option input (unused; we read the hidden arrays directly).
	 *
	 * @return array<int, string>
	 * @since 1.1.0
	 */
	public function sanitize_settings( $input ): array {
		unset( $input ); // Hidden trigger field only; actual values come from the arrays below.

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by Settings API; sanitized below.
		$presented_raw = isset( $_POST['albert_presented_abilities'] ) ? wp_unslash( $_POST['albert_presented_abilities'] ) : [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by Settings API; sanitized below.
		$enabled_raw = isset( $_POST['albert_enabled_on_page'] ) ? wp_unslash( $_POST['albert_enabled_on_page'] ) : [];

		$presented = array_map( 'sanitize_text_field', (array) $presented_raw );
		$enabled   = array_map( 'sanitize_text_field', (array) $enabled_raw );

		$presented = array_filter( $presented, [ $this, 'is_valid_ability_slug' ] );
		$enabled   = array_filter( $enabled, [ $this, 'is_valid_ability_slug' ] );

		$newly_disabled = array_diff( $presented, $enabled );

		$existing_disabled = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $existing_disabled ) ) {
			$existing_disabled = [];
		}
		$existing_disabled = array_map( 'sanitize_text_field', $existing_disabled );
		$existing_disabled = array_filter( $existing_disabled, [ $this, 'is_valid_ability_slug' ] );

		// Anything that was presented *and* is now enabled is no longer disabled.
		$existing_disabled = array_diff( $existing_disabled, $enabled );

		$disabled = array_values( array_unique( array_merge( $existing_disabled, $newly_disabled ) ) );

		update_option( 'albert_abilities_saved', true );

		return $disabled;
	}

	/**
	 * Get currently disabled abilities.
	 *
	 * On fresh install returns the default blocklist (Albert write abilities).
	 *
	 * @return array<int, string>
	 * @since 1.1.0
	 */
	public static function get_disabled_abilities(): array {
		$disabled = get_option( self::OPTION_NAME, [] );

		if ( empty( $disabled ) && ! get_option( 'albert_abilities_saved' ) ) {
			return AbilitiesRegistry::get_default_disabled_abilities();
		}

		return (array) $disabled;
	}

	/**
	 * Validate an ability slug.
	 *
	 * @param string $ability_slug Slug to validate.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	private function is_valid_ability_slug( string $ability_slug ): bool {
		return (bool) preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]+$/', $ability_slug );
	}

	/**
	 * Enqueue admin assets for this page only.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'albert_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			ALBERT_VERSION
		);

		wp_enqueue_script(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[],
			ALBERT_VERSION,
			true
		);

		wp_localize_script(
			'albert-admin',
			'albertAdmin',
			[
				'i18n' => [
					'copied'             => __( 'Copied!', 'albert-ai-butler' ),
					'copyFailed'         => __( 'Copy failed', 'albert-ai-butler' ),
					/* translators: 1: visible count, 2: total count, 3: enabled count. */
					'statsTemplate'      => __( 'Showing %1$s of %2$s · %3$s enabled', 'albert-ai-butler' ),
					/* translators: 1: current page number, 2: total page count. */
					'pageTemplate'       => __( 'Page %1$s of %2$s', 'albert-ai-butler' ),
					'destructiveConfirm' => __( 'This ability can permanently delete data. Are you sure you want to enable it?', 'albert-ai-butler' ),
					'noMatches'          => __( 'No abilities match your filters.', 'albert-ai-butler' ),
				],
			]
		);
	}
}
