/**
 * URL <-> DataViews view synchronisation, shared by every screen that wants a
 * filtered view to be shareable and bookmarkable (Abilities, Skills, ...).
 *
 * The whole search / filter / sort / layout / pagination state lives in one
 * DataViews `view` object. These helpers mirror the meaningful parts into the
 * page's query string and restore them on load. Only the field-shaped bits
 * differ between screens (which filters exist, whether each is single- or
 * multi-value, and what the default sort is), so those are the one thing a
 * caller supplies; everything else — parsing, serialising, which query keys
 * get touched — is common.
 */

// Query keys this module always owns, beyond whatever filter fields the
// caller's `filters` config adds. Cleared before every write.
const BASE_OWNED_KEYS = [ 's', 'layout', 'paged', 'orderby', 'order', 'ability' ];

/**
 * The id of the detail panel to open on load, if the URL names one.
 *
 * Separate from the view: which row is open is not a filter, a sort or a page.
 * It exists so something outside this screen can link *at* an ability rather
 * than at the list and leave the reader to find it.
 *
 * @return {string|null} The ability id, or null.
 */
export function openIdFromUrl() {
	const value = new URLSearchParams( window.location.search ).get( 'ability' );

	return value ? value : null;
}

/**
 * Build the initial view by applying URL query overrides onto the default view.
 *
 * Unknown or malformed params are ignored, so a hand-edited URL can never break
 * the screen — at worst a filter is dropped.
 *
 * @param {Object} defaultView    The baseline view.
 * @param {Object} config         Per-screen configuration.
 * @param {Object} config.filters Filterable fields, keyed by field name,
 *                                each `{ multiple: boolean, operator: string }`.
 * @return {Object} The view with URL-derived overrides applied.
 */
export function viewFromUrl( defaultView, { filters } ) {
	const params = new URLSearchParams( window.location.search );
	const view = { ...defaultView };

	const search = params.get( 's' );
	if ( search ) {
		view.search = search;
	}

	const layout = params.get( 'layout' );
	if ( layout === 'grid' || layout === 'table' ) {
		view.type = layout;
	}

	const paged = parseInt( params.get( 'paged' ) ?? '', 10 );
	if ( Number.isInteger( paged ) && paged > 1 ) {
		view.page = paged;
	}

	const orderby = params.get( 'orderby' );
	if ( orderby ) {
		view.sort = {
			field: orderby,
			direction: params.get( 'order' ) === 'desc' ? 'desc' : 'asc',
		};
	}

	const parsedFilters = [];
	Object.entries( filters ).forEach(
		( [ field, { multiple, operator } ] ) => {
			const raw = params.get( field );
			if ( ! raw ) {
				return;
			}
			const value = multiple ? raw.split( ',' ).filter( Boolean ) : raw;
			if ( multiple && value.length === 0 ) {
				return;
			}
			parsedFilters.push( { field, operator, value } );
		}
	);
	if ( parsedFilters.length > 0 ) {
		view.filters = parsedFilters;
	}

	return view;
}

/**
 * Mirror the meaningful parts of the view into the URL via replaceState, so the
 * address bar stays shareable without flooding browser history. Only non-default
 * values are written; the page's own query arg and any unrelated params are
 * preserved.
 *
 * @param {Object} view               The current DataViews view.
 * @param {Object} config             Per-screen configuration.
 * @param {Object} config.filters     Filterable fields, keyed by field name,
 *                                    each `{ multiple: boolean, operator: string }`.
 * @param {Object} config.defaultSort The view's default `{ field, direction }`,
 *                                    omitted from the URL when unchanged.
 * @return {void}
 */
export function syncViewToUrl( view, { filters, defaultSort, openId = null } ) {
	const params = new URLSearchParams( window.location.search );

	[ ...BASE_OWNED_KEYS, ...Object.keys( filters ) ].forEach( ( key ) =>
		params.delete( key )
	);

	if ( view.search ) {
		params.set( 's', view.search );
	}
	if ( view.type && view.type !== 'table' ) {
		params.set( 'layout', view.type );
	}
	if ( view.page && view.page > 1 ) {
		params.set( 'paged', String( view.page ) );
	}
	if (
		view.sort &&
		( view.sort.field !== defaultSort.field ||
			view.sort.direction !== defaultSort.direction )
	) {
		params.set( 'orderby', view.sort.field );
		params.set( 'order', view.sort.direction );
	}
	( view.filters || [] ).forEach( ( filter ) => {
		if ( ! ( filter.field in filters ) ) {
			return;
		}
		const value = Array.isArray( filter.value )
			? filter.value.join( ',' )
			: filter.value;
		if ( value === undefined || value === null || value === '' ) {
			return;
		}
		params.set( filter.field, String( value ) );
	} );

	if ( openId ) {
		params.set( 'ability', openId );
	}

	const query = params.toString();
	window.history.replaceState(
		null,
		'',
		window.location.pathname + ( query ? `?${ query }` : '' )
	);
}
