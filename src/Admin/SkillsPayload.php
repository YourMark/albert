<?php
/**
 * Skills screen payload.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\MCP\Skills\Skill;
use Albert\MCP\Skills\SkillRegistry;

/**
 * Normalises the doc-21 skill registry into rows the Albert → Skills screen renders.
 *
 * One invariant is test-asserted: every skill this class marks `available` is a
 * skill {@see \Albert\Context\SkillIndex::entries()} also lists, and every skill
 * it marks unavailable is one that index omits. What the site owner sees here is
 * exactly what the connected assistant can read, because both read the same
 * {@see SkillRegistry::all()} call and the same {@see Skill::status()} logic.
 *
 * @since 1.4.0
 */
class SkillsPayload {

	/**
	 * Build the screen payload: every registered skill, regardless of whether
	 * its preconditions currently hold.
	 *
	 * Unavailable skills are included, not filtered out, so a site owner can see
	 * a disabled toggle with "Requires WooCommerce" as its reason, rather than
	 * wondering why a skill they know exists is missing.
	 *
	 * @return array{skills: list<array<string, mixed>>} The screen payload.
	 * @since 1.4.0
	 */
	public static function build(): array {
		$rows = [];

		foreach ( SkillRegistry::all() as $skill ) {
			$rows[] = self::row( $skill );
		}

		return [ 'skills' => $rows ];
	}

	/**
	 * Normalise one skill into a row.
	 *
	 * @param Skill $skill The skill.
	 *
	 * @return array<string, mixed> The row.
	 * @since 1.4.0
	 */
	private static function row( Skill $skill ): array {
		$status = $skill->status();

		return [
			'slug'      => $skill->slug(),
			'summary'   => $skill->summary(),
			'source'    => $skill->source(),
			'available' => $status['available'],
			'status'    => $status['label'],
			'body'      => $skill->body(),
		];
	}
}
