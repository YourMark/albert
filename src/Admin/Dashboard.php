<?php
/**
 * Dashboard Admin Page
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\Core\Plugin;
use Albert\Logging\Repository as LoggingRepository;
use Albert\MCP\Server as McpServer;
use Albert\Database\Tables;

/**
 * Dashboard class
 *
 * Manages the plugin dashboard page - primary landing page for Albert.
 * Shows a contextual setup checklist for new users and status for returning users.
 *
 * @since 1.0.0
 */
class Dashboard implements Hookable {

	/**
	 * Parent menu slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $parent_slug = 'albert';

	/**
	 * Dashboard page slug (same as parent to make it the first submenu).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'albert';

	/**
	 * Ability log repository.
	 *
	 * @since 1.1.0
	 * @var LoggingRepository
	 */
	private LoggingRepository $logging_repository;

	/**
	 * Constructor.
	 *
	 * @param LoggingRepository $logging_repository Ability log repository.
	 *
	 * @since 1.1.0
	 */
	public function __construct( LoggingRepository $logging_repository ) {
		$this->logging_repository = $logging_repository;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ], Menu::POSITION_DASHBOARD );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add top-level menu and dashboard page.
	 *
	 * Creates the top-level "Albert" menu with Dashboard as the default page,
	 * then adds "Dashboard" as the first submenu (which replaces the auto-generated one).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_pages(): void {
		// Add top-level menu (shows Dashboard by default).
		add_menu_page(
			__( 'Albert Dashboard', 'albert-ai-butler' ),
			__( 'Albert', 'albert-ai-butler' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_dashboard_page' ],
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbDpzcGFjZT0icHJlc2VydmUiIHZpZXdCb3g9IjAgMCAyNTYgMjU2Ij48cGF0aCBmaWxsPSIjYTdhYWFkIiBkPSJNNjkuNCAxNC40Yy0uOS44LTEgMy40LTEgMTguOSAwIDE2LjcuMSAxOC4xIDEuMSAxOSAuNi42IDEuNSAxIDIgMXM5LjgtMi4zIDIwLjctNWwxOS44LTUgMi44IDIuOWM3LjggOC4xIDE5LjUgOC4yIDI3LjMuNWwzLjQtMy40IDIwLjEgNS4xYzEyLjggMy4zIDIwLjUgNC45IDIxLjQgNC42LjctLjIgMS42LTEuMSAyLTIgLjMtLjguNi04LjkuNi0xNy45IDAtMTcuOS0uMi0xOS40LTMuMi0xOS43LS45LS4xLTEwLjUgMi0yMS4zIDQuOGwtMTkuNyA1LTIuOC0zLjFjLTMuOS00LjEtNy45LTUuOC0xMy44LTUuOS01LjcgMC0xMC40IDItMTQuMSA2LjJsLTIuNSAyLjgtMTkuNC00LjljLTEwLjYtMi44LTIwLTUtMjAuOS01LS45LjEtMiAuNS0yLjUgMS4xeiIvPjxwYXRoIGZpbGw9IiNhN2FhYWQiIGQ9Ik0yNS4yIDUyLjRjLTYgMy41LTExLjUgNi44LTEyLjIgNy40LTMuMSAyLjgtMy0xLjctMyA5MS4xIDAgNjMuNC4yIDg3LjEuNyA4OC4yIDEuNyAzLjYtNSAzLjQgMTE3LjMgMy40czExNS43LjIgMTE3LjMtMy40Yy41LTEuMS43LTI0LjIuNy04NS43IDAtODIuOCAwLTg0LjItMS4yLTg2LjYtMS4zLTIuNi0yLTMuMy0xNS40LTEzLjgtNC45LTMuOS05LjEtNi44LTkuMy02LjYtLjIuMi0xOS40IDM3LjEtNDIuNyA4MS45LTIzLjIgNDQuOC00My44IDg0LjMtNDUuNyA4Ny44bC0zLjQgNi4zLTQuMS03LjktNDUuOC04Ny44Yy0yMi45LTQzLjktNDEuNy04MC4xLTQyLTgwLjMtLjEtLjEtNS4yIDIuNS0xMS4yIDZ6Ii8+PHBhdGggZmlsbD0iI2E3YWFhZCIgZD0iTTEyNS42IDc2LjdjLTcuOSAxLjUtMTMuNCA5LjgtMTEuNyAxNy44IDIuOCAxMi45IDE5LjQgMTYuNiAyNyA2IDguMS0xMS4yLTEuNy0yNi4zLTE1LjMtMjMuOHpNMTIzLjYgMTI4YTE0LjQgMTQuNCAwIDAgMC04LjIgNy41Yy0zLjEgNi4zLTEuOCAxMy42IDMuMyAxOC4xIDMuMSAyLjcgNS43IDMuNiAxMCAzLjYgNC40IDAgNy42LTEuMiAxMC4zLTMuOSA5LjctOS4zIDQuMS0yNS4yLTkuMy0yNi0yLjQtLjEtNC43LjEtNi4xLjd6Ii8+PC9zdmc+',
			80
		);

		// Add Dashboard submenu (replaces auto-generated first submenu).
		add_submenu_page(
			$this->parent_slug,
			__( 'Dashboard', 'albert-ai-butler' ),
			__( 'Dashboard', 'albert-ai-butler' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_dashboard_page' ]
		);
	}

	/**
	 * Enqueue dashboard assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		// Only load on our dashboard page.
		if ( $hook !== 'toplevel_page_albert' ) {
			return;
		}

		wp_enqueue_style(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/css/admin-settings.css',
			[ Assets::PRIMITIVES_HANDLE ],
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
			'albert-dashboard',
			ALBERT_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			[ 'albert-admin-utils' ],
			ALBERT_VERSION,
			true
		);
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		// Gather setup state.
		$has_allowed_users  = ! empty( get_option( 'albert_allowed_users', [] ) );
		$active_connections = $this->get_active_connections_count();
		$has_connections    = $active_connections > 0;
		$setup_complete     = $has_allowed_users && $has_connections;
		$enabled_abilities  = $this->get_enabled_abilities_count();
		$mcp_endpoint       = McpServer::get_endpoint_url();

		?>
		<div class="wrap albert-settings">
			<h1><?php echo esc_html__( 'Albert Dashboard', 'albert-ai-butler' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Connect your WordPress site to AI assistants like Claude and ChatGPT.', 'albert-ai-butler' ); ?>
			</p>

			<div class="albert-dashboard-grid">
				<!-- Setup Checklist -->
				<div class="albert-card albert-setup-checklist-card">
					<?php if ( $setup_complete ) { ?>
						<h2><?php esc_html_e( 'Setup', 'albert-ai-butler' ); ?></h2>
						<div class="albert-setup-complete-wrapper">
							<div class="albert-setup-complete-bar">
								<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<?php esc_html_e( 'Complete', 'albert-ai-butler' ); ?>
							</div>
							<div class="albert-setup-complete-content">
								<p class="albert-setup-complete-actions">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-abilities' ) ); ?>" class="button button-primary">
										<?php esc_html_e( 'Manage Abilities', 'albert-ai-butler' ); ?>
									</a>
								</p>
							</div>
						</div>
					<?php } else { ?>
						<h2><?php echo esc_html__( 'Get Started', 'albert-ai-butler' ); ?></h2>
						<ol class="albert-checklist">
							<li class="albert-checklist-item albert-checklist-done">
								<span class="albert-checklist-icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<span class="albert-checklist-text"><?php esc_html_e( 'Plugin installed', 'albert-ai-butler' ); ?></span>
							</li>
							<li class="albert-checklist-item <?php echo $has_allowed_users ? 'albert-checklist-done' : 'albert-checklist-current'; ?>">
								<span class="albert-checklist-icon dashicons <?php echo $has_allowed_users ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span>
								<span class="albert-checklist-text">
									<?php if ( $has_allowed_users ) { ?>
										<?php esc_html_e( 'Allowed user added', 'albert-ai-butler' ); ?>
									<?php } else { ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>">
											<?php esc_html_e( 'Add an allowed user', 'albert-ai-butler' ); ?>
										</a>
									<?php } ?>
								</span>
							</li>
							<li class="albert-checklist-item <?php echo $has_allowed_users ? ( $has_connections ? 'albert-checklist-done' : 'albert-checklist-current' ) : 'albert-checklist-pending'; ?>">
								<span class="albert-checklist-icon dashicons <?php echo $has_connections ? 'dashicons-yes-alt' : ( $has_allowed_users ? 'dashicons-marker' : 'dashicons-marker' ); ?>" aria-hidden="true"></span>
								<span class="albert-checklist-text">
									<?php if ( $has_connections ) { ?>
										<?php esc_html_e( 'AI assistant connected', 'albert-ai-butler' ); ?>
									<?php } elseif ( $has_allowed_users ) { ?>
										<?php esc_html_e( 'Connect an AI assistant', 'albert-ai-butler' ); ?>
									<?php } else { ?>
										<?php esc_html_e( 'Connect an AI assistant', 'albert-ai-butler' ); ?>
									<?php } ?>
								</span>
							</li>
							<?php if ( $has_allowed_users && ! $has_connections ) { ?>
								<li class="albert-checklist-endpoint">
									<label class="albert-field-label"><?php esc_html_e( 'MCP Endpoint URL', 'albert-ai-butler' ); ?></label>
									<p class="albert-field-description">
										<?php esc_html_e( 'Add this URL to Claude Desktop or ChatGPT as an MCP connector:', 'albert-ai-butler' ); ?>
									</p>
									<div class="albert-endpoint-box">
										<input
											type="text"
											id="albert-mcp-endpoint"
											class="albert-endpoint-url"
											value="<?php echo esc_url( $mcp_endpoint ); ?>"
											readonly
										/>
										<button
											type="button"
											class="button button-secondary albert-copy-btn"
											data-clipboard-target="#albert-mcp-endpoint"
										>
											<?php echo esc_html__( 'Copy', 'albert-ai-butler' ); ?>
										</button>
									</div>
								</li>
							<?php } ?>
						</ol>
					<?php } ?>
				</div>

				<!-- Status Overview -->
				<div class="albert-card albert-status-card">
					<h2><?php echo esc_html__( 'Status', 'albert-ai-butler' ); ?></h2>
					<ul class="albert-status-list">
						<li>
							<span class="albert-status-indicator albert-status-active" aria-hidden="true"></span>
							<strong><?php echo esc_html__( 'OAuth Server:', 'albert-ai-butler' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html__( 'Active', 'albert-ai-butler' ); ?></span>
						</li>
						<li>
							<span class="albert-status-indicator albert-status-active" aria-hidden="true"></span>
							<strong><?php echo esc_html__( 'MCP Endpoint:', 'albert-ai-butler' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html__( 'Active', 'albert-ai-butler' ); ?></span>
						</li>
						<li>
							<span class="albert-status-indicator albert-status-info" aria-hidden="true"></span>
							<strong><?php echo esc_html__( 'Active Connections:', 'albert-ai-butler' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html( (string) $active_connections ); ?></span>
						</li>
						<li>
							<span class="albert-status-indicator albert-status-info" aria-hidden="true"></span>
							<strong><?php echo esc_html__( 'Enabled Abilities:', 'albert-ai-butler' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html( $enabled_abilities ); ?></span>
						</li>
					</ul>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>" class="button button-secondary">
							<?php echo esc_html__( 'View Connections', 'albert-ai-butler' ); ?>
						</a>
					</p>
				</div>

				<!-- Resources -->
				<div class="albert-card albert-resources-card">
					<h2><?php esc_html_e( 'Resources', 'albert-ai-butler' ); ?></h2>
					<ul class="albert-resources-list">
						<li>
							<span class="dashicons dashicons-book" aria-hidden="true"></span>
							<a href="https://wordpress.org/plugins/albert/" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Documentation', 'albert-ai-butler' ); ?>
								<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
							</a>
						</li>
						<li>
							<span class="dashicons dashicons-sos" aria-hidden="true"></span>
							<a href="https://github.com/YourMark/albert-ai-butler/issues" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Report an Issue', 'albert-ai-butler' ); ?>
								<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
							</a>
						</li>
					</ul>
				</div>

				<!-- Recent Activity -->
				<div class="albert-card albert-activity-card">
					<h2><?php echo esc_html__( 'Recent Activity', 'albert-ai-butler' ); ?></h2>
					<?php
					$recent_activity    = $this->get_recent_activity();
					$premium_not_active = ! class_exists( 'AlbertPremium\\AlbertPremiumService' );
					if ( ! empty( $recent_activity ) ) {
						?>
						<div class="albert-activity-card__body">
							<table class="albert-log-table">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Status', 'albert-ai-butler' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Event', 'albert-ai-butler' ); ?></th>
										<th scope="col"><?php esc_html_e( 'By', 'albert-ai-butler' ); ?></th>
										<th scope="col"><?php esc_html_e( 'When', 'albert-ai-butler' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $recent_activity as $activity ) { ?>
										<tr>
											<td class="albert-log-table__status"><?php $this->render_status_dot( $activity['status'] ); ?></td>
											<td class="albert-log-table__event"><?php echo esc_html( $activity['event'] ); ?></td>
											<td class="albert-log-table__by"><?php echo esc_html( $activity['actor'] ); ?></td>
											<td class="albert-log-table__when"><?php echo esc_html( $activity['time'] ); ?></td>
										</tr>
									<?php } ?>
								</tbody>
							</table>
							<?php if ( $premium_not_active ) { ?>
								<div class="albert-upsell-fade" aria-hidden="true"></div>
							<?php } ?>
						</div>
					<?php } else { ?>
						<p class="description">
							<?php echo esc_html__( 'No recent activity. Connect an AI assistant to get started!', 'albert-ai-butler' ); ?>
						</p>
					<?php } ?>
					<?php if ( $premium_not_active ) { ?>
						<div class="albert-upsell-cta">
							<h3 class="albert-upsell-cta__title"><?php esc_html_e( 'Your complete activity log', 'albert-ai-butler' ); ?></h3>
							<ul class="albert-upsell-cta__benefits">
								<li><?php esc_html_e( 'Keep months or years of history', 'albert-ai-butler' ); ?></li>
								<li><?php esc_html_e( 'Filter by user, assistant or date', 'albert-ai-butler' ); ?></li>
								<li><?php esc_html_e( 'See the details of each action, including errors', 'albert-ai-butler' ); ?></li>
							</ul>
							<a class="button button-primary albert-upsell-cta__button" href="<?php echo esc_url( 'https://albertwp.com/add-ons/premium-service/' ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Upgrade to Premium', 'albert-ai-butler' ); ?>
								<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
							</a>
						</div>
					<?php } ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get count of active OAuth connections.
	 *
	 * @return int Number of active connections.
	 * @since 1.0.0
	 */
	private function get_active_connections_count(): int {
		global $wpdb;
		$tables = Tables::oauth();

		// Count distinct clients with non-revoked tokens (sessions persist via refresh tokens).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT client_id) FROM %i WHERE revoked = 0',
				$tables['access_tokens']
			)
		);

		return (int) $count;
	}

	/**
	 * Get count of enabled abilities.
	 *
	 * @return string Enabled/Total abilities count.
	 * @since 1.0.0
	 */
	private function get_enabled_abilities_count(): string {
		$disabled_abilities = AbilitiesPage::get_disabled_abilities();
		$all_abilities      = wp_get_abilities();

		$enabled_count = 0;
		$total_count   = count( $all_abilities );

		foreach ( $all_abilities as $ability ) {
			$name = $ability->get_name();
			// Ability is enabled if NOT in the disabled list.
			if ( ! in_array( $name, $disabled_abilities, true ) ) {
				++$enabled_count;
			}
		}

		return sprintf( '%d/%d', $enabled_count, $total_count );
	}

	/**
	 * Get recent activity from OAuth sessions and ability executions.
	 *
	 * Each row is structured by column so the renderer can lay it out as a
	 * data table: a status (success / error / connection), the resolved event
	 * label, the acting user, and a relative timestamp. Ability slugs are
	 * resolved to human labels via {@see self::resolve_ability_label()}, which
	 * reads from the in-memory abilities manager (avoiding wp_get_ability(),
	 * unsafe in this render context) and falls back to a prettified slug.
	 *
	 * @return array<int, array{status: string, event: string, actor: string, time: string}> Recent activity rows.
	 * @since 1.0.0
	 */
	private function get_recent_activity(): array {
		global $wpdb;
		$tables = Tables::oauth();

		// Get the most recent distinct connections — one row per client, not per
		// token. A new access-token row is created on every silent refresh (about
		// hourly), so keying on the client and its registration time avoids
		// surfacing a "new connection" each time the session merely refreshes.
		// INNER JOIN ensures the client actually obtained a token, MAX(user_id)
		// recovers the authorizing user (clients register anonymously), and
		// UNIX_TIMESTAMP() yields a time-zone-safe epoch.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$connections = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.client_id, c.name, UNIX_TIMESTAMP( c.created_at ) AS created_ts, MAX( t.user_id ) AS user_id
				FROM %i c
				INNER JOIN %i t ON t.client_id = c.client_id
				GROUP BY c.client_id, c.name, c.created_at
				ORDER BY c.created_at DESC
				LIMIT %d',
				$tables['clients'],
				$tables['access_tokens'],
				5
			)
		);

		$events = [];

		foreach ( $connections as $row ) {
			$user        = get_userdata( $row->user_id );
			$client_name = $row->name ?? '';

			// Action-first event label; append the client name when we have one.
			$event = __( 'New connection', 'albert-ai-butler' );
			if ( $client_name !== '' ) {
				$event = sprintf(
					/* translators: %s: Client name */
					__( 'New connection — %s', 'albert-ai-butler' ),
					$client_name
				);
			}

			$events[] = [
				'status'    => 'connection',
				'timestamp' => (int) $row->created_ts,
				'event'     => $event,
				'actor'     => $user ? $user->display_name : __( 'System', 'albert-ai-butler' ),
			];
		}

		// Merge in recent ability executions.
		foreach ( $this->logging_repository->recent( 5 ) as $row ) {
			$user     = get_userdata( (int) $row->user_id );
			$is_error = isset( $row->status ) && $row->status === 'error';

			$events[] = [
				'status'    => $is_error ? 'error' : 'success',
				'timestamp' => (int) $row->created_ts,
				'event'     => $this->resolve_ability_label( $row->ability_name ),
				'actor'     => $user ? $user->display_name : __( 'Unknown', 'albert-ai-butler' ),
			];
		}

		// Sort by timestamp DESC and keep the most recent 5.
		usort(
			$events,
			static function ( array $a, array $b ): int {
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);
		$events = array_slice( $events, 0, 5 );

		$now      = time();
		$activity = [];
		foreach ( $events as $event ) {
			$activity[] = [
				'status' => $event['status'],
				'event'  => $event['event'],
				'actor'  => $event['actor'],
				'time'   => sprintf(
					/* translators: %s: Time difference */
					__( '%s ago', 'albert-ai-butler' ),
					human_time_diff( $event['timestamp'], $now )
				),
			];
		}

		return $activity;
	}

	/**
	 * Resolve a logged ability slug to its human-readable label.
	 *
	 * Prefers the in-memory abilities manager, whose label data is populated
	 * during bootstrap and is therefore available even on this admin page —
	 * where the WordPress Abilities API registry is not yet populated and
	 * calling wp_get_ability() would emit PHP notices. Abilities the manager
	 * does not hold (e.g. third-party abilities registered directly with
	 * WordPress) fall back to {@see AbilitiesRegistry::label_for()}, which
	 * itself prettifies the slug when the Abilities API is unavailable.
	 *
	 * @param string $slug Ability slug, e.g. `albert/find-posts`.
	 *
	 * @return string Human-readable label.
	 * @since 1.2.0
	 */
	private function resolve_ability_label( string $slug ): string {
		$manager = Plugin::get_instance()->get_abilities_manager();

		if ( $manager !== null ) {
			$label = $manager->get_label( $slug );
			if ( $label !== null && $label !== '' ) {
				return $label;
			}
		}

		return AbilitiesRegistry::label_for( $slug );
	}

	/**
	 * Render a status cell: a glowing dot paired with a visible word.
	 *
	 * The visible word is required — status is never conveyed by colour alone
	 * (WCAG 2.2 AA, 1.4.1).
	 *
	 * @param string $status One of `success`, `error`, or `connection`.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function render_status_dot( string $status ): void {
		switch ( $status ) {
			case 'error':
				$modifier = 'error';
				$word     = __( 'Failed', 'albert-ai-butler' );
				break;
			case 'connection':
				$modifier = 'connection';
				$word     = __( 'Connection', 'albert-ai-butler' );
				break;
			default:
				$modifier = 'success';
				$word     = __( 'Success', 'albert-ai-butler' );
				break;
		}
		?>
		<span class="albert-status-dot albert-status-dot--<?php echo esc_attr( $modifier ); ?>">
			<span class="albert-status-dot__dot" aria-hidden="true"></span>
			<span class="albert-status-dot__label"><?php echo esc_html( $word ); ?></span>
		</span>
		<?php
	}
}
