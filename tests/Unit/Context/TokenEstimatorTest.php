<?php
/**
 * Tests for the token estimator.
 *
 * @package Albert\Tests\Unit\Context
 */

namespace Albert\Tests\Unit\Context;

use Albert\Context\TokenEstimator;
use PHPUnit\Framework\TestCase;

/**
 * TokenEstimator unit tests.
 *
 * The estimator's whole reason for existing is that characters ÷ 4 is wrong by
 * up to 67% on non-Latin scripts, so what is pinned here is the property that
 * makes it better: cost tracks script, and the corrections are large enough that
 * a regression to a flat divisor would fail these outright.
 *
 * The calibration itself: mean error and band against a real tokeniser, is
 * checked by `bin/calibrate-token-estimator.php`, which needs the corpus and its
 * reference counts. These tests pin the behaviour that must not silently change.
 */
class TokenEstimatorTest extends TestCase {

	/**
	 * Empty text costs nothing; anything else costs at least one token.
	 *
	 * @return void
	 */
	public function test_empty_text_costs_nothing(): void {
		$this->assertSame( 0, TokenEstimator::estimate( '' ) );
		$this->assertGreaterThanOrEqual( 1, TokenEstimator::estimate( 'a' ) );
	}

	/**
	 * A CJK string costs far more than its character count ÷ 4.
	 *
	 * This is the correction that matters most: a flat divisor priced Japanese
	 * at a third of its real cost, which would have told a Japanese site owner
	 * their instructions were nearly free.
	 *
	 * @return void
	 */
	public function test_cjk_costs_about_one_token_per_character(): void {
		$japanese = '私たちはユトレヒトにある小さな家具工房です';
		$length   = mb_strlen( $japanese );

		$estimate = TokenEstimator::estimate( $japanese );

		// Near one token per character, and unambiguously more than chars ÷ 4.
		$this->assertGreaterThan( $length * 0.8, $estimate );
		$this->assertGreaterThan( (int) ceil( $length / 4 ) * 2, $estimate );
	}

	/**
	 * Greek and Cyrillic cost more per character than Latin, and less than CJK.
	 *
	 * @return void
	 */
	public function test_other_scripts_sit_between_latin_and_cjk(): void {
		// Same rough sentence length in three scripts.
		$latin = TokenEstimator::estimate( str_repeat( 'word ', 20 ) );
		$greek = TokenEstimator::estimate( str_repeat( 'λέξη ', 20 ) );
		$han   = TokenEstimator::estimate( str_repeat( '詞語 ', 20 ) );

		$this->assertGreaterThan( $latin, $greek );
		$this->assertGreaterThan( $greek, $han );
	}

	/**
	 * A run of hex colours costs far more than the same length of prose.
	 *
	 * Every symbol breaks a token, and a palette line is mostly symbols, which
	 * is why Albert's own design section was the most under-priced part of the
	 * payload under a flat divisor.
	 *
	 * @return void
	 */
	public function test_symbol_dense_text_costs_more_than_prose(): void {
		$palette = 'Palette: primary #5344F4, primary-accent #e9e7ff, primary-alt #DEC9FF';
		$prose   = 'The palette here has a primary colour and two accents beside it, all told';

		// Comparable lengths, so the difference is structure rather than size.
		$this->assertLessThan( 12, abs( strlen( $palette ) - strlen( $prose ) ) );
		$this->assertGreaterThan( TokenEstimator::estimate( $prose ), TokenEstimator::estimate( $palette ) );
	}

	/**
	 * Accented Latin is priced above plain ASCII but nowhere near CJK.
	 *
	 * @return void
	 */
	public function test_accented_latin_costs_a_little_more_than_ascii(): void {
		$plain    = TokenEstimator::estimate( str_repeat( 'schone ', 20 ) );
		$accented = TokenEstimator::estimate( str_repeat( 'schöne ', 20 ) );

		$this->assertGreaterThan( $plain, $accented );
		$this->assertLessThan( $plain * 2, $accented );
	}

	/**
	 * Cost is measured in characters, never bytes.
	 *
	 * `strlen()` would count UTF-8 continuation bytes no tokeniser ever sees and
	 * price a Greek site at three times its real cost.
	 *
	 * @return void
	 */
	public function test_multibyte_text_is_not_priced_by_its_byte_length(): void {
		$greek = str_repeat( 'ελληνικά ', 10 );

		$this->assertLessThan( strlen( $greek ) / 2, TokenEstimator::estimate( $greek ) );
	}
}
