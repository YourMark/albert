<?php
/**
 * Skill loader — exposes Albert skills as MCP prompts.
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.2.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

use Albert\Vendor\WP\MCP\Domain\Prompts\McpPrompt;

/**
 * SkillLoader class
 *
 * Discovers skill files, parses their frontmatter and body, and turns each
 * into an {@see McpPrompt} so connected assistants can fetch them as MCP
 * prompts. Skills are static playbooks (the body is returned verbatim as a
 * single user message) that teach assistants how to use Albert.
 *
 * By default it loads the plugin's own `skills/` directory; add-ons and themes
 * can contribute their own files via the `albert/skills` filter.
 *
 * The loader is deliberately defensive: a missing file, an unreadable file, or
 * a file without a `name` is skipped rather than fatalling, so one bad skill
 * never breaks the prompts channel.
 *
 * @since 1.2.0
 */
class SkillLoader {

	/**
	 * The frontmatter parser.
	 *
	 * @since 1.2.0
	 * @var FrontmatterParser
	 */
	private FrontmatterParser $parser;

	/**
	 * Constructor.
	 *
	 * @param FrontmatterParser|null $parser Optional parser (injectable for tests).
	 *
	 * @since 1.2.0
	 */
	public function __construct( ?FrontmatterParser $parser = null ) {
		$this->parser = $parser ?? new FrontmatterParser();
	}

	/**
	 * Build the list of MCP prompts from all discovered skill files.
	 *
	 * Suitable for passing straight to the adapter's `create_server()` prompts
	 * argument. Files that cannot be parsed into a valid prompt are skipped.
	 *
	 * @return array<int, McpPrompt> The skill prompts.
	 * @since 1.2.0
	 */
	public function prompts(): array {
		$prompts = [];

		foreach ( $this->skill_files() as $file ) {
			$prompt = $this->build_prompt( $file );
			if ( $prompt instanceof McpPrompt ) {
				$prompts[] = $prompt;
			}
		}

		return $prompts;
	}

	/**
	 * Resolve the absolute paths of all skill files to load.
	 *
	 * Starts with the plugin's bundled `skills/` directory and lets add-ons or
	 * themes extend the list via the `albert/skills` filter. The result is
	 * de-duplicated; non-string entries are dropped.
	 *
	 * @return array<int, string> Absolute file paths.
	 * @since 1.2.0
	 */
	private function skill_files(): array {
		$default = $this->default_skill_files();

		/**
		 * Filters the list of skill files exposed as MCP prompts.
		 *
		 * Each entry must be an absolute path to a Markdown skill file with a
		 * `name` field in its frontmatter. Add-ons and themes use this to
		 * register their own skills. Following the `albert/{location}/{hook_name}`
		 * convention, the location is the skills subsystem.
		 *
		 * @since 1.2.0
		 *
		 * @param array<int, string> $files Absolute paths to skill files. Defaults to the plugin's bundled skills.
		 */
		$files = apply_filters( 'albert/skills', $default );

		if ( ! is_array( $files ) ) {
			$files = $default;
		}

		$paths = [];
		foreach ( $files as $file ) {
			if ( is_string( $file ) && $file !== '' ) {
				$paths[ $file ] = $file;
			}
		}

		return array_values( $paths );
	}

	/**
	 * Get the plugin's bundled skill files.
	 *
	 * @return array<int, string> Absolute file paths.
	 * @since 1.2.0
	 */
	private function default_skill_files(): array {
		if ( ! defined( 'ALBERT_PLUGIN_DIR' ) ) {
			return [];
		}

		$pattern = ALBERT_PLUGIN_DIR . 'skills/*.md';
		$matches = glob( $pattern );

		return is_array( $matches ) ? $matches : [];
	}

	/**
	 * Build a single MCP prompt from a skill file.
	 *
	 * Returns null when the file is missing/unreadable, has no `name`, or the
	 * adapter rejects the configuration — the caller skips nulls.
	 *
	 * @param string $file Absolute path to a skill file.
	 *
	 * @return McpPrompt|null
	 * @since 1.2.0
	 */
	private function build_prompt( string $file ): ?McpPrompt {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin file, not a remote resource.
		if ( $contents === false ) {
			return null;
		}

		$skill = $this->parser->parse( $contents );

		// A skill without a name cannot be registered as a prompt.
		if ( empty( $skill['name'] ) ) {
			return null;
		}

		$body = $skill['body'];

		$config = [
			'name'        => $skill['name'],
			'description' => $skill['description'] ?? '',
			'handler'     => static function () use ( $body ): array {
				return [
					'messages' => [
						[
							'role'    => 'user',
							'content' => [
								'type' => 'text',
								'text' => $body,
							],
						],
					],
				];
			},
			'permission'  => static function (): bool {
				return true;
			},
		];

		if ( ! empty( $skill['arguments'] ) ) {
			$config['arguments'] = $skill['arguments'];
		}

		$prompt = McpPrompt::fromArray( $config );

		return $prompt instanceof McpPrompt ? $prompt : null;
	}
}
