/**
 * Right-docked detail fly-in: the dialog shell shared by every Albert screen
 * that opens one (Abilities today, Skills now).
 *
 * @wordpress/components has no drawer, so this is a custom dialog: a backdrop
 * plus a right-docked panel with slide/fade animation (gated behind
 * prefers-reduced-motion in CSS). Accessibility uses @wordpress/compose:
 * constrained tabbing (focus trap), focus-on-mount, and focus-return; Escape
 * and a backdrop click both request close.
 *
 * This owns only the chrome: backdrop, dialog frame, header layout, close
 * button, scrollable body, footer. A screen's own content (what's in the
 * heading, what's in the body, what's in the footer) is composed by the
 * caller, so this file carries nothing specific to any one screen.
 */
import { Button } from '@wordpress/components';
import {
	useConstrainedTabbing,
	useFocusReturn,
	useMergeRefs,
} from '@wordpress/compose';
import { useCallback, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { close } from '@wordpress/icons';

/**
 * Render the fly-in.
 *
 * @param {Object}   props               Props.
 * @param {string}   props.titleId       Id of the element inside `heading` that names the dialog (aria-labelledby target).
 * @param {Element}  props.heading       Header content: title, id/category, badges, whatever this screen shows.
 * @param {Function} props.onRequestClose Called on Escape, a backdrop click, or the close button. The caller decides
 *                                        whether that actually closes (a screen may intercept it, e.g. to confirm
 *                                        discarding unsaved input) or calls its own close handler directly.
 * @param {Element}  props.children      Body content, inside the scrollable region.
 * @param {Element}  [props.footer]      Optional footer content.
 * @return {Element} The fly-in.
 */
export default function FlyInShell( {
	titleId,
	heading,
	onRequestClose,
	children,
	footer,
} ) {
	const panelRef = useRef( null );
	const dialogRef = useMergeRefs( [
		useConstrainedTabbing(),
		useFocusReturn(),
		panelRef,
	] );

	// Focus the dialog container on open so its accessible name
	// (aria-labelledby) is announced first, rather than landing on the
	// top-right Close button.
	useEffect( () => {
		panelRef.current?.focus();
	}, [] );

	const onKeyDown = useCallback(
		( event ) => {
			if ( event.key === 'Escape' ) {
				event.stopPropagation();
				onRequestClose();
			}
		},
		[ onRequestClose ]
	);

	return (
		<>
			{ /* eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events */ }
			<div
				className="albert-flyin__backdrop"
				role="presentation"
				onClick={ onRequestClose }
			/>
			<div
				className="albert-flyin"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
				tabIndex={ -1 }
				ref={ dialogRef }
				onKeyDown={ onKeyDown }
			>
				<header className="albert-flyin__header">
					<div className="albert-flyin__heading">{ heading }</div>
					<Button
						icon={ close }
						label={ __( 'Close', 'albert-ai-butler' ) }
						onClick={ onRequestClose }
						className="albert-flyin__close"
					/>
				</header>

				<div className="albert-flyin__body">{ children }</div>

				{ footer && (
					<footer className="albert-flyin__footer">
						{ footer }
					</footer>
				) }
			</div>
		</>
	);
}
