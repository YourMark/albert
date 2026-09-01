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

use Albert\Core\AbilitiesState;
use Albert\Core\Plugin;
use Albert\MCP\Server as McpServer;
use Albert\MCP\Skills\SkillRegistry;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\ConnectionRetention;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Settings\Value;

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
 * {@see self::is_dismissible()}. It also **lapses**, and an item's id carries
 * the instance it is about rather than only its subject, so neither one can
 * turn a single click into a permanent blind spot. See
 * {@see self::is_dismissed()}.
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
	 * How long a dismissal holds before the finding is offered again.
	 *
	 * Long enough that clicking Dismiss is not pointless, short enough that a
	 * condition still true a season later gets said once more.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const DISMISSAL_DAYS = 90;

	/**
	 * Tones, most urgent first. Also the sort order of the rendered list.
	 *
	 * @since 1.4.0
	 * @var array<int, string>
	 */
	private const TONE_ORDER = [ 'danger', 'warning', 'info' ];

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

		// A tone this class does not know sorts last, not first. `array_search()`
		// returns false for a miss and (int) false is 0, which is `danger`'s own
		// rank, so an add-on item with a typo'd tone jumped the queue while
		// render_attention_item() drew it as `info`. The rank and the rendering
		// have to agree, and both now fall back to the least urgent reading.
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$rank = static function ( array $item ): int {
					$position = array_search( $item['tone'] ?? 'info', self::TONE_ORDER, true );

					return $position === false ? count( self::TONE_ORDER ) : (int) $position;
				};

				return $rank( $a ) <=> $rank( $b );
			}
		);

		return $items;
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
		// The same read the sweep makes, chain and all. A prediction derived
		// from a different source than the thing it predicts is not a
		// prediction: with a constant pinning the window, reading the stored
		// option here would count down to a date the sweep was never going to
		// act on.
		$days = (int) Value::get( ConnectionRetention::NEVER_USED_OPTION, ConnectionRetention::DEFAULT_NEVER_USED_DAYS );

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
				// The deadline is part of the id, so a fresh invitation to the
				// same person is a different finding. Without it, dismissing
				// one person's expiry notice silenced every later one they were
				// ever sent.
				'id'          => 'invitation:' . $user_id . ':' . $expires,
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
	 * Whether this user has dismissed this exact finding, recently enough for
	 * it to still count.
	 *
	 * Keyed on the item id, which carries the subject with it
	 * (`ability-failed:albert/upload-media`), so dismissing one ability's
	 * failure never hides another's. Per user, because one administrator
	 * deciding they do not care must not blind the rest.
	 *
	 * **A dismissal expires.** "I have seen this" is a statement about today,
	 * not a permanent instruction, and this card's whole contract is that an
	 * item is a condition that is *still true*. A dismissal that never lapsed
	 * turned the one advisory item somebody clicked away into a permanent
	 * blind spot: switch `albert/get-skill` back on and off again a year later
	 * and the card stays silent about it.
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

		$dismissed = self::dismissals( $user_id );

		if ( ! isset( $dismissed[ $id ] ) ) {
			return false;
		}

		return $dismissed[ $id ] > time() - ( self::DISMISSAL_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * This user's dismissals, as `id => unix timestamp`.
	 *
	 * @since 1.4.0
	 *
	 * @param int $user_id User id.
	 *
	 * @return array<string, int>
	 */
	private static function dismissals( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::DISMISSED_META, true );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$dismissals = [];

		foreach ( $raw as $id => $when ) {
			// A flat list of ids, with no timestamp, is the shape this used
			// before dismissals expired. Read it as long-lapsed rather than
			// guessing a date: the item comes back once, and is written in the
			// current shape the next time it is dismissed.
			if ( is_int( $id ) && is_string( $when ) ) {
				$dismissals[ $when ] = 0;
				continue;
			}

			if ( is_string( $id ) && is_numeric( $when ) ) {
				$dismissals[ $id ] = (int) $when;
			}
		}

		return $dismissals;
	}

	/**
	 * Record a dismissal.
	 *
	 * Lapsed entries are dropped on the way past. Nothing else ever prunes this
	 * meta, and an id carries its subject (every invitation, every ability),
	 * so an append-only list on a busy site grows without bound and is read on
	 * every Dashboard render.
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

		$now     = time();
		$cutoff  = $now - ( self::DISMISSAL_DAYS * DAY_IN_SECONDS );
		$current = array_filter(
			self::dismissals( $user_id ),
			static fn( int $when ): bool => $when > $cutoff
		);

		$current[ $id ] = $now;

		update_user_meta( $user_id, self::DISMISSED_META, $current );
	}
}
