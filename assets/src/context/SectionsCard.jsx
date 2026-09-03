/**
 * "What Albert sends", one switchable row per auto-detected section.
 *
 * Each row answers two questions at a glance: what the section is, and what
 * it found on this site. The peek is what makes the switch a decision rather
 * than a guess. "Environment" alone is not enough to choose on, "nl_NL ·
 * Europe/Amsterdam · Ollie 1.6.0" is.
 */
import { FormToggle } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Render the detected design tokens: swatches and font names.
 *
 * Each swatch carries its value in a `title`, and the colour is never the only
 * carrier of meaning, the row's peek line and the payload preview both name the
 * same values in text.
 *
 * @param {Object} props         Props.
 * @param {Array}  props.palette Detected colours.
 * @param {Array}  props.fonts   Detected font families.
 * @return {Element|null} The preview, or null when there is nothing to show.
 */
// Colours come from the active theme's theme.json, which WordPress does not
// sanitise for the `theme` origin, so the value is whatever the theme author
// typed. Anything that is not a plain colour is dropped rather than painted:
// `background: url(https://…)` in an inline style would have wp-admin fetch a
// third-party URL every time this screen is opened.
const SAFE_COLOUR = /^(#[0-9a-f]{3,8}|(rgba?|hsla?|oklch|oklab|lch|lab|color)\([^;{}()]*\)|[a-z]{3,20})$/i;

/**
 * The swatch background, when the theme gave us something paintable.
 *
 * @param {string} value The colour as the theme declared it.
 * @return {Object} Style props, empty when the value is not a plain colour.
 */
function swatchStyle( value ) {
	const colour = String( value || '' ).trim();

	return SAFE_COLOUR.test( colour ) ? { background: colour } : {};
}

function DesignPeek( { palette, fonts } ) {
	if ( ! palette.length && ! fonts.length ) {
		return null;
	}

	return (
		<div className="albert-context__design-peek">
			{ palette.length > 0 && (
				<ul className="albert-context__swatches">
					{ palette.map( ( colour ) => (
						<li key={ colour.slug || colour.color }>
							<span
								className="albert-swatch"
								style={ swatchStyle( colour.color ) }
								title={ `${ colour.slug || colour.name }: ${
									colour.color
								}` }
							/>
							<span className="screen-reader-text">
								{ `${ colour.slug || colour.name }: ${
									colour.color
								}` }
							</span>
						</li>
					) ) }
				</ul>
			) }
			{ fonts.length > 0 && (
				<ul className="albert-context__fonts">
					{ fonts.map( ( font ) => (
						<li key={ font }>
							<span className="albert-badge albert-badge--outline">
								{ font }
							</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * Render the sections card.
 *
 * @param {Object}   props            Props.
 * @param {Array}    props.sections   Section rows from the REST payload.
 * @param {boolean}  props.managed    Whether a filter owns the section choices.
 * @param {boolean}  props.off        Whether context is switched off entirely.
 * @param {boolean}  props.hasCommerce Whether a Commerce row is present.
 * @param {Function} props.onToggle   Called with (key, enabled).
 * @return {Element} The card.
 */
export default function SectionsCard( {
	sections,
	managed,
	off,
	hasCommerce,
	onToggle,
} ) {
	return (
		<section className="albert-card">
			<header className="albert-card__header">
				<div className="albert-card__text">
					<h2 className="albert-card__title">
						{ __( 'What Albert sends', 'albert-ai-butler' ) }
					</h2>
					<p className="albert-card__description">
						{ __(
							'Albert detects these from your site. Switch off anything an assistant does not need.',
							'albert-ai-butler'
						) }
					</p>
				</div>
			</header>

			{ managed && (
				<p className="albert-hint albert-hint--info albert-context__managed">
					{ __(
						'These choices are set in code on this site, so they cannot be changed here.',
						'albert-ai-butler'
					) }
				</p>
			) }

			<div className="albert-card__body albert-card__body--flush">
				{ sections.map( ( section ) => (
					<div className="albert-toggle-row" key={ section.key }>
						<div className="albert-toggle-row__text">
							<div className="albert-toggle-row__label">
								<span>{ section.label }</span>
							</div>
							<p
								className="albert-toggle-row__description"
								id={ `albert-context-${ section.key }-desc` }
							>
								{ section.description }
							</p>

							{ section.available && section.peek && (
								<p
									className="albert-toggle-row__peek"
									id={ `albert-context-${ section.key }-peek` }
								>
									{ section.peek }
								</p>
							) }

							{ section.available && (
								<DesignPeek
									palette={ section.palette }
									fonts={ section.fonts }
								/>
							) }

							{ ! section.available && section.hint && (
								<p
									className="albert-hint albert-hint--info"
									id={ `albert-context-${ section.key }-hint` }
								>
									{ section.hint }
								</p>
							) }
						</div>

						{ /*
						 * Named after the section, with the checked state
						 * carrying on/off, so it never announces "Include
						 * Design tokens, checked" for a section already
						 * included. Same shape as the Abilities row toggle.
						 *
						 * Described by the description and whichever of the
						 * peek or the unavailable hint is showing.
						 */ }
						<FormToggle
							checked={ section.enabled }
							disabled={
								! section.available || managed || off
							}
							aria-describedby={ [
								`albert-context-${ section.key }-desc`,
								section.available && section.peek
									? `albert-context-${ section.key }-peek`
									: null,
								! section.available && section.hint
									? `albert-context-${ section.key }-hint`
									: null,
							]
								.filter( Boolean )
								.join( ' ' ) }
							aria-label={ sprintf(
								/* translators: %s: the name of a context section, e.g. "Design tokens". */
								__( '%s included', 'albert-ai-butler' ),
								section.label
							) }
							onChange={ () =>
								onToggle( section.key, ! section.enabled )
							}
						/>
					</div>
				) ) }
			</div>

			{ ! hasCommerce && (
				<p className="albert-card__footnote">
					{ __(
						'Commerce is not listed, because WooCommerce is not active on this site.',
						'albert-ai-butler'
					) }
				</p>
			) }
		</section>
	);
}
