<?php
/**
 * Block Issues helper
 *
 * Partitions the issue list produced by BlockSerializer::serialize_with_issues()
 * into errors and warnings, and shapes each side for a write ability:
 *   - errors   → a single WP_Error (code 'block_validation_failed') the caller
 *                returns instead of saving, with every error message inlined so
 *                the LLM can fix the block specs and retry.
 *   - warnings → a plain list of strings for the ability's `block_issues`
 *                output (the post/page was still saved).
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Severity-aware partitioning of serializer issues for write abilities.
 *
 * @phpstan-type Issue array{severity: string, message: string}
 *
 * @since 1.2.0
 */
class BlockIssues {

	/**
	 * Build a block_validation_failed WP_Error from any error-severity issues.
	 *
	 * Returns null when there are no errors, so the caller can proceed to save.
	 *
	 * @param array<int, Issue> $issues Issues from BlockSerializer.
	 * @return WP_Error|null WP_Error when at least one error is present, else null.
	 *
	 * @since 1.2.0
	 */
	public static function to_wp_error( array $issues ): ?WP_Error {
		$errors = self::messages_by_severity( $issues, BlockSerializer::SEVERITY_ERROR );

		if ( $errors === [] ) {
			return null;
		}

		$summary = sprintf(
			/* translators: %d: number of block validation errors. */
			_n(
				'The content could not be saved because of %d block validation error. Fix it and try again:',
				'The content could not be saved because of %d block validation errors. Fix them and try again:',
				count( $errors ),
				'albert-ai-butler'
			),
			count( $errors )
		);

		$message = $summary . ' ' . implode( ' ', $errors );

		return new WP_Error(
			'block_validation_failed',
			$message,
			[
				'status' => 400,
				'errors' => $errors,
			]
		);
	}

	/**
	 * Extract the warning-severity messages as a plain list of strings.
	 *
	 * @param array<int, Issue> $issues Issues from BlockSerializer.
	 * @return array<int, string> Warning messages (the post/page was still saved).
	 *
	 * @since 1.2.0
	 */
	public static function warning_messages( array $issues ): array {
		return self::messages_by_severity( $issues, BlockSerializer::SEVERITY_WARNING );
	}

	/**
	 * Collect the messages for issues of a given severity.
	 *
	 * @param array<int, Issue> $issues   Issues from BlockSerializer.
	 * @param string            $severity Severity to keep.
	 * @return array<int, string> Matching messages, re-indexed.
	 *
	 * @since 1.2.0
	 */
	private static function messages_by_severity( array $issues, string $severity ): array {
		$messages = [];

		foreach ( $issues as $issue ) {
			if ( ( $issue['severity'] ?? '' ) === $severity ) {
				$messages[] = (string) ( $issue['message'] ?? '' );
			}
		}

		return $messages;
	}
}
