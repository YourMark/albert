/**
 * URL <-> DataViews view synchronisation for the Abilities screen.
 *
 * The `page=albert-abilities` arg (and any other params already present) are
 * left untouched; see ../shared/urlState.js for what these two functions
 * actually do.
 */
import {
	viewFromUrl as sharedViewFromUrl,
	syncViewToUrl as sharedSyncViewToUrl,
	openIdFromUrl,
} from '../shared/urlState';

export { openIdFromUrl };

// The filterable fields and how each one's value serialises. Mirrors the
// filterBy config in fields.js — keep the two in sync if a filter is added.
const FILTERS = {
	category: { multiple: false, operator: 'is' },
	operation: { multiple: false, operator: 'is' },
	source: { multiple: false, operator: 'is' },
	status: { multiple: false, operator: 'is' },
	badges: { multiple: true, operator: 'isAny' },
};

const DEFAULT_SORT = { field: 'label', direction: 'asc' };

/**
 * Build the initial view by applying URL query overrides onto the default view.
 *
 * @param {Object} defaultView The baseline view.
 * @return {Object} The view with URL-derived overrides applied.
 */
export function viewFromUrl( defaultView ) {
	return sharedViewFromUrl( defaultView, { filters: FILTERS } );
}

/**
 * Mirror the meaningful parts of the view into the URL.
 *
 * @param {Object}      view   The current DataViews view.
 * @param {string|null} openId The ability whose detail panel is open, if any.
 * @return {void}
 */
export function syncViewToUrl( view, openId = null ) {
	sharedSyncViewToUrl( view, {
		filters: FILTERS,
		defaultSort: DEFAULT_SORT,
		openId,
	} );
}
