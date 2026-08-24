<?php
/**
 * Integration tests for the Skills screen's payload.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Abilities\WordPress\Skills\GetSkill;
use Albert\Admin\SkillsPayload;
use Albert\Context\SkillIndex;
use Albert\MCP\Skills\SkillRegistry;
use Albert\Tests\TestCase;

/**
 * Skills screen integration tests.
 *
 * The one invariant doc 23a makes test-asserted: what a site owner sees on the
 * Skills screen is exactly what the connected assistant can read. Two things
 * follow from that. First, the screen's list of *available* skills has to be the
 * same set of slugs as the model-facing Tier 0 index: a skill this screen says
 * applies but the index omits (or the reverse) would make the screen a lie.
 * Second, the body view has to be byte-identical to what `albert/get-skill`
 * actually returns, not a re-rendering that could drift from it.
 *
 * @covers \Albert\Admin\SkillsPayload
 * @covers \Albert\MCP\Skills\Skill
 */
class SkillsPayloadTest extends TestCase {

	/**
	 * Fresh registry state for each test, so one test's skill does not leak
	 * into another's.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		SkillRegistry::reset_cache();
		SkillIndex::reset_cache();
	}

	/**
	 * Same again after the test, for suites that run after this one.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		SkillRegistry::reset_cache();
		SkillIndex::reset_cache();

		parent::tear_down();
	}

	/**
	 * The screen's available skills are exactly the Tier 0 index's skills.
	 *
	 * @return void
	 */
	public function test_available_skills_match_the_tier_0_index(): void {
		$screen_available = array_values(
			array_map(
				static fn( array $row ): string => $row['slug'],
				array_filter(
					SkillsPayload::build()['skills'],
					static fn( array $row ): bool => $row['available']
				)
			)
		);

		$index_slugs = array_column( SkillIndex::entries(), 'slug' );

		sort( $screen_available );
		sort( $index_slugs );

		$this->assertSame( $index_slugs, $screen_available );
	}

	/**
	 * A skill whose precondition does not hold is still listed on the screen,
	 * marked unavailable, rather than dropped.
	 *
	 * @return void
	 */
	public function test_unavailable_skills_are_listed_with_a_false_flag(): void {
		add_filter(
			'albert/skills/registry',
			static function ( array $definitions ): array {
				$definitions['gated-test-skill'] = [
					'body'     => 'Guidance for a shop that is not there.',
					'summary'  => 'Only for shops.',
					'requires' => [ 'woocommerce' ],
				];

				return $definitions;
			}
		);
		SkillRegistry::reset_cache();

		$row = $this->find_row( 'gated-test-skill' );

		$this->assertNotNull( $row );
		$this->assertFalse( $row['available'] );
		$this->assertStringContainsString( 'Requires', $row['status'] );
		$this->assertNotContains( 'gated-test-skill', array_column( SkillIndex::entries(), 'slug' ) );
	}

	/**
	 * The body view is byte-identical to what `albert/get-skill` returns.
	 *
	 * @return void
	 */
	public function test_body_matches_get_skill_output(): void {
		add_filter(
			'albert/skills/registry',
			static function ( array $definitions ): array {
				$definitions['byte-identical-test'] = [
					'body'    => "# A guide\n\nWith more than one line.",
					'summary' => 'Testing byte identity.',
					'source'  => 'Test Add-on',
				];

				return $definitions;
			}
		);
		SkillRegistry::reset_cache();

		$row = $this->find_row( 'byte-identical-test' );
		$this->assertNotNull( $row );

		$ability = new GetSkill();
		$result  = $ability->execute( [ 'slug' => 'byte-identical-test' ] );

		$this->assertIsArray( $result );
		$this->assertSame( $result['body'], $row['body'] );
		$this->assertSame( 'Test Add-on', $row['source'] );
	}

	/**
	 * A skill registered without an explicit source falls back to a generic
	 * label rather than being mislabelled as one Albert shipped.
	 *
	 * @return void
	 */
	public function test_missing_source_falls_back_to_a_generic_label(): void {
		add_filter(
			'albert/skills/registry',
			static function ( array $definitions ): array {
				$definitions['no-source-test'] = [ 'body' => 'Body.' ];

				return $definitions;
			}
		);
		SkillRegistry::reset_cache();

		$row = $this->find_row( 'no-source-test' );

		$this->assertNotNull( $row );
		$this->assertNotSame( '', $row['source'] );
		$this->assertNotSame( 'Albert', $row['source'] );
	}

	/**
	 * Find one row in the screen payload by slug.
	 *
	 * @param string $slug The skill slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find_row( string $slug ): ?array {
		foreach ( SkillsPayload::build()['skills'] as $row ) {
			if ( $row['slug'] === $slug ) {
				return $row;
			}
		}

		return null;
	}
}
