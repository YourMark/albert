<?php
/**
 * Skill-file parser — reads a skill file's frontmatter and body.
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.2.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

/**
 * SkillFileParser class
 *
 * Parses a skill Markdown file into its YAML-ish frontmatter fields and body.
 * Intentionally small and dependency-free so it can be unit-tested without
 * WordPress. It understands the leading `---` fenced block at the top of a
 * file and a flat set of `key: value` pairs inside it. Anything after the
 * closing fence is returned verbatim as the body.
 *
 * @since 1.2.0
 */
class SkillFileParser {

	/**
	 * Parse raw skill-file contents into frontmatter fields and body.
	 *
	 * Recognises the keys `name`, `description`, and an optional `arguments`
	 * block. When no valid frontmatter fence is present, the whole input is
	 * treated as the body and the field keys are returned empty.
	 *
	 * @param string $contents The raw file contents.
	 *
	 * @return array{name: ?string, description: ?string, arguments: array<int, array<string, mixed>>, body: string}
	 * @since 1.2.0
	 */
	public function parse( string $contents ): array {
		$result = [
			'name'        => null,
			'description' => null,
			'arguments'   => [],
			'body'        => $contents,
		];

		// Normalise line endings so the fence regex is reliable.
		$normalised = str_replace( [ "\r\n", "\r" ], "\n", $contents );

		// Require the frontmatter fence at the very start of the file. The
		// opening fence tolerates trailing horizontal whitespace (e.g. "--- \n")
		// so a stray space never makes the whole file parse as body-only.
		if ( ! preg_match( '/\A---[^\S\n]*\n(.*?)\n---\n?(.*)\z/s', $normalised, $matches ) ) {
			$result['body'] = trim( $normalised );
			return $result;
		}

		$frontmatter    = $matches[1];
		$result['body'] = trim( $matches[2] );

		$parsed                = $this->parse_frontmatter( $frontmatter );
		$result['name']        = $parsed['name'];
		$result['description'] = $parsed['description'];
		$result['arguments']   = $parsed['arguments'];

		return $result;
	}

	/**
	 * Parse the flat key/value frontmatter block.
	 *
	 * @param string $frontmatter The text between the opening and closing fences.
	 *
	 * @return array{name: ?string, description: ?string, arguments: array<int, array<string, mixed>>}
	 * @since 1.2.0
	 */
	private function parse_frontmatter( string $frontmatter ): array {
		$fields = [
			'name'        => null,
			'description' => null,
			'arguments'   => [],
		];

		foreach ( explode( "\n", $frontmatter ) as $line ) {
			// Skip blank lines and YAML comments.
			if ( trim( $line ) === '' || str_starts_with( ltrim( $line ), '#' ) ) {
				continue;
			}

			// Only consider top-level keys (no leading indentation).
			if ( ! preg_match( '/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $pair ) ) {
				continue;
			}

			$key   = strtolower( $pair[1] );
			$value = $this->unquote( trim( $pair[2] ) );

			if ( $key === 'name' && $value !== '' ) {
				$fields['name'] = $value;
			} elseif ( $key === 'description' && $value !== '' ) {
				$fields['description'] = $value;
			} elseif ( $key === 'arguments' ) {
				$fields['arguments'] = $this->parse_arguments( $value );
			}
		}

		return $fields;
	}

	/**
	 * Parse an inline JSON array of argument definitions.
	 *
	 * Arguments are optional and only accepted as a single-line JSON array,
	 * e.g. `arguments: [{"name":"slug","required":true}]`. Invalid or
	 * non-array JSON yields an empty list so a malformed value never fatals.
	 *
	 * @param string $value The raw value following `arguments:`.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.2.0
	 */
	private function parse_arguments( string $value ): array {
		if ( $value === '' ) {
			return [];
		}

		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$arguments = [];
		foreach ( $decoded as $argument ) {
			if ( is_array( $argument ) && isset( $argument['name'] ) && is_string( $argument['name'] ) ) {
				$arguments[] = $argument;
			}
		}

		return $arguments;
	}

	/**
	 * Strip a single pair of matching surrounding quotes from a value.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	private function unquote( string $value ): string {
		if ( strlen( $value ) >= 2 ) {
			$first = $value[0];
			$last  = $value[ strlen( $value ) - 1 ];
			if ( ( $first === '"' && $last === '"' ) || ( $first === "'" && $last === "'" ) ) {
				return substr( $value, 1, -1 );
			}
		}

		return $value;
	}
}
