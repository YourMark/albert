<?php
/**
 * The two context fields added to the discovery response.
 *
 * @package Albert
 * @subpackage Context
 * @since      1.4.0
 */

namespace Albert\Context;

defined( 'ABSPATH' ) || exit;

use Albert\Core\AbilitiesState;

/**
 * Assembles the `site` and `skills` fields carried by `discover-abilities`.
 *
 * One class owns both, and owns the preview the Context screen shows, so there
 * is exactly one definition of "what the assistant receives". The screen does
 * not re-render anything: it displays {@see self::segments()}, and the wire
 * fields are the join of those same segments.
 *
 * Both fields are text. The structured arrays behind them
 * ({@see SiteContext::build()}, {@see SkillIndex::entries()}) are the filterable
 * API; this is the boundary where structure becomes the representation the
 * client reads.
 *
 * @since 1.4.0
 */
class Payload {

	/**
	 * Build the two wire fields.
	 *
	 * An empty array is returned when there is nothing to say: context switched
	 * off, or every section off with no instructions written, so the discovery
	 * response is left exactly as the adapter produced it rather than carrying
	 * two empty keys.
	 *
	 * @return array{site?: string, skills?: string}
	 * @since 1.4.0
	 */
	public static function build(): array {
		$payload = [];

		$site = self::site();
		if ( $site !== '' ) {
			$payload['site'] = $site;
		}

		$skills = self::skills();
		if ( $skills !== '' ) {
			$payload['skills'] = $skills;
		}

		return $payload;
	}

	/**
	 * The `site` field: the rendered context block.
	 *
	 * @return string The rendered context, or an empty string when nothing is sent.
	 * @since 1.4.0
	 */
	public static function site(): string {
		return ( new PayloadRenderer() )->join( self::site_segments() );
	}

	/**
	 * The `skills` field: the rendered index.
	 *
	 * Suppressed along with everything else when context is switched off. The
	 * master switch means "tell the assistant nothing about this site", and a
	 * list of the site's task guides is something about this site.
	 *
	 * Also suppressed when `albert/get-skill` has been switched off on the
	 * Abilities screen. The index exists to say "these guides are one call away";
	 * with the ability disabled that call fails, and an index that names guidance
	 * the assistant cannot reach is worse than no index, it spends budget to
	 * send it somewhere that is not there.
	 *
	 * @return string The rendered index, or an empty string when no skill applies or the fetch ability is off.
	 * @since 1.4.0
	 */
	public static function skills(): string {
		if ( ! ContextSettings::enabled() || ! ContextSettings::section_enabled( 'skills' ) ) {
			return '';
		}

		if ( ! AbilitiesState::is_enabled( SkillIndex::FETCH_ABILITY ) ) {
			return '';
		}

		return ( new PayloadRenderer() )->render_skills( SkillIndex::entries() );
	}

	/**
	 * Every rendered segment, in the order the payload presents them.
	 *
	 * This is what the Context screen displays. Joining the `text` of each
	 * segment with {@see PayloadRenderer::SEPARATOR} reproduces the two wire
	 * fields end to end, which is what makes the screen's claim checkable rather
	 * than merely asserted, the test that pins it compares this join against
	 * the fields themselves.
	 *
	 * @return list<array{key: string, text: string}>
	 * @since 1.4.0
	 */
	public static function segments(): array {
		$segments = [];

		foreach ( self::site_segments() as $segment ) {
			$segments[] = $segment;
		}

		$skills = self::skills();
		if ( $skills !== '' ) {
			$segments[] = [
				'key'  => 'skills',
				'text' => $skills,
			];
		}

		return $segments;
	}

	/**
	 * The full payload as the assistant reads it, both fields end to end.
	 *
	 * Exists so the equality between what the screen previews and what the wire
	 * carries can be asserted in one line rather than described in a comment. The
	 * budget measurement in `docs/context-token-budget.md` is taken from this too,
	 * so anything that changes its output changes the numbers recorded there.
	 *
	 * @return string The `site` and `skills` fields, joined as the screen shows them.
	 * @since 1.4.0
	 */
	public static function text(): string {
		return implode( PayloadRenderer::SEPARATOR, array_column( self::segments(), 'text' ) );
	}

	/**
	 * The rendered segments of the `site` field.
	 *
	 * The untrusted-data notice closes the block rather than opening it. Placing
	 * a framing statement before untrusted text is the stronger position in
	 * general, and that position is already taken here. The owner's instructions
	 * are the one section written by a human whose intent Albert cannot vouch
	 * for, and they carry their framing in their own heading, on the line
	 * immediately above them. The closing notice generalises that to everything else, including the
	 * post and product text the assistant will read later.
	 *
	 * @return list<array{key: string, text: string}>
	 * @since 1.4.0
	 */
	private static function site_segments(): array {
		return ( new PayloadRenderer() )->segments( SiteContext::build() );
	}
}
