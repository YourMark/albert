/**
 * Ability detail fly-in (right-docked drawer).
 *
 * @wordpress/components has no drawer, so this is a custom dialog: a backdrop +
 * a right-docked panel with slide/fade animation (gated behind
 * prefers-reduced-motion in CSS). Accessibility uses @wordpress/compose:
 * constrained tabbing (focus trap), focus-on-mount, and focus-return; Escape
 * closes. The enabled toggle saves instantly (same handler as the row toggle).
 *
 * Add-ons (e.g. Albert Premium) inject sections via the
 * `albert.abilities.panel_sections` filter — see the [Premium seam] below.
 */
import { Button, Dropdown, ToggleControl } from '@wordpress/components';
import {
	useConstrainedTabbing,
	useFocusOnMount,
	useFocusReturn,
	useMergeRefs,
} from '@wordpress/compose';
import { Component } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { close, info } from '@wordpress/icons';

const OPERATION_LABELS = {
	read: __( 'Read', 'albert-ai-butler' ),
	write: __( 'Write', 'albert-ai-butler' ),
	delete: __( 'Delete', 'albert-ai-butler' ),
};

/**
 * An info "(i)" button that opens a small explanatory popover.
 *
 * Rendered inline (popoverProps.inline) so it lives inside the fly-in's focus
 * trap; Dropdown handles open state, Escape, outside-click, and focus.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.label    Accessible button label.
 * @param {Element} props.children Popover content.
 * @return {Element} The info control.
 */
function InfoPopover( { label, children } ) {
	return (
		<Dropdown
			className="albert-info"
			focusOnMount={ true }
			popoverProps={ { placement: 'bottom-end', inline: true } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					size="small"
					icon={ info }
					label={ label }
					onClick={ onToggle }
					aria-expanded={ isOpen }
				/>
			) }
			renderContent={ () => (
				<div className="albert-info__content">{ children }</div>
			) }
		/>
	);
}

/**
 * Error boundary around a single add-on section.
 *
 * A third-party section injected via the `albert.abilities.panel_sections` filter
 * is untrusted code — if its render throws, this catches it so the rest of the
 * fly-in keeps working instead of blanking out.
 */
class SectionBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false };
	}

	static getDerivedStateFromError() {
		return { hasError: true };
	}

	render() {
		if ( this.state.hasError ) {
			return (
				<div className="albert-flyin__section-error notice notice-warning">
					<p>
						{ __(
							'An add-on section failed to load.',
							'albert-ai-butler'
						) }
					</p>
				</div>
			);
		}
		return this.props.children;
	}
}

/**
 * A labelled section with an uppercase heading and optional info popover.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.title    Section heading.
 * @param {Element} props.info     Optional info control shown next to the title.
 * @param {Element} props.children Section body.
 * @return {Element} The section.
 */
function Section( { title, info: infoControl, children } ) {
	return (
		<section className="albert-flyin__section">
			<div className="albert-flyin__section-head">
				<h3 className="albert-flyin__section-title">{ title }</h3>
				{ infoControl }
			</div>
			{ children }
		</section>
	);
}

/**
 * The detail fly-in for one ability.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.ability  The ability row.
 * @param {Array}    props.roles    Role options ({ value, label }) for add-on sections.
 * @param {Function} props.onClose  Close handler.
 * @param {Function} props.onToggle Enabled toggle handler (item, nextEnabled).
 * @return {Element} The fly-in.
 */
export default function FlyInPanel( { ability, roles, onClose, onToggle } ) {
	const dialogRef = useMergeRefs( [
		useConstrainedTabbing(),
		useFocusOnMount( 'firstElement' ),
		useFocusReturn(),
	] );

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			onClose();
		}
	};

	/**
	 * [Premium seam] Sections injected by add-ons (e.g. the advanced permission
	 * manager in Albert Premium). Free renders nothing here. Each entry is
	 * { id, priority?, render: ({ ability, api }) => Element }.
	 */
	const api = { ability, roles };
	const sections = applyFilters(
		'albert.abilities.panel_sections',
		[],
		ability,
		api
	).slice().sort( ( a, b ) => ( a.priority ?? 10 ) - ( b.priority ?? 10 ) );

	return (
		<>
			{ /* eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events */ }
			<div
				className="albert-flyin__backdrop"
				role="presentation"
				onClick={ onClose }
			/>
			<div
				className="albert-flyin"
				role="dialog"
				aria-modal="true"
				aria-labelledby="albert-flyin-title"
				ref={ dialogRef }
				onKeyDown={ onKeyDown }
			>
				<header className="albert-flyin__header">
					<div className="albert-flyin__heading">
						<span
							className={ `albert-op-badge albert-op-badge--${ ability.operation }` }
						>
							<span
								className="albert-op-badge__dot"
								aria-hidden="true"
							/>
							{ OPERATION_LABELS[ ability.operation ] ||
								ability.operation }
						</span>
						<h2
							id="albert-flyin-title"
							className="albert-flyin__title"
						>
							{ ability.label }
						</h2>
						<code className="albert-flyin__id">{ ability.id }</code>
					</div>
					<Button
						icon={ close }
						label={ __( 'Close', 'albert-ai-butler' ) }
						onClick={ onClose }
						className="albert-flyin__close"
					/>
				</header>

				<div className="albert-flyin__body">
					<div className="albert-flyin__enabled">
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Enabled', 'albert-ai-butler' ) }
							help={ __(
								'Allow apps and agents to invoke this ability.',
								'albert-ai-butler'
							) }
							checked={ ability.enabled }
							onChange={ ( value ) => onToggle( ability, value ) }
						/>
					</div>

					<Section title={ __( 'Description', 'albert-ai-butler' ) }>
						<p className="albert-flyin__description">
							{ ability.description }
						</p>
					</Section>

					<div className="albert-flyin__cards">
						<div className="albert-flyin__card">
							<span className="albert-flyin__card-label">
								{ __( 'Category', 'albert-ai-butler' ) }
							</span>
							<span className="albert-flyin__card-value">
								{ ability.categoryLabel }
							</span>
						</div>
						<div className="albert-flyin__card">
							<div className="albert-flyin__card-head">
								<span className="albert-flyin__card-label">
									{ __(
										'Required capability',
										'albert-ai-butler'
									) }
								</span>
								<InfoPopover
									label={ __(
										'About required capability',
										'albert-ai-butler'
									) }
								>
									<p>
										{ __(
											'A WordPress capability that gates this ability — it can run only for users whose role grants this capability. This value is best-effort; an ability or add-on can override it.',
											'albert-ai-butler'
										) }
									</p>
									{ ability.capabilityRoles?.length > 0 ? (
										<p>
											<strong>
												{ __(
													'Roles with this capability:',
													'albert-ai-butler'
												) }
											</strong>{ ' ' }
											{ ability.capabilityRoles.join(
												', '
											) }
										</p>
									) : (
										<p>
											{ __(
												'No roles currently grant this capability.',
												'albert-ai-butler'
											) }
										</p>
									) }
								</InfoPopover>
							</div>
							<code className="albert-flyin__card-value">
								{ ability.capability }
							</code>
						</div>
					</div>

					{ sections.map( ( section ) => (
						<SectionBoundary key={ section.id }>
							{ section.render( { ability, api } ) }
						</SectionBoundary>
					) ) }

					{ ability.inputs.length > 0 && (
						<Section
							title={ __( 'Input schema', 'albert-ai-butler' ) }
							info={
								<InfoPopover
									label={ __(
										'About the input schema',
										'albert-ai-butler'
									) }
								>
									<p>
										{ __(
											'The parameters this ability accepts. An AI assistant supplies these when it calls the ability — fields marked * are required, the rest are optional.',
											'albert-ai-butler'
										) }
									</p>
								</InfoPopover>
							}
						>
							<ul className="albert-flyin__schema">
								{ ability.inputs.map( ( field ) => (
									<li
										key={ field.name }
										className="albert-flyin__field"
									>
										<div className="albert-flyin__field-head">
											<code className="albert-flyin__field-name">
												{ field.name }
											</code>
											{ field.required && (
												<span
													className="albert-flyin__field-required"
													aria-label={ __(
														'required',
														'albert-ai-butler'
													) }
												>
													*
												</span>
											) }
											<span className="albert-flyin__field-type">
												{ field.type }
											</span>
										</div>
										{ field.description && (
											<p className="albert-flyin__field-desc">
												{ field.description }
											</p>
										) }
									</li>
								) ) }
							</ul>
						</Section>
					) }

					{ ability.output && (
						<Section title={ __( 'Returns', 'albert-ai-butler' ) }>
							<p className="albert-flyin__returns">
								{ ability.output }
							</p>
						</Section>
					) }
				</div>

				<footer className="albert-flyin__footer">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Close', 'albert-ai-butler' ) }
					</Button>
				</footer>
			</div>
		</>
	);
}
