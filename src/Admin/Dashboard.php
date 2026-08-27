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

use Albert\Admin\Connections\UserPickerModal;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\Core\Plugin;
use Albert\Logging\Repository as LoggingRepository;
use Albert\MCP\Server as McpServer;
use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;

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
			Assets::version( 'assets/css/admin-settings.css' )
		);

		wp_enqueue_script(
			'albert-admin-utils',
			ALBERT_PLUGIN_URL . 'assets/js/albert-admin-utils.js',
			[],
			ALBERT_VERSION,
			true
		);

		// The same script Connections and Settings load, not a Dashboard-specific
		// one. `admin-dashboard.js` used to exist purely to wire a copy button,
		// against its own `.albert-copy-btn` class, duplicating the handler in
		// `admin-settings.js` that every other screen already used. One endpoint
		// field, one class, one handler.
		wp_enqueue_script(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'albert-admin-utils' ],
			Assets::version( 'assets/js/admin-settings.js' ),
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

		// The onboarding checklist's "Choose users" button opens the Connections
		// screen's picker, so the dashboard loads the same script and the same
		// localised data rather than a copy of either.
		UserPickerModal::enqueue( 'dashboard' );
	}

	/**
	 * Render dashboard page.
	 *
	 * Two states, not one layout with conditional cards (handoff §8.1): an
	 * unfinished setup is a task list, a finished one is a status readout, and
	 * they answer different questions.
	 *
	 * There are deliberately no buttons in the header. "Manage abilities" and
	 * "View connections" are navigation, not actions; the submenu and the tab
	 * row already reach both, and on a finished setup there is no single next
	 * action to promote. The only filled button on the screen belongs to the
	 * current onboarding step, where there genuinely is one thing to do next.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		$has_allowed_users  = AllowedUsers::has_any();
		$active_connections = $this->get_active_connections_count();
		$has_connections    = $active_connections > 0;
		$setup_complete     = $has_allowed_users && $has_connections;
		$abilities          = $this->get_ability_counts();
		$mcp_endpoint       = McpServer::get_endpoint_url();

		?>
		<div class="wrap albert-dashboard-page">
			<div class="albert-page">
				<div class="albert-page__header">
					<div class="albert-page__text">
						<h1 class="albert-page__title"><?php esc_html_e( 'Albert', 'albert-ai-butler' ); ?></h1>
						<p class="albert-page__description">
							<?php esc_html_e( 'Connect your WordPress site to AI assistants like Claude and ChatGPT.', 'albert-ai-butler' ); ?>
						</p>
					</div>
				</div>

				<?php Notices::render( 'albert_connections' ); ?>

				<div class="albert-page__body">
					<?php
					if ( $setup_complete ) {
						$this->render_complete_state( $active_connections, $abilities, $mcp_endpoint );
					} else {
						$this->render_onboarding_state( $has_allowed_users, $has_connections, $abilities, $mcp_endpoint );
					}
					?>
				</div>
			</div>
		</div>
		<?php

		// The same picker the Connections screen opens, from the same markup and
		// the same script. Two pickers answering one question drift.
		UserPickerModal::render( 'dashboard' );
	}

	/**
	 * State B: setup complete.
	 *
	 * Status banner, then the figures this site can actually compute, then the
	 * two-column body: what happened recently on the left, where to point an
	 * assistant on the right.
	 *
	 * @param int                             $active_connections Live connection count.
	 * @param array{enabled: int, total: int} $abilities         Ability counts.
	 * @param string                          $mcp_endpoint       The MCP endpoint URL.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_complete_state( int $active_connections, array $abilities, string $mcp_endpoint ): void {
		?>
		<section class="albert-setupdone">
			<span class="albert-setupdone__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<div class="albert-setupdone__text">
				<p class="albert-setupdone__title"><?php esc_html_e( 'Albert is connected and ready', 'albert-ai-butler' ); ?></p>
				<p class="albert-setupdone__detail">
					<?php
					/* translators: 1: number of connected assistants, 2: number of enabled abilities, 3: total abilities. */
					$detail = _n(
						'Setup complete. %1$d assistant connected, %2$d of %3$d abilities enabled.',
						'Setup complete. %1$d assistants connected, %2$d of %3$d abilities enabled.',
						$active_connections,
						'albert-ai-butler'
					);

					printf(
						esc_html( $detail ),
						(int) $active_connections,
						(int) $abilities['enabled'],
						(int) $abilities['total']
					);
					?>
				</p>
			</div>
		</section>

		<?php $this->render_stat_row( $abilities, $active_connections ); ?>

		<div class="albert-dashboard__split">
			<?php $this->render_activity_card(); ?>

			<div class="albert-dashboard__aside">
				<?php $this->render_endpoint_card( $mcp_endpoint ); ?>
				<?php $this->render_resources_card(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * State A: setup unfinished.
	 *
	 * A progress bar and four numbered steps, with the current one carrying the
	 * only filled button on the screen. Beside it, what an assistant would be
	 * able to do here once connected.
	 *
	 * The handoff's sub line reads "Two steps left, about three minutes." The
	 * step count is computed and kept; the time estimate is dropped, because
	 * doc 70 §0 forbids rendering a number the site cannot compute and we have
	 * no idea how long anyone's setup takes.
	 *
	 * @param bool                            $has_allowed_users Whether anyone may approve an assistant.
	 * @param bool                            $has_connections   Whether anything is connected.
	 * @param array{enabled: int, total: int} $abilities        Ability counts.
	 * @param string                          $mcp_endpoint      The MCP endpoint URL.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_onboarding_state( bool $has_allowed_users, bool $has_connections, array $abilities, string $mcp_endpoint ): void {
		// Step 1 (installed) is always done; 4 (review abilities) is optional and
		// never blocks, so "left" counts the two that actually gate a connection.
		$done      = 1 + ( $has_allowed_users ? 1 : 0 ) + ( $has_connections ? 1 : 0 );
		$total     = 4;
		$remaining = max( 0, 3 - $done );
		?>
		<div class="albert-dashboard__split">
			<section class="albert-card albert-setup">
				<div class="albert-card__header albert-setup__head">
					<div class="albert-card__text">
						<h2 class="albert-card__title"><?php esc_html_e( 'Set up Albert', 'albert-ai-butler' ); ?></h2>
						<p class="albert-card__description">
							<?php
							printf(
								/* translators: %d: number of setup steps still to do. */
								esc_html( _n( '%d step left.', '%d steps left.', $remaining, 'albert-ai-butler' ) ),
								(int) $remaining
							);
							?>
						</p>
					</div>
					<?php
					$percent = (int) round( ( $done / $total ) * 100 );
					?>
					<div
						class="albert-setup__progress"
						role="progressbar"
						aria-valuenow="<?php echo esc_attr( (string) $done ); ?>"
						aria-valuemin="0"
						aria-valuemax="<?php echo esc_attr( (string) $total ); ?>"
						aria-label="<?php esc_attr_e( 'Setup progress', 'albert-ai-butler' ); ?>">
						<span class="albert-setup__progress-fill" style="inline-size: <?php echo esc_attr( (string) $percent ); ?>%;"></span>
					</div>
				</div>

				<ol class="albert-setup__steps">
					<?php
					$this->render_setup_step(
						1,
						true,
						false,
						__( 'Plugin installed', 'albert-ai-butler' ),
						__( 'Done. The OAuth server and MCP endpoint are running.', 'albert-ai-butler' )
					);
					?>

					<?php
					$this->render_setup_step(
						2,
						$has_allowed_users,
						! $has_allowed_users,
						__( 'Add an allowed user', 'albert-ai-butler' ),
						__( 'Only the users you pick here can authorise an AI assistant to act on this site.', 'albert-ai-butler' ),
						function (): void {
							?>
							<div class="albert-setup__actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>" class="button button-primary" data-albert-open-userpicker>
									<?php esc_html_e( 'Choose users', 'albert-ai-butler' ); ?>
								</a>
							</div>
							<?php
						}
					);
		?>

					<?php
					$this->render_setup_step(
						3,
						$has_connections,
						$has_allowed_users && ! $has_connections,
						__( 'Connect an AI assistant', 'albert-ai-butler' ),
						__( 'Paste this endpoint into Claude or ChatGPT as an MCP connector.', 'albert-ai-butler' ),
						function () use ( $mcp_endpoint ): void {
							?>
							<div class="albert-endpoint">
								<label class="screen-reader-text" for="albert-mcp-endpoint"><?php esc_html_e( 'MCP endpoint address', 'albert-ai-butler' ); ?></label>
								<input
									type="text"
									id="albert-mcp-endpoint"
									class="albert-endpoint__field"
									value="<?php echo esc_url( $mcp_endpoint ); ?>"
									readonly
								/>
								<button type="button" class="button button-secondary albert-copy-button" data-copy-target="albert-mcp-endpoint">
									<?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?>
								</button>
							</div>
							<?php
						}
					);
		?>

					<?php
					$review = sprintf(
						/* translators: %d: number of abilities enabled by default. */
						__( '%d abilities are enabled by default.', 'albert-ai-butler' ),
						$abilities['enabled']
					);
					$this->render_setup_step(
						4,
						false,
						false,
						__( 'Choose what Albert may do', 'albert-ai-butler' ),
						$review,
						function (): void {
							?>
							<p class="albert-setup__aside-link">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-abilities' ) ); ?>">
									<?php esc_html_e( 'Review them', 'albert-ai-butler' ); ?>
								</a>
							</p>
							<?php
						}
					);
		?>
				</ol>
			</section>

			<div class="albert-dashboard__aside">
				<?php $this->render_capability_card(); ?>
				<?php $this->render_resources_card(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one onboarding step.
	 *
	 * The marker is a numbered circle, and its state is carried by the shape
	 * (filled accent for current, success tick for done, outline for pending)
	 * plus the step's own text, never by colour alone.
	 *
	 * @param int           $number      The step's position, 1-indexed.
	 * @param bool          $is_done     Whether the step is complete.
	 * @param bool          $is_current  Whether this is the step to do next.
	 * @param string        $title       Step title.
	 * @param string        $description Step description.
	 * @param callable|null $extra       Optional renderer for the step's controls.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_setup_step( int $number, bool $is_done, bool $is_current, string $title, string $description, ?callable $extra = null ): void {
		$classes = 'albert-setup__step';
		if ( $is_done ) {
			$classes .= ' albert-setup__step--done';
		} elseif ( $is_current ) {
			$classes .= ' albert-setup__step--current';
		}
		?>
		<li class="<?php echo esc_attr( $classes ); ?>">
			<span class="albert-setup__marker" aria-hidden="true">
				<?php if ( $is_done ) { ?>
					<span class="dashicons dashicons-yes-alt"></span>
				<?php } else { ?>
					<?php echo esc_html( (string) $number ); ?>
				<?php } ?>
			</span>
			<div class="albert-setup__body">
				<p class="albert-setup__title">
					<?php echo esc_html( $title ); ?>
					<span class="screen-reader-text">
						<?php
						if ( $is_done ) {
							esc_html_e( '(done)', 'albert-ai-butler' );
						} elseif ( $is_current ) {
							esc_html_e( '(current step)', 'albert-ai-butler' );
						} else {
							esc_html_e( '(not started)', 'albert-ai-butler' );
						}
						?>
					</span>
				</p>
				<p class="albert-setup__description"><?php echo esc_html( $description ); ?></p>
				<?php
				if ( $extra !== null && ! $is_done ) {
					call_user_func( $extra );
				}
				?>
			</div>
		</li>
		<?php
	}

	/**
	 * The stat row: only figures this site can actually compute.
	 *
	 * Free has two. Calls, failures and median duration all need history Free
	 * does not retain ({@see \Albert\Logging\Repository} prunes to two rows per
	 * ability), so rather than a hardcoded Premium check for aggregates that do
	 * not exist yet, this is a seam: an add-on with the history appends its own
	 * tiles. Nothing hooks it today, so nothing renders beyond Free's two —
	 * never a zero, never an empty tile shaped like a promise.
	 *
	 * @param array{enabled: int, total: int} $abilities          Ability counts.
	 * @param int                             $active_connections Live connection count.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_stat_row( array $abilities, int $active_connections ): void {
		$disabled = max( 0, $abilities['total'] - $abilities['enabled'] );

		$abilities_meta = '';
		if ( $disabled > 0 ) {
			$abilities_meta = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=albert-abilities' ) ),
				esc_html(
					sprintf(
						/* translators: %d: number of abilities currently switched off. */
						_n( 'Review the %d you switched off', 'Review the %d you switched off', $disabled, 'albert-ai-butler' ),
						$disabled
					)
				)
			);
		}

		$stats = [
			[
				'label' => __( 'Enabled abilities', 'albert-ai-butler' ),
				'value' => sprintf(
					/* translators: 1: enabled ability count, 2: total ability count. */
					__( '%1$d / %2$d', 'albert-ai-butler' ),
					$abilities['enabled'],
					$abilities['total']
				),
				'meta'  => $abilities_meta,
			],
			[
				'label' => __( 'Active connections', 'albert-ai-butler' ),
				'value' => number_format_i18n( $active_connections ),
				'meta'  => esc_html( $this->get_connection_names() ),
			],
		];

		/**
		 * Filters the tiles shown in the Dashboard's stat row.
		 *
		 * Each tile is `[ 'label' => string, 'value' => string, 'meta' => string ]`,
		 * where `meta` may contain a link and is expected to be already escaped.
		 * Free seeds the two figures it can compute from data it retains; an
		 * add-on with its own history (call volume, failure rate, duration)
		 * appends tiles here rather than Free guessing at numbers it cannot
		 * verify. See docs/features/70-admin-design-system.md §4.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, array{label: string, value: string, meta: string}> $stats Stat tiles.
		 */
		$stats = apply_filters( 'albert/dashboard/stats', $stats );

		if ( ! is_array( $stats ) || empty( $stats ) ) {
			return;
		}
		?>
		<div class="albert-stat-row">
			<?php
			foreach ( $stats as $stat ) {
				if ( ! is_array( $stat ) || ! isset( $stat['label'], $stat['value'] ) ) {
					continue;
				}
				$meta = isset( $stat['meta'] ) && is_string( $stat['meta'] ) ? $stat['meta'] : '';
				?>
				<div class="albert-stat">
					<span class="albert-stat__label"><?php echo esc_html( (string) $stat['label'] ); ?></span>
					<span class="albert-stat__value"><?php echo esc_html( (string) $stat['value'] ); ?></span>
					<?php if ( $meta !== '' ) { ?>
						<?php // Pre-escaped by the builder above; add-ons are documented to do the same. ?>
						<span class="albert-stat__meta"><?php echo wp_kses( $meta, [ 'a' => [ 'href' => [] ] ] ); ?></span>
					<?php } ?>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * The recent-activity card: Free's whole logging surface, alongside the
	 * per-ability "last run" line on the Abilities screen.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_activity_card(): void {
		$recent_activity    = $this->get_recent_activity();
		$premium_not_active = ! class_exists( 'AlbertPremium\\AlbertPremiumService' );
		?>
		<section class="albert-card albert-dashboard__activity">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'Recent activity', 'albert-ai-butler' ); ?></h2>
				</div>
			</div>
			<?php if ( ! empty( $recent_activity ) ) { ?>
				<div class="albert-card__body albert-card__body--flush albert-activity-card__body">
					<table class="albert-log-table">
						<caption class="screen-reader-text"><?php esc_html_e( 'The most recent ability executions and connections', 'albert-ai-butler' ); ?></caption>
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
									<td class="albert-log-table__event">
										<span class="albert-log-table__label"><?php echo esc_html( $activity['event'] ); ?></span>
										<?php if ( $activity['id'] !== '' ) { ?>
											<code class="albert-log-table__id"><?php echo esc_html( $activity['id'] ); ?></code>
										<?php } ?>
									</td>
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
				<div class="albert-card__body">
					<p class="albert-dashboard__empty">
						<?php esc_html_e( 'Nothing has happened yet. Connect an AI assistant to get started.', 'albert-ai-butler' ); ?>
					</p>
				</div>
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
		</section>
		<?php
	}

	/**
	 * The MCP endpoint card, using the same `.albert-endpoint` markup and the
	 * same copy button as the Connections screen.
	 *
	 * @param string $mcp_endpoint The endpoint URL.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_endpoint_card( string $mcp_endpoint ): void {
		?>
		<section class="albert-card">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'MCP endpoint', 'albert-ai-butler' ); ?></h2>
					<p class="albert-card__description"><?php esc_html_e( 'Add this URL to your AI assistant as an MCP connector.', 'albert-ai-butler' ); ?></p>
				</div>
			</div>
			<div class="albert-card__body">
				<div class="albert-endpoint">
					<label class="screen-reader-text" for="albert-dashboard-endpoint"><?php esc_html_e( 'MCP endpoint address', 'albert-ai-butler' ); ?></label>
					<input
						type="text"
						id="albert-dashboard-endpoint"
						class="albert-endpoint__field"
						value="<?php echo esc_url( $mcp_endpoint ); ?>"
						readonly
					/>
					<button type="button" class="button button-secondary albert-copy-button" data-copy-target="albert-dashboard-endpoint">
						<?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?>
					</button>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * "What assistants can do here": the enabled ability count per category.
	 *
	 * Shown during onboarding, where the question it answers ("what am I
	 * actually switching on?") is live.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_capability_card(): void {
		$categories = $this->get_category_counts();

		if ( empty( $categories ) ) {
			return;
		}
		?>
		<section class="albert-card">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'What assistants can do here', 'albert-ai-butler' ); ?></h2>
				</div>
			</div>
			<div class="albert-card__body">
				<ul class="albert-dashboard__categories">
					<?php foreach ( $categories as $label => $count ) { ?>
						<li>
							<span class="albert-dashboard__category-label"><?php echo esc_html( $label ); ?></span>
							<span class="albert-dashboard__category-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
						</li>
					<?php } ?>
				</ul>
			</div>
		</section>
		<?php
	}

	/**
	 * The resources card, identical in both states.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function render_resources_card(): void {
		?>
		<section class="albert-card">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'Resources', 'albert-ai-butler' ); ?></h2>
				</div>
			</div>
			<div class="albert-card__body">
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
							<?php esc_html_e( 'Report an issue', 'albert-ai-butler' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
						</a>
					</li>
				</ul>
			</div>
		</section>
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
	 * Enabled and total ability counts.
	 *
	 * Returns the two numbers rather than a preformatted "65/65" string: the
	 * stat tile needs them apart to work out how many are switched off, and the
	 * status line needs them apart to put them in a translatable sentence.
	 *
	 * @return array{enabled: int, total: int}
	 * @since 1.4.0
	 */
	private function get_ability_counts(): array {
		$disabled_abilities = AbilitiesPage::get_disabled_abilities();

		// Raw registry, not wp_get_abilities(): the count reports what the site has,
		// so it must not shrink because a plugin filtered the presented view.
		$all_abilities = AbilitiesRegistry::get_all_raw();

		$enabled_count = 0;

		foreach ( $all_abilities as $ability ) {
			$name = $ability->get_name();
			// Ability is enabled if NOT in the disabled list.
			if ( ! in_array( $name, $disabled_abilities, true ) ) {
				++$enabled_count;
			}
		}

		return [
			'enabled' => $enabled_count,
			'total'   => count( $all_abilities ),
		];
	}

	/**
	 * Enabled ability count per category, for "What assistants can do here".
	 *
	 * Counts only what is switched on: the card answers "what could an
	 * assistant do here right now", which a total including disabled abilities
	 * would overstate.
	 *
	 * @return array<string, int> Category label => enabled count, highest first.
	 * @since 1.4.0
	 */
	private function get_category_counts(): array {
		$disabled = AbilitiesPage::get_disabled_abilities();

		// The label map is optional: `wp_get_ability_categories()` is a WordPress
		// Abilities API function that may not exist, and the integration suite
		// runs without core's own `site`/`user` categories registered. Missing
		// entries fall back to a prettified slug rather than dropping the row.
		$categories = function_exists( 'wp_get_ability_categories' ) ? wp_get_ability_categories() : [];

		$counts = [];

		foreach ( AbilitiesRegistry::get_all_raw() as $ability ) {
			if ( in_array( $ability->get_name(), $disabled, true ) ) {
				continue;
			}

			if ( ! method_exists( $ability, 'get_category' ) ) {
				continue;
			}

			$slug = (string) $ability->get_category();

			if ( $slug === '' ) {
				continue;
			}

			$label = $this->category_label( $slug, $categories );

			$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
		}

		arsort( $counts );

		return $counts;
	}

	/**
	 * Resolve a category slug to its human label.
	 *
	 * Mirrors {@see AbilitiesPayload::category_label()}, including the
	 * prettified-slug fallback, so the Dashboard and the Abilities screen never
	 * name the same category two different ways.
	 *
	 * @param string               $slug       Category slug.
	 * @param array<string, mixed> $categories Map from wp_get_ability_categories().
	 *
	 * @return string
	 * @since 1.4.0
	 */
	private function category_label( string $slug, array $categories ): string {
		$category = $categories[ $slug ] ?? null;

		if ( is_object( $category ) && method_exists( $category, 'get_label' ) ) {
			return (string) $category->get_label();
		}

		if ( is_array( $category ) && isset( $category['label'] ) ) {
			return (string) $category['label'];
		}

		return ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );
	}

	/**
	 * The names of the currently connected clients, for the stat tile's meta line.
	 *
	 * Names are self-reported by the connecting app, and the owner's own label
	 * takes precedence where one exists, matching the Connections screen. Past
	 * two, this says "and N more" rather than growing the tile.
	 *
	 * @return string A short, already-plain list, or an empty string when nothing is connected.
	 * @since 1.4.0
	 */
	private function get_connection_names(): string {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT COALESCE( NULLIF( c.label, "" ), c.name ) AS display_name
				FROM %i c
				INNER JOIN %i t ON t.client_id = c.client_id
				WHERE t.revoked = 0
				ORDER BY display_name ASC',
				$tables['clients'],
				$tables['access_tokens']
			)
		);

		$names = array_values( array_filter( array_map( 'strval', (array) $names ) ) );

		if ( empty( $names ) ) {
			return '';
		}

		if ( count( $names ) <= 2 ) {
			return implode( ', ', $names );
		}

		$shown = array_slice( $names, 0, 2 );

		return sprintf(
			/* translators: 1: a comma-separated list of assistant names, 2: how many further assistants are connected. */
			_n( '%1$s and %2$d more', '%1$s and %2$d more', count( $names ) - 2, 'albert-ai-butler' ),
			implode( ', ', $shown ),
			count( $names ) - 2
		);
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
	 * @return array<int, array{status: string, event: string, id: string, actor: string, time: string}> Recent activity rows.
	 * @since 1.0.0
	 */
	private function get_recent_activity(): array {
		global $wpdb;
		$tables = Tables::oauth();

		// Get the most recent distinct connections: one row per client, not per
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
					__( 'New connection: %s', 'albert-ai-butler' ),
					$client_name
				);
			}

			$events[] = [
				'status'    => 'connection',
				'timestamp' => (int) $row->created_ts,
				'event'     => $event,
				'id'        => '',
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
				'id'        => (string) $row->ability_name,
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
				'id'     => $event['id'],
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
	 * during bootstrap and is therefore available even on this admin page,
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
	 * The visible word is required: status is never conveyed by colour alone
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
