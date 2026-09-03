<?php
/**
 * Context settings: what the site owner chose to send.
 *
 * @package Albert
 * @subpackage Context
 * @since      1.4.0
 */

namespace Albert\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the Albert → Context screen's stored choices.
 *
 * Three things live here: the master switch, the owner's free-text
 * instructions, and which of the auto-detected sections are included. All three
 * are a single option so one read serves the whole payload, and all three are
 * filterable so an agency can set them in code across a fleet without touching
 * the database on each site.
 *
 * Every accessor returns the *resolved* value, option, then filter. The raw
 * stored value is available separately, because the admin screen has to be able
 * to tell "you chose this" from "code chose this for you"; a screen that lets
 * you edit a value a filter is overriding is showing you a control that does
 * nothing.
 *
 * @since 1.4.0
 */
class ContextSettings {

	/**
	 * Option name holding every context choice.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const OPTION = 'albert_context';

	/**
	 * The auto-detected sections, in the order they are rendered.
	 *
	 * The owner's instructions are deliberately not in this list: they are the
	 * one part Albert cannot derive. They are switched by the master switch
	 * rather than a section toggle, and they are budgeted separately.
	 *
	 * @since 1.4.0
	 * @var list<string>
	 */
	public const SECTIONS = [ 'environment', 'design', 'content_model', 'commerce', 'skills' ];

	/**
	 * Longest instructions text accepted, in bytes.
	 *
	 * Not a token budget: a storage sanity bound. Everything the owner writes
	 * is sent on every conversation, so an accidental paste of a whole document
	 * should be refused at the boundary rather than quietly shipped.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	public const MAX_INSTRUCTIONS_BYTES = 20000;

	/**
	 * Per-request memo of the resolved settings.
	 *
	 * Typed with the full shape rather than `array<string, mixed>` so callers
	 * narrow from it directly; a looser type here would need an inline `@var`
	 * at every read to say the same thing again.
	 *
	 * @since 1.4.0
	 * @var array{enabled: bool, instructions: string, sections: array<string, bool>}|null
	 */
	private static ?array $resolved = null;

	/**
	 * The shipped defaults.
	 *
	 * Context is on by default and every detected section with it: the whole
	 * point is that an assistant stops guessing without the owner having to do
	 * anything first. Instructions start empty, because that is the part only a
	 * human can write.
	 *
	 * @return array{enabled: bool, instructions: string, sections: array<string, bool>}
	 * @since 1.4.0
	 */
	public static function defaults(): array {
		$sections = [];
		foreach ( self::SECTIONS as $section ) {
			$sections[ $section ] = true;
		}

		return [
			'enabled'      => true,
			'instructions' => '',
			'sections'     => $sections,
		];
	}

	/**
	 * The stored value, normalised against the defaults but not filtered.
	 *
	 * @return array{enabled: bool, instructions: string, sections: array<string, bool>}
	 * @since 1.4.0
	 */
	public static function stored(): array {
		$raw = get_option( self::OPTION, [] );

		return self::normalise( is_array( $raw ) ? $raw : [] );
	}

	/**
	 * The resolved settings: stored value with every filter applied.
	 *
	 * Memoised for the request, which has a consequence worth knowing before
	 * changing anything here: a filter registered *after* the first read never
	 * applies. Everything that filters these values must be hooked by `init` at
	 * the latest. {@see self::reset_cache()} exists for tests and for
	 * {@see self::save()}, so a read straight after a write sees the new value.
	 *
	 * @return array{enabled: bool, instructions: string, sections: array<string, bool>}
	 * @since 1.4.0
	 */
	public static function all(): array {
		if ( self::$resolved !== null ) {
			return self::$resolved;
		}

		$stored = self::stored();

		/**
		 * Filters whether Albert sends any context with the discovery response.
		 *
		 * Returning false is the code equivalent of switching the master switch
		 * off: the discovery response goes back to a bare ability list.
		 *
		 * @since 1.4.0
		 *
		 * @param bool $enabled Whether context is sent. Default true.
		 */
		$enabled = (bool) apply_filters( 'albert/context/enabled', $stored['enabled'] );

		/**
		 * Filters the site owner's context instructions.
		 *
		 * Set this in code to manage a fleet of sites from one place. The value
		 * is treated exactly like the stored one, as data the assistant reads
		 * for tone and subject matter, never as instructions about tools,
		 * permissions or scope.
		 *
		 * @since 1.4.0
		 *
		 * @param string $instructions The stored instructions.
		 */
		$instructions = (string) apply_filters( 'albert/context/instructions', $stored['instructions'] );

		/**
		 * Filters which auto-detected sections are included in the payload.
		 *
		 * Keyed by section: environment, design, content_model, commerce,
		 * skills, with a boolean value each. Unknown keys are dropped and
		 * missing keys fall back to the stored choice.
		 *
		 * @since 1.4.0
		 *
		 * @param array<string, bool> $sections The stored section choices.
		 */
		$sections = apply_filters( 'albert/context/sections', $stored['sections'] );

		self::$resolved = self::normalise(
			[
				'enabled'      => $enabled,
				'instructions' => $instructions,
				'sections'     => is_array( $sections ) ? $sections : $stored['sections'],
			]
		);

		return self::$resolved;
	}

	/**
	 * Whether context is sent at all.
	 *
	 * @return bool True when Albert sends context with the discovery response.
	 * @since 1.4.0
	 */
	public static function enabled(): bool {
		return self::all()['enabled'];
	}

	/**
	 * The resolved owner instructions.
	 *
	 * @return string The owner's text, filters applied, or an empty string.
	 * @since 1.4.0
	 */
	public static function instructions(): string {
		return self::all()['instructions'];
	}

	/**
	 * The resolved section choices.
	 *
	 * @return array<string, bool>
	 * @since 1.4.0
	 */
	public static function sections(): array {
		return self::all()['sections'];
	}

	/**
	 * Whether one auto-detected section is included.
	 *
	 * @param string $section Section key, one of {@see self::SECTIONS}.
	 *
	 * @return bool True when the section is included. False for an unknown key.
	 * @since 1.4.0
	 */
	public static function section_enabled( string $section ): bool {
		return self::sections()[ $section ] ?? false;
	}

	/**
	 * Which settings a filter is currently overriding.
	 *
	 * The admin screen uses this to lock the affected control and say who owns
	 * the value. Comparing resolved against stored is the only honest test: a
	 * filter that returns the stored value is not overriding anything, and
	 * marking it as such would put a spurious lock on the screen.
	 *
	 * @return array<string, bool> Keyed by `enabled`, `instructions`, `sections`.
	 * @since 1.4.0
	 */
	public static function filtered_keys(): array {
		$stored   = self::stored();
		$resolved = self::all();

		return [
			'enabled'      => $stored['enabled'] !== $resolved['enabled'],
			'instructions' => $stored['instructions'] !== $resolved['instructions'],
			'sections'     => $stored['sections'] !== $resolved['sections'],
		];
	}

	/**
	 * Persist a (partial) set of choices.
	 *
	 * Only the keys present in `$input` are changed; everything else keeps its
	 * stored value, and the full normalised shape is always written so a later
	 * read never has to guess at a missing key.
	 *
	 * @param array<string, mixed> $input Partial settings, any of `enabled`, `instructions`, `sections`.
	 *
	 * @return array{enabled: bool, instructions: string, sections: array<string, bool>} The stored result.
	 * @since 1.4.0
	 */
	public static function save( array $input ): array {
		$next = self::stored();

		if ( array_key_exists( 'enabled', $input ) ) {
			$next['enabled'] = (bool) $input['enabled'];
		}

		if ( array_key_exists( 'instructions', $input ) ) {
			$next['instructions'] = self::sanitise_instructions( (string) $input['instructions'] );
		}

		if ( array_key_exists( 'sections', $input ) && is_array( $input['sections'] ) ) {
			foreach ( self::SECTIONS as $section ) {
				if ( array_key_exists( $section, $input['sections'] ) ) {
					$next['sections'][ $section ] = (bool) $input['sections'][ $section ];
				}
			}
		}

		$next = self::normalise( $next );

		update_option( self::OPTION, $next, false );

		self::$resolved = null;

		return $next;
	}

	/**
	 * Clean the owner's instructions for storage.
	 *
	 * Tags are stripped rather than escaped: this is prose bound for a language
	 * model, not markup, and leaving HTML in it would only give an injected
	 * `<script>` somewhere to survive. Line breaks are preserved because the
	 * owner writes in paragraphs, and the byte cap is applied last so a
	 * multi-byte character can never be cut in half.
	 *
	 * @param string $value Raw submitted text.
	 *
	 * @return string Plain text, trimmed, within the byte cap.
	 * @since 1.4.0
	 */
	public static function sanitise_instructions( string $value ): string {
		$clean = wp_strip_all_tags( $value );
		$clean = str_replace( [ "\r\n", "\r" ], "\n", $clean );
		$clean = trim( $clean );

		if ( strlen( $clean ) > self::MAX_INSTRUCTIONS_BYTES ) {
			$clean = mb_strcut( $clean, 0, self::MAX_INSTRUCTIONS_BYTES, 'UTF-8' );
			$clean = trim( $clean );
		}

		return $clean;
	}

	/**
	 * Force any input into the full, well-typed settings shape.
	 *
	 * @param array<string, mixed> $input Partial or malformed settings.
	 *
	 * @return array{enabled: bool, instructions: string, sections: array<string, bool>}
	 * @since 1.4.0
	 */
	private static function normalise( array $input ): array {
		$defaults = self::defaults();

		$sections   = [];
		$from_input = ( isset( $input['sections'] ) && is_array( $input['sections'] ) ) ? $input['sections'] : [];

		foreach ( self::SECTIONS as $section ) {
			$sections[ $section ] = array_key_exists( $section, $from_input )
				? (bool) $from_input[ $section ]
				: $defaults['sections'][ $section ];
		}

		return [
			'enabled'      => array_key_exists( 'enabled', $input ) ? (bool) $input['enabled'] : $defaults['enabled'],
			'instructions' => isset( $input['instructions'] ) && is_string( $input['instructions'] )
				? self::sanitise_instructions( $input['instructions'] )
				: $defaults['instructions'],
			'sections'     => $sections,
		];
	}

	/**
	 * Drop the per-request memo.
	 *
	 * Intended for tests, and used by {@see self::save()} so a read after a
	 * write sees the new value.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function reset_cache(): void {
		self::$resolved = null;
	}
}
