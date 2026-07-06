/**
 * URL <-> DataViews view synchronisation for the Abilities screen.
 *
 * The whole search / filter / sort / layout / pagination state lives in one
 * DataViews `view` object. These helpers mirror the meaningful parts into the
 * page's query string so a filtered view is shareable and bookmarkable, and
 * restore them on load. The `page=albert-abilities` arg (and any other params
 * already present) are left untouched.
 */

// The filterable fields and how each one's value serialises. Mirrors the
// filterBy config in fields.js — keep the two in sync if a filter is added.
const FILTERS = {
	category: { multiple: false, operator: 'is' },
	operation: { multiple: false, operator: 'is' },
	supplier: { multiple: false, operator: 'is' },
	status: { multiple: false, operator: 'is' },
	badges: { multiple: true, operator: 'isAny' },
};

// Keys this module owns in the query string (cleared before each write).
const OWNED_KEYS = [
	's',
	'layout',
	'paged',
	'orderby',
	'order',
	...Object.keys( FILTERS ),
];

const DEFAULT_SORT = { field: 'label', direction: 'asc' };

/**
 * Build the initial view by applying URL query overrides onto the default view.
 *
 * Unknown or malformed params are ignored, so a hand-edited URL can never break
 * the screen — at worst a filter is dropped.
 *
 * @param {Object} defaultView The baseline view.
 * @return {Object} The view with URL-derived overrides applied.
 */
export function viewFromUrl( defaultView ) {
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

	const filters = [];
	Object.entries( FILTERS ).forEach(
		( [ field, { multiple, operator } ] ) => {
			const raw = params.get( field );
			if ( ! raw ) {
				return;
			}
			const value = multiple ? raw.split( ',' ).filter( Boolean ) : raw;
			if ( multiple && value.length === 0 ) {
				return;
			}
			filters.push( { field, operator, value } );
		}
	);
	if ( filters.length > 0 ) {
		view.filters = filters;
	}

	return view;
}

/**
 * Mirror the meaningful parts of the view into the URL via replaceState, so the
 * address bar stays shareable without flooding browser history. Only non-default
 * values are written; `page=` and any unrelated params are preserved.
 *
 * @param {Object} view The current DataViews view.
 * @return {void}
 */
export function syncViewToUrl( view ) {
	const params = new URLSearchParams( window.location.search );

	OWNED_KEYS.forEach( ( key ) => params.delete( key ) );

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
		( view.sort.field !== DEFAULT_SORT.field ||
			view.sort.direction !== DEFAULT_SORT.direction )
	) {
		params.set( 'orderby', view.sort.field );
		params.set( 'order', view.sort.direction );
	}
	( view.filters || [] ).forEach( ( filter ) => {
		if ( ! ( filter.field in FILTERS ) ) {
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

	const query = params.toString();
	window.history.replaceState(
		null,
		'',
		window.location.pathname + ( query ? `?${ query }` : '' )
	);
}
