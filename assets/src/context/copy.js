/**
 * Shared copy for the Context screen.
 *
 * The token sentence lives here rather than beside a component because more than
 * one place has to say the same thing about the same number, and two hand-edited
 * versions of an explanation drift the same way two estimators did.
 */
import { __ } from '@wordpress/i18n';

/**
 * What a token is, and that the number is an estimate.
 *
 * States the measured error band, because "an estimate" on its own invites the
 * reader to assume it is a good one. See docs/context-token-budget.md.
 *
 * @type {string}
 */
export const TOKEN_EXPLANATION = __(
	'Assistants read text in chunks called tokens, roughly a short word each. These numbers are an estimate, not a count. Measured against a real tokeniser they land within about a third either way, and they run high more often than low.',
	'albert-ai-butler'
);
