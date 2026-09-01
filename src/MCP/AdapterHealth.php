<?php
/**
 * Site Health reporting for the MCP endpoint.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.4.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;

/**
 * AdapterHealth class
 *
 * Puts "is the MCP endpoint actually able to work" somewhere a site owner can
 * find it on purpose, rather than only in a banner they may have dismissed or
 * never loaded the right screen to see.
 *
 * It is the same question {@see AdapterStatus} answers for the admin notice.
 * The reason it is worth answering twice is that the failure it describes is
 * indistinguishable from an authentication problem at the endpoint itself, so
 * the person debugging it is usually looking anywhere except the plugin.
 *
 * @since 1.4.0
 */
class AdapterHealth implements Hookable {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_filter( 'site_status_tests', [ $this, 'add_test' ] );
		add_filter( 'debug_information', [ $this, 'add_debug_information' ] );

		// The scan is a filesystem walk over active plugins; the set of those
		// only changes when one is activated or deactivated.
		add_action( 'activated_plugin', [ AdapterStatus::class, 'flush' ] );
		add_action( 'deactivated_plugin', [ AdapterStatus::class, 'flush' ] );
	}

	/**
	 * Register the direct Site Health test.
	 *
	 * @param array<string, mixed> $tests Registered tests.
	 *
	 * @return array<string, mixed> Tests with ours added.
	 * @since 1.4.0
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['albert_mcp_adapter'] = [
			'label' => __( 'Albert MCP endpoint', 'albert-ai-butler' ),
			'test'  => [ $this, 'run_test' ],
		];

		return $tests;
	}

	/**
	 * The test result.
	 *
	 * @return array<string, mixed> Site Health result array.
	 * @since 1.4.0
	 */
	public function run_test(): array {
		if ( ! AdapterStatus::scoped_adapter_available() ) {
			return $this->result(
				'critical',
				__( 'Albert&#8217;s MCP endpoint is switched off', 'albert-ai-butler' ),
				sprintf(
					'<p>%s</p><p><code>%s</code></p>',
					esc_html__( 'The bundled MCP library is missing, so no MCP server is registered. Every request to the endpoint answers with an authentication error, which is misleading: no token would work, because there is nothing there to authenticate against. This happens when the plugin is installed from source and its dependencies were installed without development requirements. Reinstall from an official release zip, or rebuild the bundled library:', 'albert-ai-butler' ),
					esc_html( 'composer install && composer run mozart' )
				)
			);
		}

		$foreign = AdapterStatus::foreign_copies();

		if ( $foreign !== [] ) {
			return $this->result(
				'critical',
				__( 'Another plugin is bundling the same MCP library', 'albert-ai-butler' ),
				sprintf(
					'<p>%s</p><ul><li><code>%s</code></li></ul>',
					esc_html__( 'These active plugins ship their own unscoped copy of the MCP library. Two copies cannot both register a server, so Albert&#8217;s never registers and its endpoint answers with a misleading authentication error. Deactivate them, or ask their authors to namespace-scope the dependency:', 'albert-ai-butler' ),
					implode( '</code></li><li><code>', array_map( 'esc_html', array_keys( $foreign ) ) )
				)
			);
		}

		return $this->result(
			'good',
			__( 'Albert&#8217;s MCP endpoint is available', 'albert-ai-butler' ),
			'<p>' . esc_html__( 'The bundled MCP library is present and no other plugin is competing with it.', 'albert-ai-butler' ) . '</p>'
		);
	}

	/**
	 * Add the same facts to the Site Health debug report, for support copy-paste.
	 *
	 * @param array<string, mixed> $info Debug information.
	 *
	 * @return array<string, mixed> Info with ours added.
	 * @since 1.4.0
	 */
	public function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$foreign = AdapterStatus::foreign_copies();

		$info['albert'] = [
			'label'  => __( 'Albert', 'albert-ai-butler' ),
			'fields' => [
				'mcp_library'      => [
					'label' => __( 'Bundled MCP library', 'albert-ai-butler' ),
					'value' => AdapterStatus::scoped_adapter_available()
						? __( 'present', 'albert-ai-butler' )
						: __( 'MISSING — the MCP endpoint cannot work', 'albert-ai-butler' ),
				],
				'mcp_other_copies' => [
					'label' => __( 'Other plugins bundling it', 'albert-ai-butler' ),
					'value' => $foreign === [] ? __( 'none', 'albert-ai-butler' ) : implode( ', ', array_keys( $foreign ) ),
				],
			],
		];

		return $info;
	}

	/**
	 * Build a Site Health result array.
	 *
	 * @param string $status      One of `good`, `recommended` or `critical`.
	 * @param string $label       Result heading.
	 * @param string $description Result body, already escaped HTML.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	private function result( string $status, string $label, string $description ): array {
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [
				'label' => __( 'Albert', 'albert-ai-butler' ),
				'color' => $status === 'good' ? 'blue' : 'red',
			],
			'description' => $description,
			'test'        => 'albert_mcp_adapter',
		];
	}
}
