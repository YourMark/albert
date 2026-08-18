<?php
/**
 * Integration tests for the agent context: what a connected assistant is told.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Context;

use Albert\Abilities\WordPress\Skills\GetSkill;
use Albert\Admin\ContextPayload;
use Albert\Context\ContextSettings;
use Albert\Context\Payload;
use Albert\Context\SiteContext;
use Albert\Context\TokenEstimator;
use Albert\MCP\DiscoveryContext;
use Albert\MCP\Skills\SkillRegistry;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Agent context integration tests.
 *
 * These run against a real WordPress, which is what makes the budget assertion
 * worth anything: the payload is assembled from the actual theme, post types and
 * taxonomies rather than from fixtures that would drift from them.
 *
 * @covers \Albert\Context\Payload
 * @covers \Albert\Abilities\WordPress\Skills\GetSkill
 */
class AgentContextTest extends TestCase {

	/**
	 * Reset context state between tests.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( ContextSettings::OPTION );
		ContextSettings::reset_cache();
		SiteContext::reset_cache();
		SkillRegistry::reset_cache();
	}

	/**
	 * The context stays the small part of the discovery response.
	 *
	 * Not a budget: measurement established there is no threshold worth
	 * drawing, since the ability list in the same response is ten times larger
	 * (see `docs/context-token-budget.md`). This is a regression guard with a
	 * deliberately loose bound: it catches a section that quietly doubles the
	 * payload, and stays quiet about the ordinary drift that means nothing.
	 *
	 * @return void
	 */
	public function test_tier_zero_stays_small_relative_to_the_response(): void {
		$payload = Payload::build();
		$total   = TokenEstimator::estimate( Payload::text() );

		$this->assertNotEmpty( $payload );
		$this->assertLessThan(
			1500,
			$total,
			sprintf(
				'The context payload is %d estimated tokens. Measured payloads run 315-502; something has grown by a lot. Confirm it is intended and record the new measurement in docs/context-token-budget.md.',
				$total
			)
		);
	}

	/**
	 * The untrusted-data framing rides with any site-supplied text.
	 *
	 * @return void
	 */
	public function test_framing_is_present_whenever_site_text_is(): void {
		ContextSettings::save( [ 'instructions' => 'Write in Dutch.' ] );
		SiteContext::reset_cache();

		$site = Payload::build()['site'];

		$this->assertStringContainsString( 'Write in Dutch.', $site );
		$this->assertStringContainsString( '# How to read this', $site );
		$this->assertStringContainsString( 'which tools you may call', $site );
	}

	/**
	 * No filter can strip the framing off the payload.
	 *
	 * It is the safety statement for everything the same filter can change, so
	 * it lives outside the array that filter receives.
	 *
	 * @return void
	 */
	public function test_the_framing_cannot_be_filtered_away(): void {
		add_filter(
			'albert/context/site',
			static function (): array {
				return [ 'site' => [ 'name' => 'Nothing to see' ] ];
			}
		);

		$this->assertStringContainsString( '# How to read this', Payload::site() );

		remove_all_filters( 'albert/context/site' );
	}

	/**
	 * The discovery response carries both context fields.
	 *
	 * @return void
	 */
	public function test_discovery_response_carries_site_and_skills(): void {
		$discovery = wp_get_ability( 'mcp-adapter/discover-abilities' );

		if ( $discovery === null ) {
			$this->markTestSkipped( 'The MCP adapter discovery ability is not registered.' );
		}

		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = ( new DiscoveryContext() )->add_context(
			$discovery->execute(),
			[],
			'mcp-adapter/discover-abilities'
		);

		$this->assertArrayHasKey( 'abilities', $result );
		$this->assertArrayHasKey( 'site', $result );
		$this->assertIsString( $result['site'] );
	}

	/**
	 * A tool that is not discovery is never given context.
	 *
	 * @return void
	 */
	public function test_other_tool_results_are_untouched(): void {
		$result = [ 'id' => 7 ];

		$this->assertSame(
			$result,
			( new DiscoveryContext() )->add_context( $result, [], 'albert/create-post' )
		);
	}

	/**
	 * Switching context off restores the bare discovery response.
	 *
	 * @return void
	 */
	public function test_the_master_switch_removes_the_context_fields(): void {
		ContextSettings::save( [ 'enabled' => false ] );
		SiteContext::reset_cache();

		$result = [ 'abilities' => [] ];

		$this->assertSame(
			$result,
			( new DiscoveryContext() )->add_context( $result, [], 'mcp-adapter/discover-abilities' )
		);
	}

	/**
	 * `get-skill` returns a body to someone who may edit content.
	 *
	 * @return void
	 */
	public function test_get_skill_returns_a_body_for_an_editor(): void {
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = ( new GetSkill() )->execute( [ 'slug' => 'block-editor' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'block-editor', $result['slug'] );
		$this->assertNotEmpty( $result['body'] );
	}

	/**
	 * `get-skill` is refused to someone who may not edit content.
	 *
	 * A skill is written guidance about how to work on this site, so the people
	 * who may read it are the people who may work on it.
	 *
	 * @return void
	 */
	public function test_get_skill_is_refused_to_a_subscriber(): void {
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertInstanceOf( WP_Error::class, ( new GetSkill() )->check_permission() );
	}

	/**
	 * `get-skill` is refused when nobody is logged in.
	 *
	 * @return void
	 */
	public function test_get_skill_is_refused_when_logged_out(): void {
		wp_set_current_user( 0 );

		$this->assertInstanceOf( WP_Error::class, ( new GetSkill() )->check_permission() );
	}

	/**
	 * An unknown slug says which slugs do exist.
	 *
	 * A bare "not found" sends the assistant hunting for a typo; naming the real
	 * ones lets it correct itself in one step.
	 *
	 * @return void
	 */
	public function test_an_unknown_slug_names_the_available_ones(): void {
		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = ( new GetSkill() )->execute( [ 'slug' => 'no-such-skill' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'skill_not_found', $result->get_error_code() );
		$this->assertStringContainsString( 'block-editor', $result->get_error_message() );
	}

	/**
	 * A skill only lists when its preconditions hold.
	 *
	 * @return void
	 */
	public function test_a_skill_lists_only_when_its_preconditions_hold(): void {
		add_filter(
			'albert/skills/registry',
			static function ( array $skills ): array {
				$skills['impossible'] = [
					'slug'     => 'impossible',
					'summary'  => 'Never applies here.',
					'body'     => 'Guidance.',
					'requires' => [ 'nonsense-condition' ],
				];

				return $skills;
			}
		);

		SkillRegistry::reset_cache();

		$this->assertArrayHasKey( 'impossible', SkillRegistry::all() );
		$this->assertArrayNotHasKey( 'impossible', SkillRegistry::available() );
		$this->assertStringNotContainsString( 'impossible', (string) Payload::skills() );

		remove_all_filters( 'albert/skills/registry' );
		SkillRegistry::reset_cache();
	}

	/**
	 * At least three abilities carry per-ability instructions, and they are exposed.
	 *
	 * `get-ability-info` returns an ability's meta verbatim, so the assertion is
	 * on what a client would actually read back.
	 *
	 * @return void
	 */
	public function test_abilities_carry_instructions_visible_through_get_ability_info(): void {
		$with_instructions = 0;

		foreach ( wp_get_abilities() as $ability ) {
			$annotations = $ability->get_meta()['annotations'] ?? [];

			if ( ! empty( $annotations['instructions'] ) ) {
				++$with_instructions;
			}
		}

		$this->assertGreaterThanOrEqual( 3, $with_instructions );
	}

	/**
	 * The screen's preview is the wire payload, byte for byte.
	 *
	 * The Context screen renders these segments and calls the result exactly
	 * what the assistant receives. If a section ever renders differently for
	 * display, this test is what catches it.
	 *
	 * @return void
	 */
	public function test_the_screen_preview_matches_the_wire_payload(): void {
		ContextSettings::save( [ 'instructions' => 'Write in Dutch.' ] );
		SiteContext::reset_cache();

		$screen = ContextPayload::build();
		$wire   = array_values( array_filter( [ Payload::site(), Payload::skills() ] ) );

		$this->assertSame(
			implode( "\n\n", $wire ),
			implode( "\n\n", array_column( $screen['preview'], 'text' ) )
		);
	}
}
