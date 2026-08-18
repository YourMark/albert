/**
 * Site instructions card: the owner's own voice.
 *
 * The only editable content on the screen, and the only part of the payload
 * Albert cannot derive from the site itself.
 *
 * Typing is local state saved on a debounce; the token count under the field
 * follows the keystrokes rather than the save, because a counter that only moved
 * when a request came back would lag exactly when it is being watched.
 *
 * The field starts empty, with an example in the placeholder. An earlier version
 * wrote a generated draft into it and saved that draft on first visit, which was
 * wrong twice over: it stored words the owner had not written, and the draft was
 * only ever a restatement of facts Albert already sends, so it taught the wrong
 * thing about what this field is for. Generating a genuinely useful draft is
 * worth doing later, but it would have to infer voice from published posts
 * rather than restate facts the payload already carries.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { estimateTokens } from './tokens';

// Long enough that ordinary typing does not queue a request per word, short
// enough that pausing to think produces a save before you look away.
const SAVE_DELAY = 900;

/**
 * Price what is in the field the same way the server prices what it stores.
 *
 * Two things have to match, and both got this wrong before. The *estimator* is
 * now the shared port in `tokens.js` rather than a rougher second opinion, the
 * two disagreed by 10% on the same sentence. And the *scope* includes the
 * heading Albert wraps the instructions in, because that heading exists only if
 * you write something, so it is part of what having instructions costs. Counting
 * it on the server and not in the field is why the card said 51 and the field
 * said 30.
 *
 * @param {string} text    The instructions as typed.
 * @param {string} heading The heading the payload wraps them in.
 * @return {number} Estimated tokens.
 */
function priceInstructions( text, heading ) {
	const trimmed = text.trim();

	if ( ! trimmed ) {
		return 0;
	}

	return estimateTokens( `${ heading }\n${ trimmed }` );
}

/**
 * Render the instructions card.
 *
 * @param {Object}   props              Props.
 * @param {string}   props.value        Stored instructions.
 * @param {boolean}  props.managed      Whether a filter owns this value.
 * @param {number}   props.tokens       Server-side estimate of the stored value.
 * @param {string}   props.heading      The heading the payload wraps instructions in.
 * @param {boolean}  props.off          Whether context is switched off entirely.
 * @param {Function} props.onChange     Called with the new text, debounced.
 * @return {Element} The card.
 */
export default function InstructionsCard( {
	value,
	managed,
	tokens,
	heading,
	off,
	onChange,
} ) {
	const [ draft, setDraft ] = useState( value );
	const timer = useRef( null );
	const lastSent = useRef( value );

	// Follow the server when it disagrees: a save elsewhere, or the first load
	// finishing, but never while the owner is mid-edit of a value we have not
	// sent yet.
	useEffect( () => {
		if ( value !== lastSent.current ) {
			setDraft( value );
			lastSent.current = value;
		}
	}, [ value ] );

	useEffect( () => () => clearTimeout( timer.current ), [] );

	// The marker advances only once the write has actually landed. Advancing it
	// when the request was fired meant a failed save left the typing unsent
	// while the screen reported "Saved", and the retry sent an empty change that
	// succeeded, confirming a save that had never happened.
	//
	// Leaving it behind on failure is also what protects the draft: the sync
	// effect above only overwrites the field when the server's value differs
	// from the marker, and after a failure they still agree.
	const update = ( next ) => {
		setDraft( next );
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			Promise.resolve( onChange( next ) ).then( ( saved ) => {
				if ( saved ) {
					lastSent.current = next;
				}
			} );
		}, SAVE_DELAY );
	};

	// While a filter owns the value the field shows what will actually be sent
	// and refuses edits, because a control that accepts typing and changes
	// nothing is worse than one that is plainly not yours to change.
	const liveTokens =
		draft === lastSent.current
			? tokens
			: priceInstructions( draft, heading );

	return (
		<section className="albert-card">
			<header className="albert-card__header">
				<div className="albert-card__text">
					<h2 className="albert-card__title">
						{ __( 'Site instructions', 'albert-ai-butler' ) }
					</h2>
					<p className="albert-card__description">
						{ __(
							'Your voice and your boundaries: language, tone, words you do and do not use, and anything an assistant must never touch.',
							'albert-ai-butler'
						) }
					</p>
				</div>
			</header>

			{ managed && (
				<p
					className="albert-hint albert-hint--info albert-context__managed"
					id="albert-context-instructions-managed"
				>
					{ __(
						'These instructions are set in code on this site, so they cannot be edited here. Below is what is being sent.',
						'albert-ai-butler'
					) }
				</p>
			) }

			<div className="albert-card__body">
				<label
					className="screen-reader-text"
					htmlFor="albert-context-instructions"
				>
					{ __( 'Site instructions', 'albert-ai-butler' ) }
				</label>
				{ /*
				 * Described by the help text below and, when it applies, by the
				 * reason the field is not editable. Both are genuinely
				 * instructional, and jumping straight to the field by form-control
				 * navigation skipped both.
				 */ }
				<textarea
					id="albert-context-instructions"
					className="albert-context__textarea"
					rows="8"
					value={ draft }
					readOnly={ managed || off }
					aria-describedby={ [
						'albert-context-instructions-help',
						managed
							? 'albert-context-instructions-managed'
							: null,
					]
						.filter( Boolean )
						.join( ' ' ) }
					onChange={ ( event ) => update( event.target.value ) }
					placeholder={ __(
						'For example: We are a small furniture workshop in Utrecht. Write in Dutch, informally, in short sentences. Call our products meubels, never furniture. Prices are in euros. Leave new pages as drafts for review, and never change the header or footer.',
						'albert-ai-butler'
					) }
				/>
				<div className="albert-context__field-foot">
					<span
						className="albert-context__field-help"
						id="albert-context-instructions-help"
					>
						{ __(
							'Plain sentences work best. Assistants read this as background, not as commands.',
							'albert-ai-butler'
						) }
					</span>
					<span className="albert-context__field-count">
						{ sprintf(
							/* translators: %d: estimated token cost of the instructions. */
							__( '≈%d tokens', 'albert-ai-butler' ),
							liveTokens
						) }
					</span>
				</div>
			</div>
		</section>
	);
}
