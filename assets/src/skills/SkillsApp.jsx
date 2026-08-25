/**
 * Albert → Skills screen.
 *
 * A read-only library of every skill in the doc-21 registry, on the same
 * DataViews table Abilities uses (search, filter, sort, density, a table/grid
 * switch), so this screen matches the one it was scoped to mirror
 * (docs/features/23-skills.md's Claude Code prompt: "mirror Abilities screen
 * conventions"). Data loads once over REST and is filtered/sorted/paginated
 * client-side via filterSortAndPaginate, same as Abilities. Clicking a row
 * opens the read-only detail fly-in; there is nothing to enable, disable, or
 * bulk-act on; 23a ships visibility only.
 */
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { Spinner } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { fetchSkills } from './api';
import { getFields } from './fields';
import { viewFromUrl, syncViewToUrl } from './url';
import Toolbar from './Toolbar';
import SkillFlyIn from './SkillFlyIn';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	titleField: 'slug',
	showMedia: false,
	fields: [ 'source' ],
	sort: { field: 'slug', direction: 'asc' },
	filters: [],
	layout: {
		styles: {
			slug: { minWidth: 240 },
			source: { minWidth: 120, maxWidth: 200 },
		},
	},
};

const DEFAULT_LAYOUTS = {
	table: {},
	grid: {},
};

export default function SkillsApp() {
	const [ items, setItems ] = useState( [] );
	// Initialise from the URL so a shared/bookmarked link opens pre-filtered.
	const [ view, setView ] = useState( () => viewFromUrl( DEFAULT_VIEW ) );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ openSlug, setOpenSlug ] = useState( null );

	// Mirror the view (search, filters, sort, layout, page) into the URL so it
	// can be shared and bookmarked. replaceState keeps history clean.
	useEffect( () => {
		syncViewToUrl( view );
	}, [ view ] );

	useEffect( () => {
		let active = true;
		fetchSkills()
			.then( ( payload ) => {
				if ( ! active ) {
					return;
				}
				setItems( Array.isArray( payload?.skills ) ? payload.skills : [] );
			} )
			.catch(
				( err ) => active && setError( err.message || String( err ) )
			)
			.finally( () => active && setIsLoading( false ) );
		return () => {
			active = false;
		};
	}, [] );

	// Source filter options are the deduped union of every source present in
	// the loaded data, same reasoning as Abilities' badge filter: options
	// reflect what's actually here rather than a hardcoded list.
	const sources = useMemo( () => {
		const seen = new Set();
		items.forEach( ( item ) => seen.add( item.source ) );
		return [ ...seen ].sort().map( ( value ) => ( { value, label: value } ) );
	}, [ items ] );

	const fields = useMemo( () => getFields( { sources } ), [ sources ] );

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( items, view, fields ),
		[ items, view, fields ]
	);

	// Search/filter/sort change the table's content without a page reload or
	// a focus change, so nothing tells a screen-reader user the result count
	// changed unless something announces it. Skipped on the initial load
	// (isFirstResult) since that isn't a filter changing.
	const isFirstResult = useRef( true );
	useEffect( () => {
		if ( isLoading ) {
			return;
		}
		if ( isFirstResult.current ) {
			isFirstResult.current = false;
			return;
		}
		speak(
			sprintf(
				/* translators: %d: number of matching skills. */
				_n(
					'%d skill found.',
					'%d skills found.',
					paginationInfo.totalItems,
					'albert-ai-butler'
				),
				paginationInfo.totalItems
			)
		);
	}, [ paginationInfo.totalItems, isLoading ] );

	const getItemId = useCallback( ( item ) => item.slug, [] );
	const onClickItem = useCallback( ( item ) => setOpenSlug( item.slug ), [] );
	const onCloseFlyIn = useCallback( () => setOpenSlug( null ), [] );

	const openItem = useMemo(
		() => items.find( ( item ) => item.slug === openSlug ) || null,
		[ items, openSlug ]
	);

	return (
		<div className="albert-skills">
			<header
				className="albert-skills__header"
				aria-hidden={ openItem ? true : undefined }
			>
				<h1 className="albert-skills__title">
					{ __( 'Skills', 'albert-ai-butler' ) }
				</h1>
				<p className="albert-skills__subtitle">
					{ __(
						'An overview of the task guides Albert currently ships. Open one to read the exact guidance a connected assistant follows.',
						'albert-ai-butler'
					) }
				</p>
				{ ! isLoading && (
					<div className="albert-skills__summary-row">
						<p
							className="albert-skills__summary"
							aria-live="polite"
						>
							<span>
								<strong>{ items.length }</strong>{ ' ' }
								{ __( 'registered', 'albert-ai-butler' ) }
							</span>
						</p>
					</div>
				) }
			</header>

			{ error && (
				<div
					className="albert-skills__error notice notice-error"
					role="alert"
				>
					<p>{ error }</p>
				</div>
			) }

			{ isLoading ? (
				<div className="albert-skills__loading">
					<Spinner />
				</div>
			) : items.length === 0 ? (
				<p className="albert-hint albert-hint--info">
					{ __(
						'No skills are registered on this site.',
						'albert-ai-butler'
					) }
				</p>
			) : (
				<div
					className="albert-skills__card"
					aria-hidden={ openItem ? true : undefined }
				>
					<DataViews
						data={ data }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						defaultLayouts={ DEFAULT_LAYOUTS }
						paginationInfo={ paginationInfo }
						getItemId={ getItemId }
						onClickItem={ onClickItem }
					>
						<Toolbar
							view={ view }
							onChangeView={ setView }
							sources={ sources }
						/>
						<DataViews.Layout />
						<DataViews.Footer />
					</DataViews>
				</div>
			) }

			{ openItem && (
				<SkillFlyIn skill={ openItem } onClose={ onCloseFlyIn } />
			) }
		</div>
	);
}
