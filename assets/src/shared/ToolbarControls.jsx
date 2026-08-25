/**
 * Shared bits of a DataViews custom toolbar: the "all" sentinel option, the
 * view-filter/sort helpers, and the aligned <select> filter every screen's
 * toolbar (Abilities, Skills, ...) wires the same way. What differs between
 * screens is only which filters appear and in what order, composed by the
 * caller; this file carries nothing specific to any one screen.
 */
import { SelectControl } from '@wordpress/components';

/**
 * Build SelectControl options with an "all" sentinel first.
 *
 * @param {Array}  elements Filter elements ({ value, label }).
 * @param {string} allLabel Label for the "no filter" option.
 * @return {Array} Options for SelectControl.
 */
export function withAll( elements, allLabel ) {
	return [ { value: '', label: allLabel }, ...elements ];
}

/**
 * Read a single-value filter's current selection out of a DataViews view.
 *
 * @param {Object} view  The current DataViews view.
 * @param {string} field Filter field name.
 * @return {string} The selected value, or '' when unset.
 */
export function filterValue( view, field ) {
	return (
		view.filters?.find( ( filter ) => filter.field === field )?.value ?? ''
	);
}

/**
 * Write a single-value ("is") filter into a DataViews view, replacing any
 * existing filter on the same field. An empty value clears the filter rather
 * than writing an empty one. Always resets to page 1, so a changed filter
 * never leaves the view on a page that filter may no longer have.
 *
 * @param {Object}   view         The current DataViews view.
 * @param {Function} onChangeView View setter.
 * @param {string}   field        Filter field name.
 * @param {string}   value        Selected value, or '' to clear.
 */
export function setFilter( view, onChangeView, field, value ) {
	const others = ( view.filters || [] ).filter(
		( filter ) => filter.field !== field
	);
	const filters =
		value === '' ? others : [ ...others, { field, operator: 'is', value } ];
	onChangeView( { ...view, filters, page: 1 } );
}

/**
 * Write the sort field into a DataViews view, keeping the current direction.
 *
 * @param {Object}   view         The current DataViews view.
 * @param {Function} onChangeView View setter.
 * @param {string}   field        Field to sort by.
 */
export function setSort( view, onChangeView, field ) {
	onChangeView( {
		...view,
		sort: { field, direction: view.sort?.direction || 'asc' },
		page: 1,
	} );
}

/**
 * A single-value filter, rendered as a hidden-label <select> bound to a
 * DataViews view via {@see filterValue} / {@see setFilter}.
 *
 * @param {Object}   props              Props.
 * @param {string}   props.label        Accessible label (visually hidden).
 * @param {string}   props.field        Filter field name.
 * @param {Array}    props.options      SelectControl options.
 * @param {Object}   props.view         The current DataViews view.
 * @param {Function} props.onChangeView View setter.
 * @return {Element} The filter select.
 */
export function FilterSelect( { label, field, options, view, onChangeView } ) {
	return (
		<SelectControl
			__nextHasNoMarginBottom
			hideLabelFromVision
			label={ label }
			value={ filterValue( view, field ) }
			options={ options }
			onChange={ ( value ) => setFilter( view, onChangeView, field, value ) }
		/>
	);
}
