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

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Media\UploadLinks\UploadLinkService;
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
						// 'custom', not 'number': shows the filter's value, disabled, when it's overriding.
						'id'                => 'default_max_mb',
						'type'              => 'custom',
						'label'             => __( 'Default upload size limit (MB)', 'albert-ai-butler' ),
						'description'       => sprintf(
							/* translators: %s: this server's own upload size ceiling, human-readable (e.g. "64 MB") */
							__( 'Used when an assistant mints an upload link without asking for a specific size limit. An assistant can still request a smaller or larger limit for a specific upload; either way, this server itself won\'t accept more than %s regardless of what\'s set here.', 'albert-ai-butler' ),
							self::server_upload_ceiling()
						),
						'option_name'       => UploadLinkService::MAX_BYTES_OPTION,
						'default'           => UploadLinkService::DEFAULT_MAX_MB,
						'render_callback'   => [ self::class, 'render_max_mb_field' ],
						'sanitize_callback' => [ self::class, 'sanitize_max_mb' ],
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
	 * This server's own upload ceiling, human-readable, for the Uploads field description.
	 *
	 * @since 1.4.0
	 *
	 * @return string e.g. "64 MB".
	 */
	private static function server_upload_ceiling(): string {
		// size_format() can return false; wp_max_upload_size() can't actually trigger that.
		$formatted = size_format( wp_max_upload_size() );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Render the Uploads section's default_max_mb field.
	 *
	 * `'type' => 'custom'`, the same escape hatch the licenses table uses,
	 * since the generic renderer has no concept of "disabled because a
	 * filter overrides it".
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field         Field definition (unused — the input is hand-rolled).
	 * @param mixed                $current_value The stored option's value (or default), from get_option().
	 *
	 * @return void
	 */
	public static function render_max_mb_field( array $field, $current_value ): void {
		unset( $field );

		$state   = UploadLinkService::get_default_max_bytes_filter_state();
		$active  = $state['state'] === 'active';
		$clamped = $active && $state['requested'] > $state['value'];

		// Round a sub-megabyte filter value UP, never to 0: the field's own
		// min is 1, and rendering value="0" against it is invalid. The hint
		// below carries the exact size so the rounding can't mislead.
		$value = $active
			? max( 1, (int) ceil( $state['value'] / UploadLinkService::BYTES_PER_MB ) )
			: (int) $current_value;

		printf(
			'<input type="number" name="%1$s" id="albert-field-%1$s" value="%2$d" class="albert-text-input" min="1" max="%3$d" step="1"%4$s />',
			esc_attr( UploadLinkService::MAX_BYTES_OPTION ),
			absint( $value ),
			absint( UploadLinkService::MAX_SETTABLE_MB ),
			$active ? ' disabled' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string, not user input.
		);

		if ( $clamped ) {
			self::render_hint(
				sprintf(
					/* translators: 1: opening <code>, 2: closing </code> wrapping the filter name, 3: the value the filter requested (MB), 4: the maximum allowed (MB) */
					__( 'A %1$salbert/media/upload_link_max_bytes%2$s filter is requesting %3$d MB, above the %4$d MB maximum — %4$d MB is being used instead.', 'albert-ai-butler' ),
					'<code>',
					'</code>',
					(int) round( $state['requested'] / UploadLinkService::BYTES_PER_MB ),
					UploadLinkService::MAX_SETTABLE_MB
				),
				'warning'
			);
		} elseif ( $active ) {
			self::render_hint(
				sprintf(
					/* translators: 1: opening <code>, 2: closing </code> wrapping the filter name, 3: the effective size, human-readable (e.g. "500 B") */
					__( 'A %1$salbert/media/upload_link_max_bytes%2$s filter is currently active, overriding what\'s saved here. The limit in effect is %3$s.', 'albert-ai-butler' ),
					'<code>',
					'</code>',
					size_format( $state['value'] )
				),
				'info'
			);
		}
	}

	/**
	 * Render a `.albert-hint` block, matching the Connections screen's own hints.
	 *
	 * @param string $notice May contain `<code>` tags; built via sprintf(), not raw user input.
	 * @param string $tone   'info' or 'warning'.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private static function render_hint( string $notice, string $tone ): void {
		$icon = $tone === 'warning' ? 'warning' : 'info';

		echo '<div class="albert-hint albert-hint--' . esc_attr( $tone ) . '">';
		echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		echo '<p>' . wp_kses( $notice, [ 'code' => [] ] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() with an explicit allowlist above.
		echo '</div>';
	}

	/**
	 * Sanitize the Settings-screen field for {@see UploadLinkService::MAX_BYTES_OPTION}.
	 *
	 * While the filter is active the field renders disabled, so it's never
	 * submitted; return the stored value as a no-op rather than resetting it.
	 *
	 * @param mixed $value Raw value from the settings form.
	 *
	 * @return int MB, clamped to [1, UploadLinkService::MAX_SETTABLE_MB].
	 * @since 1.4.0
	 */
	public static function sanitize_max_mb( $value ): int {
		$stored = (int) get_option( UploadLinkService::MAX_BYTES_OPTION, UploadLinkService::DEFAULT_MAX_MB );

		// null means the field was not submitted, which is what a disabled
		// field does. Keyed on the value rather than only on the filter's
		// state at save time: the filter can go inactive between the render
		// that disabled the field and the save, and treating "absent" as
		// "invalid" would then overwrite a stored value nobody touched.
		if ( $value === null || UploadLinkService::get_default_max_bytes_filter_state()['state'] === 'active' ) {
			return $stored;
		}

		// (int) cast, not absint(): a negative input must fall through to the default below.
		$mb = is_scalar( $value ) ? (int) $value : 0;

		if ( $mb < 1 ) {
			// Say so, exactly as an over-range value does. Silently rewriting
			// a 0 to 10 leaves somebody staring at a number they did not type.
			add_settings_error(
				'albert_settings',
				'upload_link_max_mb_too_low',
				sprintf(
					/* translators: %d: the default that was saved instead (MB) */
					__( 'The default upload size limit must be at least 1 MB, so %d MB was saved instead.', 'albert-ai-butler' ),
					UploadLinkService::DEFAULT_MAX_MB
				),
				'warning'
			);

			return UploadLinkService::DEFAULT_MAX_MB;
		}

		if ( $mb > UploadLinkService::MAX_SETTABLE_MB ) {
			// Reuses the page's own "Settings saved" notice mechanism.
			add_settings_error(
				'albert_settings',
				'upload_link_max_mb_clamped',
				sprintf(
					/* translators: 1: the value that was requested (MB), 2: the maximum allowed (MB) */
					__( 'The default upload size limit can\'t be set above %2$d MB. %1$d MB was requested, so %2$d MB was saved instead.', 'albert-ai-butler' ),
					$mb,
					UploadLinkService::MAX_SETTABLE_MB
				),
				'warning'
			);
		}

		return min( $mb, UploadLinkService::MAX_SETTABLE_MB );
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
