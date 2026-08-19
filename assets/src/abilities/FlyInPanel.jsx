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
import {
	Button,
	Icon,
	Modal,
	ToggleControl,
} from '@wordpress/components';
import {
	useConstrainedTabbing,
	useFocusReturn,
	useMergeRefs,
} from '@wordpress/compose';
import {
	Component,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __, _n, sprintf } from '@wordpress/i18n';
import { chevronRight, close } from '@wordpress/icons';
import InfoPopover from '../shared/InfoPopover';
import { OPERATION_LABELS } from './constants';
import { Badges } from './fields';
import AdvancedPermissionsUpsell from './AdvancedPermissionsUpsell';

/**
 * Invokes one add-on section's render factory.
 *
 * This is a child of SectionBoundary on purpose: a synchronous throw in
 * `section.render()` happens during *this* component's render, so the boundary
 * above it can catch it. (An error boundary cannot catch a throw in its own
 * render method, only in its children — so the factory must be called here, not
 * inside SectionBoundary or in the parent's map callback.)
 *
 * @param {Object} props         Props.
 * @param {Object} props.section The section ({ id, render }).
 * @param {Object} props.ability The ability row.
 * @param {Object} props.api     The seam api ({ ability, roles }).
 * @return {Element} The section's rendered output.
 */
function SectionRenderer( { section, ability, api } ) {
	return section.render( { ability, api } );
}

/**
 * Error boundary around a single add-on section.
 *
 * A third-party section injected via the `albert.abilities.panel_sections` filter
 * is untrusted code — if its render throws, this catches it (via the
 * SectionRenderer child) so the rest of the fly-in keeps working instead of
 * blanking out.
 */
class SectionBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false };
	}

	static getDerivedStateFromError() {
		return { hasError: true };
	}

	componentDidCatch( error, info ) {
		// Surface third-party section failures; the fallback UI is otherwise silent.
		// eslint-disable-next-line no-console
		console.error( '[Albert] An abilities panel section threw during render:', error, info );
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
 * A collapsible accordion card — an accessible disclosure (collapsed by default)
 * whose heading shows a short summary so the gist is visible without expanding.
 * Used for the parameters list, which is reference detail.
 *
 * @param {Object}  props             Props.
 * @param {string}  props.id          Id for the disclosure region (aria-controls target).
 * @param {string}  props.title       Section heading.
 * @param {string}  [props.summary]   Short summary shown beside the heading.
 * @param {boolean} [props.defaultOpen] Whether it starts expanded (default false).
 * @param {Element} props.children    Section body.
 * @return {Element} The section.
 */
function CollapsibleSection( {
	id,
	title,
	summary,
	defaultOpen = false,
	children,
} ) {
	const [ open, setOpen ] = useState( defaultOpen );
	return (
		<section className="albert-flyin__accordion">
			<h3 className="albert-flyin__accordion-heading">
				<button
					type="button"
					className="albert-flyin__accordion-toggle"
					aria-expanded={ open }
					aria-controls={ id }
					onClick={ () => setOpen( ( value ) => ! value ) }
				>
					<Icon
						className="albert-flyin__accordion-chevron"
						icon={ chevronRight }
						size={ 18 }
					/>
					<span className="albert-flyin__accordion-title">
						{ title }
					</span>
					{ summary && (
						<span className="albert-flyin__accordion-summary">
							{ summary }
						</span>
					) }
				</button>
			</h3>
			<div
				id={ id }
				className="albert-flyin__accordion-panel"
				hidden={ ! open }
			>
				{ children }
			</div>
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
	const panelRef = useRef( null );
	const dialogRef = useMergeRefs( [
		useConstrainedTabbing(),
		useFocusReturn(),
		panelRef,
	] );

	// Focus the dialog container on open so its accessible name (aria-labelledby)
	// is announced first, rather than landing on the top-right Close button.
	useEffect( () => {
		panelRef.current?.focus();
	}, [] );

	// An add-on (e.g. Premium) can register a close guard via the seam api. On a
	// close attempt the guard may return a confirmation descriptor; if so we show
	// a styled dialog instead of closing immediately.
	const closeGuardRef = useRef( null );
	const [ closePrompt, setClosePrompt ] = useState( null );

	// Panel-level save status, reported by an add-on via api.setSaveStatus and
	// shown (as a live region) in the footer — not inside the add-on's section.
	const [ saveStatus, setSaveStatus ] = useState( null );
	const reportSaveStatus = useCallback( ( status ) => {
		setSaveStatus( status );
	}, [] );

	const registerCloseGuard = useCallback( ( fn ) => {
		closeGuardRef.current = fn;
		return () => {
			if ( closeGuardRef.current === fn ) {
				closeGuardRef.current = null;
			}
		};
	}, [] );

	const requestClose = useCallback( () => {
		const prompt = closeGuardRef.current?.();
		if ( prompt ) {
			setClosePrompt( prompt );
			return;
		}
		onClose();
	}, [ onClose ] );

	const onKeyDown = useCallback(
		( event ) => {
			if ( event.key === 'Escape' ) {
				event.stopPropagation();
				requestClose();
			}
		},
		[ requestClose ]
	);

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

	// The required-capability display. Reused by Free's default Permissions
	// section and — when an add-on takes over — inside its UI (see the seam
	// below). Kept as one node so the capability is rendered identically either
	// way.
	const capabilityContent = (
		<div className="albert-flyin__card albert-flyin__card--capability">
			<div className="albert-flyin__card-head">
				<span className="albert-flyin__card-label">
					{ __( 'Required capability', 'albert-ai-butler' ) }
				</span>
				<InfoPopover
					inline
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
							{ ability.capabilityRoles.join( ', ' ) }
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
	);

	/**
	 * [Premium seam] Replace the whole Permissions section. Free's default shows
	 * the required capability; an add-on (Albert Premium) returns its own UI and
	 * reuses `api.capabilityContent` inside its "Capability only" tab. Returning
	 * the unchanged default keeps Free's behaviour when no add-on is present.
	 */
	const permissionsApi = {
		ability,
		roles,
		capabilityContent,
		registerCloseGuard,
		setSaveStatus: reportSaveStatus,
	};
	const permissionsSection = applyFilters(
		'albert.abilities.permissions_section',
		<Section
			title={ __( 'Permissions', 'albert-ai-butler' ) }
			info={
				<InfoPopover
					inline
					label={ __( 'About permissions', 'albert-ai-butler' ) }
				>
					<p>
						{ __(
							'Controls who can invoke this ability over MCP. By default it follows the required WordPress capability.',
							'albert-ai-butler'
						) }
					</p>
					<p>
						{ __(
							'Albert Premium adds custom per-role and per-user rules that override the capability check.',
							'albert-ai-butler'
						) }
					</p>
				</InfoPopover>
			}
		>
			<AdvancedPermissionsUpsell
				capabilityContent={ capabilityContent }
			/>
		</Section>,
		permissionsApi
	);

	// Summary shown on the collapsed input-schema header, e.g. "6 fields · 2 required".
	const inputCount = ability.inputs.length;
	const requiredCount = ability.inputs.filter(
		( field ) => field.required
	).length;
	const inputCountLabel = sprintf(
		// translators: %d: number of input fields.
		_n( '%d field', '%d fields', inputCount, 'albert-ai-butler' ),
		inputCount
	);
	const inputSummary =
		requiredCount > 0
			? sprintf(
					// translators: 1: field-count phrase (e.g. "6 fields"); 2: required count.
					__( '%1$s · %2$d required', 'albert-ai-butler' ),
					inputCountLabel,
					requiredCount
			  )
			: inputCountLabel;

	return (
		<>
			{ /* eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events */ }
			<div
				className="albert-flyin__backdrop"
				role="presentation"
				onClick={ requestClose }
			/>
			<div
				className="albert-flyin"
				role="dialog"
				aria-modal="true"
				aria-labelledby="albert-flyin-title"
				tabIndex={ -1 }
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
						<Badges badges={ ability.badges } />
						<h2
							id="albert-flyin-title"
							className="albert-flyin__title"
						>
							{ ability.label }
						</h2>
						<code className="albert-flyin__id">{ ability.id }</code>
						{ ability.categoryLabel && (
							<span className="albert-flyin__category">
								<span className="albert-flyin__category-label">
									{ __( 'Category', 'albert-ai-butler' ) }
								</span>
								<span className="albert-flyin__category-value">
									{ ability.categoryLabel }
								</span>
							</span>
						) }
					</div>
					<Button
						icon={ close }
						label={ __( 'Close', 'albert-ai-butler' ) }
						onClick={ requestClose }
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

					{ ability.inputs.length > 0 && (
						<CollapsibleSection
							id="albert-flyin-parameters"
							title={ __( 'Inputs', 'albert-ai-butler' ) }
							summary={ inputSummary }
						>
							<ul className="albert-flyin__params">
								{ ability.inputs.map( ( field ) => (
									<li
										key={ field.name }
										className={
											'albert-flyin__param' +
											( field.required
												? ' is-required'
												: '' )
										}
									>
										<div className="albert-flyin__param-head">
											<code className="albert-flyin__param-name">
												{ field.name }
											</code>
											<span className="albert-flyin__param-type">
												{ field.type }
											</span>
											<span className="albert-flyin__param-flag">
												{ field.required
													? __(
															'Required',
															'albert-ai-butler'
													  )
													: __(
															'Optional',
															'albert-ai-butler'
													  ) }
											</span>
										</div>
										{ field.description && (
											<p className="albert-flyin__param-desc">
												{ field.description }
											</p>
										) }
									</li>
								) ) }
							</ul>
						</CollapsibleSection>
					) }

					<SectionBoundary>{ permissionsSection }</SectionBoundary>

					{ sections.map( ( section ) => (
						<SectionBoundary key={ section.id }>
							<SectionRenderer
								section={ section }
								ability={ ability }
								api={ api }
							/>
						</SectionBoundary>
					) ) }

					{ ability.output && (
						<Section title={ __( 'Returns', 'albert-ai-butler' ) }>
							<p className="albert-flyin__returns">
								{ ability.output }
							</p>
						</Section>
					) }
				</div>

				<footer className="albert-flyin__footer">
					<span
						className="albert-flyin__status"
						role="status"
						aria-live="polite"
					>
						{ saveStatus === 'saving' &&
							__( 'Saving…', 'albert-ai-butler' ) }
						{ saveStatus === 'saved' &&
							__( 'Saved', 'albert-ai-butler' ) }
					</span>
					<Button variant="tertiary" onClick={ requestClose }>
						{ __( 'Close', 'albert-ai-butler' ) }
					</Button>
				</footer>
			</div>

			{ closePrompt && (
				<Modal
					title={ closePrompt.title }
					onRequestClose={ () => setClosePrompt( null ) }
					className="albert-flyin__confirm"
					size="small"
				>
					<p className="albert-flyin__confirm-body">
						{ closePrompt.body }
					</p>
					<div className="albert-flyin__confirm-actions">
						<Button
							variant="tertiary"
							onClick={ () => {
								setClosePrompt( null );
								closePrompt.onDiscard?.();
								onClose();
							} }
						>
							{ closePrompt.discardLabel }
						</Button>
						<Button
							variant="primary"
							onClick={ () => {
								setClosePrompt( null );
								closePrompt.onKeep?.();
								onClose();
							} }
						>
							{ closePrompt.keepLabel }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
