/**
 * Albert — Abilities (DataViews) admin app entry.
 *
 * Phase 1 scaffolding: mounts a placeholder into the React root so we can verify
 * the wp-scripts build, asset externalization, and enqueue wiring end-to-end
 * before any DataViews UI exists. The real <AbilitiesApp /> lands in Phase 3.
 *
 * No CSS is imported here — styles are authored as plain CSS in
 * assets/css/admin-abilities.css and enqueued separately.
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const ROOT_ID = 'albert-abilities-root';

function Placeholder() {
	return (
		<div className="albert-abilities-app__placeholder">
			{ __( 'Loading abilities…', 'albert-ai-butler' ) }
		</div>
	);
}

function mount() {
	const node = document.getElementById( ROOT_ID );
	if ( ! node ) {
		return;
	}
	createRoot( node ).render( <Placeholder /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
