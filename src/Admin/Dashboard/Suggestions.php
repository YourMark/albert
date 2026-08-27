<?php
/**
 * Dashboard prompt suggestions
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin\Dashboard;

defined( 'ABSPATH' ) || exit;

use Albert\Core\AbilitiesState;

/**
 * Things worth asking an assistant on this particular site.
 *
 * The hard part of Albert is not connecting it, it is the moment after: an
 * assistant is attached to the site and nobody knows what to say to it. No
 * other screen answers that, and unlike a count of abilities it cannot be
 * derived from the Abilities screen either.
 *
 * **Every suggestion is checked before it is shown.** A prompt names the
 * abilities it needs, and it is listed only when all of them are switched on,
 * so the card never recommends something that would fail. That is also what
 * makes it site-specific for free: a shop with the WooCommerce abilities
 * enabled is offered order questions, and a site without them never sees them.
 *
 * Registered as data so an add-on contributes a prompt without touching markup.
 *
 * @since 1.4.0
 */
class Suggestions {

	/**
	 * How many to show. Enough to suggest range, few enough to read.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const LIMIT = 3;

	/**
	 * Prompts that work on this site right now.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array{text: string, requires: array<int, string>}>
	 */
	public function all(): array {
		/**
		 * Filters the prompt suggestions offered on the Dashboard.
		 *
		 * Each entry is `[ 'text' => string, 'requires' => string[] ]`, where
		 * `requires` lists the ability ids the prompt depends on. An entry is
		 * shown only when every one of them is enabled, so a suggestion never
		 * sends somebody to an assistant that will refuse.
		 *
		 * Write them as a person would speak, not as a feature list.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, array{text: string, requires: array<int, string>}> $prompts Registered prompts.
		 */
		$prompts = apply_filters( 'albert/dashboard/suggestions', $this->defaults() );

		if ( ! is_array( $prompts ) ) {
			return [];
		}

		$available = array_values(
			array_filter(
				$prompts,
				static function ( $prompt ): bool {
					if ( ! is_array( $prompt ) || empty( $prompt['text'] ) || ! is_string( $prompt['text'] ) ) {
						return false;
					}

					$requires = isset( $prompt['requires'] ) && is_array( $prompt['requires'] ) ? $prompt['requires'] : [];

					foreach ( $requires as $ability_id ) {
						if ( ! AbilitiesState::is_enabled( (string) $ability_id ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);

		return array_slice( $available, 0, self::LIMIT );
	}

	/**
	 * The prompts Free ships.
	 *
	 * Ordered so the first one that survives filtering is the most
	 * characteristic thing this site can do: commerce on a shop, content
	 * everywhere else.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array{text: string, requires: array<int, string>}>
	 */
	private function defaults(): array {
		return [
			[
				'text'     => __( 'Which products sold best last month?', 'albert-ai-butler' ),
				'requires' => [ 'albert/woo-find-orders', 'albert/woo-find-products' ],
			],
			[
				'text'     => __( 'Find every page that still mentions our old address.', 'albert-ai-butler' ),
				'requires' => [ 'albert/find-pages' ],
			],
			[
				'text'     => __( 'Draft a post announcing our new opening hours, and leave it as a draft.', 'albert-ai-butler' ),
				'requires' => [ 'albert/create-post' ],
			],
			[
				'text'     => __( 'Which posts have not been touched in over a year?', 'albert-ai-butler' ),
				'requires' => [ 'albert/find-posts' ],
			],
			[
				'text'     => __( 'Summarise what this site is about.', 'albert-ai-butler' ),
				'requires' => [ 'albert/find-pages' ],
			],
		];
	}
}
