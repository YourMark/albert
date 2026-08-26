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

use Albert\Media\UploadTickets\UploadTicketService;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\ConnectionRetention;
use Albert\Privacy\PrivacyMode;

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
				'id'          => 'albert/privacy',
				'title'       => __( 'Privacy', 'albert-ai-butler' ),
				'priority'    => 60,
				'icon'        => 'privacy',
				'description' => __( 'Control how customer and user personal data (names, emails, phone numbers, addresses) is shared with AI assistants. Payment and card data is always removed, regardless of this setting.', 'albert-ai-butler' ),
				'fields'      => [
					[
						'id'                => 'mode',
						'type'              => 'select',
						'label'             => __( 'Privacy mode', 'albert-ai-butler' ),
						'description'       => __( 'Strict: personal data is always anonymised. Balanced (recommended): anonymised by default, but an authorised request can reveal it. Off: personal data is not anonymised.', 'albert-ai-butler' ),
						'default'           => PrivacyMode::Balanced->value,
						'options'           => [
							PrivacyMode::Strict->value   => __( 'Strict — always anonymise personal data', 'albert-ai-butler' ),
							PrivacyMode::Balanced->value => __( 'Balanced — anonymise by default (recommended)', 'albert-ai-butler' ),
							PrivacyMode::Off->value      => __( 'Off — do not anonymise personal data', 'albert-ai-butler' ),
						],
						'sanitize_callback' => [ PrivacyMode::class, 'sanitize' ],
					],
				],
			],
			[
				'id'          => 'albert/connections',
				'title'       => __( 'Connections', 'albert-ai-butler' ),
				'priority'    => 65,
				'icon'        => 'admin-users',
				'description' => __( 'Controls standing invitations and connections that nobody is actually using.', 'albert-ai-butler' ),
				'fields'      => [
					[
						'id'          => 'invitation_expiry_days',
						'type'        => 'number',
						'label'       => __( 'Invitation expiry (days)', 'albert-ai-butler' ),
						'description' => __( 'Someone added to the allowed list who never approves an assistant can no longer do so after this many days, the same way a WordPress account-activation link expires. Once they approve one, they keep their access no matter what happens to it later. Set to 0 to disable.', 'albert-ai-butler' ),
						'option_name' => AllowedUsers::EXPIRY_OPTION,
						'default'     => AllowedUsers::DEFAULT_EXPIRY_DAYS,
						'attributes'  => [
							'min'  => 0,
							'max'  => 3650,
							'step' => 1,
						],
					],
					[
						'id'          => 'apply_expiry_to_existing',
						'type'        => 'checkbox',
						'label'       => __( 'Apply to invitations already waiting', 'albert-ai-butler' ),
						'description' => __( 'Recalculates the expiry date for everyone who has not approved yet, using their original invite date and the number of days above. Leave unchecked and they keep the deadline they were already given.', 'albert-ai-butler' ),
						'option_name' => AllowedUsers::APPLY_TO_EXISTING_OPTION,
						'default'     => false,
					],
					[
						'id'          => 'connection_never_used_days',
						'type'        => 'number',
						'label'       => __( 'Drop never-used connections (days)', 'albert-ai-butler' ),
						'description' => __( 'A connection that was approved but has never actually been used to do anything is removed after this many days. Once it is used at least once, it is never removed for this reason. Set to 0 to disable.', 'albert-ai-butler' ),
						'option_name' => ConnectionRetention::NEVER_USED_OPTION,
						'default'     => ConnectionRetention::DEFAULT_NEVER_USED_DAYS,
						'attributes'  => [
							'min'  => 0,
							'max'  => 3650,
							'step' => 1,
						],
					],
					[
						'id'          => 'connection_idle_days',
						'type'        => 'number',
						'label'       => __( 'Expire idle connections (days)', 'albert-ai-butler' ),
						'description' => __( 'A connection that has not been used in this many days is removed automatically. Off by default: turn this on only once you have a sense of how often the assistants you use normally go quiet, so a normal gap is not mistaken for an idle one. Set to 0 to disable.', 'albert-ai-butler' ),
						'option_name' => ConnectionRetention::IDLE_OPTION,
						'default'     => ConnectionRetention::DEFAULT_IDLE_DAYS,
						'attributes'  => [
							'min'  => 0,
							'max'  => 3650,
							'step' => 1,
						],
					],
				],
			],
			[
				'id'          => 'albert/media',
				'title'       => __( 'Uploads', 'albert-ai-butler' ),
				'priority'    => 68,
				'icon'        => 'upload',
				'description' => __( 'Controls the single-use links an assistant mints to upload a file it has the bytes for (rather than a URL to sideload from).', 'albert-ai-butler' ),
				'fields'      => [
					[
						// 'custom', not 'number': this field needs to show a filter's
						// value (disabled) instead of the stored option whenever
						// albert/media/upload_link_max_bytes is overriding it — behaviour
						// specific to this one field, not a generic renderer capability.
						// Same escape hatch the licenses table below already uses.
						'id'                => 'default_max_mb',
						'type'              => 'custom',
						'label'             => __( 'Default upload size limit (MB)', 'albert-ai-butler' ),
						'description'       => sprintf(
							/* translators: %s: this server's own upload size ceiling, human-readable (e.g. "64 MB") */
							__( 'Used when an assistant mints an upload link without asking for a specific size limit. An assistant can still request a smaller or larger limit for a specific upload; either way, this server itself won\'t accept more than %s regardless of what\'s set here.', 'albert-ai-butler' ),
							self::server_upload_ceiling()
						),
						'option_name'       => UploadTicketService::MAX_BYTES_OPTION,
						'default'           => UploadTicketService::DEFAULT_MAX_MB,
						'render_callback'   => [ UploadTicketService::class, 'render_max_mb_field' ],
						'sanitize_callback' => [ UploadTicketService::class, 'sanitize_max_mb' ],
					],
				],
			],
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
	 * This server's own upload ceiling, human-readable, for the Uploads
	 * field description. Purely informational — the field's actual bound is
	 * enforced at redemption time by {@see UploadTicketService}, not here.
	 *
	 * @since 1.4.0
	 *
	 * @return string e.g. "64 MB".
	 */
	private static function server_upload_ceiling(): string {
		if ( ! function_exists( 'wp_max_upload_size' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// size_format() returns false for a non-numeric/negative input, which
		// wp_max_upload_size() cannot actually produce — the fallback exists
		// only to satisfy the return type, not because it's expected to fire.
		$formatted = size_format( wp_max_upload_size() );

		return is_string( $formatted ) ? $formatted : '';
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
