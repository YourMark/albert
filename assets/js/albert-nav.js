/**
 * Albert page navigation.
 *
 * One job: keep the current page's entry visible.
 *
 * The strip is a horizontally scrollable list (`.albert-nav__list` is
 * `overflow-x: auto`), so on a narrow viewport the later entries sit outside
 * the scroll port. Settings is the last entry, which means the person most
 * likely to be looking for "where am I" — someone who just landed on the page
 * they are reading — is the one who cannot see it. The strip is reachable by
 * scrolling, but nothing tells you the current entry is over there.
 *
 * Scrolling it into view on load fixes that without changing the markup, the
 * tab order, or what a screen reader announces (`aria-current="page"` already
 * carries the state, and this only moves a scroll offset).
 *
 * @package Albert
 * @since   1.4.0
 */

( function () {
	'use strict';

	function revealCurrent() {
		const list = document.querySelector( '.albert-nav__list' );

		if ( ! list ) {
			return;
		}

		const current = list.querySelector( '[aria-current="page"]' );

		if ( ! current ) {
			return;
		}

		// Nothing to do when the strip is not scrolling, which is the common
		// case on a desktop viewport. Guarding here keeps this a no-op rather
		// than a scroll nudge on every Albert page load.
		if ( list.scrollWidth <= list.clientWidth ) {
			return;
		}

		const listBox = list.getBoundingClientRect();
		const itemBox = current.getBoundingClientRect();

		if ( itemBox.left >= listBox.left && itemBox.right <= listBox.right ) {
			return;
		}

		// `inline: 'nearest'` scrolls the minimum distance needed rather than
		// centring, so the entries either side stay visible and the strip still
		// reads as a list you are inside of.
		//
		// `block: 'nearest'` matters more than it looks: the default is
		// 'start', which would scroll the *page* to put the nav at the top of
		// the viewport, undoing the reader's position for the sake of a
		// horizontal nudge.
		current.scrollIntoView( {
			inline: 'nearest',
			block: 'nearest',
			behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
				? 'auto'
				: 'smooth',
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', revealCurrent );
	} else {
		revealCurrent();
	}
} )();
