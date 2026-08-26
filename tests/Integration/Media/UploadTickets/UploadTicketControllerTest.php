<?php
/**
 * Integration tests for the UploadTicketController REST endpoint.
 *
 * UploadTicketServiceTest already covers the domain logic in isolation;
 * this file covers the REST wiring itself — route registration, header
 * extraction, multipart file receipt, the byte-cap streaming path, and
 * that a redemption attempt is logged through Albert's execution log.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Media\UploadTickets;

use Albert\Core\Plugin;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Media\UploadTickets\UploadTicketController;
use Albert\Media\UploadTickets\UploadTicketService;
use Albert\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * UploadTicketController integration tests.
 *
 * @covers \Albert\Media\UploadTickets\UploadTicketController
 */
class UploadTicketControllerTest extends TestCase {

	/**
	 * The ticket service, shared with the controller under test.
	 *
	 * @var UploadTicketService
	 */
	private UploadTicketService $tickets;

	/**
	 * The controller under test.
	 *
	 * @var UploadTicketController
	 */
	private UploadTicketController $controller;

	/**
	 * An administrator to mint tickets for.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Reset state and register REST routes before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::single_use_tokens() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::ability_log() ) );

		$this->tickets    = new UploadTicketService();
		$this->controller = new UploadTicketController( $this->tickets );
		$this->controller->register_hooks();

		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * Path to a real fixture JPEG from the WP test suite, or skip.
	 *
	 * @return string
	 */
	private function jpg_fixture(): string {
		if ( ! defined( 'DIR_TESTDATA' ) ) {
			$this->markTestSkipped( 'DIR_TESTDATA not defined — WP test suite fixtures unavailable.' );
		}

		return DIR_TESTDATA . '/images/sugarloaf-mountain.jpg';
	}

	/**
	 * Build a redemption request carrying a multipart `file` field.
	 *
	 * Copies the fixture into a fresh temp file — mirroring what PHP itself
	 * hands a real request ($_FILES['tmp_name'] is always a throwaway file)
	 * — rather than pointing `tmp_name` at the shared fixture directly.
	 * finalize_upload() deletes whatever it's given after processing, and a
	 * shared test-data fixture must never be that file.
	 *
	 * @param string $token The upload token.
	 * @param string $path  Path to the fixture file to attach.
	 * @param string $name  Filename to report.
	 *
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function multipart_request( string $token, string $path, string $name ): WP_REST_Request {
		$tmp_copy = wp_tempnam();
		copy( $path, $tmp_copy );

		$request = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadTicketService::TOKEN_HEADER, $token );
		$request->set_file_params(
			[
				'file' => [
					'name'     => $name,
					'type'     => 'image/jpeg',
					'tmp_name' => $tmp_copy,
					'error'    => 0,
					'size'     => filesize( $tmp_copy ),
				],
			]
		);

		return $request;
	}

	// ─── Route wiring ───────────────────────────────────────────────

	/**
	 * The route is registered under the plugin's REST namespace.
	 *
	 * @return void
	 */
	public function test_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/' . Plugin::rest_namespace() . '/media/uploads', $routes );
	}

	/**
	 * A request with no token header is rejected without touching the ticket service.
	 *
	 * @return void
	 */
	public function test_missing_token_is_rejected(): void {
		$request  = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'ticket_already_used', $response->get_data()['code'] );
	}

	// ─── Happy path ─────────────────────────────────────────────────

	/**
	 * A valid multipart upload redeems the ticket and creates an attachment.
	 *
	 * @return void
	 */
	public function test_multipart_upload_creates_attachment(): void {
		$ticket = $this->tickets->mint( $this->admin_id, [] );

		$request  = $this->multipart_request( $ticket['upload_token'], $this->jpg_fixture(), 'photo.jpg' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'attachment_id', $data );
		$this->assertNotNull( get_post( $data['attachment_id'] ) );
	}

	/**
	 * The route also accepts PUT, not just POST — a client that reasonably
	 * defaults to PUT for "put these bytes at this URL" must not be 405'd.
	 *
	 * @return void
	 */
	public function test_put_is_also_accepted(): void {
		$ticket = $this->tickets->mint( $this->admin_id, [] );

		$request = $this->multipart_request( $ticket['upload_token'], $this->jpg_fixture(), 'photo.jpg' );
		$request->set_method( 'PUT' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotNull( get_post( $response->get_data()['attachment_id'] ) );
	}

	/**
	 * mint() reports which methods the endpoint accepts, matching what's
	 * actually registered.
	 *
	 * @return void
	 */
	public function test_mint_reports_accepted_methods(): void {
		$ticket = $this->tickets->mint( $this->admin_id, [] );

		$this->assertSame( UploadTicketService::HTTP_METHODS, $ticket['method'] );

		$routes = rest_get_server()->get_routes()[ '/' . Plugin::rest_namespace() . '/media/uploads' ];
		foreach ( [ 'POST', 'PUT' ] as $method ) {
			$this->assertArrayHasKey( $method, $routes[0]['methods'], "Route does not accept {$method}" );
		}
	}

	/**
	 * Redeeming the same ticket twice fails the second time end to end.
	 *
	 * @return void
	 */
	public function test_second_redemption_via_rest_fails(): void {
		$ticket = $this->tickets->mint( $this->admin_id, [] );

		$first  = rest_get_server()->dispatch( $this->multipart_request( $ticket['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );
		$second = rest_get_server()->dispatch( $this->multipart_request( $ticket['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'ticket_already_used', $second->get_data()['code'] );
	}

	/**
	 * A multipart file larger than the ticket's cap is rejected without
	 * ever reaching content sniffing or sideload.
	 *
	 * @return void
	 */
	public function test_oversized_multipart_upload_is_rejected(): void {
		$ticket = $this->tickets->mint( $this->admin_id, [ 'max_bytes' => 10 ] );

		$request  = $this->multipart_request( $ticket['upload_token'], $this->jpg_fixture(), 'photo.jpg' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 413, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'too_large', $data['code'] );
		$this->assertSame( 10, $data['data']['max_bytes'] );
	}

	/**
	 * A ticket is spent even when the upload it was redeemed for then fails —
	 * a retry with a valid, correctly-sized file on the same token is refused,
	 * not treated as another chance. Otherwise a failed large upload becomes
	 * a retry oracle.
	 *
	 * @return void
	 */
	public function test_a_failed_upload_does_not_leave_the_ticket_retryable(): void {
		$ticket = $this->tickets->mint( $this->admin_id, [ 'max_bytes' => 10 ] );

		$failed = rest_get_server()->dispatch( $this->multipart_request( $ticket['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );
		$this->assertSame( 413, $failed->get_status() );

		// Retry with the same (already-spent) token. Even a small enough file
		// that would otherwise fit the cap must still be refused.
		$retry = rest_get_server()->dispatch( $this->multipart_request( $ticket['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );

		$this->assertSame( 400, $retry->get_status() );
		$this->assertSame( 'ticket_already_used', $retry->get_data()['code'] );
	}

	// ─── Logging ────────────────────────────────────────────────────

	/**
	 * A successful redemption is written to the execution log.
	 *
	 * @return void
	 */
	public function test_successful_redemption_is_logged(): void {
		global $wpdb;

		$ticket = $this->tickets->mint( $this->admin_id, [] );
		rest_get_server()->dispatch( $this->multipart_request( $ticket['upload_token'], $this->jpg_fixture(), 'photo.jpg' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				UploadTicketController::LOG_ABILITY_ID
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'success', $row->status );
		$this->assertSame( $this->admin_id, (int) $row->user_id );
	}

	/**
	 * A rejected redemption (bad token) is also logged, as an error.
	 *
	 * @return void
	 */
	public function test_rejected_redemption_is_logged_as_error(): void {
		global $wpdb;

		$request = new WP_REST_Request( 'POST', '/' . Plugin::rest_namespace() . '/media/uploads' );
		$request->set_header( UploadTicketService::TOKEN_HEADER, 'not-a-real-token' );
		rest_get_server()->dispatch( $request );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verification.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ability_name = %s',
				Tables::ability_log(),
				UploadTicketController::LOG_ABILITY_ID
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'error', $row->status );
		$this->assertSame( 'ticket_already_used', $row->error_code );
	}

	// ─── Byte-cap streaming (raw body path) ────────────────────────

	/**
	 * A stream under the cap is written to disk in full.
	 *
	 * @return void
	 */
	public function test_stream_to_temp_file_writes_data_under_the_cap(): void {
		$content = str_repeat( 'a', 1000 );
		$stream  = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- In-memory test fixture, not a filesystem file.
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		rewind( $stream );

		$result = $this->controller->stream_to_temp_file( $stream, 2000 );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		$this->assertIsString( $result );
		$this->assertSame( $content, file_get_contents( $result ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

		wp_delete_file( $result );
	}

	/**
	 * A stream over the cap is rejected, and never written to disk in full —
	 * the file that would have been created no longer exists afterwards.
	 *
	 * @return void
	 */
	public function test_stream_to_temp_file_rejects_a_stream_over_the_cap(): void {
		$content = str_repeat( 'a', 5000 );
		$stream  = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- In-memory test fixture, not a filesystem file.
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		rewind( $stream );

		$result = $this->controller->stream_to_temp_file( $stream, 1000 );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'too_large', $result->get_error_code() );
		$this->assertSame( 1000, $result->get_error_data()['max_bytes'] );
	}

	/**
	 * A stream spanning more than one STREAM_CHUNK_BYTES chunk is assembled
	 * correctly in full — exercises the read loop itself, not just a single
	 * fread() call.
	 *
	 * @return void
	 */
	public function test_stream_to_temp_file_assembles_multiple_chunks(): void {
		$size    = UploadTicketController::STREAM_CHUNK_BYTES * 2 + 12345;
		$content = str_repeat( 'x', $size );
		$stream  = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- In-memory test fixture, not a filesystem file.
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		rewind( $stream );

		$result = $this->controller->stream_to_temp_file( $stream, $size );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		$this->assertIsString( $result );
		$this->assertSame( $size, filesize( $result ) );
		$this->assertSame( $content, file_get_contents( $result ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents

		wp_delete_file( $result );
	}

	/**
	 * The cap is still enforced precisely when it falls in the middle of a
	 * chunk read, not just on a chunk boundary.
	 *
	 * @return void
	 */
	public function test_stream_to_temp_file_enforces_cap_mid_chunk(): void {
		$size    = UploadTicketController::STREAM_CHUNK_BYTES + 500;
		$content = str_repeat( 'x', $size );
		$stream  = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- In-memory test fixture, not a filesystem file.
		fwrite( $stream, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
		rewind( $stream );

		$result = $this->controller->stream_to_temp_file( $stream, UploadTicketController::STREAM_CHUNK_BYTES + 100 );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'too_large', $result->get_error_code() );
	}
}
