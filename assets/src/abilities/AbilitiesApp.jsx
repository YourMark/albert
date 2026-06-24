/**
 * Abilities (DataViews) admin app.
 *
 * Renders the registered abilities as a DataViews table/grid with search,
 * filters, sort, density, layout switch, pagination, and an in-row enabled
 * toggle. Data is loaded once over REST and filtered/sorted/paginated
 * client-side via filterSortAndPaginate. Opening an ability (the detail fly-in)
 * arrives in the next phase.
 *
 * The accent is themed to Albert's indigo by scoping the wp-components
 * --wp-admin-theme-color custom properties to our wrapper.
 */
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { Spinner } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { fetchAbilities, setAbilityEnabled } from './api';
import { getFields } from './fields';
import Toolbar from './Toolbar';

const NO_ACTIONS = [];

const ACCENT_STYLE = {
	'--wp-admin-theme-color': '#3858e9',
	'--wp-admin-theme-color--rgb': '56, 88, 233',
	'--wp-admin-theme-color-darker-10': '#324fd2',
	'--wp-admin-theme-color-darker-20': '#2d46ba',
};

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 9,
	titleField: 'label',
	showMedia: false,
	fields: [ 'category', 'operation', 'supplier', 'status' ],
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

	const fields = useMemo(
		() => getFields( { categories, suppliers, onToggle } ),
		[ categories, suppliers, onToggle ]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items, view, fields ),
		[ items, view, fields ]
	);

	const getItemId = useCallback( ( item ) => item.id, [] );

	const enabledCount = useMemo(
		() => items.filter( ( item ) => item.enabled ).length,
		[ items ]
	);

	return (
		<div className="albert-abilities" style={ ACCENT_STYLE }>
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
						actions={ NO_ACTIONS }
						defaultLayouts={ DEFAULT_LAYOUTS }
						paginationInfo={ paginationInfo }
						getItemId={ getItemId }
					>
						<Toolbar
							view={ view }
							onChangeView={ setView }
							categories={ categories }
							suppliers={ suppliers }
						/>
						<DataViews.Layout />
						<DataViews.Footer />
					</DataViews>
				</div>
			) }
		</div>
	);
}
