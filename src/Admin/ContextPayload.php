<?php
/**
 * Context screen payload.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Context\ContextSettings;
use Albert\Context\Payload;
use Albert\Context\PayloadRenderer;
use Albert\Context\SiteContext;
use Albert\Context\SkillIndex;
use Albert\Context\TokenEstimator;
use Albert\Core\AbilitiesState;

/**
 * Normalises everything the Albert → Context screen renders into one payload.
 *
 * The screen shows three things that have to agree: a preview of what the
 * assistant receives, a price per section, and a peek at each section's detected
 * values. They agree because all three are derived here from the same source,
 * the preview is {@see Payload::segments()} verbatim, and every price is that
 * same rendered text run through the estimator. Nothing on this screen is
 * re-rendered for display.
 *
 * Prices are given for sections that are switched *off* as well as on. "What
 * would turning this back on cost me" is a question the owner can only answer
 * from a figure the payload does not currently contain, so the row carries its
 * own cost whether or not it is included.
 *
 * @since 1.4.0
 */
class ContextPayload {

	/**
	 * There is deliberately no budget ceiling here, and that is a finding rather
	 * than an omission.
	 *
	 * Doc 21 carried 600 as a working target and said it must not reach this
	 * screen as a threshold until someone had checked what it meant. Checking it
	 * settled the question in the other direction. Measured on a real site, the
	 * whole context block is **433 tokens against the ability list's 4,411**, the
	 * tool definitions the same response already carries are ten times larger, and
	 * the entire discovery response is 2.4% of a 200k context window. Nothing
	 * observable happens at 600, or at 900. A meter drawn against that number
	 * would be a warning about a cliff that is not there, and would point the
	 * owner at the smaller of the two things they can change.
	 *
	 * So the screen reports what each part costs and stops. The per-section
	 * figures are still worth having, they answer "design tokens is a third of
	 * this, do I need it", but as a relative comparison, not a limit.
	 *
	 * See `docs/context-token-budget.md` for the measurement.
	 *
	 * @since 1.4.0
	 */

	/**
	 * Build the screen payload.
	 *
	 * Three numbers on the screen have to reconcile, and they do because all
	 * three come from one call to {@see Payload::segments()}: the total in the
	 * card header, the sum of the cost chips, and the tag on the preview. If a
	 * future change prices anything from a second source, they will drift apart
	 * and the screen will show two totals for one payload, which is the bug this
	 * shape was written to prevent.
	 *
	 * @return array<string, mixed> Everything the Context screen renders.
	 * @since 1.4.0
	 */
	public static function build(): array {
		SiteContext::reset_cache();
		ContextSettings::reset_cache();

		$settings = ContextSettings::all();
		$detected = SiteContext::detected();
		$segments = Payload::segments();
		$sections = self::sections( $detected, $settings['enabled'], array_column( $segments, 'key' ) );

		$instructions = self::instruction_cost( $segments );

		return [
			'enabled'            => $settings['enabled'],
			'instructions'       => $settings['instructions'],
			'sections'           => $sections,
			'managed'            => ContextSettings::filtered_keys(),
			'budget'             => [
				'total'       => self::total_cost( $segments ),
				'instruction' => $instructions,
			],
			// The heading Albert wraps the owner's instructions in. The screen
			// needs it to price what they are typing the same way the server
			// prices what it stores. The heading is part of what having
			// instructions costs, and counting it on one side only was why the
			// field counter and the cost chip disagreed.
			'instructionHeading' => PayloadRenderer::INSTRUCTIONS_HEADING,
			'costs'              => self::costs( $segments ),
			'preview'            => $segments,
		];
	}

	/**
	 * Describe each switchable section: what it is, what it found, what it costs.
	 *
	 * A section absent from the detected set is still described: with
	 * `available` false, for every case except Commerce. The design tokens row
	 * has to be present-but-empty so it can carry the steer towards writing
	 * brand colours in prose; Commerce is dropped outright, because a row about
	 * a shop on a site with no shop is noise rather than guidance.
	 *
	 * A row reports itself included only when its section actually reached the
	 * payload, not merely when its switch is on. `albert/context/site` can drop a
	 * section the owner switched on, and a row that then still read "on" would be
	 * the screen asserting something the preview beside it contradicts.
	 *
	 * The exception is the master switch being off, where nothing reaches the
	 * payload at all. There the rows keep showing the owner's stored choices, so
	 * switching context back on does not look like it has forgotten them.
	 *
	 * @param array<string, mixed> $detected Every detected section.
	 * @param bool                 $enabled  Whether context is being sent at all.
	 * @param list<string>         $sent     Keys of the sections that reached the payload.
	 *
	 * @return list<array<string, mixed>>
	 * @since 1.4.0
	 */
	private static function sections( array $detected, bool $enabled, array $sent ): array {
		$renderer = new PayloadRenderer();
		$chosen   = ContextSettings::sections();
		$rows     = [];

		foreach ( ContextSettings::SECTIONS as $key ) {
			$available = self::is_available( $key, $detected );

			if ( $key === 'commerce' && ! $available ) {
				continue;
			}

			$data = $detected[ $key ] ?? null;
			$text = $key === 'skills'
				? $renderer->render_skills( SkillIndex::entries() )
				: ( $data === null ? '' : $renderer->render_section( $key, $data ) );

			$rows[] = [
				'key'         => $key,
				'label'       => self::label( $key ),
				'description' => self::description( $key ),
				'available'   => $available,
				'enabled'     => $available && (
					$enabled ? in_array( $key, $sent, true ) : ( $chosen[ $key ] ?? false )
				),
				'tokens'      => $available ? TokenEstimator::estimate( $text ) : 0,
				'peek'        => self::peek( $key, $data ),
				'palette'     => $key === 'design' && is_array( $data ) ? ( $data['palette'] ?? [] ) : [],
				'fonts'       => $key === 'design' && is_array( $data ) ? ( $data['fonts'] ?? [] ) : [],
				'hint'        => self::hint( $key, $available ),
			];
		}

		return $rows;
	}

	/**
	 * Whether a section has anything to contribute on this site.
	 *
	 * @param string               $key      Section key.
	 * @param array<string, mixed> $detected Every detected section.
	 *
	 * @return bool True when the section found something worth sending.
	 * @since 1.4.0
	 */
	private static function is_available( string $key, array $detected ): bool {
		if ( $key === 'skills' ) {
			// The index names guides and tells the assistant to fetch them with
			// `albert/get-skill`. With that ability switched off on the Abilities
			// screen the fetch fails, so the row reports itself unavailable
			// rather than offering to spend budget on a dead end.
			return SkillIndex::entries() !== [] && AbilitiesState::is_enabled( SkillIndex::FETCH_ABILITY );
		}

		return isset( $detected[ $key ] );
	}

	/**
	 * Estimated cost of the whole payload.
	 *
	 * Every part, the owner's instructions included. An earlier version excluded
	 * them so they could be budgeted separately, which left the card reporting
	 * one total and the preview below it reporting a larger one, two numbers for
	 * one payload, with nothing on screen to explain the gap.
	 *
	 * @param list<array{key: string, text: string}> $segments Rendered segments.
	 *
	 * @return int Estimated tokens for the whole payload.
	 * @since 1.4.0
	 */
	private static function total_cost( array $segments ): int {
		$total = 0;

		foreach ( $segments as $segment ) {
			$total += TokenEstimator::estimate( $segment['text'] );
		}

		return $total;
	}

	/**
	 * Estimated cost of the owner's own instructions.
	 *
	 * @param list<array{key: string, text: string}> $segments Rendered segments.
	 *
	 * @return int Estimated tokens for the owner's section, heading included, or 0 when they wrote nothing.
	 * @since 1.4.0
	 */
	private static function instruction_cost( array $segments ): int {
		foreach ( $segments as $segment ) {
			if ( $segment['key'] === PayloadRenderer::OWNER_SECTION ) {
				return TokenEstimator::estimate( $segment['text'] );
			}
		}

		return 0;
	}

	/**
	 * The per-part cost chips, in payload order.
	 *
	 * Built from the rendered segments rather than from the section list, so the
	 * chips account for every part of the payload including the ones that have no
	 * switch, the site identity and the safety note. Chips that summed to less
	 * than the total would be the screen quietly hiding what it spent.
	 *
	 * Every chip is counted the same way. An earlier version marked the owner's
	 * instructions with a dashed outline to say they sat outside the budget;
	 * with no budget to sit outside, that distinction became a difference in the
	 * UI standing for nothing.
	 *
	 * @param list<array{key: string, text: string}> $segments Rendered segments.
	 *
	 * @return list<array{key: string, label: string, tokens: int}>
	 * @since 1.4.0
	 */
	private static function costs( array $segments ): array {
		$chips = [];

		foreach ( $segments as $segment ) {
			$chips[] = [
				'key'    => $segment['key'],
				'label'  => self::label( $segment['key'] ),
				'tokens' => TokenEstimator::estimate( $segment['text'] ),
			];
		}

		return $chips;
	}

	/**
	 * The human label for a payload part.
	 *
	 * These names appear in three places that must agree: the cost chip here, the
	 * card heading in the React screen, and the heading the renderer writes into
	 * the payload itself. Changing one alone produces a screen that calls the same
	 * thing two names, which it did until "Your instructions" and "Site
	 * instructions" were reconciled.
	 *
	 * @param string $key Section key.
	 *
	 * @return string The label, or a prettified key for a section a filter added.
	 * @since 1.4.0
	 */
	private static function label( string $key ): string {
		$labels = [
			'site'          => __( 'Site', 'albert-ai-butler' ),
			'environment'   => __( 'Environment', 'albert-ai-butler' ),
			'design'        => __( 'Design tokens', 'albert-ai-butler' ),
			'content_model' => __( 'Content model', 'albert-ai-butler' ),
			'commerce'      => __( 'Commerce', 'albert-ai-butler' ),
			'skills'        => __( 'Skills index', 'albert-ai-butler' ),
			'instructions'  => __( 'Site instructions', 'albert-ai-butler' ),
			'notice'        => __( 'Safety note', 'albert-ai-butler' ),
		];

		return $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
	}

	/**
	 * The one-line description under a section's label.
	 *
	 * @param string $key Section key.
	 *
	 * @return string One line for the row, or an empty string for a section with no switch.
	 * @since 1.4.0
	 */
	private static function description( string $key ): string {
		switch ( $key ) {
			case 'environment':
				return __( 'WordPress and theme version, site language and timezone.', 'albert-ai-butler' );
			case 'design':
				return __( 'The colours and fonts your theme declares, including anything you changed in the Styles editor.', 'albert-ai-butler' );
			case 'content_model':
				return __( 'Which post types and taxonomies exist here, and what manages them.', 'albert-ai-butler' );
			case 'commerce':
				return __( 'Currency, tax display and order statuses from WooCommerce.', 'albert-ai-butler' );
			case 'skills':
				return __( 'The task guides an assistant can ask for, by name.', 'albert-ai-butler' );
			default:
				return '';
		}
	}

	/**
	 * A short look at what the section found.
	 *
	 * Deliberately not the rendered payload text: the payload is right below in
	 * the preview, and repeating it here would make a long row out of something
	 * that only has to answer "did it find the right things".
	 *
	 * @param string $key  Section key.
	 * @param mixed  $data Detected section data.
	 *
	 * @return string A short look at the detected values, or an empty string when there is nothing to show.
	 * @since 1.4.0
	 */
	private static function peek( string $key, $data ): string {
		if ( $key === 'skills' ) {
			$slugs = array_column( SkillIndex::entries(), 'slug' );

			return implode( ' · ', $slugs );
		}

		if ( ! is_array( $data ) ) {
			return '';
		}

		switch ( $key ) {
			case 'environment':
				$theme = is_array( $data['theme'] ?? null ) ? $data['theme'] : [];

				return implode(
					' · ',
					array_filter(
						[
							(string) ( $data['locale'] ?? '' ),
							(string) ( $data['timezone'] ?? '' ),
							trim( ( $theme['name'] ?? '' ) . ' ' . ( $theme['version'] ?? '' ) ),
							'WP ' . ( $data['wordpress'] ?? '' ),
						]
					)
				);

			case 'design':
				return __( 'Read from your theme and your Styles editor.', 'albert-ai-butler' );

			case 'content_model':
				$types      = is_array( $data['post_types'] ?? null ) ? $data['post_types'] : [];
				$taxonomies = is_array( $data['taxonomies'] ?? null ) ? $data['taxonomies'] : [];

				return implode(
					' · ',
					array_filter(
						[
							implode( ', ', array_slice( $types, 0, 4 ) ),
							sprintf(
								/* translators: %d: number of taxonomies. */
								_n( '%d taxonomy', '%d taxonomies', count( $taxonomies ), 'albert-ai-butler' ),
								count( $taxonomies )
							),
							is_array( $data['owners'] ?? null ) ? implode( ', ', $data['owners'] ) : '',
						]
					)
				);

			case 'commerce':
				$statuses = is_array( $data['order_statuses'] ?? null ) ? $data['order_statuses'] : [];

				return implode(
					' · ',
					array_filter(
						[
							(string) ( $data['currency'] ?? '' ),
							array_key_exists( 'prices_include_tax', $data )
								? ( $data['prices_include_tax']
									? __( 'incl. tax', 'albert-ai-butler' )
									: __( 'excl. tax', 'albert-ai-butler' ) )
								: '',
							$statuses === []
								? ''
								: sprintf(
									/* translators: %d: number of order statuses. */
									_n( '%d order status', '%d order statuses', count( $statuses ), 'albert-ai-butler' ),
									count( $statuses )
								),
						]
					)
				);

			default:
				return '';
		}
	}

	/**
	 * The inline steer shown when a section found nothing.
	 *
	 * Only design tokens has one, and it exists because "not detected" is the
	 * common case on page-builder sites, where the brand lives somewhere
	 * `theme.json` cannot reach. The prose route is the answer for v1: the owner
	 * writes their colours in the instructions field, which rides the channel
	 * Albert already injects and is framed as data.
	 *
	 * The wording names no file. A site owner who is told their theme "ships no
	 * theme.json" has learned nothing they can act on.
	 *
	 * @param string $key       Section key.
	 * @param bool   $available Whether the section found anything.
	 *
	 * @return string The steer for a section that found nothing, or an empty string.
	 * @since 1.4.0
	 */
	private static function hint( string $key, bool $available ): string {
		if ( $available ) {
			return '';
		}

		if ( $key === 'design' ) {
			return __( 'We could not read brand colours or fonts from your theme. Name them in the instructions above and the assistant will use those.', 'albert-ai-butler' );
		}

		if ( $key === 'skills' ) {
			return __( 'Task guides need the Get Skill ability, which is switched off. Turn it on under Abilities to offer them.', 'albert-ai-butler' );
		}

		return '';
	}
}
