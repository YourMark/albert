<?php
/**
 * Token estimator: how much of the assistant's context a block costs.
 *
 * @package Albert
 * @subpackage Context
 * @since      1.4.0
 */

namespace Albert\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Estimates how many tokens a piece of text costs.
 *
 * There is no real tokeniser here, on purpose. Every assistant that connects
 * tokenises differently, none of them ships with WordPress, and bundling one
 * would add megabytes to a plugin so a number on a settings screen could be
 * wrong with more decimal places. What the owner needs from it is the shape of
 * the trade (that switching Commerce off saves about as much as three sentences
 * of their own writing), and an estimate answers that.
 *
 * ## Why not characters ÷ 4
 *
 * That is the usual shortcut and it was the plan. Measured against a real
 * tokeniser on this payload's actual text it turned out to be wrong in a way
 * that mattered: **−67% to +28%**, and the −67% end was Japanese. A site owner
 * writing in Japanese or Chinese would have been told their instructions cost a
 * third of what they really cost. That is not an imprecise number but a
 * misleading one, and the direction of the error is the dangerous one.
 *
 * The reason is that the divisor is not a property of text in general; it is a
 * property of the script. Latin prose runs near five characters per token, CJK
 * near one, Greek and Cyrillic and Arabic near two and a half, and dense runs of
 * hex colours and version strings, which is most of what Albert's own detected
 * sections are made of, near two and a half as well.
 *
 * ## What this does instead
 *
 * Count each script for what it costs. ASCII is counted by structure: a word is
 * priced by its length, a digit run by its own rate, and every symbol as its own
 * token, which is what makes `#5344F4` and `7.1-RC4` come out near their real
 * price instead of a quarter of it. Everything else is priced per character at
 * its script's rate.
 *
 * ## The measurement
 *
 * Calibrated offline against `o200k_base` over 19 samples: prose in English,
 * Dutch, German, French, Spanish, Japanese, Chinese, Greek, Russian and Arabic,
 * plus every section this payload actually renders. Result: **mean absolute
 * error 15%, band −12% to +32%**.
 *
 * The band is deliberately lopsided. The parameters are the principled ones
 * rather than the best-fitting ones: a grid search found a set scoring 11% mean,
 * but it reached that by under-reporting some languages by 21%, and
 * over-reporting is the safe error here. Being told a section costs more than it
 * does leads to trimming; being told it costs less leads to a surprise.
 *
 * Recalibrate with `bin/calibrate-token-estimator.php` if these values are ever
 * changed. See `docs/context-token-budget.md` for the full measurement.
 *
 * @since 1.4.0
 */
class TokenEstimator {

	/**
	 * Characters of an ASCII word per token.
	 *
	 * @since 1.4.0
	 * @var float
	 */
	private const WORD_CHARS_PER_TOKEN = 6.0;

	/**
	 * Digits per token in a run of them.
	 *
	 * @since 1.4.0
	 * @var float
	 */
	private const DIGIT_CHARS_PER_TOKEN = 3.0;

	/**
	 * Accented-Latin characters per token.
	 *
	 * Umlauts, cedillas and the typographic dashes and quotes that sit beside
	 * them. Two per token: they fall outside the byte-pair merges that make
	 * plain ASCII cheap, without being as expensive as a different alphabet.
	 *
	 * @since 1.4.0
	 * @var float
	 */
	private const LATIN_EXT_CHARS_PER_TOKEN = 2.0;

	/**
	 * CJK characters per token.
	 *
	 * One each. A Han character, a kana or a Hangul syllable is close to a token
	 * on its own, which is the single biggest correction this class makes.
	 *
	 * @since 1.4.0
	 * @var float
	 */
	private const CJK_CHARS_PER_TOKEN = 1.0;

	/**
	 * Characters per token for every other script.
	 *
	 * Greek, Cyrillic, Arabic, Hebrew, Thai, the Indic scripts, and anything
	 * else not otherwise classified.
	 *
	 * @since 1.4.0
	 * @var float
	 */
	private const OTHER_CHARS_PER_TOKEN = 2.6;

	/**
	 * Estimate the token cost of a string.
	 *
	 * @param string $text The text to price.
	 *
	 * @return int Estimated tokens. Empty text costs nothing; anything else costs at least one.
	 * @since 1.4.0
	 */
	public static function estimate( string $text ): int {
		if ( $text === '' ) {
			return 0;
		}

		$counts = self::count_scripts( $text );

		$tokens = self::ascii_tokens( $counts['ascii'] )
			+ $counts['latin_ext'] / self::LATIN_EXT_CHARS_PER_TOKEN
			+ $counts['cjk'] / self::CJK_CHARS_PER_TOKEN
			+ $counts['other'] / self::OTHER_CHARS_PER_TOKEN;

		return max( 1, (int) round( $tokens ) );
	}

	/**
	 * Split the text into its ASCII part and per-script counts of the rest.
	 *
	 * @param string $text The text.
	 *
	 * @return array{ascii: string, latin_ext: int, cjk: int, other: int}
	 * @since 1.4.0
	 */
	private static function count_scripts( string $text ): array {
		$counts = [
			'ascii'     => '',
			'latin_ext' => 0,
			'cjk'       => 0,
			'other'     => 0,
		];

		$ascii = [];

		foreach ( mb_str_split( $text ) as $character ) {
			$code = mb_ord( $character, 'UTF-8' );

			if ( $code === false ) {
				continue;
			}

			if ( $code < 0x80 ) {
				$ascii[] = $character;
			} elseif ( self::is_cjk( $code ) ) {
				++$counts['cjk'];
			} elseif ( self::is_latin_ext( $code ) ) {
				++$counts['latin_ext'];
			} else {
				++$counts['other'];
			}
		}

		$counts['ascii'] = implode( '', $ascii );

		return $counts;
	}

	/**
	 * Price the ASCII part by its structure.
	 *
	 * Words by length, digit runs by their own rate, and every symbol as a token
	 * of its own. That last rule is what stops `#5344F4, #e9e7ff, #DEC9FF` being
	 * priced as if it were prose, a tokeniser breaks at each symbol, and a
	 * palette line is mostly symbols.
	 *
	 * @param string $ascii The ASCII-only part of the text.
	 *
	 * @return float Estimated tokens for the ASCII part alone.
	 * @since 1.4.0
	 */
	private static function ascii_tokens( string $ascii ): float {
		if ( $ascii === '' ) {
			return 0.0;
		}

		$tokens = 0.0;

		if ( preg_match_all( '/[A-Za-z]+/', $ascii, $words ) ) {
			foreach ( $words[0] as $word ) {
				$tokens += max( 1.0, ceil( strlen( $word ) / self::WORD_CHARS_PER_TOKEN ) );
			}
		}

		if ( preg_match_all( '/[0-9]+/', $ascii, $digits ) ) {
			foreach ( $digits[0] as $run ) {
				$tokens += max( 1.0, ceil( strlen( $run ) / self::DIGIT_CHARS_PER_TOKEN ) );
			}
		}

		$tokens += (float) preg_match_all( '/[^A-Za-z0-9\s]/', $ascii );

		return $tokens;
	}

	/**
	 * Whether a code point is Han, kana or Hangul.
	 *
	 * Includes the CJK punctuation and full-width forms blocks, because a
	 * Japanese sentence's `。` and `、` are as expensive as its characters.
	 *
	 * @param int $code Unicode code point.
	 *
	 * @return bool True for Han, kana, Hangul and CJK punctuation.
	 * @since 1.4.0
	 */
	private static function is_cjk( int $code ): bool {
		return ( $code >= 0x2E80 && $code <= 0x9FFF )
			|| ( $code >= 0xAC00 && $code <= 0xD7AF )
			|| ( $code >= 0xF900 && $code <= 0xFAFF )
			|| ( $code >= 0xFF00 && $code <= 0xFFEF )
			|| ( $code >= 0x20000 && $code <= 0x2FFFF );
	}

	/**
	 * Whether a code point is accented Latin or Latin-adjacent punctuation.
	 *
	 * @param int $code Unicode code point.
	 *
	 * @return bool True for accented Latin and the punctuation that travels with it.
	 * @since 1.4.0
	 */
	private static function is_latin_ext( int $code ): bool {
		if ( $code >= 0x80 && $code <= 0x024F ) {
			return true;
		}

		// Curly quotes, en and em dashes, the middle dot this payload separates
		// facts with, and the ellipsis.
		return in_array( $code, [ 0x2018, 0x2019, 0x201C, 0x201D, 0x2013, 0x2014, 0x00B7, 0x2026 ], true );
	}
}
