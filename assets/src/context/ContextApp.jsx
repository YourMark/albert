/**
 * Albert → Context screen.
 *
 * The site owner tells connected assistants what this site is and how to behave.
 * The screen's job is to make the trade visible: everything included costs space
 * in what the assistant receives, and the cost, the preview and the switches all
 * move together.
 *
 * Every write returns the full recomputed state, so the budget and the preview
 * are always the server's answer rather than the client's guess about what the
 * server would say.
 */
import { FormToggle } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchContext, saveContext } from './api';
import BudgetCard from './BudgetCard';
import InstructionsCard from './InstructionsCard';
import PreviewCard from './PreviewCard';
import SectionsCard from './SectionsCard';

/**
 * Render the screen.
 *
 * @return {Element} The app.
 */
export default function ContextApp() {
	const [ state, setState ] = useState( null );
	const [ status, setStatus ] = useState( 'loading' );

	useEffect( () => {
		fetchContext()
			.then( ( payload ) => {
				setState( payload );
				setStatus( 'idle' );
			} )
			.catch( () => setStatus( 'load-error' ) );
	}, [] );

	const save = ( changes ) => {
		setStatus( 'saving' );

		return saveContext( changes )
			.then( ( payload ) => {
				setState( payload );
				setStatus( 'saved' );
			} )
			.catch( () => setStatus( 'error' ) );
	};

	if ( status === 'loading' ) {
		return (
			<div className="albert-page">
				<p>{ __( 'Loading…', 'albert-ai-butler' ) }</p>
			</div>
		);
	}

	if ( status === 'load-error' || ! state ) {
		return (
			<div className="albert-page">
				<div className="notice notice-error">
					<p>
						{ __(
							'The Context settings could not be loaded. Reload the page to try again.',
							'albert-ai-butler'
						) }
					</p>
				</div>
			</div>
		);
	}

	const hasCommerce = state.sections.some(
		( section ) => section.key === 'commerce'
	);

	return (
		<div className="albert-page albert-context">
			<header className="albert-page__header">
				<div className="albert-page__text">
					<h1 className="albert-page__title">
						{ __( 'Context', 'albert-ai-butler' ) }
					</h1>
					<p className="albert-page__description">
						{ __(
							'Tell connected assistants what this site is and how to behave, so they stop guessing. Everything you include here is sent with each conversation.',
							'albert-ai-butler'
						) }
					</p>
				</div>
				<div className="albert-page__actions albert-context__master">
					<div aria-live="polite">
						<SaveState
							status={ status }
							onRetry={ () => save( {} ) }
						/>
					</div>
					<span
						className="albert-context__master-label"
						id="albert-context-master"
					>
						{ __( 'Send context', 'albert-ai-butler' ) }
					</span>
					<FormToggle
						checked={ state.enabled }
						disabled={ state.managed.enabled }
						aria-label={ __(
							'Send context to connected assistants',
							'albert-ai-butler'
						) }
						onChange={ () => save( { enabled: ! state.enabled } ) }
					/>
				</div>
			</header>

			{ ! state.enabled && (
				<div className="albert-hint albert-hint--info albert-context__off-notice">
					{ __(
						'Context is switched off. Assistants still work, but they receive nothing about this site: no language, no brand, no content model.',
						'albert-ai-butler'
					) }
				</div>
			) }

			{ /*
			 * `inert` rather than pointer-events: none, that only stops the
			 * mouse, leaving every control keyboard reachable and announced as
			 * operable. Text stays at full contrast: an earlier draft dimmed the
			 * body to 1.99:1, which fails AA. The off state is signalled
			 * structurally, by the notice above and the sunken surfaces.
			 */ }
			<div
				className="albert-page__body"
				inert={ state.enabled ? undefined : '' }
			>
				<BudgetCard
					budget={ state.budget }
					costs={ state.costs }
					enabled={ state.enabled }
				/>

				<InstructionsCard
					value={ state.instructions }
					managed={ state.managed.instructions }
					tokens={ state.budget.instruction }
					heading={ state.instructionHeading }
					onChange={ ( instructions ) => save( { instructions } ) }
				/>

				<SectionsCard
					sections={ state.sections }
					managed={ state.managed.sections }
					hasCommerce={ hasCommerce }
					onToggle={ ( key, enabled ) =>
						save( { sections: { [ key ]: enabled } } )
					}
				/>

				{ /*
				 * The same total the card above reports. They were two numbers
				 * for one payload before, the card excluded the owner's
				 * instructions so it could budget them separately, and the
				 * preview counted everything.
				 */ }
				<PreviewCard
					segments={ state.preview }
					tokens={ state.budget.total }
				/>
			</div>
		</div>
	);
}

/**
 * The instant-save indicator that replaces a submit button.
 *
 * @param {Object}   props         Props.
 * @param {string}   props.status  Current save status.
 * @param {Function} props.onRetry Retry a failed save.
 * @return {Element|null} The indicator.
 */
function SaveState( { status, onRetry } ) {
	if ( status === 'saving' ) {
		return (
			<span className="albert-savestate">
				<span className="albert-savestate__dot" />
				{ __( 'Saving…', 'albert-ai-butler' ) }
			</span>
		);
	}

	if ( status === 'error' ) {
		return (
			<span className="albert-savestate albert-savestate--error">
				<span className="albert-savestate__dot" />
				{ __( 'Not saved.', 'albert-ai-butler' ) }{ ' ' }
				<button
					type="button"
					className="albert-link-button"
					onClick={ onRetry }
				>
					{ __( 'Try again', 'albert-ai-butler' ) }
				</button>
			</span>
		);
	}

	return (
		<span className="albert-savestate albert-savestate--saved">
			<span className="albert-savestate__dot" />
			{ __( 'Saved', 'albert-ai-butler' ) }
		</span>
	);
}
