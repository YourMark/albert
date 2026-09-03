/**
 * Mount a React admin app into its root element.
 *
 * Every screen entry does the same three things: find its root element, bail
 * quietly if it isn't there (a different admin screen), and render once the
 * DOM is ready rather than assuming it already is.
 *
 * @param {string}  rootId  Id of the element to mount into.
 * @param {Element} element The app's root element, e.g. `<SkillsApp />`.
 */
import { createRoot } from '@wordpress/element';

export function mountApp( rootId, element ) {
	function mount() {
		const node = document.getElementById( rootId );
		if ( ! node ) {
			return;
		}
		createRoot( node ).render( element );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
}
