<?php
/**
 * Create Upload Link Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Media
 * @since      1.4.0
 */

namespace Albert\Abilities\WordPress\Media;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use Albert\Media\UploadTickets\UploadTicketService;
use WP_Error;

/**
 * Create Upload Link Ability class
 *
 * Mints a short-lived, single-use HTTP endpoint an assistant can PUT/POST
 * bytes to directly, for the case URL sideload can't cover: the assistant
 * has the file itself (a generated image, a user-supplied file) rather than
 * a URL to fetch. Pushing those bytes through MCP tool arguments would mean
 * base64, a 33% size penalty on a payload that already counts against the
 * conversation's context window — this ability hands back a plain HTTP
 * endpoint instead and gets out of the way.
 *
 * Named "link", not "ticket": the ability is the external surface an LLM or
 * a site owner reads from a tool list, and "ticket" reads as a support/issue
 * ticket in that context. The single-use, hashed, expiring credential this
 * mints is still a "ticket" as domain vocabulary internally — see
 * {@see UploadTicketService} — that word just doesn't belong on the public name.
 *
 * @since 1.4.0
 */
class CreateUploadLink extends BaseAbility {

	/**
	 * The upload ticket service.
	 *
	 * @since 1.4.0
	 * @var UploadTicketService
	 */
	private UploadTicketService $tickets;

	/**
	 * Constructor.
	 *
	 * @param UploadTicketService|null $tickets Optional service override (tests).
	 *
	 * @since 1.4.0
	 */
	public function __construct( ?UploadTicketService $tickets = null ) {
		$this->tickets = $tickets ?? new UploadTicketService();

		$this->id          = 'albert/create-upload-link';
		$this->label       = __( 'Create Upload Link', 'albert-ai-butler' );
		$this->description = __( 'Mint a short-lived, single-use link that accepts an uploaded file and adds it to the media library.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'media';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::action(
				'Use this when you have the file\'s bytes rather than a URL — otherwise `albert/upload-media` is simpler. '
				. 'The returned `upload_token` must be sent in the `token_header` header, never in the URL: URLs end up '
				. 'in logs and referrers. The link accepts POST (multipart `file` field) or PUT (raw body) — use whichever '
				. 'your HTTP tooling makes easier. The link expires in minutes and works exactly once.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> Input schema.
	 * @since 1.4.0
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'accepted_types' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Optional MIME types to narrow the upload to (e.g. ["image/jpeg", "image/png"]). Defaults to everything the current user is allowed to upload.',
					'default'     => [],
				],
				'max_bytes'      => [
					'type'        => 'integer',
					'description' => 'Optional byte cap for the upload. Defaults to a conservative cap; always limited to what the server itself accepts.',
					'default'     => 0,
				],
				'post_id'        => [
					'type'        => 'integer',
					'description' => 'Optional post ID to attach the resulting media item to.',
					'default'     => 0,
				],
			],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> Output schema.
	 * @since 1.4.0
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'upload_url'     => [
					'type'        => 'string',
					'description' => 'The endpoint to send the file to.',
				],
				'upload_token'   => [
					'type'        => 'string',
					'description' => 'Single-use credential. Send it in the token_header header — never as part of the URL.',
				],
				'token_header'   => [
					'type'        => 'string',
					'description' => 'The header name to send upload_token in.',
				],
				'method'         => [
					'type'        => 'string',
					'description' => 'HTTP method(s) the endpoint accepts.',
				],
				'expires_at'     => [
					'type'        => 'string',
					'description' => 'UTC datetime the link stops working.',
				],
				'max_bytes'      => [
					'type'        => 'integer',
					'description' => 'The byte cap enforced on the upload.',
				],
				'accepted_types' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'MIME types this link will accept.',
				],
				'post_id'        => [
					'type'        => 'integer',
					'description' => 'The post the resulting media item will be attached to, if any.',
				],
				'curl_example'   => [
					'type'        => 'string',
					'description' => 'A ready-to-run curl command that redeems the link.',
				],
			],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.4.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'upload_files' );
	}

	/**
	 * Execute the ability — mint an upload link.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>|WP_Error Link data on success, WP_Error on failure.
	 * @since 1.4.0
	 */
	public function execute( array $args ): array|WP_Error {
		return $this->tickets->mint( get_current_user_id(), $args );
	}
}
