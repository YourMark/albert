<?php
/**
 * Integration tests for the Dashboard's attention items.
 *
 * The rules tested here are what stop the card becoming wallpaper. Built
 * against a real site first, which is where they came from: the first version
 * reported every ability whose last run had failed, and on a real install that
 * meant seven red rows about one-off experiments from three months earlier,
 * most of them abilities correctly refusing bad input. A card like that gets
 * ignored, and the genuine warning next to it gets ignored with it.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin\Dashboard;

use Albert\Admin\Dashboard\Attention;
use Albert\Database\Tables;
use Albert\Logging\Repository as LoggingRepository;
use Albert\Tests\TestCase;

/**
 * Attention item tests.
 *
 * @covers \Albert\Admin\Dashboard\Attention
 */
class AttentionTest extends TestCase {

	/**
	 * An ability that is actually registered right now.
	 *
	 * Chosen at runtime rather than hardcoded: a fresh install disables every
	 * ability that can change data, and a disabled ability is unregistered, so
	 * its log rows are never even looked at. Naming one directly gave a test
	 * that failed for a reason that had nothing to do with what it asserted.
	 *
	 * @var string
	 */
	private string $ability = '';

	/**
	 * Pick a registered, non-transport ability to hang the fixtures on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		foreach ( array_keys( \Albert\Core\AbilitiesRegistry::get_all_raw() ) as $id ) {
			if ( ! \Albert\Core\AbilitiesRegistry::is_transport_ability( (string) $id ) ) {
				$this->ability = (string) $id;
				break;
			}
		}

		$this->assertNotSame( '', $this->ability, 'The suite needs at least one registered ability.' );
	}

	/**
	 * Write a failure row directly, so the age and code are ours to choose.
	 *
	 * `created_at` is left to the column default when "now" is wanted, matching
	 * what the logger does; MySQL's clock and UNIX_TIMESTAMP() then agree.
	 *
	 * @param string $code     Error code.
	 * @param int    $days_ago How long ago it failed.
	 *
	 * @return void
	 */
	private function record_failure( string $code, int $days_ago = 0 ): void {
		global $wpdb;

		$wpdb->insert(
			Tables::ability_log(),
			[
				'ability_name'  => $this->ability,
				'user_id'       => 1,
				'created_at'    => gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) ),
				'status'        => 'error',
				'error_code'    => $code,
				'error_message' => 'Something went wrong.',
			]
		);
	}

	/**
	 * Only this ability's items, so unrelated site state cannot sway a test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function failures(): array {
		$items = ( new Attention( new LoggingRepository() ) )->items( 1 );

		return array_values(
			array_filter(
				$items,
				static fn( array $item ): bool => str_starts_with( (string) $item['id'], 'ability-failed:' )
			)
		);
	}

	/**
	 * Clear this ability's rows between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wpdb;

		if ( $this->ability !== '' ) {
			$wpdb->delete( Tables::ability_log(), [ 'ability_name' => $this->ability ] );
		}

		parent::tear_down();
	}

	/**
	 * A recent, genuine failure is reported.
	 *
	 * @return void
	 */
	public function test_a_recent_failure_is_reported(): void {
		$this->record_failure( 'media_dir_unwritable' );

		$failures = $this->failures();

		$this->assertCount( 1, $failures );
		$this->assertSame( 'danger', $failures[0]['tone'] );
		$this->assertFalse( $failures[0]['dismissible'], 'A fault carries its fix, not a way to hide it.' );
	}

	/**
	 * An old failure is history, not a problem with the site today.
	 *
	 * The log keeps the last failure per ability indefinitely, so without this
	 * window one failed experiment sits on the Dashboard in red forever.
	 *
	 * @return void
	 */
	public function test_an_old_failure_is_not_reported(): void {
		$this->record_failure( 'media_dir_unwritable', 30 );

		$this->assertSame( [], $this->failures() );
	}

	/**
	 * An ability declining is not an ability breaking.
	 *
	 * @dataProvider refusal_codes
	 *
	 * @param string $code A code meaning "the ability worked and said no".
	 *
	 * @return void
	 */
	public function test_a_refusal_is_not_a_fault( string $code ): void {
		$this->record_failure( $code );

		$this->assertSame( [], $this->failures(), sprintf( '"%s" is a refusal, not a fault.', $code ) );
	}

	/**
	 * Codes that mean the ability refused rather than broke.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function refusal_codes(): array {
		return [
			'explicit permission denied' => [ 'ability_permission_denied' ],
			'invalid input'              => [ 'ability_invalid_input' ],
			'suffix: not found'          => [ 'term_not_found' ],
			'suffix: invalid id'         => [ 'rest_post_invalid_id' ],
			'suffix: already exists'     => [ 'existing_user_exists' ],
		];
	}

	/**
	 * Dismissal is per user and per finding, and a consequential item cannot
	 * be dismissed at all.
	 *
	 * @return void
	 */
	public function test_dismissal_is_scoped_to_the_finding(): void {
		$this->assertTrue( Attention::is_dismissible( [ 'dismissible' => true ] ) );
		$this->assertFalse( Attention::is_dismissible( [ 'dismissible' => false ] ) );
		$this->assertFalse( Attention::is_dismissible( [] ), 'Anything that does not say so is not dismissible.' );

		$this->record_failure( 'media_dir_unwritable' );
		$this->assertCount( 1, $this->failures() );

		// Dismissing a different finding must not hide this one.
		Attention::dismiss( 'ability-failed:albert/create-post', 1 );
		$this->assertCount( 1, $this->failures() );

		Attention::dismiss( 'ability-failed:' . $this->ability, 1 );
		$this->assertSame( [], $this->failures() );

		delete_user_meta( 1, Attention::DISMISSED_META );
	}

	/**
	 * Add-ons can contribute, and the filter runs before dismissal is applied
	 * so their items can be dismissed too.
	 *
	 * @return void
	 */
	public function test_addons_can_contribute_items(): void {
		add_filter(
			'albert/dashboard/attention',
			static function ( array $items ): array {
				$items[] = [
					'id'          => 'addon-thing',
					'tone'        => 'warning',
					'title'       => 'An add-on noticed something',
					'dismissible' => true,
				];

				return $items;
			}
		);

		$ids = array_column( ( new Attention( new LoggingRepository() ) )->items( 1 ), 'id' );
		$this->assertContains( 'addon-thing', $ids );

		Attention::dismiss( 'addon-thing', 1 );

		$ids = array_column( ( new Attention( new LoggingRepository() ) )->items( 1 ), 'id' );
		$this->assertNotContains( 'addon-thing', $ids );

		delete_user_meta( 1, Attention::DISMISSED_META );
		remove_all_filters( 'albert/dashboard/attention' );
	}
}
