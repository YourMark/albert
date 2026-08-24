/**
 * Skill detail fly-in (right-docked drawer).
 *
 * Read-only, on purpose. See docs/features/23-skills.md. Built on the shared
 * FlyInShell (the same dialog Abilities opens), with content specific to a
 * skill: a locked notice, the enabled toggle, the summary, and the guide's
 * full Markdown body, shown as-is rather than rendered, the acceptance
 * criterion is that this text is byte-identical to what `albert/get-skill`
 * returns.
 *
 * The locked notice comes first, before the toggle: it answers "can I edit
 * this" (no), the question a reader actually has, before they meet a control
 * that answers a different one ("does this currently apply here"). Info tone,
 * not warning: nothing is at risk and there is no decision to make, it is a
 * fact about how the product works right now, not a caution.
 */
import { Icon, Button, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { lock } from '@wordpress/icons';
import FlyInShell from '../shared/FlyInShell';

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
				<Icon icon={ lock } size={ 18 } />
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

			<div className="albert-flyin__enabled">
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Enabled', 'albert-ai-butler' ) }
					help={ skill.status }
					checked={ skill.available }
					disabled
				/>
			</div>

			{ skill.summary && (
				<section className="albert-flyin__section">
					<h3 className="albert-flyin__section-title">
						{ __( 'Description', 'albert-ai-butler' ) }
					</h3>
					<p className="albert-skill-flyin__summary">
						{ skill.summary }
					</p>
				</section>
			) }

			<section className="albert-flyin__section">
				<h3 className="albert-flyin__section-title">
					{ __( 'Guide text', 'albert-ai-butler' ) }
				</h3>
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
			</section>
		</FlyInShell>
	);
}
