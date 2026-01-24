<?php
/**
 * Connections Page
 *
 * Allows admins to view and manage all AI assistant connections (OAuth sessions).
 *
 * @package    AIBridge
 * @subpackage Admin
 * @since      1.0.0
 */

namespace AIBridge\Admin;

use AIBridge\Contracts\Interfaces\Hookable;

/**
 * Connections class
 *
 * Provides a page for admins to manage AI assistant OAuth connections.
 *
 * @since 1.0.0
 */
class Connections implements Hookable {

	/**
	 * Parent menu slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $parent_slug = 'ai-bridge';

	/**
	 * Page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'ai-bridge-connections';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add the connections page to admin menu.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			$this->parent_slug,
			__( 'Connections', 'ai-bridge' ),
			__( 'Connections', 'ai-bridge' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Handle user actions.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( ! isset( $_GET['action'] ) || ! isset( $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( $this->page_slug !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$action = sanitize_key( $_GET['action'] );

		if ( $action === 'revoke' ) {
			$this->handle_revoke_session();
		} elseif ( $action === 'revoke_all' ) {
			$this->handle_revoke_all_sessions();
		}
	}

	/**
	 * Handle revoking a single session.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_revoke_session(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$token_id = isset( $_GET['token_id'] ) ? absint( $_GET['token_id'] ) : 0;

		if ( ! $token_id ) {
			return;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'revoke_my_session_' . $token_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ai-bridge' ) );
		}

		global $wpdb;
		$table       = $wpdb->prefix . 'aibridge_oauth_access_tokens';
		$current_uid = get_current_user_id();

		// Admins can revoke any connection.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			[ 'revoked' => 1 ],
			[ 'id' => $token_id ],
			[ '%d' ],
			[ '%d' ]
		);

		add_settings_error(
			'aibridge_connections',
			'session_revoked',
			__( 'Session revoked successfully.', 'ai-bridge' ),
			'success'
		);

		// Redirect back.
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
	 * Handle revoking all user sessions.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_revoke_all_sessions(): void {
		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'revoke_all_my_sessions' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ai-bridge' ) );
		}

		// Use shared method from Settings class.
		Settings::revoke_user_tokens( get_current_user_id() );

		add_settings_error(
			'aibridge_connections',
			'all_sessions_revoked',
			__( 'All sessions revoked successfully.', 'ai-bridge' ),
			'success'
		);

		// Redirect back.
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
	 * Render the page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ai-bridge' ) );
		}

		global $wpdb;

		$tokens_table  = $wpdb->prefix . 'aibridge_oauth_access_tokens';
		$clients_table = $wpdb->prefix . 'aibridge_oauth_clients';

		// Get all active connections (all users) grouped by client.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sessions = $wpdb->get_results(
			"SELECT
				t.id,
				t.client_id,
				t.user_id,
				t.token_id,
				t.created_at,
				t.expiry_datetime,
				COALESCE(c.name, 'Unknown Client') as client_name
			FROM {$tokens_table} t
			LEFT JOIN {$clients_table} c ON t.client_id = c.client_id
			WHERE t.revoked = 0 AND t.expiry_datetime > NOW()
			ORDER BY t.created_at DESC"
		);
		// phpcs:enable

		echo '<div class="wrap aibridge-settings">';
		echo '<h1>' . esc_html__( 'AI Assistant Connections', 'ai-bridge' ) . '</h1>';

		settings_errors( 'aibridge_connections' );

		echo '<p class="description">';
		esc_html_e( 'Active AI assistant connections to your WordPress site. Each connection represents an authorized AI tool that can interact with your site via the MCP protocol.', 'ai-bridge' );
		echo '</p>';

		if ( empty( $sessions ) ) {
			echo '<div class="notice notice-info">';
			echo '<p>' . esc_html__( 'You have no active AI sessions. When you authorize an AI tool to access this site, it will appear here.', 'ai-bridge' ) . '</p>';
			echo '</div>';
		} else {
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'App', 'ai-bridge' ) . '</th>';
			echo '<th>' . esc_html__( 'Session', 'ai-bridge' ) . '</th>';
			echo '<th>' . esc_html__( 'Connected', 'ai-bridge' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'ai-bridge' ) . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $sessions as $session ) {
				$app_name        = ! empty( $session->client_name ) ? $session->client_name : __( 'Unknown', 'ai-bridge' );
				$first_connected = $session->first_connected ?? $session->created_at;
				echo '<tr>';
				echo '<td><strong>' . esc_html( $app_name ) . '</strong></td>';
				echo '<td><code>' . esc_html( substr( $session->token_id, 0, 16 ) . '...' ) . '</code></td>';
				echo '<td>';
				printf(
					/* translators: %s: human-readable time difference */
					esc_html__( '%s ago', 'ai-bridge' ),
					esc_html( human_time_diff( strtotime( $first_connected ), time() ) )
				);
				echo '</td>';
				echo '<td>';

				$revoke_url = wp_nonce_url(
					add_query_arg(
						[
							'page'     => $this->page_slug,
							'action'   => 'revoke',
							'token_id' => $session->id,
						],
						admin_url( 'admin.php' )
					),
					'revoke_my_session_' . $session->id
				);

				echo '<a href="' . esc_url( $revoke_url ) . '" class="button button-small" onclick="return confirm(\'' . esc_js( __( 'Disconnect this AI tool?', 'ai-bridge' ) ) . '\');">';
				esc_html_e( 'Disconnect', 'ai-bridge' );
				echo '</a>';
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			$revoke_all_url = wp_nonce_url(
				add_query_arg(
					[
						'page'   => $this->page_slug,
						'action' => 'revoke_all',
					],
					admin_url( 'admin.php' )
				),
				'revoke_all_my_sessions'
			);

			echo '<p style="margin-top: 15px;">';
			echo '<a href="' . esc_url( $revoke_all_url ) . '" class="button" onclick="return confirm(\'' . esc_js( __( 'Disconnect ALL your AI tools?', 'ai-bridge' ) ) . '\');">';
			esc_html_e( 'Disconnect All', 'ai-bridge' );
			echo '</a>';
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Enqueue assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'dashboard_page_' . $this->page_slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'aibridge-user-sessions',
			AIBRIDGE_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			AIBRIDGE_VERSION
		);
	}
}
