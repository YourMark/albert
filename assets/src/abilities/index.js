/**
 * Albert — Abilities (DataViews) admin app entry.
 *
 * Phase 2: fetches the live abilities dataset over the REST API and renders a
 * temporary summary to prove the round-trip. The real <DataViews> UI replaces
 * this in Phase 3.
 *
 * No CSS is imported here — styles are authored as plain CSS in
 * assets/css/admin-abilities.css and enqueued separately.
 */
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { fetchAbilities } from './api';

const ROOT_ID = 'albert-abilities-root';

function App() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		fetchAbilities()
			.then( setData )
			.catch( ( err ) => setError( err.message || String( err ) ) );
	}, [] );

	if ( error ) {
		return (
			<div className="albert-abilities-app__placeholder">{ error }</div>
		);
	}

	if ( ! data ) {
		return (
			<div className="albert-abilities-app__placeholder">
				{ __( 'Loading abilities…', 'albert-ai-butler' ) }
			</div>
		);
	}

	return (
		<div className="albert-abilities-app__placeholder">
			<p>
				{ sprintf(
					/* translators: 1: total abilities, 2: enabled count. */
					__( '%1$d abilities · %2$d enabled', 'albert-ai-butler' ),
					data.counts.total,
					data.counts.enabled
				) }
			</p>
			<ul>
				{ data.abilities.map( ( ability ) => (
					<li key={ ability.id }>
						{ ability.label } — <code>{ ability.id }</code> (
						{ ability.operation }){ ability.enabled ? ' ✓' : '' }
					</li>
				) ) }
			</ul>
		</div>
	);
}

function mount() {
	const node = document.getElementById( ROOT_ID );
	if ( ! node ) {
		return;
	}
	createRoot( node ).render( <App /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
