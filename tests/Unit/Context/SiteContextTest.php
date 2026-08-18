<?php
/**
 * Tests for the site context assembler.
 *
 * @package Albert\Tests\Unit\Context
 */

namespace Albert\Tests\Unit\Context;

use Albert\Context\ContextSettings;
use Albert\Context\Payload;
use Albert\Context\SiteContext;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/wordpress-site.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

/**
 * SiteContext and Payload unit tests.
 *
 * The two properties worth pinning are provenance and honesty. Design tokens are
 * included only when the theme genuinely declares them, because sending
 * WordPress's own defaults dressed as brand tokens would have a model
 * confidently paint the site in colours nobody chose. And the payload the screen
 * previews has to be the payload the assistant receives.
 */
class SiteContextTest extends TestCase {

	/**
	 * Reset the world between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_options'] = [];
		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_site']    = [
			'name'            => 'Studio Albert',
			'tagline'         => 'Furniture for people who make things',
			'locale'          => 'nl_NL',
			'timezone'        => 'Europe/Amsterdam',
			'theme'           => 'Ollie',
			'theme_version'   => '1.6.0',
			'post_types'      => [ 'post', 'page', 'attachment' ],
			'taxonomies'      => [ 'category', 'post_tag' ],
			'global_settings' => [],
		];

		SiteContext::reset_cache();
		ContextSettings::reset_cache();
	}

	/**
	 * Clean up globals so the next test starts from nothing.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_site'] );
		SiteContext::reset_cache();
		ContextSettings::reset_cache();
		parent::tearDown();
	}

	/**
	 * Set the palette and font families a theme declares, by origin.
	 *
	 * @param array<string, mixed> $palette Origin-keyed palette.
	 * @param array<string, mixed> $fonts   Origin-keyed font families.
	 *
	 * @return void
	 */
	private function declare_tokens( array $palette, array $fonts = [] ): void {
		$GLOBALS['albert_test_site']['global_settings'] = [
			'color.palette'           => $palette,
			'typography.fontFamilies' => $fonts,
			'spacing.spacingSizes'    => [],
		];

		SiteContext::reset_cache();
	}

	/**
	 * The site identity is always present.
	 *
	 * @return void
	 */
	public function test_site_identity_is_always_detected(): void {
		$detected = SiteContext::detected();

		$this->assertSame( 'Studio Albert', $detected['site']['name'] );
		$this->assertSame( 'Furniture for people who make things', $detected['site']['tagline'] );
	}

	/**
	 * Core's own defaults never reach the payload as brand tokens.
	 *
	 * This is the provenance gate. `wp_get_global_settings()` never returns
	 * empty. WordPress ships its own theme.json, so a theme that declares
	 * nothing still yields core's generic palette. Sending that would be worse
	 * than sending nothing.
	 *
	 * @return void
	 */
	public function test_core_default_palette_is_not_sent_as_brand_tokens(): void {
		$this->declare_tokens(
			[
				'default' => [
					[
						'slug'  => 'black',
						'name'  => 'Black',
						'color' => '#000000',
					],
				],
			]
		);

		$this->assertArrayNotHasKey( 'design', SiteContext::detected() );
	}

	/**
	 * A theme's own palette is included.
	 *
	 * @return void
	 */
	public function test_theme_declared_palette_is_included(): void {
		$this->declare_tokens(
			[
				'default' => [
					[
						'slug'  => 'black',
						'color' => '#000000',
					],
				],
				'theme'   => [
					[
						'slug'  => 'primary',
						'name'  => 'Brand',
						'color' => '#5344F4',
					],
				],
			]
		);

		$design = SiteContext::detected()['design'];

		$this->assertCount( 1, $design['palette'] );
		$this->assertSame( 'primary', $design['palette'][0]['slug'] );
	}

	/**
	 * The Styles editor's overrides count as the site's own.
	 *
	 * @return void
	 */
	public function test_custom_origin_counts_as_site_provenance(): void {
		$this->declare_tokens(
			[
				'default' => [
					[
						'slug'  => 'black',
						'color' => '#000000',
					],
				],
				'custom'  => [
					[
						'slug'  => 'brand',
						'color' => '#c8a24e',
					],
				],
			]
		);

		$this->assertArrayHasKey( 'design', SiteContext::detected() );
	}

	/**
	 * An override replaces the theme's colour of the same slug, not doubles it.
	 *
	 * @return void
	 */
	public function test_an_override_replaces_rather_than_duplicates(): void {
		$this->declare_tokens(
			[
				'theme'  => [
					[
						'slug'  => 'primary',
						'color' => '#5344F4',
					],
				],
				'custom' => [
					[
						'slug'  => 'primary',
						'color' => '#1a2b4c',
					],
				],
			]
		);

		$palette = SiteContext::detected()['design']['palette'];

		$this->assertCount( 1, $palette );
		$this->assertSame( '#1a2b4c', $palette[0]['color'] );
	}

	/**
	 * Media is not offered as a content type.
	 *
	 * The assistant reaches media through the media abilities; naming
	 * `attachment` here invites it to treat uploads as posts.
	 *
	 * @return void
	 */
	public function test_attachments_are_not_listed_as_a_content_type(): void {
		$this->assertNotContains( 'attachment', SiteContext::detected()['content_model']['post_types'] );
	}

	/**
	 * A site with no shop carries no commerce section.
	 *
	 * @return void
	 */
	public function test_no_commerce_section_without_woocommerce(): void {
		$this->assertArrayNotHasKey( 'commerce', SiteContext::detected() );
	}

	/**
	 * A switched-off section does not reach the payload.
	 *
	 * @return void
	 */
	public function test_a_switched_off_section_is_not_built(): void {
		ContextSettings::save( [ 'sections' => [ 'environment' => false ] ] );
		SiteContext::reset_cache();

		$this->assertArrayNotHasKey( 'environment', SiteContext::build() );
		$this->assertArrayHasKey( 'environment', SiteContext::detected() );
	}

	/**
	 * The master switch empties the context entirely.
	 *
	 * @return void
	 */
	public function test_the_master_switch_empties_the_context(): void {
		ContextSettings::save( [ 'enabled' => false ] );
		SiteContext::reset_cache();

		$this->assertSame( [], SiteContext::build() );
		$this->assertSame( [], Payload::build() );
	}

	/**
	 * The owner's instructions ride in the same array, and are rendered.
	 *
	 * @return void
	 */
	public function test_owner_instructions_reach_the_payload(): void {
		ContextSettings::save( [ 'instructions' => 'Write in Dutch, informally.' ] );
		SiteContext::reset_cache();

		$this->assertStringContainsString( 'Write in Dutch, informally.', Payload::site() );
	}

	/**
	 * Instructions are stored as prose, with any markup stripped.
	 *
	 * This is bound for a language model, not a browser; leaving HTML in it
	 * would only give an injected tag somewhere to survive. Script and style
	 * bodies go with their tags rather than being flattened into the prose.
	 *
	 * @return void
	 */
	public function test_instructions_are_stripped_of_markup(): void {
		ContextSettings::save( [ 'instructions' => 'Write <em>well</em> <script>alert(1)</script>in Dutch.' ] );

		$this->assertSame( 'Write well in Dutch.', ContextSettings::instructions() );
	}

	/**
	 * The preview the screen renders is the payload the assistant receives.
	 *
	 * The Context screen joins the segments and calls the result "exactly what
	 * the assistant receives". This is that promise, as an assertion, if a
	 * future section renders differently for display, this fails.
	 *
	 * @return void
	 */
	public function test_the_preview_segments_join_to_the_wire_fields(): void {
		ContextSettings::save( [ 'instructions' => 'Write in Dutch.' ] );
		SiteContext::reset_cache();

		$wire = array_filter( [ Payload::site(), Payload::skills() ] );

		$this->assertSame( implode( "\n\n", $wire ), Payload::text() );
	}

	/**
	 * The untrusted-data framing rides with any site-supplied text.
	 *
	 * @return void
	 */
	public function test_framing_accompanies_site_supplied_text(): void {
		$payload = Payload::build();

		$this->assertArrayHasKey( 'site', $payload );
		$this->assertStringContainsString( 'Studio Albert', $payload['site'] );
		$this->assertStringContainsString( '# How to read this', $payload['site'] );
	}

	/**
	 * A filter can add a section, and it is rendered.
	 *
	 * @return void
	 */
	public function test_a_filter_can_add_a_section(): void {
		$GLOBALS['albert_test_filter_callbacks']['albert/context/site'] = static function ( array $context ): array {
			$context['analytics'] = [ 'provider' => 'Plausible' ];

			return $context;
		};

		SiteContext::reset_cache();

		$this->assertStringContainsString( 'Provider: Plausible', Payload::site() );

		unset( $GLOBALS['albert_test_filter_callbacks']['albert/context/site'] );
	}
}
