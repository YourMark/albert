<?php
/**
 * Per-client MCP setup instructions.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.4.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * The curated list of assistants Albert tells you how to connect.
 *
 * One place, deliberately. The same content has to appear on the Connections
 * screen today and in a CLI-produced setup page later, and a list that lives
 * inside a render method can only ever appear once. Everything a caller needs
 * to draw an entry (the steps, the file path, the snippet) is data on the
 * entry, so a second surface renders it without re-deriving anything.
 *
 * Every guide is offered regardless of whether this site is reachable from the
 * internet. A cloud client that cannot reach a `.test` or private host will
 * simply fail to connect when tried; that is a clearer signal than a guide
 * that silently disappears with no explanation on screen.
 *
 * **Short and accurate beats long and stale.** Six entries is a decision, not a
 * limit: a list of twenty clients nobody re-checks is worse than five that are
 * right, because a wrong instruction costs more than a missing one. A client
 * only earns a deeplink when it genuinely publishes one; the rest get a copy
 * button, which always works.
 *
 * @phpstan-type AlbertClientGuide array{
 *     id: string,
 *     label: string,
 *     description: string,
 *     config_path: string,
 *     steps: array<int, string>,
 *     snippet: string,
 *     snippet_label: string,
 *     deeplink: string,
 *     deeplink_label: string
 * }
 *
 * @since 1.4.0
 */
class ClientSetupGuides {

	/**
	 * Every guide, ready to render.
	 *
	 * @param string $endpoint The live MCP endpoint URL for this site.
	 *
	 * @return array<int, AlbertClientGuide> Every guide, in display order.
	 * @since 1.4.0
	 */
	public static function all( string $endpoint ): array {
		return [
			self::claude_desktop( $endpoint ),
			self::claude_code( $endpoint ),
			self::cursor( $endpoint ),
			self::vs_code( $endpoint ),
			self::chatgpt( $endpoint ),
			self::generic( $endpoint ),
		];
	}

	/**
	 * Claude Desktop and claude.ai.
	 *
	 * @param string $endpoint The MCP endpoint URL.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function claude_desktop( string $endpoint ): array {
		return [
			'id'             => 'claude',
			'label'          => __( 'Claude Desktop and claude.ai', 'albert-ai-butler' ),
			'description'    => __( 'Connects through Anthropic, so your site needs a public web address.', 'albert-ai-butler' ),
			'config_path'    => '',
			'steps'          => [
				__( 'Open Settings, then Connectors.', 'albert-ai-butler' ),
				__( 'Choose "Add custom connector".', 'albert-ai-butler' ),
				__( 'Paste the address below as the connector URL and save.', 'albert-ai-butler' ),
				__( 'Claude opens a WordPress sign-in page. Sign in as a user you allowed below, and approve the request.', 'albert-ai-butler' ),
			],
			'snippet'        => $endpoint,
			'snippet_label'  => __( 'Connector URL', 'albert-ai-butler' ),
			'deeplink'       => '',
			'deeplink_label' => '',
		];
	}

	/**
	 * Claude Code.
	 *
	 * @param string $endpoint The MCP endpoint URL.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function claude_code( string $endpoint ): array {
		return [
			'id'             => 'claude-code',
			'label'          => __( 'Claude Code', 'albert-ai-butler' ),
			'description'    => __( 'Runs on your own computer, so it works with a local site too.', 'albert-ai-butler' ),
			'config_path'    => '',
			'steps'          => [
				__( 'Run the command below in a terminal.', 'albert-ai-butler' ),
				__( 'Claude Code opens your browser to sign in to WordPress and approve the connection.', 'albert-ai-butler' ),
				/* translators: %s: the /mcp slash command. */
				sprintf( __( 'Check it worked by running %s inside Claude Code.', 'albert-ai-butler' ), '/mcp' ),
			],
			'snippet'        => sprintf( 'claude mcp add --transport http albert %s', $endpoint ),
			'snippet_label'  => __( 'Terminal command', 'albert-ai-butler' ),
			'deeplink'       => '',
			'deeplink_label' => '',
		];
	}

	/**
	 * Cursor.
	 *
	 * @param string $endpoint The MCP endpoint URL.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function cursor( string $endpoint ): array {
		$server_config = [
			'url' => $endpoint,
		];

		return [
			'id'             => 'cursor',
			'label'          => __( 'Cursor', 'albert-ai-butler' ),
			'description'    => __( 'Runs on your own computer. Add it once for every project, or per project.', 'albert-ai-butler' ),
			'config_path'    => '~/.cursor/mcp.json (' . __( 'all projects', 'albert-ai-butler' ) . ')  ·  .cursor/mcp.json (' . __( 'this project only', 'albert-ai-butler' ) . ')',
			'steps'          => [
				__( 'Create or open one of the files named above.', 'albert-ai-butler' ),
				__( 'Add the block below. If the file already has an "mcpServers" section, add the "albert" entry inside it.', 'albert-ai-butler' ),
				__( 'Reopen Cursor, then approve the WordPress sign-in it offers.', 'albert-ai-butler' ),
			],
			'snippet'        => self::json_snippet( [ 'mcpServers' => [ 'albert' => $server_config ] ] ),
			'snippet_label'  => __( 'mcp.json', 'albert-ai-butler' ),
			// Cursor publishes this scheme for one-click installs; the config is
			// the server object, base64-encoded. Encoding here is Cursor's wire
			// format, not obfuscation.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'deeplink'       => 'cursor://anysphere.cursor-deeplink/mcp/install?name=albert&config=' . rawurlencode( base64_encode( (string) wp_json_encode( $server_config ) ) ),
			'deeplink_label' => __( 'Add to Cursor', 'albert-ai-butler' ),
		];
	}

	/**
	 * Visual Studio Code.
	 *
	 * @param string $endpoint The MCP endpoint URL.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function vs_code( string $endpoint ): array {
		$server_config = [
			'type' => 'http',
			'url'  => $endpoint,
		];

		return [
			'id'             => 'vscode',
			'label'          => __( 'Visual Studio Code', 'albert-ai-butler' ),
			'description'    => __( 'Runs on your own computer. Needs GitHub Copilot in agent mode.', 'albert-ai-butler' ),
			'config_path'    => '.vscode/mcp.json',
			'steps'          => [
				__( 'Create the file above in your project folder.', 'albert-ai-butler' ),
				__( 'Add the block below, then use the Start action VS Code shows above the entry.', 'albert-ai-butler' ),
				__( 'Approve the WordPress sign-in, then pick Albert\'s tools from the tools list in agent mode.', 'albert-ai-butler' ),
			],
			'snippet'        => self::json_snippet( [ 'servers' => [ 'albert' => $server_config ] ] ),
			'snippet_label'  => __( 'mcp.json', 'albert-ai-butler' ),
			// VS Code's documented install URI: the query string is the server
			// object with its name, URL-encoded.
			'deeplink'       => 'vscode:mcp/install?' . rawurlencode( (string) wp_json_encode( array_merge( [ 'name' => 'albert' ], $server_config ) ) ),
			'deeplink_label' => __( 'Add to VS Code', 'albert-ai-butler' ),
		];
	}

	/**
	 * ChatGPT.
	 *
	 * @param string $endpoint The MCP endpoint URL.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function chatgpt( string $endpoint ): array {
		return [
			'id'             => 'chatgpt',
			'label'          => __( 'ChatGPT', 'albert-ai-butler' ),
			'description'    => __( 'Connects through OpenAI, so your site needs a public web address. Connectors are not on every ChatGPT plan.', 'albert-ai-butler' ),
			'config_path'    => '',
			'steps'          => [
				__( 'Open Settings, then Connectors, and create a new connector.', 'albert-ai-butler' ),
				__( 'Paste the address below as the server URL and choose OAuth for authentication.', 'albert-ai-butler' ),
				__( 'Sign in to WordPress as a user you allowed below, and approve the request.', 'albert-ai-butler' ),
			],
			'snippet'        => $endpoint,
			'snippet_label'  => __( 'Server URL', 'albert-ai-butler' ),
			'deeplink'       => '',
			'deeplink_label' => '',
		];
	}

	/**
	 * Any other MCP client.
	 *
	 * @param string $endpoint The MCP endpoint URL.
	 *
	 * @return AlbertClientGuide The guide entry.
	 * @since 1.4.0
	 */
	private static function generic( string $endpoint ): array {
		return [
			'id'             => 'generic',
			'label'          => __( 'Any other MCP client', 'albert-ai-butler' ),
			'description'    => __( 'The raw details, for a client that is not listed here.', 'albert-ai-butler' ),
			'config_path'    => '',
			'steps'          => [
				__( 'Add the address below as a remote MCP server over HTTP.', 'albert-ai-butler' ),
				__( 'Choose OAuth 2.1 for authentication. The client registers itself; there is no key to copy.', 'albert-ai-butler' ),
				__( 'Albert sends the client to WordPress to sign in, and the connection appears on this page once it is approved.', 'albert-ai-butler' ),
			],
			'snippet'        => $endpoint,
			'snippet_label'  => __( 'Server URL', 'albert-ai-butler' ),
			'deeplink'       => '',
			'deeplink_label' => '',
		];
	}

	/**
	 * Pretty-print a configuration fragment for a copy button.
	 *
	 * @param array<string, mixed> $config The configuration structure.
	 *
	 * @return string Indented JSON, or an empty string if encoding fails.
	 * @since 1.4.0
	 */
	private static function json_snippet( array $config ): string {
		$json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return $json === false ? '' : $json;
	}
}
