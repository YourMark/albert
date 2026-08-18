/**
 * Shared copy for the Context screen.
 *
 * The token sentence lives here rather than beside a component because more than
 * one place has to say the same thing about the same number, and two hand-edited
 * versions of an explanation drift the same way two estimators did.
 */
import { __ } from '@wordpress/i18n';

/**
 * What a token is.
 *
 * Safe to leave behind the info control, because the sentence around it reads
 * correctly without it: someone who never opens this still knows the number
 * measures how much space the context takes.
 *
 * @type {string}
 */
export const TOKEN_EXPLANATION = __(
	'Assistants read text in chunks called tokens, roughly a short word each.',
	'albert-ai-butler'
);

/**
 * That every number on this screen is an estimate, and how far out it can be.
 *
 * Visible rather than behind the info control, which is where it used to live.
 * It is the only statement anywhere that these figures are approximate, and the
 * `≈` in front of each one does not carry that on its own: screen readers
 * commonly skip the character, so the number is announced as a flat count.
 * States the measured error band, because "an estimate" on its own invites the
 * reader to assume it is a good one. See docs/context-token-budget.md.
 *
 * @type {string}
 */
export const TOKEN_ESTIMATE_NOTE = __(
	'These numbers are an estimate, not a count. Measured against a real tokeniser they land within about a third either way, and they run high more often than low.',
	'albert-ai-butler'
);
