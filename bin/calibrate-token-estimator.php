<?php
/**
 * Check TokenEstimator against real tokeniser counts.
 *
 * Run from the plugin root:
 *
 *     php bin/calibrate-token-estimator.php
 *
 * The corpus in `bin/calibration/token-corpus.json` carries, for each sample,
 * the token count a real tokeniser produced for it (`o200k_base`, recorded when
 * the estimator was calibrated). This script prices the same samples with
 * {@see \Albert\Context\TokenEstimator} and reports the error against those
 * counts, so a change to the estimator's parameters can be checked rather than
 * asserted.
 *
 * To regenerate the reference counts against a different tokeniser:
 *
 *     pip install tiktoken
 *     python3 -c "
 *     import json, tiktoken
 *     enc = tiktoken.get_encoding('o200k_base')
 *     p = 'bin/calibration/token-corpus.json'
 *     c = json.load(open(p))
 *     for s in c: s['tokens_o200k'] = len(enc.encode(s['text']))
 *     json.dump(c, open(p,'w'), ensure_ascii=False, indent=1)
 *     "
 *
 * The corpus deliberately mixes nine languages with the sections this payload
 * actually renders, because the estimator's whole design rests on the fact that
 * script, not length, is what drives the cost.
 *
 * @package Albert
 * @since   1.4.0
 */

declare( strict_types=1 );

// This is a developer tool, not part of the plugin runtime.
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require __DIR__ . '/../vendor/autoload.php';

// TokenEstimator guards on ABSPATH like every other class in src/.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

$corpus_path = __DIR__ . '/calibration/token-corpus.json';
$corpus      = json_decode( (string) file_get_contents( $corpus_path ), true );

if ( ! is_array( $corpus ) ) {
	fwrite( STDERR, "Could not read {$corpus_path}\n" );
	exit( 1 );
}

printf( "%-12s %6s %6s %6s %8s\n", 'sample', 'chars', 'real', 'est', 'error' );

$errors = [];

foreach ( $corpus as $sample ) {
	$text     = (string) ( $sample['text'] ?? '' );
	$real     = (int) ( $sample['tokens_o200k'] ?? 0 );
	$estimate = \Albert\Context\TokenEstimator::estimate( $text );

	if ( $real === 0 ) {
		continue;
	}

	$error    = 100 * ( $estimate - $real ) / $real;
	$errors[] = $error;

	printf(
		"%-12s %6d %6d %6d %+7.1f%%\n",
		(string) ( $sample['id'] ?? '?' ),
		mb_strlen( $text ),
		$real,
		$estimate,
		$error
	);
}

if ( $errors === [] ) {
	exit( 1 );
}

$mean = array_sum( array_map( 'abs', $errors ) ) / count( $errors );

printf(
	"\nmean |error| %.1f%%   band %+.0f%% .. %+.0f%%\n",
	$mean,
	min( $errors ),
	max( $errors )
);

// The band recorded in TokenEstimator's docblock and in
// docs/context-token-budget.md. A change that widens it, or that pushes the
// lower edge further negative, needs those two updated with it.
printf( "documented: mean |error| 15%%   band -12%% .. +32%%\n" );
