/**
 * URL <-> DataViews view synchronisation for the Skills screen.
 *
 * The `page=albert-skills` arg (and any other params already present) are
 * left untouched; see ../shared/urlState.js for what these two functions
 * actually do.
 */
import {
	viewFromUrl as sharedViewFromUrl,
	syncViewToUrl as sharedSyncViewToUrl,
} from '../shared/urlState';

// The filterable fields and how each one's value serialises. Mirrors the
// filterBy config in fields.js — keep the two in sync if a filter is added.
const FILTERS = {
	source: { multiple: false, operator: 'is' },
	status: { multiple: false, operator: 'is' },
};

const DEFAULT_SORT = { field: 'slug', direction: 'asc' };

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
 * @param {Object} view The current DataViews view.
 * @return {void}
 */
export function syncViewToUrl( view ) {
	sharedSyncViewToUrl( view, { filters: FILTERS, defaultSort: DEFAULT_SORT } );
}
