<?php
/**
 * Settings Admin Page
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\Database\Installer;

/**
 * Settings class
 *
 * Manages the plugin settings page for configuration options
 * (MCP endpoint, developer settings, addon licenses).
 *
 * @since 1.0.0
 */
class Settings implements Hookable {

	/**
	 * Parent menu slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $parent_slug = 'albert';

	/**
	 * Settings page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'albert-settings';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_albert_save_settings', [ $this, 'handle_save_settings' ] );
	}

	/**
	 * Add settings page to WordPress admin menu.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			$this->parent_slug,
			__( 'Settings', 'albert-ai-butler' ),
			__( 'Settings', 'albert-ai-butler' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		$sections = $this->collect_sections();
		$renderer = new SettingsRenderer();
		?>
		<div class="wrap albert-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors( 'albert_settings' ); ?>

			<div class="albert-content-header">
				<p class="albert-content-description">
					<?php esc_html_e( 'Configure plugin settings and connection details.', 'albert-ai-butler' ); ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="albert-settings-form">
				<?php wp_nonce_field( 'albert_save_settings', 'albert_save_settings_nonce' ); ?>
				<input type="hidden" name="action" value="albert_save_settings" />

				<div class="albert-page">
					<?php
					$has_visible_section = false;
					foreach ( $sections as $section ) {
						$capability = isset( $section['capability'] ) && is_string( $section['capability'] ) && $section['capability'] !== ''
							? $section['capability']
							: 'manage_options';
						if ( ! current_user_can( $capability ) ) {
							continue;
						}
						$show_if = $section['show_if'] ?? null;
						if ( is_callable( $show_if ) && ! (bool) call_user_func( $show_if ) ) {
							continue;
						}

						// Sections with zero fields never render — skip them so the
						// submit button doesn't appear for an otherwise-empty page.
						$section_fields = isset( $section['fields'] ) && is_array( $section['fields'] ) ? $section['fields'] : [];
						if ( empty( $section_fields ) ) {
							continue;
						}

						$has_visible_section = true;
						$this->render_section( $section, $renderer );
					}
					?>

					<?php if ( $has_visible_section ) { ?>
						<div class="albert-settings-submit">
							<button type="submit" class="button button-primary">
								<?php esc_html_e( 'Save Settings', 'albert-ai-butler' ); ?>
							</button>
						</div>
					<?php } ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single settings section card.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $section  Normalised section.
	 * @param SettingsRenderer     $renderer Field renderer.
	 *
	 * @return void
	 */
	private function render_section( array $section, SettingsRenderer $renderer ): void {
		$icon        = isset( $section['icon'] ) && is_string( $section['icon'] ) ? $section['icon'] : '';
		$title       = isset( $section['title'] ) && is_string( $section['title'] ) ? $section['title'] : '';
		$badge       = isset( $section['badge'] ) && is_string( $section['badge'] ) ? $section['badge'] : '';
		$description = isset( $section['description'] ) && is_string( $section['description'] ) ? $section['description'] : '';
		$fields      = isset( $section['fields'] ) && is_array( $section['fields'] ) ? $section['fields'] : [];

		// Skip empty sections so the synthetic `albert/settings` card doesn't appear
		// as an empty box when no add-on has registered any settings.
		if ( empty( $fields ) ) {
			return;
		}
		?>
		<section class="albert-settings-card">
			<div class="albert-settings-card-header">
				<?php if ( $icon !== '' ) { ?>
					<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<?php } ?>
				<h2><?php echo esc_html( $title ); ?></h2>
				<?php if ( $badge !== '' ) { ?>
					<span class="albert-section-badge"><?php echo esc_html( $badge ); ?></span>
				<?php } ?>
			</div>
			<div class="albert-settings-card-body">
				<?php if ( $description !== '' ) { ?>
					<p class="albert-section-description"><?php echo esc_html( $description ); ?></p>
				<?php } ?>
				<?php
				foreach ( $fields as $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$show_if = $field['show_if'] ?? null;
					if ( is_callable( $show_if ) && ! (bool) call_user_func( $show_if ) ) {
						continue;
					}

					$option_name   = SettingsRegistry::get_option_name(
						isset( $section['id'] ) && is_string( $section['id'] ) ? $section['id'] : '',
						isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '',
						isset( $field['option_name'] ) && is_string( $field['option_name'] ) ? $field['option_name'] : null
					);
					$default_value = array_key_exists( 'default', $field ) ? $field['default'] : '';
					$current_value = get_option( $option_name, $default_value );

					$renderer->render_field( $field, $current_value );
				}
				?>
			</div>
		</section>
		<?php
	}

	/**
	 * Collect, register, and filter sections for both render and save paths.
	 *
	 * @since 1.1.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_sections(): array {
		$registry = SettingsRegistry::instance();

		// Register Free's built-in sections FIRST so the synthetic `albert/settings`
		// card created by `albert_register_setting()` can slot between them in the
		// rendered order.
		foreach ( SettingsBootstrap::get_builtin_sections() as $builtin ) {
			$registry->register_section( $builtin );
		}

		/**
		 * Fires before the unified settings sections are collected.
		 *
		 * Add-ons hook here to call {@see albert_register_setting()} or (for
		 * advanced use) {@see albert_register_settings_section()}.
		 *
		 * @since 1.1.0
		 */
		do_action( 'albert/settings/register' );

		/**
		 * Filters the final list of settings sections.
		 *
		 * Last chance to add, remove, or re-order sections before render or save.
		 *
		 * @since 1.1.0
		 *
		 * @param array<int, array<string, mixed>> $sections Normalised, sorted sections.
		 */
		$sections = apply_filters( 'albert/settings/sections', $registry->get_sections() );

		return is_array( $sections ) ? $sections : [];
	}

	/**
	 * Handle the unified settings form submission.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		check_admin_referer( 'albert_save_settings', 'albert_save_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'albert-ai-butler' ) );
		}

		$sections  = $this->collect_sections();
		$sanitizer = new SettingsSanitizer();
		$saved     = [];

		foreach ( $sections as $section ) {
			$capability = isset( $section['capability'] ) && is_string( $section['capability'] ) && $section['capability'] !== ''
				? $section['capability']
				: 'manage_options';
			if ( ! current_user_can( $capability ) ) {
				continue;
			}
			$show_if = $section['show_if'] ?? null;
			if ( is_callable( $show_if ) && ! (bool) call_user_func( $show_if ) ) {
				continue;
			}

			$section_id = isset( $section['id'] ) && is_string( $section['id'] ) ? $section['id'] : '';
			$fields     = isset( $section['fields'] ) && is_array( $section['fields'] ) ? $section['fields'] : [];

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$field_show_if = $field['show_if'] ?? null;
				if ( is_callable( $field_show_if ) && ! (bool) call_user_func( $field_show_if ) ) {
					continue;
				}

				$type     = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';
				$callback = $field['sanitize_callback'] ?? null;
				// Read-only custom fields opt out of persistence with the `__return_null` callback.
				if ( $type === 'custom' && is_string( $callback ) && $callback === '__return_null' ) {
					continue;
				}

				$field_id    = isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '';
				$override    = isset( $field['option_name'] ) && is_string( $field['option_name'] ) ? $field['option_name'] : null;
				$option_name = SettingsRegistry::get_option_name( $section_id, $field_id, $override );

				// $_POST is read raw here; sanitization happens in SettingsSanitizer per field type.
				// Nonce verified at top of method via check_admin_referer().
				// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
				$raw_value = array_key_exists( $option_name, $_POST ) ? wp_unslash( $_POST[ $option_name ] ) : null;

				$sanitized = $sanitizer->sanitize_field( $field, $raw_value );

				if ( $sanitized === null || $sanitized === '' ) {
					// Empty URL / text — delete to keep wp_options tidy and match prior behaviour.
					if ( in_array( $type, [ 'url', 'text', 'textarea' ], true ) ) {
						delete_option( $option_name );
					} else {
						update_option( $option_name, $sanitized );
					}
				} else {
					update_option( $option_name, $sanitized );
				}

				$saved[ $option_name ] = $sanitized;
			}
		}

		/**
		 * Fires after the unified settings form has been saved.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, mixed> $saved Map of option_name => sanitized value.
		 */
		do_action( 'albert/settings/saved', $saved );

		add_settings_error(
			'albert_settings',
			'settings_saved',
			__( 'Settings saved.', 'albert-ai-butler' ),
			'success'
		);
		// Standard WP Settings API pattern — persist notices across the redirect.
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'             => $this->page_slug,
					'settings-updated' => 'true',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Revoke all tokens for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function revoke_user_tokens( int $user_id ): void {
		global $wpdb;

		$tables = Installer::get_table_names();

		// Get all access token IDs for this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$token_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT token_id FROM %i WHERE user_id = %d',
				$tables['access_tokens'],
				$user_id
			)
		);

		// Revoke access tokens.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$tables['access_tokens'],
			[ 'revoked' => 1 ],
			[ 'user_id' => $user_id ],
			[ '%d' ],
			[ '%d' ]
		);

		// Revoke associated refresh tokens.
		if ( ! empty( $token_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $token_ids ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET revoked = 1 WHERE access_token_id IN ({$placeholders})",
					$tables['refresh_tokens'],
					...$token_ids
				)
			);
			// phpcs:enable
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		// Hook format for submenu is: {parent_slug}_page_{menu_slug}.
		if ( 'albert_page_' . $this->page_slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			ALBERT_VERSION
		);

		wp_enqueue_script(
			'albert-admin-utils',
			ALBERT_PLUGIN_URL . 'assets/js/albert-admin-utils.js',
			[],
			ALBERT_VERSION,
			true
		);

		wp_enqueue_script(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'albert-admin-utils' ],
			ALBERT_VERSION,
			true
		);

		wp_localize_script(
			'albert-admin',
			'albertAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'albert_oauth_nonce' ),
				'i18n'    => [
					'copied'     => __( 'Copied!', 'albert-ai-butler' ),
					'copyFailed' => __( 'Copy failed', 'albert-ai-butler' ),
				],
			]
		);

		// License management assets.
		wp_enqueue_style(
			'albert-licenses',
			ALBERT_PLUGIN_URL . 'assets/css/albert-licenses.css',
			[ 'albert-admin' ],
			ALBERT_VERSION
		);

		wp_enqueue_script(
			'albert-licenses',
			ALBERT_PLUGIN_URL . 'assets/js/albert-licenses.js',
			[ 'albert-admin-utils' ],
			ALBERT_VERSION,
			true
		);

		$addons_for_js = [];
		if ( class_exists( '\Albert\Abstracts\AbstractAddon' ) ) {
			foreach ( \Albert\Abstracts\AbstractAddon::get_registered_addons() as $addon ) {
				$addons_for_js[] = [
					'name'        => $addon['name'],
					'option_slug' => $addon['option_slug'],
				];
			}
		}

		$timestamp = time();
		$token     = '';
		$edd_nonce = '';
		if ( class_exists( '\EasyDigitalDownloads\Updater\Utilities\Tokenizer' ) ) {
			$token     = \EasyDigitalDownloads\Updater\Utilities\Tokenizer::tokenize( $timestamp );
			$edd_nonce = wp_create_nonce( 'edd_sl_sdk_license_handler' );
		}

		wp_localize_script(
			'albert-licenses',
			'albertLicenses',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'albert_license_nonce' ),
				'token'     => $token,
				'timestamp' => $timestamp,
				'eddNonce'  => $edd_nonce,
				'addons'    => $addons_for_js,
				'i18n'      => [
					'activating'        => __( 'Activating...', 'albert-ai-butler' ),
					'activate'          => __( 'Activate', 'albert-ai-butler' ),
					'deactivating'      => __( 'Deactivating...', 'albert-ai-butler' ),
					/* translators: %s: addon name */
					'confirmDeactivate' => __( 'Deactivate license for %s?', 'albert-ai-butler' ),
					'emptyKey'          => __( 'Please enter a license key.', 'albert-ai-butler' ),
					'networkError'      => __( 'A network error occurred. Please try again.', 'albert-ai-butler' ),
				],
			]
		);
	}

	/**
	 * Render the licenses table.
	 *
	 * This method is public and static so the AJAX handler can call it to
	 * return refreshed table HTML.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function render_licenses_table(): void {
		if ( ! class_exists( '\Albert\Abstracts\AbstractAddon' ) ) {
			self::render_licenses_empty_state();
			return;
		}

		$addons = \Albert\Abstracts\AbstractAddon::get_registered_addons();

		if ( empty( $addons ) ) {
			self::render_licenses_empty_state();
			return;
		}
		?>
		<table class="albert-licenses-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Addon', 'albert-ai-butler' ); ?></th>
					<th><?php esc_html_e( 'Version', 'albert-ai-butler' ); ?></th>
					<th><?php esc_html_e( 'Status', 'albert-ai-butler' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'albert-ai-butler' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'albert-ai-butler' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $addons as $addon ) {
					$option_slug  = $addon['option_slug'];
					$license_data = get_option( "{$option_slug}_license", false );
					$license_key  = get_option( "{$option_slug}_license_key", '' );
					$status       = is_object( $license_data ) ? ( $license_data->license ?? '' ) : '';
					$expires      = is_object( $license_data ) ? ( $license_data->expires ?? '' ) : '';
					$store_url    = $addon['store_url'] ?? 'https://albertwp.com';
					?>
					<tr>
						<td><strong><?php echo esc_html( $addon['name'] ); ?></strong></td>
						<td><?php echo esc_html( $addon['version'] ); ?></td>
						<td><?php self::render_status( $status ); ?></td>
						<td><?php self::render_expires( $status, $expires ); ?></td>
						<td><?php self::render_actions( $status, $option_slug, $addon['name'], $store_url, $license_key ); ?></td>
					</tr>
				<?php } ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the status cell content.
	 *
	 * @since 1.1.0
	 *
	 * @param string $status The EDD license status.
	 *
	 * @return void
	 */
	private static function render_status( string $status ): void {
		switch ( $status ) {
			case 'valid':
				$class = 'albert-status-dot--valid';
				$label = __( 'Active', 'albert-ai-butler' );
				break;

			case 'expired':
				$class = 'albert-status-dot--expired';
				$label = __( 'Expired', 'albert-ai-butler' );
				break;

			case 'disabled':
			case 'invalid':
			case 'site_inactive':
			case 'item_name_mismatch':
			case 'no_activations_left':
				$class = 'albert-status-dot--invalid';
				$label = ucfirst( str_replace( '_', ' ', $status ) );
				break;

			default:
				$class = 'albert-status-dot--none';
				$label = __( 'Not activated', 'albert-ai-butler' );
				break;
		}

		echo '<span class="albert-status-dot ' . esc_attr( $class ) . '"></span> ';
		echo esc_html( $label );
	}

	/**
	 * Render the "Expires" cell content.
	 *
	 * @since 1.1.0
	 *
	 * @param string $status  The license status.
	 * @param string $expires The expiration date string.
	 *
	 * @return void
	 */
	private static function render_expires( string $status, string $expires ): void {
		if ( empty( $status ) || $status === 'inactive' || empty( $expires ) ) {
			echo '<span class="albert-no-license">&mdash;</span>';
			return;
		}

		if ( $expires === 'lifetime' ) {
			echo esc_html__( 'Lifetime', 'albert-ai-butler' );
			return;
		}

		$timestamp = strtotime( $expires );
		$formatted = $timestamp !== false ? wp_date( get_option( 'date_format' ), $timestamp ) : false;
		if ( $formatted !== false ) {
			echo esc_html( $formatted );
		} else {
			echo esc_html( $expires );
		}
	}

	/**
	 * Render the actions cell content.
	 *
	 * @since 1.1.0
	 *
	 * @param string $status      The license status.
	 * @param string $option_slug The addon option slug (basename of plugin dir).
	 * @param string $name        The addon name.
	 * @param string $store_url   The addon store URL.
	 * @param string $license_key The stored license key.
	 *
	 * @return void
	 */
	private static function render_actions( string $status, string $option_slug, string $name, string $store_url, string $license_key = '' ): void {
		if ( $status === 'valid' ) {
			echo '<button type="button" class="albert-deactivate-btn"'
				. ' data-option-slug="' . esc_attr( $option_slug ) . '"'
				. ' data-addon-name="' . esc_attr( $name ) . '"'
				. ' data-license-key="' . esc_attr( $license_key ) . '">'
				. esc_html__( 'Deactivate', 'albert-ai-butler' )
				. '</button>';
		} elseif ( $status === 'expired' ) {
			echo '<a href="' . esc_url( $store_url ) . '" target="_blank" rel="noopener noreferrer" class="button button-small">'
				. esc_html__( 'Renew', 'albert-ai-butler' )
				. '</a>';
		} else {
			echo '<span class="albert-no-license">&mdash;</span>';
		}
	}

	/**
	 * Render the empty state when no addons are installed.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function render_licenses_empty_state(): void {
		?>
		<div class="albert-empty-state">
			<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
			<p>
				<?php esc_html_e( 'Premium addons extend Albert with powerful features like bulk operations, WooCommerce management, and SEO tools.', 'albert-ai-butler' ); ?>
			</p>
			<p>
				<a href="https://albertwp.com/addons/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Explore addons', 'albert-ai-butler' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
				</a>
			</p>
		</div>
		<?php
	}
}
