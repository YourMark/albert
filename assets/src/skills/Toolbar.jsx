/**
 * Custom toolbar for the Skills DataViews screen.
 *
 * Same composition as the Abilities toolbar (search + aligned <select>
 * filters left, density/layout right), scaled down to what Skills actually
 * has to filter by: source and whether a skill is currently enabled.
 */
import { DataViews } from '@wordpress/dataviews/wp';
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const STATUS_OPTIONS = [
	{ value: '', label: __( 'Any status', 'albert-ai-butler' ) },
	{ value: 'enabled', label: __( 'Enabled', 'albert-ai-butler' ) },
	{ value: 'disabled', label: __( 'Disabled', 'albert-ai-butler' ) },
];

const SORT_OPTIONS = [
	{ value: 'slug', label: __( 'Sort: Name', 'albert-ai-butler' ) },
	{ value: 'source', label: __( 'Sort: Source', 'albert-ai-butler' ) },
];

/**
 * Build SelectControl options with an "all" sentinel first.
 *
 * @param {Array}  elements Filter elements ({ value, label }).
 * @param {string} allLabel Label for the "no filter" option.
 * @return {Array} Options for SelectControl.
 */
function withAll( elements, allLabel ) {
	return [ { value: '', label: allLabel }, ...elements ];
}

/**
 * The custom toolbar.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.view         The current DataViews view.
 * @param {Function} props.onChangeView View setter.
 * @param {Array}    props.sources      Source filter elements.
 * @return {Element} The toolbar.
 */
export default function Toolbar( { view, onChangeView, sources } ) {
	const filterValue = ( field ) =>
		view.filters?.find( ( filter ) => filter.field === field )?.value ??
		'';

	const setFilter = ( field, value ) => {
		const others = ( view.filters || [] ).filter(
			( filter ) => filter.field !== field
		);
		const filters =
			value === ''
				? others
				: [ ...others, { field, operator: 'is', value } ];
		onChangeView( { ...view, filters, page: 1 } );
	};

	const setSort = ( field ) =>
		onChangeView( {
			...view,
			sort: { field, direction: view.sort?.direction || 'asc' },
			page: 1,
		} );

	const select = ( label, field, options ) => (
		<SelectControl
			__nextHasNoMarginBottom
			hideLabelFromVision
			label={ label }
			value={ filterValue( field ) }
			options={ options }
			onChange={ ( value ) => setFilter( field, value ) }
		/>
	);

	return (
		<div className="albert-toolbar">
			<div className="albert-toolbar__group">
				<DataViews.Search />
				{ select(
					__( 'Filter by source', 'albert-ai-butler' ),
					'source',
					withAll( sources, __( 'All sources', 'albert-ai-butler' ) )
				) }
				{ select(
					__( 'Filter by status', 'albert-ai-butler' ),
					'status',
					STATUS_OPTIONS
				) }
			</div>
			<div className="albert-toolbar__group albert-toolbar__group--end">
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Sort skills', 'albert-ai-butler' ) }
					value={ view.sort?.field || 'slug' }
					options={ SORT_OPTIONS }
					onChange={ setSort }
				/>
				<DataViews.ViewConfig />
				<DataViews.LayoutSwitcher />
			</div>
		</div>
	);
}
