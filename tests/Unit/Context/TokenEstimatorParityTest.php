<?php
/**
 * Guards the PHP and JavaScript token estimators against drifting apart.
 *
 * @package Albert\Tests\Unit\Context
 */

namespace Albert\Tests\Unit\Context;

use Albert\Context\TokenEstimator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Estimator parity tests.
 *
 * The Context screen prices what is being typed in JavaScript, before the
 * debounced save returns the server's figure. Two estimators mean two answers
 * for one sentence, and that is not hypothetical. The first version reported 30
 * under the field and 51 in the cost chip above it for the same instructions.
 *
 * `assets/src/context/tokens.js` is therefore a port of {@see TokenEstimator},
 * not a second opinion. Nothing in the build enforces that, so this does: it
 * reads the constants out of the JavaScript and compares them to the PHP ones.
 * A change to either side that is not made to both fails here rather than
 * showing up as two numbers disagreeing on screen.
 *
 * It cannot check that the *algorithm* still matches, only its parameters. The
 * algorithm is short and the docblocks on both sides say they are a pair; the
 * parameters are what someone would plausibly tune in isolation.
 */
class TokenEstimatorParityTest extends TestCase {

	/**
	 * The JavaScript port's source.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Read the JavaScript port once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$path = dirname( __DIR__, 3 ) . '/assets/src/context/tokens.js';

		$this->assertFileExists( $path, 'The JavaScript token estimator is missing.' );

		$this->source = (string) file_get_contents( $path );
	}

	/**
	 * Every rate constant matches its PHP counterpart.
	 *
	 * @dataProvider provide_constants
	 *
	 * @param string $php_constant Name of the private constant on TokenEstimator.
	 * @param string $js_constant  Name of the constant in tokens.js.
	 *
	 * @return void
	 */
	public function test_rate_constants_match( string $php_constant, string $js_constant ): void {
		$reflection = new ReflectionClass( TokenEstimator::class );
		$expected   = (float) $reflection->getConstant( $php_constant );

		$this->assertNotSame(
			0.0,
			$expected,
			sprintf( 'TokenEstimator::%s is missing, so the port has nothing to match.', $php_constant )
		);

		$matched = preg_match(
			'/const\s+' . preg_quote( $js_constant, '/' ) . '\s*=\s*([0-9.]+)\s*;/',
			$this->source,
			$found
		);

		$this->assertSame(
			1,
			$matched,
			sprintf( '%s is not declared in assets/src/context/tokens.js.', $js_constant )
		);

		$this->assertSame(
			$expected,
			(float) $found[1],
			sprintf(
				'%s is %s in JavaScript but %s in PHP. The screen would report one number while the payload costs another.',
				$js_constant,
				$found[1],
				$expected
			)
		);
	}

	/**
	 * The constant pairs to compare.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provide_constants(): array {
		return [
			'ASCII words'    => [ 'WORD_CHARS_PER_TOKEN', 'WORD_CHARS_PER_TOKEN' ],
			'digit runs'     => [ 'DIGIT_CHARS_PER_TOKEN', 'DIGIT_CHARS_PER_TOKEN' ],
			'accented Latin' => [ 'LATIN_EXT_CHARS_PER_TOKEN', 'LATIN_EXT_CHARS_PER_TOKEN' ],
			'CJK'            => [ 'CJK_CHARS_PER_TOKEN', 'CJK_CHARS_PER_TOKEN' ],
			'other scripts'  => [ 'OTHER_CHARS_PER_TOKEN', 'OTHER_CHARS_PER_TOKEN' ],
		];
	}

	/**
	 * The port still classifies the same scripts.
	 *
	 * A CJK range dropped from one side and not the other would leave Japanese
	 * priced correctly on the server and at a third of its cost in the field.
	 * That is the exact failure the shared estimator exists to prevent,
	 * reintroduced quietly.
	 *
	 * @return void
	 */
	public function test_script_ranges_are_present_in_the_port(): void {
		foreach ( [ '0x2e80', '0x9fff', '0xac00', '0xd7af', '0xf900', '0xfaff', '0xff00', '0xffef', '0x20000', '0x2ffff' ] as $boundary ) {
			$this->assertStringContainsStringIgnoringCase(
				$boundary,
				$this->source,
				sprintf( 'CJK range boundary %s is missing from the JavaScript port.', $boundary )
			);
		}
	}
}
