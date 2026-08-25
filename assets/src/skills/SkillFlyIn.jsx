/**
 * Skill detail fly-in (right-docked drawer).
 *
 * Read-only, on purpose. See docs/features/23-skills.md. Built on the shared
 * FlyInShell (the same dialog Abilities opens), with content specific to a
 * skill: a locked notice, the summary, and the guide's full Markdown body,
 * shown as-is rather than rendered, the acceptance criterion is that this
 * text is byte-identical to what `albert/get-skill` returns.
 *
 * No status display here yet either: whether a skill's precondition
 * currently holds is real, computed data (skill.available/skill.status), but
 * 1.4.0 only lists skills Albert itself ships, so there's nothing yet to
 * distinguish one from another. That changes once doc 24's non-Albert
 * sources exist.
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import FlyInShell, { Section } from '../shared/FlyInShell';

/**
 * Render the fly-in.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.skill   The skill row ({ slug, summary, source, available, status, body }).
 * @param {Function} props.onClose Close handler.
 * @return {Element} The fly-in.
 */
export default function SkillFlyIn( { skill, onClose } ) {
	const heading = (
		<>
			<h2 id="albert-skill-flyin-title" className="albert-flyin__title">
				{ skill.slug }
			</h2>
			<span className="albert-flyin__category">
				<span className="albert-flyin__category-label">
					{ __( 'Source', 'albert-ai-butler' ) }
				</span>
				<span className="albert-flyin__category-value">
					{ skill.source }
				</span>
			</span>
		</>
	);

	const footer = (
		<>
			<span className="albert-skill-flyin__hint">
				{ __(
					"Editing isn't available for this guide.",
					'albert-ai-butler'
				) }
			</span>
			<Button variant="tertiary" onClick={ onClose }>
				{ __( 'Close', 'albert-ai-butler' ) }
			</Button>
		</>
	);

	return (
		<FlyInShell
			titleId="albert-skill-flyin-title"
			heading={ heading }
			onRequestClose={ onClose }
			footer={ footer }
		>
			<div className="albert-hint albert-hint--info">
				<span className="dashicons dashicons-lock" aria-hidden="true" />
				<div>
					<p>
						<strong>
							{ __(
								"This guide can't be edited yet",
								'albert-ai-butler'
							) }
						</strong>
					</p>
					<p>
						{ __(
							'Shipped by its source and kept up to date automatically. Built-in guides like this one stay read-only.',
							'albert-ai-butler'
						) }
					</p>
				</div>
			</div>

			{ skill.summary && (
				<Section title={ __( 'Description', 'albert-ai-butler' ) }>
					<p className="albert-skill-flyin__summary">
						{ skill.summary }
					</p>
				</Section>
			) }

			<Section title={ __( 'Guide text', 'albert-ai-butler' ) }>
				{ skill.body ? (
					<pre className="albert-skill-flyin__body">
						{ skill.body }
					</pre>
				) : (
					<p className="albert-hint albert-hint--info">
						{ __(
							'This skill is registered but its text could not be read.',
							'albert-ai-butler'
						) }
					</p>
				) }
			</Section>
		</FlyInShell>
	);
}
