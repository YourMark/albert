<?php
/**
 * Tests for the Skill value object.
 *
 * @package Albert\Tests\Unit\MCP\Skills
 */

namespace Albert\Tests\Unit\MCP\Skills;

use Albert\MCP\Skills\Skill;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/wordpress.php';

/**
 * Skill unit tests.
 *
 * The behaviour worth pinning is the precondition gate. An index entry is a
 * promise that the guidance applies here, so a skill that lists on a site where
 * its preconditions do not hold is worse than one that never lists at all, it
 * tells the model about tools that are not there.
 */
class SkillTest extends TestCase {

	/**
	 * A skill with no declared preconditions applies everywhere.
	 *
	 * @return void
	 */
	public function test_skill_without_preconditions_is_available(): void {
		$skill = new Skill( 'general', 'Applies everywhere.', '', 'Body.' );

		$this->assertTrue( $skill->is_available() );
	}

	/**
	 * A skill requiring WooCommerce does not list without it.
	 *
	 * @return void
	 */
	public function test_woocommerce_precondition_gates_on_the_class(): void {
		$skill = new Skill( 'woo', 'Shop work.', '', 'Body.', [ 'woocommerce' ] );

		// WooCommerce is not loaded in the unit suite.
		$this->assertFalse( $skill->is_available() );
	}

	/**
	 * An unrecognised precondition fails closed.
	 *
	 * A skill declaring `requires: shopify` is asking a question this vocabulary
	 * cannot answer. Listing it anyway would be answering "yes" to a question
	 * nobody understood.
	 *
	 * @return void
	 */
	public function test_unknown_precondition_fails_closed(): void {
		$skill = new Skill( 'mystery', 'Unknown ground.', '', 'Body.', [ 'shopify' ] );

		$this->assertFalse( $skill->is_available() );
	}

	/**
	 * Every declared precondition has to hold, not just one.
	 *
	 * @return void
	 */
	public function test_all_preconditions_must_hold(): void {
		$skill = new Skill( 'both', 'Two conditions.', '', 'Body.', [ 'multisite', 'shopify' ] );

		$this->assertFalse( $skill->is_available() );
	}

	/**
	 * A callable precondition covers what the vocabulary cannot express.
	 *
	 * @return void
	 */
	public function test_callable_precondition_is_consulted(): void {
		$yes = new Skill( 'yes', 'Yes.', '', 'Body.', [], static fn (): bool => true );
		$no  = new Skill( 'no', 'No.', '', 'Body.', [], static fn (): bool => false );

		$this->assertTrue( $yes->is_available() );
		$this->assertFalse( $no->is_available() );
	}

	/**
	 * A registration array without a slug builds nothing.
	 *
	 * One malformed add-on registration is skipped rather than breaking the
	 * whole index.
	 *
	 * @return void
	 */
	public function test_registration_without_a_slug_is_rejected(): void {
		$this->assertNull( Skill::from_array( [ 'body' => 'Some guidance.' ] ) );
	}

	/**
	 * A registration with neither a body nor a file builds nothing.
	 *
	 * @return void
	 */
	public function test_registration_without_content_is_rejected(): void {
		$this->assertNull( Skill::from_array( [ 'slug' => 'empty' ] ) );
	}

	/**
	 * A single precondition may be given as a string rather than a list.
	 *
	 * @return void
	 */
	public function test_a_single_precondition_may_be_a_string(): void {
		$skill = Skill::from_array(
			[
				'slug'     => 'woo',
				'body'     => 'Body.',
				'requires' => 'woocommerce',
			]
		);

		$this->assertInstanceOf( Skill::class, $skill );
		$this->assertSame( [ 'woocommerce' ], $skill->requires() );
	}

	/**
	 * A literal body is returned trimmed.
	 *
	 * @return void
	 */
	public function test_literal_body_is_returned(): void {
		$skill = new Skill( 'inline', 'Inline.', '', '  Guidance here.  ' );

		$this->assertSame( 'Guidance here.', $skill->body() );
	}

	/**
	 * A missing file yields an empty body rather than a fatal.
	 *
	 * The caller turns that into an error the assistant can act on; a warning
	 * from `file_get_contents()` would not reach it.
	 *
	 * @return void
	 */
	public function test_missing_file_yields_an_empty_body(): void {
		$skill = new Skill( 'gone', 'Gone.', '/nowhere/at/all.md' );

		$this->assertSame( '', $skill->body() );
	}

	/**
	 * A file-backed body is parsed, with its frontmatter stripped.
	 *
	 * @return void
	 */
	public function test_file_body_has_its_frontmatter_stripped(): void {
		$file = tempnam( sys_get_temp_dir(), 'albert-skill' );
		file_put_contents( $file, "---\nname: temp\ndescription: A test.\n---\n# Heading\n\nBody text." );

		$skill = new Skill( 'temp', 'A test.', $file );

		$this->assertSame( "# Heading\n\nBody text.", $skill->body() );

		unlink( $file );
	}

	/**
	 * A skill with no source declared falls back to a generic label, not
	 * "Albert": an add-on that forgot to declare one should not be mislabelled
	 * as something Albert shipped.
	 *
	 * @return void
	 */
	public function test_undeclared_source_falls_back_to_a_generic_label(): void {
		$skill = new Skill( 'general', 'Applies everywhere.', '', 'Body.' );

		$this->assertNotSame( '', $skill->source() );
		$this->assertNotSame( 'Albert', $skill->source() );
	}

	/**
	 * A declared source is returned verbatim.
	 *
	 * @return void
	 */
	public function test_declared_source_is_returned_verbatim(): void {
		$skill = new Skill( 'woo', 'Shop work.', '', 'Body.', [], null, 'WooCommerce Add-on' );

		$this->assertSame( 'WooCommerce Add-on', $skill->source() );
	}

	/**
	 * A skill with no preconditions is always enabled, with no reason attached.
	 *
	 * @return void
	 */
	public function test_status_for_an_unconditional_skill(): void {
		$skill  = new Skill( 'general', 'Applies everywhere.', '', 'Body.' );
		$status = $skill->status();

		$this->assertTrue( $status['available'] );
		$this->assertSame( 'Always enabled.', $status['label'] );
	}

	/**
	 * An unmet precondition names itself in the status label.
	 *
	 * @return void
	 */
	public function test_status_names_the_unmet_precondition(): void {
		$skill  = new Skill( 'woo', 'Shop work.', '', 'Body.', [ 'woocommerce' ] );
		$status = $skill->status();

		$this->assertFalse( $status['available'] );
		$this->assertStringContainsString( 'Requires', $status['label'] );
		$this->assertStringContainsString( 'WooCommerce', $status['label'] );
	}

	/**
	 * A `when` callable can make a skill unavailable even once every named
	 * precondition holds, required for a skill declaring none at all, and the
	 * status label falls back to a generic reason since there is no named
	 * condition to describe.
	 *
	 * @return void
	 */
	public function test_status_reflects_a_failing_when_callable(): void {
		$skill  = new Skill( 'no', 'No.', '', 'Body.', [], static fn (): bool => false );
		$status = $skill->status();

		$this->assertFalse( $status['available'] );
		$this->assertStringContainsString( "isn't met", $status['label'] );
	}

	/**
	 * A skill gated only by a currently-passing `when` callable is not
	 * "Always enabled.": it can still flip to unavailable on the next request,
	 * so the label must not promise something the class cannot guarantee.
	 *
	 * @return void
	 */
	public function test_status_does_not_claim_always_enabled_for_a_when_only_skill(): void {
		$skill  = new Skill( 'yes', 'Yes.', '', 'Body.', [], static fn (): bool => true );
		$status = $skill->status();

		$this->assertTrue( $status['available'] );
		$this->assertNotSame( 'Always enabled.', $status['label'] );
	}

	/**
	 * When more than one declared precondition is unmet, the status label
	 * names all of them, not just the first, so fixing one does not leave the
	 * site owner thinking the skill should now be available.
	 *
	 * @return void
	 */
	public function test_status_names_every_unmet_precondition(): void {
		$skill  = new Skill( 'both', 'Two conditions.', '', 'Body.', [ 'woocommerce', 'multisite' ] );
		$status = $skill->status();

		$this->assertFalse( $status['available'] );
		$this->assertStringContainsString( 'WooCommerce', $status['label'] );
		$this->assertStringContainsString( 'multisite network', $status['label'] );
	}

	/**
	 * The is_available() shorthand never disagrees with status()['available'];
	 * the Skills screen and the model-facing index both have to be able to
	 * trust either one.
	 *
	 * @return void
	 */
	public function test_is_available_matches_status(): void {
		$skill = new Skill( 'woo', 'Shop work.', '', 'Body.', [ 'woocommerce' ] );

		$this->assertSame( $skill->status()['available'], $skill->is_available() );
	}
}
