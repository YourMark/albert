<?php
/**
 * Design token reader: the site's real palette, not WordPress's.
 *
 * @package Albert
 * @subpackage Context\Readers
 * @since      1.4.0
 */

namespace Albert\Context\Readers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the design tokens the theme genuinely declares.
 *
 * The point of sending these is that a model asked for "a button in our brand
 * colour" should use a colour that exists on the site instead of inventing one.
 * That only works if the values are actually the site's.
 *
 * **Provenance is the whole design.** `wp_get_global_settings()` never comes
 * back empty: WordPress ships its own `theme.json`, so a theme that declares
 * nothing still yields core's generic palette. Handing that to a model dressed
 * as brand tokens is worse than sending nothing, it would confidently paint the
 * site in WordPress's defaults. So every value here is read from the `theme` and
 * `custom` origins only, and the section is omitted when those are empty.
 *
 * Reading by origin is also what makes one gate cover both theme kinds: a block
 * theme's `theme.json` and a classic theme's
 * `add_theme_support( 'editor-color-palette' )` both land in the `theme` origin,
 * and the Styles editor's overrides land in `custom`. No `wp_is_block_theme()`
 * branch is needed, and none is wanted, a block theme without a `theme.json`
 * would pass that check while declaring nothing.
 *
 * @since 1.4.0
 */
class DesignTokens {

	/**
	 * Origins that represent a real decision by this site.
	 *
	 * `default` is WordPress's own and is never read.
	 *
	 * @since 1.4.0
	 * @var list<string>
	 */
	private const SITE_ORIGINS = [ 'theme', 'custom' ];

	/**
	 * Most palette entries to send.
	 *
	 * A theme can declare thirty colours; the model needs the brand, not the
	 * catalogue. Within a theme's own entries, the first ones are what themes
	 * put first, the primary, secondary and base colours, so a cap reads
	 * better than a sample; a person's own custom colours are kept ahead of
	 * that cap regardless of position, see {@see self::cap_prioritising_custom()}.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const MAX_COLOURS = 8;

	/**
	 * Most font families to send.
	 *
	 * Themes ship a family per weight variant (Ollie ships four Mona Sans cuts
	 * before it reaches its serif), so a cap of four would hide the contrast
	 * pairing that is the useful part.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private const MAX_FONTS = 6;

	/**
	 * Build the design section, or null when the theme declares nothing.
	 *
	 * Null is meaningful and is not the same as an empty array: it is what tells
	 * the Context screen to show the classic-theme steer instead of an empty
	 * detected row.
	 *
	 * @return array{palette?: list<array{name: string, slug: string, color: string}>, fonts?: list<string>, spacing?: list<string>}|null Sections the theme declares, or null when it declares none.
	 * @since 1.4.0
	 */
	public function read(): ?array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return null;
		}

		$design = [];

		$palette = $this->palette();
		if ( $palette !== [] ) {
			$design['palette'] = $palette;
		}

		$fonts = $this->fonts();
		if ( $fonts !== [] ) {
			$design['fonts'] = $fonts;
		}

		// A spacing scale on its own says nothing about the brand, so it rides
		// along with real colour or type evidence rather than standing the
		// section up by itself.
		if ( $design === [] ) {
			return null;
		}

		$spacing = $this->spacing();
		if ( $spacing !== [] ) {
			$design['spacing'] = $spacing;
		}

		return $design;
	}

	/**
	 * The declared colour palette.
	 *
	 * Custom-origin entries survive the cap ahead of theme ones, {@see
	 * self::cap_prioritising_custom()}, they are the one part of this section a
	 * person chose deliberately in the Styles editor rather than a theme author
	 * choosing on their behalf, and a theme with a full palette of its own would
	 * otherwise push every one of them off the end silently.
	 *
	 * @return list<array{name: string, slug: string, color: string}>
	 * @since 1.4.0
	 */
	private function palette(): array {
		$colours = [];

		foreach ( $this->site_origin_values( [ 'color', 'palette' ] ) as $tagged ) {
			$origin = $tagged['origin'];
			$entry  = $tagged['value'];

			if ( ! is_array( $entry ) || ! isset( $entry['color'] ) || ! is_string( $entry['color'] ) ) {
				continue;
			}

			$slug = isset( $entry['slug'] ) && is_string( $entry['slug'] ) ? $entry['slug'] : '';

			// A later origin (custom) overriding an earlier one (theme) reuses
			// the slug, so keying on it keeps the override and drops the
			// original rather than sending both.
			$colours[ $slug !== '' ? $slug : $entry['color'] ] = [
				'origin' => $origin,
				'value'  => [
					'name'  => isset( $entry['name'] ) && is_string( $entry['name'] ) ? $entry['name'] : $slug,
					'slug'  => $slug,
					'color' => $entry['color'],
				],
			];
		}

		return $this->cap_prioritising_custom( array_values( $colours ), self::MAX_COLOURS );
	}

	/**
	 * The declared font families, as the names a person would say.
	 *
	 * A `fontFamily` value is a full CSS stack: `Cardo, Georgia, serif`. The
	 * model wants the family the site chose, so the declared `name` is preferred
	 * and the stack's first entry is the fallback.
	 *
	 * @return list<string> Font family names, capped at {@see self::MAX_FONTS}.
	 * @since 1.4.0
	 */
	private function fonts(): array {
		$fonts = [];

		foreach ( $this->site_origin_values( [ 'typography', 'fontFamilies' ] ) as $tagged ) {
			$origin = $tagged['origin'];
			$entry  = $tagged['value'];

			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name = '';

			if ( isset( $entry['name'] ) && is_string( $entry['name'] ) && $entry['name'] !== '' ) {
				$name = $entry['name'];
			} elseif ( isset( $entry['fontFamily'] ) && is_string( $entry['fontFamily'] ) ) {
				$stack = explode( ',', $entry['fontFamily'] );
				$name  = trim( $stack[0], " \t\n\r\0\x0B\"'" );
			}

			if ( $name !== '' ) {
				$fonts[ strtolower( $name ) ] = [
					'origin' => $origin,
					'value'  => $name,
				];
			}
		}

		return $this->cap_prioritising_custom( array_values( $fonts ), self::MAX_FONTS );
	}

	/**
	 * The declared spacing scale, as preset slugs.
	 *
	 * Slugs, not sizes, and the difference is the whole value of the line. A
	 * theme's spacing sizes are `clamp(1.5rem, 4vw, 2rem)` expressions, long,
	 * and useless to a model, which cannot put a raw clamp into block markup.
	 * What block markup takes is the preset reference built from the slug
	 * (`var:preset|spacing|medium`), so the slug is the actionable half and the
	 * cheap one.
	 *
	 * @return list<string> Spacing preset slugs, as block markup references them.
	 * @since 1.4.0
	 */
	private function spacing(): array {
		$slugs = [];

		foreach ( $this->site_origin_values( [ 'spacing', 'spacingSizes' ] ) as $tagged ) {
			$entry = $tagged['value'];

			if ( is_array( $entry ) && isset( $entry['slug'] ) && is_string( $entry['slug'] ) && $entry['slug'] !== '' ) {
				$slugs[ $entry['slug'] ] = $entry['slug'];
			}
		}

		return array_values( $slugs );
	}

	/**
	 * Flatten one origin-split global setting down to this site's own entries.
	 *
	 * Core returns these settings keyed by origin: `default`, `theme`,
	 * `custom`, but only when more than one origin has values; a single-origin
	 * setting comes back as a plain list. Both shapes are handled, and the
	 * `default` origin is dropped either way.
	 *
	 * Each entry keeps the origin it came from, `theme` or `custom`, because a
	 * cap applied later has to know which entries a person chose deliberately
	 * in the Styles editor rather than which ones a theme author shipped, see
	 * {@see self::cap_prioritising_custom()}. The single-origin fallback shape
	 * carries no origin of its own; it is tagged `theme`, the only origin that
	 * shape represents once the excluded `default`-only case above has already
	 * returned.
	 *
	 * @param list<string> $path Global settings path, e.g. `[ 'color', 'palette' ]`.
	 *
	 * @return list<array{origin: string, value: mixed}> Entries declared by the theme or the site owner, tagged with their origin.
	 * @since 1.4.0
	 */
	private function site_origin_values( array $path ): array {
		$setting = wp_get_global_settings( $path );

		if ( ! is_array( $setting ) || $setting === [] ) {
			return [];
		}

		// Origin-keyed shape: every key is an origin name.
		$keys       = array_keys( $setting );
		$is_origins = $keys === array_values( array_intersect( $keys, [ 'default', 'theme', 'custom' ] ) );

		if ( ! $is_origins ) {
			// A plain list means one origin supplied everything. That origin is
			// `default` exactly when the theme declares no theme.json and no
			// editor-palette support, which is the case this class exists to
			// exclude.
			if ( ! $this->theme_declares_tokens() ) {
				return [];
			}

			return array_map(
				static fn( $value ): array => [
					'origin' => 'theme',
					'value'  => $value,
				],
				array_values( $setting )
			);
		}

		$values = [];
		foreach ( self::SITE_ORIGINS as $origin ) {
			if ( ! isset( $setting[ $origin ] ) || ! is_array( $setting[ $origin ] ) ) {
				continue;
			}

			foreach ( array_values( $setting[ $origin ] ) as $value ) {
				$values[] = [
					'origin' => $origin,
					'value'  => $value,
				];
			}
		}

		return $values;
	}

	/**
	 * Cap a list of origin-tagged entries, keeping custom-origin ones first.
	 *
	 * `array_slice` from the front over-represents whichever origin merged
	 * first, which {@see self::SITE_ORIGINS} always makes `theme`, so an
	 * override can reuse a theme slug. A theme that already declares as many
	 * entries as the cap allows then pushed every custom entry off the end
	 * silently, dropping the one thing here a person chose deliberately in
	 * favour of what a theme author shipped, which is backwards. Custom
	 * entries are kept first here, and only the remaining budget is filled from
	 * what the theme declared.
	 *
	 * @param list<array{origin: string, value: mixed}> $entries Origin-tagged entries.
	 * @param int                                       $limit   Maximum entries to keep.
	 *
	 * @return list<mixed> Values, custom-origin ones prioritised, capped at $limit.
	 * @since 1.4.0
	 */
	private function cap_prioritising_custom( array $entries, int $limit ): array {
		$custom = [];
		$theme  = [];

		foreach ( $entries as $entry ) {
			if ( $entry['origin'] === 'custom' ) {
				$custom[] = $entry['value'];
			} else {
				$theme[] = $entry['value'];
			}
		}

		return array_slice( array_merge( $custom, $theme ), 0, $limit );
	}

	/**
	 * Whether the active theme declares tokens of its own, either way it can.
	 *
	 * Only consulted for the single-origin shape above, where the origin is not
	 * named in the returned data and has to be established some other way.
	 *
	 * @return bool True when the theme ships a theme.json or declares editor presets.
	 * @since 1.4.0
	 */
	private function theme_declares_tokens(): bool {
		if ( function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json() ) {
			return true;
		}

		return current_theme_supports( 'editor-color-palette' )
			|| current_theme_supports( 'editor-font-sizes' );
	}
}
