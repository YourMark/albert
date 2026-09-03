<?php
/**
 * Context REST Controller
 *
 * REST API for the Albert → Context admin screen.
 *
 * @package Albert
 * @subpackage Admin\Rest
 * @since      1.4.0
 */

namespace Albert\Admin\Rest;

use Albert\Admin\ContextPayload;
use Albert\Context\ContextSettings;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Serves and persists the Context screen's state.
 *
 * Two routes, both `manage_options`. `GET` returns everything the screen
 * renders: settings, detected sections, per-section costs and the payload
 * preview.
 * `POST` saves a partial change and returns the same shape, which is what lets
 * the screen show the recomputed budget and preview from the save response
 * rather than following every write with a read.
 *
 * There is no separate preview route on purpose. A preview endpoint that
 * assembled the payload from what the client sent would be previewing the
 * client's opinion; this one previews what is stored, which is what the
 * assistant will actually receive.
 *
 * @since 1.4.0
 */
class ContextController implements Hookable {

	use RequiresManageOptions;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the REST routes under the plugin namespace.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_routes(): void {
		$namespace = Plugin::rest_namespace();

		register_rest_route(
			$namespace,
			'/context',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_context' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_context' ],
					'permission_callback' => [ $this, 'check_permission' ],
					'args'                => [
						'enabled'      => [
							'type'     => 'boolean',
							'required' => false,
						],
						'instructions' => [
							'type'     => 'string',
							'required' => false,
						],
						'sections'     => [
							'type'                 => 'object',
							'required'             => false,
							'additionalProperties' => [ 'type' => 'boolean' ],
						],
					],
				],
			]
		);
	}

	/**
	 * GET /context: everything the screen renders.
	 *
	 * @return WP_REST_Response The full screen payload.
	 * @since 1.4.0
	 */
	public function get_context(): WP_REST_Response {
		return rest_ensure_response( ContextPayload::build() );
	}

	/**
	 * POST /context: save a partial change and return the new state.
	 *
	 * Only the keys present in the request body change; the screen saves one
	 * control at a time, so a request that carried the whole state would let a
	 * stale tab overwrite a change made in another one.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The request.
	 *
	 * @return WP_REST_Response The screen payload, recomputed after the save.
	 * @since 1.4.0
	 */
	public function update_context( WP_REST_Request $request ): WP_REST_Response {
		$input = [];

		foreach ( [ 'enabled', 'instructions', 'sections' ] as $key ) {
			$value = $request->get_param( $key );

			if ( $value !== null ) {
				$input[ $key ] = $value;
			}
		}

		ContextSettings::save( $input );

		return rest_ensure_response( ContextPayload::build() );
	}
}
