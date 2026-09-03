<?php
/**
 * Skills REST Controller
 *
 * REST API for the Albert → Skills admin screen.
 *
 * @package Albert
 * @subpackage Admin\Rest
 * @since      1.4.0
 */

namespace Albert\Admin\Rest;

use Albert\Admin\SkillsPayload;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the Skills screen's data.
 *
 * One route, one method: the screen is read-only, so there is nothing to write.
 * `GET` returns every registered skill with its full body, so the fly-in
 * renders it without a second round trip. The live precondition status
 * (`available`/`status`) rides along too but isn't rendered by 1.4.0's
 * screen — see {@see SkillsPayload::build()}.
 *
 * @since 1.4.0
 */
class SkillsController implements Hookable {

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
	 * Register the REST route under the plugin namespace.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::rest_namespace(),
			'/skills',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_skills' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	/**
	 * GET /skills: every registered skill for the screen.
	 *
	 * @return WP_REST_Response
	 * @since 1.4.0
	 */
	public function get_skills(): WP_REST_Response {
		return rest_ensure_response( SkillsPayload::build() );
	}
}
