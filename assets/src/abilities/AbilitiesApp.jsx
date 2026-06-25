/**
 * Abilities (DataViews) admin app.
 *
 * Renders the registered abilities as a DataViews table/grid with search,
 * filters, sort, density, layout switch, pagination, and an in-row enabled
 * toggle. Data is loaded once over REST and filtered/sorted/paginated
 * client-side via filterSortAndPaginate. Opening an ability (the detail fly-in)
 * arrives in the next phase.
 *
 * The accent follows the site's WordPress admin color scheme — DataViews and
 * wp-components read --wp-admin-theme-color, which we do not override.
 */
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { Button, Spinner } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	fetchAbilities,
	setAbilityEnabled,
	setAbilitiesEnabledBulk,
} from './api';
import { getFields } from './fields';
import Toolbar from './Toolbar';
import FlyInPanel from './FlyInPanel';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 9,
	titleField: 'label',
	showMedia: false,
	fields: [ 'category', 'operation', 'supplier', 'lastUsed', 'status' ],
	sort: { field: 'label', direction: 'asc' },
	filters: [],
	layout: {
		styles: {
			// Let the Ability column shrink (small min) so the table fits more
			// before scrolling; bound the others so they don't stretch.
			label: { minWidth: 200 },
			category: { minWidth: 96, maxWidth: 150 },
			operation: { width: 116 },
			supplier: { minWidth: 100, maxWidth: 160 },
			lastUsed: { minWidth: 110, maxWidth: 160 },
			status: { width: 84 },
		},
	},
};

const DEFAULT_LAYOUTS = {
	table: {},
	grid: { layout: { badgeFields: [ 'operation' ] } },
};

export default function AbilitiesApp() {
	const [ items, setItems ] = useState( [] );
	const [ categories, setCategories ] = useState( [] );
	const [ suppliers, setSuppliers ] = useState( [] );
	const [ roles, setRoles ] = useState( [] );
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ selection, setSelection ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ openId, setOpenId ] = useState( null );

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
				setRoles( payload.roles || [] );
			} )
			.catch( ( err ) => active && setError( err.message || String( err ) ) )
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
		if ( ids.length === 0 ) {
			return;
		}
		// Optimistic update, then one bulk request, then resync from the server.
		setItems( ( prev ) =>
			prev.map( ( row ) =>
				ids.includes( row.id ) ? { ...row, enabled } : row
			)
		);
		setAbilitiesEnabledBulk( ids, enabled )
			.catch( () =>
				setError(
					__(
						'Could not save your changes. Please try again.',
						'albert-ai-butler'
					)
				)
			)
			.finally( () => {
				fetchAbilities()
					.then( ( payload ) => setItems( payload.abilities ) )
					.catch( () => {} );
			} );
		setSelection( [] );
	}, [] );

	const setAllEnabled = useCallback(
		( enabled ) => {
			if (
				enabled &&
				// eslint-disable-next-line no-alert
				! window.confirm(
					__(
						'Enable all abilities? This includes abilities that can create, change, or permanently delete data.',
						'albert-ai-butler'
					)
				)
			) {
				return;
			}
			bulkToggle( items, enabled );
		},
		[ items, bulkToggle ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'enable',
				label: __( 'Enable', 'albert-ai-butler' ),
				supportsBulk: true,
				isEligible: ( item ) => ! item.enabled,
				callback: ( selectedItems ) => bulkToggle( selectedItems, true ),
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

	const fields = useMemo(
		() => getFields( { categories, suppliers, onToggle } ),
		[ categories, suppliers, onToggle ]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items, view, fields ),
		[ items, view, fields ]
	);

	const getItemId = useCallback( ( item ) => item.id, [] );

	const onClickItem = useCallback( ( item ) => setOpenId( item.id ), [] );

	const enabledCount = useMemo(
		() => items.filter( ( item ) => item.enabled ).length,
		[ items ]
	);

	const openItem = useMemo(
		() => items.find( ( item ) => item.id === openId ) || null,
		[ items, openId ]
	);

	return (
		<div className="albert-abilities">
			<header className="albert-abilities__header">
				<h1 className="albert-abilities__title">
					{ __( 'Abilities', 'albert-ai-butler' ) }
				</h1>
				<p className="albert-abilities__subtitle">
					{ __(
						'Capabilities exposed to apps and agents through the WordPress Abilities API. Enable an ability to make it callable, or open one to review its schema and permissions.',
						'albert-ai-butler'
					) }
				</p>
				{ ! isLoading && (
					<div className="albert-abilities__summary-row">
						<p className="albert-abilities__summary">
							<span>
								<strong>{ items.length }</strong>{ ' ' }
								{ __( 'registered', 'albert-ai-butler' ) }
							</span>
							<span aria-hidden="true">·</span>
							<span>
								<strong>{ enabledCount }</strong>{ ' ' }
								{ __( 'enabled', 'albert-ai-butler' ) }
							</span>
						</p>
						<div className="albert-abilities__bulk-all">
							<Button
								variant="secondary"
								size="compact"
								disabled={ enabledCount === items.length }
								onClick={ () => setAllEnabled( true ) }
							>
								{ __( 'Enable all', 'albert-ai-butler' ) }
							</Button>
							<Button
								variant="secondary"
								size="compact"
								disabled={ enabledCount === 0 }
								onClick={ () => setAllEnabled( false ) }
							>
								{ __( 'Disable all', 'albert-ai-butler' ) }
							</Button>
						</div>
					</div>
				) }
			</header>

			{ error && (
				<div
					className="albert-abilities__error notice notice-error"
					role="alert"
				>
					<p>{ error }</p>
				</div>
			) }

			{ isLoading ? (
				<div className="albert-abilities__loading">
					<Spinner />
				</div>
			) : (
				<div className="albert-abilities__card">
					<DataViews
						data={ data }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						defaultLayouts={ DEFAULT_LAYOUTS }
						paginationInfo={ paginationInfo }
						getItemId={ getItemId }
						onClickItem={ onClickItem }
						selection={ selection }
						onChangeSelection={ setSelection }
					>
						<Toolbar
							view={ view }
							onChangeView={ setView }
							categories={ categories }
							suppliers={ suppliers }
						/>
						{ selection.length > 0 && (
							<div className="albert-abilities__bulkbar">
								<DataViews.BulkActionToolbar />
							</div>
						) }
						<DataViews.Layout />
						<DataViews.Footer />
					</DataViews>
				</div>
			) }

			{ openItem && (
				<FlyInPanel
					ability={ openItem }
					roles={ roles }
					onClose={ () => setOpenId( null ) }
					onToggle={ onToggle }
				/>
			) }
		</div>
	);
}
