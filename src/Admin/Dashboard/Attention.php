<?php
/**
 * Dashboard attention items
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin\Dashboard;

defined( 'ABSPATH' ) || exit;

use Albert\Core\AbilitiesRegistry;
use Albert\Core\AbilitiesState;
use Albert\Core\Plugin;
use Albert\Logging\Repository as LoggingRepository;
use Albert\MCP\Server as McpServer;
use Albert\MCP\Skills\SkillRegistry;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\ConnectionRetention;
use Albert\OAuth\Repositories\ClientRepository;

/**
 * What on this site needs the owner to do something.
 *
 * **The rule that keeps this card worth reading: it reports standing state,
 * never events.** The activity table beside it is the timeline, and anything
 * that merely happened belongs there. An item here is a condition that is still
 * true right now, or an automatic action that will happen unless somebody
 * intervenes. An ability whose *last* run failed is a condition; the same
 * failure scrolling past in the log is an event, and restating it here would
 * make the two halves of the screen echo each other.
 *
 * Two consequences follow, and both are deliberate:
 *
 * - **An item disappears when the thing it describes is fixed**, without anyone
 *   dismissing it. Fix the uploads folder and the failure clears itself.
 * - **A setting the owner deliberately chose is not an item.** Context being
 *   switched off is a choice, not a fault, and a dashboard that nags about
 *   choices teaches people to stop reading it.
 *
 * Dismissal is per item and only where dismissing is safe, see
 * {@see self::is_dismissible()}.
 *
 * Every check is cheap: options that are already autoloaded, one bulk log query
 * shared across every ability, and the connection list the screen loads anyway.
 *
 * @since 1.4.0
 */
class Attention {

	/**
	 * Where per-user dismissals are stored.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const DISMISSED_META = 'albert_dismissed_attention';

	/**
	 * The ajax action, which doubles as the nonce name.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const DISMISS_ACTION = 'albert_dismiss_attention';

	/**
	 * Tones, most urgent first. Also the sort order of the rendered list.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	private const TONE_ORDER = [ 'danger', 'warning', 'info' ];

	/**
	 * How recently an ability must have failed to count as a current problem.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const FAILURE_WINDOW_DAYS = 7;

	/**
	 * The most failures worth listing before the card becomes a wall.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const MAX_FAILURES = 3;

	/**
	 * Error codes that mean the ability worked and said no.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	private const REFUSAL_CODES = [
		'ability_permission_denied',
		'ability_invalid_input',
		'rest_forbidden',
	];

	/**
	 * The log, for the one bulk query this class makes.
	 *
	 * @since 1.4.0
	 * @var LoggingRepository
	 */
	private LoggingRepository $log;

	/**
	 * Constructor.
	 *
	 * @since 1.4.0
	 *
	 * @param LoggingRepository|null $log Injected in tests.
	 */
	public function __construct( ?LoggingRepository $log = null ) {
		$this->log = $log ?? new LoggingRepository();
	}

	/**
	 * Everything that needs the owner's attention, most urgent first.
	 *
	 * @since 1.4.0
	 *
	 * @param int $user_id User whose dismissals apply. 0 for the current user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function items( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		$items = array_merge(
			$this->failing_abilities(),
			$this->connections_about_to_go(),
			$this->invitations_needing_a_nudge(),
			$this->unreachable_skills(),
			$this->broken_endpoint_override()
		);

		/**
		 * Filters the Dashboard's attention items.
		 *
		 * An add-on appends its own findings here. Keep to the rule the built-in
		 * checks follow: a standing condition or a pending automatic action,
		 * never a restatement of something in the activity log, and never a
		 * setting the owner chose on purpose.
		 *
		 * Each item is `[ id, tone, tone_label, title, detail, action, dismissible ]`
		 * where `tone` is danger|warning|info and `action` is
		 * `[ 'label' => string, 'url' => string ]` or null.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, array<string, mixed>> $items   The items so far.
		 * @param int                              $user_id User the card is rendered for.
		 */
		$items = apply_filters( 'albert/dashboard/attention', $items, $user_id );

		if ( ! is_array( $items ) ) {
			return [];
		}

		$items = array_values(
			array_filter(
				$items,
				fn( $item ): bool => is_array( $item )
					&& isset( $item['id'], $item['title'] )
					&& ! $this->is_dismissed( (string) $item['id'], $user_id )
			)
		);

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$rank = static fn( array $i ): int => (int) array_search(
					$i['tone'] ?? 'info',
					self::TONE_ORDER,
					true
				);

				return $rank( $a ) <=> $rank( $b );
			}
		);

		return $items;
	}

	/**
	 * Abilities whose most recent run failed.
	 *
	 * This is the one genuine activity signal Free keeps. The log retains the
	 * newest rows per ability and status, so "did this ability last succeed or
	 * fail" survives indefinitely even though the timeline does not. It is
	 * reported as a state, never as a count, because a count would be capped by
	 * that retention and would therefore be a lie.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function failing_abilities(): array {
		$names = array_keys( AbilitiesRegistry::get_all_raw() );

		if ( $names === [] ) {
			return [];
		}

		$latest = $this->log->latest_bulk( $names );
		$items  = [];
		$cutoff = time() - ( self::FAILURE_WINDOW_DAYS * DAY_IN_SECONDS );

		foreach ( $latest as $ability_name => $row ) {
			if ( ! is_object( $row ) || ( $row->status ?? '' ) !== 'error' ) {
				continue;
			}

			// Only recent failures. The log keeps the last failure per ability
			// indefinitely, so without a window a single failed experiment from
			// months ago sits at the top of this card forever, in red, and the
			// card stops meaning "act on this".
			if ( (int) ( $row->created_ts ?? 0 ) < $cutoff ) {
				continue;
			}

			// A refusal is not a fault. These codes are the ability correctly
			// declining: the caller lacked the capability, or asked for
			// something that does not exist, or sent input that failed
			// validation. Reporting them as problems with the site trains an
			// owner to ignore the card, and the genuinely broken entry beside
			// them goes with it.
			if ( $this->is_expected_refusal( $row ) ) {
				continue;
			}

			$label = Plugin::get_instance()->get_abilities_manager()->get_label( (string) $ability_name );
			$when  = isset( $row->created_ts ) ? (int) $row->created_ts : 0;

			$items[] = [
				'id'          => 'ability-failed:' . $ability_name,
				'tone'        => 'danger',
				'tone_label'  => __( 'Failed', 'albert-ai-butler' ),
				/* translators: %s: the ability's name, e.g. "Upload media" */
				'title'       => sprintf( __( '%s failed the last time it ran', 'albert-ai-butler' ), $label ),
				'detail'      => $this->failure_detail( $row, $when ),
				'action'      => [
					'label' => __( 'See the ability', 'albert-ai-butler' ),
					'url'   => admin_url( 'admin.php?page=albert-abilities' ),
				],
				'dismissible' => false,
				'sort_ts'     => $when,
			];
		}

		// Newest first, then capped. Five broken abilities is a site-wide
		// problem, not five separate ones, and listing all of them pushes
		// everything else off the screen.
		usort(
			$items,
			static fn( array $a, array $b ): int => ( $b['sort_ts'] ?? 0 ) <=> ( $a['sort_ts'] ?? 0 )
		);

		return array_slice( $items, 0, self::MAX_FAILURES );
	}

	/**
	 * Whether a failure is the ability declining rather than breaking.
	 *
	 * Matched on the recorded code, plus the suffixes WordPress and Albert both
	 * use for the same idea, so a new `*_permission_denied` or `*_not_found`
	 * does not have to be added here to be understood.
	 *
	 * @since 1.4.0
	 *
	 * @param object $row Log row.
	 *
	 * @return bool
	 */
	private function is_expected_refusal( object $row ): bool {
		$code = isset( $row->error_code ) && is_string( $row->error_code ) ? $row->error_code : '';

		if ( $code === '' ) {
			return false;
		}

		if ( in_array( $code, self::REFUSAL_CODES, true ) ) {
			return true;
		}

		foreach ( [ '_permission_denied', '_not_found', '_invalid_id', '_invalid_input', '_exists' ] as $suffix ) {
			if ( str_ends_with( $code, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The sentence under a failed ability.
	 *
	 * Prefers the recorded error message, because "the uploads folder is not
	 * writable" is actionable and "it failed" is not. Falls back to the code,
	 * then to the time alone.
	 *
	 * @since 1.4.0
	 *
	 * @param object $row  Log row.
	 * @param int    $when Unix timestamp, 0 when unknown.
	 *
	 * @return string
	 */
	private function failure_detail( object $row, int $when ): string {
		$message = isset( $row->error_message ) && is_string( $row->error_message ) ? trim( $row->error_message ) : '';

		if ( $message === '' && isset( $row->error_code ) && is_string( $row->error_code ) ) {
			$message = trim( $row->error_code );
		}

		// A stored message is whatever the failing code chose to say, and some
		// of them are a paragraph. One line is what fits here; the ability's own
		// row carries the rest.
		if ( $message !== '' ) {
			$message = wp_strip_all_tags( $message );
			$message = mb_strimwidth( $message, 0, 120, "\u{2026}" );
		}

		$ago = $when > 0
			/* translators: %s: human readable time difference, e.g. "2 hours" */
			? sprintf( __( '%s ago', 'albert-ai-butler' ), human_time_diff( $when, time() ) )
			: '';

		if ( $message !== '' && $ago !== '' ) {
			/* translators: 1: when it failed, e.g. "2 hours ago", 2: the reason it failed */
			return sprintf( __( '%1$s. %2$s', 'albert-ai-butler' ), $ago, $message );
		}

		return $message !== '' ? $message : $ago;
	}

	/**
	 * Connections the retention sweep will remove, and when.
	 *
	 * Predicted rather than recorded: the sweep runs daily and reads the same
	 * connection list and the same option, so the date is arithmetic over data
	 * already in hand. Somebody losing a working integration to a sweep they
	 * did not know about is the failure this exists to prevent, which is also
	 * why the item cannot be dismissed.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function connections_about_to_go(): array {
		$days = (int) get_option( ConnectionRetention::NEVER_USED_OPTION, ConnectionRetention::DEFAULT_NEVER_USED_DAYS );

		if ( $days <= 0 ) {
			return [];
		}

		$items = [];
		$now   = time();

		foreach ( ( new ClientRepository() )->getLiveConnections() as $connection ) {
			if ( (int) ( $connection['last_used_ts'] ?? 0 ) > 0 ) {
				continue;
			}

			$created = (int) ( $connection['created_ts'] ?? 0 );

			if ( $created <= 0 ) {
				continue;
			}

			$drops_at = $created + ( $days * DAY_IN_SECONDS );
			$left     = $drops_at - $now;

			// Only worth saying once it is close. A fortnight of advance notice
			// on day one is noise, and noise is what stops the card being read.
			if ( $left <= 0 || $left > 7 * DAY_IN_SECONDS ) {
				continue;
			}

			$name = (string) ( $connection['label'] ?? '' );
			$name = $name !== '' ? $name : (string) ( $connection['name'] ?? __( 'An assistant', 'albert-ai-butler' ) );

			$items[] = [
				'id'          => 'connection-dropping:' . ( $connection['client_id'] ?? '' ),
				'tone'        => 'warning',
				'tone_label'  => __( 'Expiring', 'albert-ai-butler' ),
				'title'       => sprintf(
					/* translators: 1: connection name, 2: human readable time, e.g. "6 days" */
					__( '%1$s will be removed in %2$s', 'albert-ai-butler' ),
					$name,
					human_time_diff( $now, $drops_at )
				),
				'detail'      => sprintf(
					/* translators: %d: the number of days after which unused connections are dropped */
					_n(
						'It has never been used. Albert removes connections that go unused for %d day.',
						'It has never been used. Albert removes connections that go unused for %d days.',
						$days,
						'albert-ai-butler'
					),
					$days
				),
				'action'      => [
					'label' => __( 'Manage connections', 'albert-ai-butler' ),
					'url'   => admin_url( 'admin.php?page=albert-connections' ),
				],
				'dismissible' => false,
			];
		}

		return $items;
	}

	/**
	 * Invitations that expired, or are about to, without being taken up.
	 *
	 * Entries carried over from before 1.4.0 have no timestamps at all, by
	 * design, so they are skipped rather than described with invented dates.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function invitations_needing_a_nudge(): array {
		$items = [];
		$now   = time();

		foreach ( array_keys( AllowedUsers::all() ) as $user_id ) {
			$user_id = (int) $user_id;

			if ( AllowedUsers::has_authorised( $user_id ) ) {
				continue;
			}

			$expires = AllowedUsers::expires_at( $user_id );

			if ( $expires === null ) {
				continue;
			}

			$left = $expires - $now;

			if ( $left > 2 * DAY_IN_SECONDS ) {
				continue;
			}

			$user = get_userdata( $user_id );
			$name = $user instanceof \WP_User ? $user->display_name : __( 'Someone', 'albert-ai-butler' );

			$items[] = [
				'id'          => 'invitation:' . $user_id,
				'tone'        => 'warning',
				'tone_label'  => $left <= 0 ? __( 'Expired', 'albert-ai-butler' ) : __( 'Expiring', 'albert-ai-butler' ),
				'title'       => $left <= 0
					/* translators: %s: the invited person's name */
					? sprintf( __( "%s's invitation has expired", 'albert-ai-butler' ), $name )
					: sprintf(
						/* translators: 1: the invited person's name, 2: human readable time, e.g. "5 hours" */
						__( "%1\$s's invitation expires in %2\$s", 'albert-ai-butler' ),
						$name,
						human_time_diff( $now, $expires )
					),
				'detail'      => __( 'They have not connected an assistant yet, so the invitation was never used.', 'albert-ai-butler' ),
				'action'      => [
					'label' => __( 'Review access', 'albert-ai-butler' ),
					'url'   => admin_url( 'admin.php?page=albert-connections' ),
				],
				'dismissible' => true,
			];
		}

		return $items;
	}

	/**
	 * Skills that apply here but that no assistant can actually fetch.
	 *
	 * An index entry is a promise that the guidance applies to this site, and
	 * `albert/get-skill` is what redeems it. With that ability switched off the
	 * index still lists them and every fetch fails, so the two halves disagree.
	 * That is an inconsistency rather than a preference, which is what earns it
	 * a place beside genuine faults, and why it is the one built-in item that
	 * can be dismissed.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function unreachable_skills(): array {
		$available = SkillRegistry::available();

		if ( $available === [] || AbilitiesState::is_enabled( 'albert/get-skill' ) ) {
			return [];
		}

		return [
			[
				'id'          => 'skills-unreachable',
				'tone'        => 'info',
				'tone_label'  => __( 'Unreachable', 'albert-ai-butler' ),
				'title'       => sprintf(
					/* translators: %d: how many skills apply to this site */
					_n(
						'%d skill applies to this site but no assistant can read it',
						'%d skills apply to this site but no assistant can read them',
						count( $available ),
						'albert-ai-butler'
					),
					count( $available )
				),
				'detail'      => __( 'Fetching a skill needs the Get skill ability, which is switched off.', 'albert-ai-butler' ),
				'action'      => [
					'label' => __( 'Switch it on', 'albert-ai-butler' ),
					'url'   => admin_url( 'admin.php?page=albert-abilities' ),
				],
				'dismissible' => true,
			],
		];
	}

	/**
	 * An endpoint override that was rejected.
	 *
	 * The filter returned something `wp_http_validate_url()` refused, so the
	 * endpoint quietly fell back to this site's own address. Quietly is the
	 * problem: the address an owner copies is then not the one they configured,
	 * and nothing else on any screen says so.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function broken_endpoint_override(): array {
		if ( McpServer::get_external_url_state()['state'] !== 'invalid' ) {
			return [];
		}

		return [
			[
				'id'          => 'endpoint-override-invalid',
				'tone'        => 'danger',
				'tone_label'  => __( 'Ignored', 'albert-ai-butler' ),
				'title'       => __( 'The endpoint address set in code was rejected', 'albert-ai-butler' ),
				'detail'      => __( 'An albert/mcp/external_url filter returned an address Albert could not use, so assistants are being given this site\'s own address instead.', 'albert-ai-butler' ),
				'action'      => null,
				'dismissible' => false,
			],
		];
	}

	/**
	 * Whether an item may be dismissed.
	 *
	 * Advisory items may. Anything carrying a consequence may not, because
	 * dismissing a countdown hides the countdown and not the deletion: the
	 * connection still goes, and the owner who dismissed the warning is exactly
	 * the person who will not know why.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $item Attention item.
	 *
	 * @return bool
	 */
	public static function is_dismissible( array $item ): bool {
		return ! empty( $item['dismissible'] );
	}

	/**
	 * Whether this user has dismissed this exact finding.
	 *
	 * Keyed on the item id, which carries the subject with it
	 * (`ability-failed:albert/upload-media`), so dismissing one ability's
	 * failure never hides another's. Per user, because one administrator
	 * deciding they do not care must not blind the rest.
	 *
	 * @since 1.4.0
	 *
	 * @param string $id      Item id.
	 * @param int    $user_id User id.
	 *
	 * @return bool
	 */
	private function is_dismissed( string $id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$dismissed = get_user_meta( $user_id, self::DISMISSED_META, true );

		return is_array( $dismissed ) && in_array( $id, $dismissed, true );
	}

	/**
	 * Record a dismissal.
	 *
	 * @since 1.4.0
	 *
	 * @param string $id      Item id.
	 * @param int    $user_id User id.
	 *
	 * @return void
	 */
	public static function dismiss( string $id, int $user_id ): void {
		if ( $id === '' || $user_id <= 0 ) {
			return;
		}

		$dismissed = get_user_meta( $user_id, self::DISMISSED_META, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : [];

		if ( in_array( $id, $dismissed, true ) ) {
			return;
		}

		$dismissed[] = $id;

		update_user_meta( $user_id, self::DISMISSED_META, $dismissed );
	}
}
