/**
 * Abilities (DataViews) admin app.
 *
 * Renders the registered abilities as a DataViews table/grid with search,
 * filters, sort, density, layout switch, pagination, an in-row enabled toggle,
 * and bulk enable/disable. Data is loaded once over REST and filtered/sorted/
 * paginated client-side via filterSortAndPaginate. Opening an ability (the
 * detail fly-in) arrives in the next phase.
 */
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { fetchAbilities, setAbilityEnabled } from './api';
import { getFields } from './fields';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 9,
	titleField: 'label',
	descriptionField: 'description',
	fields: [ 'category', 'operation', 'status' ],
	sort: { field: 'label', direction: 'asc' },
	filters: [],
	layout: {},
};

const DEFAULT_LAYOUTS = {
	table: {},
	grid: { layout: { badgeFields: [ 'operation' ] } },
};

export default function AbilitiesApp() {
	const [ items, setItems ] = useState( [] );
	const [ categories, setCategories ] = useState( [] );
	const [ suppliers, setSuppliers ] = useState( [] );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ selection, setSelection ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let active = true;
		fetchAbilities()
			.then( ( payload ) => {
				if ( ! active ) {
					return;
				}
				setItems( payload.abilities );
				setCategories( payload.categories );
				setSuppliers( payload.suppliers );
			} )
			.catch(
				( err ) => active && setError( err.message || String( err ) )
			)
			.finally( () => active && setIsLoading( false ) );
		return () => {
			active = false;
		};
	}, [] );

	const onToggle = useCallback( ( item, enabled ) => {
		setError( null );
		setItems( ( prev ) =>
			prev.map( ( row ) =>
				row.id === item.id ? { ...row, enabled } : row
			)
		);
		setAbilityEnabled( item.id, enabled )
			.then( ( updated ) =>
				setItems( ( prev ) =>
					prev.map( ( row ) =>
						row.id === updated.id ? updated : row
					)
				)
			)
			.catch( ( err ) => {
				// Revert the optimistic change.
				setItems( ( prev ) =>
					prev.map( ( row ) =>
						row.id === item.id
							? { ...row, enabled: item.enabled }
							: row
					)
				);
				setError( err.message || String( err ) );
			} );
	}, [] );

	const bulkToggle = useCallback( ( selectedItems, enabled ) => {
		setError( null );
		const ids = selectedItems.map( ( item ) => item.id );
		setItems( ( prev ) =>
			prev.map( ( row ) =>
				ids.includes( row.id ) ? { ...row, enabled } : row
			)
		);
		Promise.allSettled(
			ids.map( ( id ) => setAbilityEnabled( id, enabled ) )
		).then( ( results ) => {
			const failed = results.filter(
				( result ) => result.status === 'rejected'
			).length;
			if ( failed ) {
				setError(
					sprintf(
						/* translators: %d: number of failed changes. */
						__(
							'%d change(s) could not be saved.',
							'albert-ai-butler'
						),
						failed
					)
				);
			}
			// Resync from the server so partial failures can't leave stale state.
			fetchAbilities()
				.then( ( payload ) => setItems( payload.abilities ) )
				.catch( () => {} );
		} );
		setSelection( [] );
	}, [] );

	const fields = useMemo(
		() => getFields( { categories, suppliers, onToggle } ),
		[ categories, suppliers, onToggle ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'enable',
				label: __( 'Enable', 'albert-ai-butler' ),
				supportsBulk: true,
				isEligible: ( item ) => ! item.enabled,
				callback: ( selectedItems ) =>
					bulkToggle( selectedItems, true ),
			},
			{
				id: 'disable',
				label: __( 'Disable', 'albert-ai-butler' ),
				supportsBulk: true,
				isEligible: ( item ) => item.enabled,
				callback: ( selectedItems ) =>
					bulkToggle( selectedItems, false ),
			},
		],
		[ bulkToggle ]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items, view, fields ),
		[ items, view, fields ]
	);

	const getItemId = useCallback( ( item ) => item.id, [] );

	if ( isLoading ) {
		return (
			<div className="albert-abilities-app__loading">
				<Spinner />
			</div>
		);
	}

	return (
		<div className="albert-abilities-app">
			{ error && (
				<Notice
					status="error"
					onRemove={ () => setError( null ) }
					className="albert-abilities-app__notice"
				>
					{ error }
				</Notice>
			) }
			<DataViews
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				defaultLayouts={ DEFAULT_LAYOUTS }
				paginationInfo={ paginationInfo }
				selection={ selection }
				onChangeSelection={ setSelection }
				getItemId={ getItemId }
			/>
		</div>
	);
}
