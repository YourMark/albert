<?php
/**
 * Integration tests for the CreateUploadLink ability.
 *
 * The generic ability-contract, execute/schema, input-validation, and
 * permission-failure suites already cover this ability via
 * ProvidesAbilities auto-discovery — this file covers the parts specific
 * to it: that execute() actually delegates to UploadLinkService and a
 * minted link is genuinely redeemable end to end.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Media\CreateUploadLink;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Media\UploadLinks\UploadLinkService;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * CreateUploadLink ability integration tests.
 *
 * @covers \Albert\Abilities\WordPress\Media\CreateUploadLink
 */
class CreateUploadLinkAbilityTest extends TestCase {

	/**
	 * Run as administrator with all abilities enabled.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::single_use_tokens() ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * A minted link is genuinely redeemable through the same service the
	 * REST controller uses — proves the ability isn't just shaping a schema.
	 *
	 * @return void
	 */
	public function test_minted_ticket_is_redeemable(): void {
		$result = ( new CreateUploadLink() )->execute( [] );

		$this->assertIsArray( $result );

		$service = new UploadLinkService();
		$context = $service->redeem_link( $result['upload_token'] );

		$this->assertIsArray( $context );
		$this->assertSame( get_current_user_id(), $context['user_id'] );
	}

	/**
	 * A user without upload_files is denied at the ability's own permission
	 * check, before ever reaching the service.
	 *
	 * @return void
	 */
	public function test_check_permission_denies_without_upload_files(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = ( new CreateUploadLink() )->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
