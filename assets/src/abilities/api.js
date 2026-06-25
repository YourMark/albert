/**
 * REST data layer for the Abilities screen.
 *
 * Thin wrappers over @wordpress/api-fetch. The REST root URL and wp_rest nonce
 * are wired automatically by WordPress when the wp-api-fetch handle is enqueued,
 * so no nonce plumbing is needed here.
 */
import apiFetch from '@wordpress/api-fetch';

// REST base injected by PHP (Plugin::rest_namespace()); fall back to the default
// namespace so the module still works if the inline script is ever absent.
const BASE = `/${ window.albertAbilities?.restBase || 'albert/v1/abilities' }`;

/**
 * Fetch the full abilities dataset (abilities, categories, suppliers, counts).
 *
 * @return {Promise<Object>} The payload.
 */
export function fetchAbilities() {
	return apiFetch( { path: BASE } );
}

/**
 * Toggle a single ability's enabled state.
 *
 * @param {string}  id      Ability id, e.g. 'albert/create-post'.
 * @param {boolean} enabled Desired enabled state.
 * @return {Promise<Object>} The updated ability row.
 */
export function setAbilityEnabled( id, enabled ) {
	return apiFetch( {
		path: `${ BASE }/${ id }`,
		method: 'POST',
		data: { enabled },
	} );
}

/**
 * Enable or disable many abilities in one request.
 *
 * @param {string[]} ids     Ability ids.
 * @param {boolean}  enabled Desired enabled state.
 * @return {Promise<Object>} { updated, enabled }.
 */
export function setAbilitiesEnabledBulk( ids, enabled ) {
	return apiFetch( {
		path: `${ BASE }/bulk`,
		method: 'POST',
		data: { ids, enabled },
	} );
}
