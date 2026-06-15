<?php
/**
 * Content Formatter
 *
 * Resolves the optional `format` selector shared by the read abilities
 * (view-post, view-page, find-posts, find-pages) into the concrete set of
 * content representations to include in an ability's result.
 *
 * The five representations are:
 *   - content   → raw block markup (post_content), verbatim.
 *   - blocks    → the structured block tree from BlockReader::read().
 *   - plaintext → flattened human-readable text from BlockReader::plaintext_of().
 *   - html      → rendered output via do_blocks() (the only way to see the
 *                 output of dynamic/server-rendered blocks).
 *   - markdown  → Markdown rendering via BlockMarkdown::render().
 *
 * Keeping this in one place guarantees every read ability honours the same
 * default and the same backward-compatible field set.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the requested content representations for a piece of block markup.
 *
 * @since 1.2.0
 */
class ContentFormatter {

	/**
	 * Every representation this formatter can produce.
	 *
	 * @var array<int, string>
	 *
	 * @since 1.2.0
	 */
	public const FORMATS = [ 'content', 'blocks', 'plaintext', 'html', 'markdown' ];

	/**
	 * The default representations when no `format` is requested.
	 *
	 * Preserves the pre-1.2.0 output (content + blocks + plaintext) so existing
	 * callers keep working unchanged.
	 *
	 * @var array<int, string>
	 *
	 * @since 1.2.0
	 */
	public const DEFAULT_FORMATS = [ 'content', 'blocks', 'plaintext' ];

	/**
	 * Build the shared `format` input-schema property used by the read abilities.
	 *
	 * The view-post, view-page, find-posts and find-pages abilities all expose
	 * the same optional `format` selector; defining it here keeps the description
	 * and the allowed-value enum in one place.
	 *
	 * @return array<string, mixed> JSON Schema fragment for the optional format selector.
	 *
	 * @since 1.2.0
	 */
	public static function input_schema_property(): array {
		return [
			'type'        => 'array',
			'description' => 'Which content representations to return. Optional; when omitted or empty, defaults to ["content","blocks","plaintext"] for backward compatibility. '
				. 'Allowed values: "content" (raw block markup), "blocks" (structured block tree), "plaintext" (flattened text), '
				. '"html" (rendered HTML via do_blocks — the only way to see dynamic-block output), "markdown" (Markdown rendering). '
				. 'Only the requested representations are included in the result.',
			'items'       => [
				'type' => 'string',
				'enum' => self::FORMATS,
			],
		];
	}

	/**
	 * Build the requested content representations for raw block markup.
	 *
	 * @param string             $raw_content Raw block markup (post_content).
	 * @param array<int, string> $format      Requested representations; falls back
	 *                                         to {@see DEFAULT_FORMATS} when empty.
	 * @return array<string, mixed> Map of representation key → value, containing
	 *                              only the requested (and recognised) keys.
	 *
	 * @since 1.2.0
	 */
	public static function build( string $raw_content, array $format = [] ): array {
		$requested = self::normalize( $format );

		$out = [];

		// Only parse the block tree when a representation actually needs it.
		$needs_tree = in_array( 'blocks', $requested, true ) || in_array( 'plaintext', $requested, true );
		$blocks     = $needs_tree ? ( new BlockReader() )->read( $raw_content ) : [];

		foreach ( $requested as $key ) {
			switch ( $key ) {
				case 'content':
					$out['content'] = $raw_content;
					break;

				case 'blocks':
					$out['blocks'] = $blocks;
					break;

				case 'plaintext':
					$out['plaintext'] = BlockReader::plaintext_of( $blocks );
					break;

				case 'html':
					$out['html'] = do_blocks( $raw_content );
					break;

				case 'markdown':
					$out['markdown'] = ( new BlockMarkdown() )->render( $raw_content );
					break;
			}
		}

		return $out;
	}

	/**
	 * Normalise a raw `format` input into a clean, ordered, deduped list of
	 * recognised representation keys.
	 *
	 * An empty or all-invalid input yields {@see DEFAULT_FORMATS}. The output is
	 * ordered to match {@see FORMATS} so result keys are deterministic.
	 *
	 * @param mixed $format Raw input (expected array of strings, but defensive).
	 * @return array<int, string> Recognised representation keys.
	 *
	 * @since 1.2.0
	 */
	public static function normalize( $format ): array {
		if ( ! is_array( $format ) ) {
			return self::DEFAULT_FORMATS;
		}

		$requested = [];
		foreach ( $format as $value ) {
			if ( is_string( $value ) && in_array( $value, self::FORMATS, true ) ) {
				$requested[] = $value;
			}
		}

		if ( $requested === [] ) {
			return self::DEFAULT_FORMATS;
		}

		// Order by FORMATS and dedupe for deterministic output.
		return array_values( array_filter( self::FORMATS, static fn ( string $f ): bool => in_array( $f, $requested, true ) ) );
	}
}
