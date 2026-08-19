<?php
/**
 * Site context assembler: the structured array behind the discovery payload.
 *
 * @package Albert
 * @subpackage Context
 * @since      1.4.0
 */

namespace Albert\Context;

defined( 'ABSPATH' ) || exit;

use Albert\Context\Readers\Commerce;
use Albert\Context\Readers\ContentModel;
use Albert\Context\Readers\DesignTokens;
use Albert\Context\Readers\Environment;

/**
 * Assembles everything Albert knows about this site into one structured array.
 *
 * This array is the API: filters run on it, tests assert its keys, and the
 * Context screen measures each of its sections. It is *not* the wire format,
 * {@see PayloadRenderer} turns it into the compact labeled text the assistant
 * actually receives. Structure in, text out; one renderer produces both the wire
 * payload and the screen's preview, so the two cannot drift.
 *
 * Two build paths, deliberately separate:
 *
 *  - {@see self::detected()} reads every section whether or not the owner has it
 *    switched on. The Context screen needs this to price a section that is
 *    currently off. "What would turning this back on cost me" is unanswerable
 *    from the sections that are already included.
 *  - {@see self::build()} is what ships: the owner's switches applied, the
 *    filter run, the result ready to render.
 *
 * @since 1.4.0
 */
class SiteContext {

	/**
	 * Per-request memo of the detected sections.
	 *
	 * The readers touch the theme JSON resolver, the post-type registry and
	 * WooCommerce; the Context screen asks for them several times per request
	 * (costs, preview, section peeks) and they cannot change mid-request.
	 *
	 * @since 1.4.0
	 * @var array<string, mixed>|null
	 */
	private static ?array $detected = null;

	/**
	 * Read every section, ignoring the owner's switches.
	 *
	 * Sections that genuinely have nothing to report are absent rather than
	 * empty: no `design` key when the theme declares no tokens, no `commerce`
	 * key when there is no shop. Absence is the signal the Context screen reads
	 * to show the classic-theme steer and to drop the Commerce row.
	 *
	 * @return array<string, mixed> Every available section, keyed by section name.
	 * @since 1.4.0
	 */
	public static function detected(): array {
		if ( self::$detected !== null ) {
			return self::$detected;
		}

		$detected = [ 'site' => self::identity() ];

		$detected['environment'] = ( new Environment() )->read();

		$design = ( new DesignTokens() )->read();
		if ( $design !== null ) {
			$detected['design'] = $design;
		}

		$detected['content_model'] = ( new ContentModel() )->read();

		$commerce = ( new Commerce() )->read();
		if ( $commerce !== null ) {
			$detected['commerce'] = $commerce;
		}

		self::$detected = $detected;

		return $detected;
	}

	/**
	 * Build the context that will actually be sent.
	 *
	 * Order matters: the owner's switches are applied first, then the filter, so
	 * a filter has the last word and can add a section the UI knows nothing
	 * about. The owner's instructions ride in the same array because they are
	 * part of what gets rendered, and having one thing to filter beats two.
	 *
	 * @return array<string, mixed> The context to render, or an empty array when context is switched off.
	 * @since 1.4.0
	 */
	public static function build(): array {
		if ( ! ContextSettings::enabled() ) {
			return [];
		}

		$detected = self::detected();
		$context  = [ 'site' => $detected['site'] ];

		foreach ( ContextSettings::SECTIONS as $section ) {
			if ( ! isset( $detected[ $section ] ) || ! ContextSettings::section_enabled( $section ) ) {
				continue;
			}

			$context[ $section ] = $detected[ $section ];
		}

		$instructions = ContextSettings::instructions();
		if ( $instructions !== '' ) {
			$context['instructions'] = $instructions;
		}

		/**
		 * Filters the structured site context before it is rendered.
		 *
		 * This is the extension point for everything about the `site` field:
		 * add a section, drop one, or rewrite a detected value. The array is
		 * rendered to the compact text block the assistant receives, so keys
		 * added here need a renderer, an unrecognised section is rendered
		 * generically as `Key: value` lines under its own heading.
		 *
		 * The untrusted-data statement is deliberately not part of this array
		 * and cannot be filtered away: it is the safety framing for everything
		 * else in it.
		 *
		 * @since 1.4.0
		 *
		 * @param array<string, mixed> $context  The assembled context, switches already applied.
		 * @param array<string, mixed> $detected Every detected section, before switches.
		 */
		$filtered = apply_filters( 'albert/context/site', $context, $detected );

		return is_array( $filtered ) ? $filtered : $context;
	}

	/**
	 * The site's own name for itself.
	 *
	 * Always sent when context is on, and not switchable: it is two short lines,
	 * it is the single most useful thing a model can know about where it is, and
	 * a switch for it would be a switch nobody should flip.
	 *
	 * @return array<string, string>
	 * @since 1.4.0
	 */
	private static function identity(): array {
		$identity = [ 'name' => (string) get_bloginfo( 'name' ) ];

		$tagline = (string) get_bloginfo( 'description' );
		if ( $tagline !== '' ) {
			$identity['tagline'] = $tagline;
		}

		$identity['url'] = home_url();

		return $identity;
	}

	/**
	 * Drop the per-request memo.
	 *
	 * Intended for tests; production reads each section once per request.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function reset_cache(): void {
		self::$detected = null;
	}
}
