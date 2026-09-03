/**
 * Swap a DataViews screen between the table and list layouts by viewport width.
 *
 * A DataViews table cannot shrink past its own header text, so every Albert
 * table bottoms out somewhere near 980px however its columns are sized. Below
 * that the only remaining behaviour is a sideways scrollbar, and whatever sits
 * at the right-hand end goes off screen first — on Abilities that is the
 * Enabled toggle, the one control the screen exists for.
 *
 * The list layout stacks each row instead: title, then the same fields as a
 * meta line underneath. Nothing is dropped and nothing scrolls.
 *
 * Shared by Abilities and Skills so the two screens behave identically. A
 * layout the reader picks by hand wins permanently, otherwise choosing Table on
 * a narrow screen would be undone by the next resize.
 */
import { useCallback, useEffect, useRef } from '@wordpress/element';

/**
 * Below this width a DataViews table can no longer fit its own columns.
 *
 * @type {string}
 */
export const NARROW = '(max-width: 1200px)';

/**
 * @param {Object}   view    The current DataViews view.
 * @param {Function} setView The view state setter.
 * @return {Function} An onChangeView handler to pass to DataViews.
 */
export default function useNarrowLayout( view, setView ) {
	const pinned = useRef( false );

	const onChangeView = useCallback(
		( next ) => {
			if ( next.type !== view.type ) {
				pinned.current = true;
			}
			setView( next );
		},
		[ view.type, setView ]
	);

	useEffect( () => {
		const query = window.matchMedia( NARROW );
		const apply = () => {
			if ( pinned.current ) {
				return;
			}
			const wanted = query.matches ? 'list' : 'table';
			setView( ( prev ) =>
				prev.type === wanted ? prev : { ...prev, type: wanted }
			);
		};
		apply();
		query.addEventListener( 'change', apply );
		return () => query.removeEventListener( 'change', apply );
	}, [ setView ] );

	return onChangeView;
}
