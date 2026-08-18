<?php
/**
 * Skills index: what guidance exists, without paying for it.
 *
 * @package Albert
 * @subpackage Context
 * @since      1.4.0
 */

namespace Albert\Context;

defined( 'ABSPATH' ) || exit;

use Albert\MCP\Skills\Skill;
use Albert\MCP\Skills\SkillRegistry;

/**
 * Builds the one-line-per-skill index carried in the discovery response.
 *
 * The index exists because the full playbooks do not fit and should not have to.
 * A skill body runs to hundreds of lines; sending them all on every conversation
 * would spend the whole context budget on guidance for work the assistant may
 * never do. One line each, and a plain instruction for how to fetch the rest,
 * costs a few tokens and makes the bodies reachable. That is the gap this class
 * exists to close: skills registered only as MCP prompts are, in practice, never
 * fetched autonomously.
 *
 * Entries are conditional. A skill lists only when its preconditions hold, so a
 * site with no shop never hears about the WooCommerce skill. That is not
 * tidiness. An index entry is a promise that the guidance applies here.
 *
 * @since 1.4.0
 */
class SkillIndex {

	/**
	 * The ability an assistant calls to read a skill body.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const FETCH_ABILITY = 'albert/get-skill';

	/**
	 * The built index, or null before it has been built.
	 *
	 * Memoised because one screen render reads this four times over: {@see
	 * \Albert\Admin\ContextPayload::build()} calls it for the preview segment,
	 * the availability check and the cost breakdown, and the wire path reads it
	 * again through {@see Payload::skills()}. Without this the
	 * `albert/context/skills` filter ran four times per request, which is
	 * wasted work and a surprise to any add-on that assumed one call each.
	 *
	 * @var list<array{slug: string, summary: string}>|null
	 * @since 1.4.0
	 */
	private static $entries = null;

	/**
	 * Build the index entries for this site.
	 *
	 * Preconditions are applied before the filter runs, so `albert/context/skills`
	 * sees only the skills that apply here. An add-on wanting to *add* one must
	 * use `albert/skills/registry` instead; adding a slug here would put it in the
	 * index without `albert/get-skill` knowing it exists, and the assistant would
	 * be told to fetch something that cannot be fetched.
	 *
	 * @return list<array{slug: string, summary: string}> Entries for skills that apply to this site.
	 * @since 1.4.0
	 */
	public static function entries(): array {
		if ( self::$entries !== null ) {
			return self::$entries;
		}

		$entries = [];

		foreach ( SkillRegistry::available() as $skill ) {
			$entries[] = [
				'slug'    => $skill->slug(),
				'summary' => self::summary( $skill ),
			];
		}

		/**
		 * Filters the skills index carried in the discovery response.
		 *
		 * Entries are `[ 'slug' => …, 'summary' => … ]`. Preconditions have
		 * already been applied, so this filter sees only the skills that apply
		 * to this site, use {@see 'albert/skills/registry'} to add a skill, and
		 * this to change how the ones that survived are presented.
		 *
		 * @since 1.4.0
		 *
		 * @param list<array{slug: string, summary: string}> $entries The index entries.
		 */
		$filtered = apply_filters( 'albert/context/skills', $entries );

		self::$entries = is_array( $filtered ) ? array_values( $filtered ) : $entries;

		return self::$entries;
	}

	/**
	 * Forget the memoised index.
	 *
	 * For tests and for any caller that changes what the registry would return
	 * after the index has already been built once in the same request.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function reset_cache(): void {
		self::$entries = null;
	}

	/**
	 * One sentence describing a skill.
	 *
	 * A skill's frontmatter description is written to be read by a person
	 * browsing prompts and can run to several sentences. The index pays for
	 * every character of it on every conversation, so only the first sentence
	 * survives, which is where a well-written description puts the point.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return string The first sentence of the skill's description, or an empty string.
	 * @since 1.4.0
	 */
	private static function summary( Skill $skill ): string {
		$summary = trim( $skill->summary() );

		if ( $summary === '' ) {
			return '';
		}

		if ( preg_match( '/^(.+?[.!?])(?:\s|$)/u', $summary, $match ) ) {
			return trim( $match[1] );
		}

		return $summary;
	}
}
