/**
 * REST data layer for the Skills screen.
 *
 * A thin wrapper over @wordpress/api-fetch. The REST root URL and wp_rest nonce
 * are wired automatically by WordPress when the wp-api-fetch handle is enqueued,
 * so no nonce plumbing is needed here. There is only a read: the screen is
 * view-only and writes nothing.
 */
import apiFetch from '@wordpress/api-fetch';

// REST base injected by PHP (Plugin::rest_namespace()); fall back to the default
// namespace so the module still works if the inline script is ever absent.
const BASE = `/${ window.albertSkills?.restBase || 'albert/v1/skills' }`;

/**
 * Fetch every registered skill.
 *
 * @return {Promise<Object>} `{ skills: [...] }`.
 */
export function fetchSkills() {
	return apiFetch( { path: BASE } );
}
