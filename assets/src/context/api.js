/**
 * REST data layer for the Context screen.
 *
 * Thin wrappers over @wordpress/api-fetch. The REST root URL and wp_rest nonce
 * are wired automatically by WordPress when the wp-api-fetch handle is enqueued,
 * so no nonce plumbing is needed here.
 *
 * Every save returns the full recomputed state: budget, costs and preview, so
 * the screen never has to follow a write with a read to stay truthful about what
 * the assistant will receive.
 */
import apiFetch from '@wordpress/api-fetch';

// REST base injected by PHP (Plugin::rest_namespace()); fall back to the default
// namespace so the module still works if the inline script is ever absent.
const BASE = `/${ window.albertContext?.restBase || 'albert/v1/context' }`;

/**
 * Fetch the full Context screen state.
 *
 * @return {Promise<Object>} The payload.
 */
export function fetchContext() {
	return apiFetch( { path: BASE } );
}

/**
 * Save a partial change and get the recomputed state back.
 *
 * Only send what changed: the screen saves one control at a time, so a request
 * carrying the whole state would let a stale tab overwrite a change made in
 * another one.
 *
 * @param {Object} changes Any of `enabled`, `instructions`, `sections`.
 * @return {Promise<Object>} The recomputed payload.
 */
export function saveContext( changes ) {
	return apiFetch( {
		path: BASE,
		method: 'POST',
		data: changes,
	} );
}
