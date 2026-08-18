/**
 * Token estimate, mirroring PHP's Albert\Context\TokenEstimator.
 *
 * The screen needs a number that moves while you type, before the debounced save
 * comes back with the server's answer. Two estimators would mean the counter
 * under the field and the cost chip above it disagree, which is exactly what
 * they did before this file existed: 30 against 33 on the same sentence.
 *
 * So this is a port, not a second opinion. Every constant and every rule below
 * matches `TokenEstimator`, and any change to one has to be made to both. The
 * PHP side carries the reasoning and the calibration; see
 * `docs/context-token-budget.md`.
 */

// Characters of an ASCII word per token.
const WORD_CHARS_PER_TOKEN = 6;

// Digits per token in a run of them.
const DIGIT_CHARS_PER_TOKEN = 3;

// Accented-Latin characters per token.
const LATIN_EXT_CHARS_PER_TOKEN = 2;

// CJK characters per token. The correction that matters most, and the reason
// characters ÷ 4 was abandoned: it priced Japanese at a third of its real cost.
const CJK_CHARS_PER_TOKEN = 1;

// Every other script: Greek, Cyrillic, Arabic, Hebrew, Thai, the Indic scripts.
const OTHER_CHARS_PER_TOKEN = 2.6;

/**
 * Whether a code point is Han, kana or Hangul.
 *
 * @param {number} code Unicode code point.
 * @return {boolean} True for CJK.
 */
function isCjk( code ) {
	return (
		( code >= 0x2e80 && code <= 0x9fff ) ||
		( code >= 0xac00 && code <= 0xd7af ) ||
		( code >= 0xf900 && code <= 0xfaff ) ||
		( code >= 0xff00 && code <= 0xffef ) ||
		( code >= 0x20000 && code <= 0x2ffff )
	);
}

/**
 * Whether a code point is accented Latin or Latin-adjacent punctuation.
 *
 * @param {number} code Unicode code point.
 * @return {boolean} True for extended Latin.
 */
function isLatinExt( code ) {
	if ( code >= 0x80 && code <= 0x024f ) {
		return true;
	}

	// Curly quotes, en and em dashes, the middle dot, the ellipsis.
	return [
		0x2018, 0x2019, 0x201c, 0x201d, 0x2013, 0x2014, 0x00b7, 0x2026,
	].includes( code );
}

/**
 * Price the ASCII part by its structure.
 *
 * Words by length, digit runs by their own rate, and every symbol as a token of
 * its own, which is what stops a line of hex colours being priced as prose.
 *
 * @param {string} ascii The ASCII-only part of the text.
 * @return {number} Estimated tokens.
 */
function asciiTokens( ascii ) {
	let tokens = 0;

	for ( const word of ascii.match( /[A-Za-z]+/g ) || [] ) {
		tokens += Math.max(
			1,
			Math.ceil( word.length / WORD_CHARS_PER_TOKEN )
		);
	}

	for ( const run of ascii.match( /[0-9]+/g ) || [] ) {
		tokens += Math.max(
			1,
			Math.ceil( run.length / DIGIT_CHARS_PER_TOKEN )
		);
	}

	tokens += ( ascii.match( /[^A-Za-z0-9\s]/g ) || [] ).length;

	return tokens;
}

/**
 * Estimate the token cost of a string.
 *
 * @param {string} text The text to price.
 * @return {number} Estimated tokens. Empty text costs nothing.
 */
export function estimateTokens( text ) {
	if ( ! text ) {
		return 0;
	}

	let ascii = '';
	let latinExt = 0;
	let cjk = 0;
	let other = 0;

	// Iterating the string yields whole code points, so an astral character is
	// counted once rather than as two surrogate halves.
	for ( const character of text ) {
		const code = character.codePointAt( 0 );

		if ( code < 0x80 ) {
			ascii += character;
		} else if ( isCjk( code ) ) {
			cjk += 1;
		} else if ( isLatinExt( code ) ) {
			latinExt += 1;
		} else {
			other += 1;
		}
	}

	const tokens =
		asciiTokens( ascii ) +
		latinExt / LATIN_EXT_CHARS_PER_TOKEN +
		cjk / CJK_CHARS_PER_TOKEN +
		other / OTHER_CHARS_PER_TOKEN;

	return Math.max( 1, Math.round( tokens ) );
}
