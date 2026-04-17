<?php
/**
 * Settings Bootstrap
 *
 * Provides the built-in sections that Free always registers with the
 * SettingsRegistry on page load.
 *
 * @package    Albert
 * @subpackage Admin
 * @since      1.1.0
 */

declare(strict_types=1);

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * SettingsBootstrap class.
 *
 * Returns the built-in section schemas and provides the static render
 * callbacks the schemas point at. The class is intentionally stateless —
 * the page calls {@see self::get_builtin_sections()} once per request.
 *
 * @since 1.1.0
 */
class SettingsBootstrap {

	/**
	 * Get the built-in sections registered by Free.
	 *
	 * @since 1.1.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_builtin_sections(): array {
		return [
			[
				// Always last — add-ons sit between the shared Settings card (50) and Licenses (9000).
				'id'       => 'albert/licenses',
				'title'    => __( 'Licenses', 'albert-ai-butler' ),
				'priority' => 9000,
				'icon'     => 'admin-network',
				'fields'   => [
					[
						'id'                => 'licenses_table',
						'type'              => 'custom',
						'label'             => '',
						'render_callback'   => [ self::class, 'render_licenses_block' ],
						'sanitize_callback' => '__return_null',
					],
				],
			],
		];
	}

	/**
	 * Render the licenses block (table or empty state).
	 *
	 * Delegates to {@see Settings} for the table/empty-state markup so the
	 * AJAX refresh handler keeps a single source of truth.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Field definition (unused).
	 * @param mixed                $current_value Current value (unused).
	 *
	 * @return void
	 */
	public static function render_licenses_block( array $field, $current_value ): void {
		unset( $field, $current_value );

		$has_addons = class_exists( '\Albert\Abstracts\AbstractAddon' )
			&& ! empty( \Albert\Abstracts\AbstractAddon::get_registered_addons() );

		if ( $has_addons ) {
			?>
			<div id="albert-license-notice" class="albert-license-notice" hidden></div>
			<div class="albert-license-form">
				<input
					type="text"
					id="albert-license-key"
					class="albert-text-input"
					placeholder="<?php esc_attr_e( 'Enter your license key', 'albert-ai-butler' ); ?>"
					autocomplete="off"
				/>
				<button type="button" id="albert-activate-btn" class="button button-primary">
					<?php esc_html_e( 'Activate', 'albert-ai-butler' ); ?>
				</button>
			</div>
			<p class="albert-field-description albert-license-hint">
				<?php esc_html_e( 'Enter your license key. It will be automatically matched to the correct addon.', 'albert-ai-butler' ); ?>
			</p>
			<div id="albert-addons-table-wrap">
				<?php Settings::render_licenses_table(); ?>
			</div>
			<?php
		} else {
			Settings::render_licenses_empty_state();
		}
	}
}
