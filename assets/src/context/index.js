/**
 * Albert: Context admin app entry.
 *
 * Mounts the React app into the admin page root. No CSS is imported here,
 * styles are authored as plain CSS in assets/css/admin-context.css and enqueued
 * separately, on top of the shared primitives.
 */
import { createRoot } from '@wordpress/element';
import ContextApp from './ContextApp';

const ROOT_ID = 'albert-context-root';

function mount() {
	const node = document.getElementById( ROOT_ID );
	if ( ! node ) {
		return;
	}
	createRoot( node ).render( <ContextApp /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
