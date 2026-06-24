/**
 * Albert — Abilities (DataViews) admin app entry.
 *
 * Mounts the React app into the admin page root. No CSS is imported here —
 * styles are authored as plain CSS in assets/css/admin-abilities.css and the
 * DataViews/component stylesheets are enqueued separately.
 */
import { createRoot } from '@wordpress/element';
import AbilitiesApp from './AbilitiesApp';

const ROOT_ID = 'albert-abilities-root';

function mount() {
	const node = document.getElementById( ROOT_ID );
	if ( ! node ) {
		return;
	}
	createRoot( node ).render( <AbilitiesApp /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
