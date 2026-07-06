/**
 * Custom toolbar for the Abilities DataViews screen.
 *
 * DataViews renders `children ?? DefaultUI`, so by composing our own children we
 * replace DataViews' default toolbar (search + funnel toggle + chip filters on a
 * second row) with a single aligned row of accessible <select> filters
 * (SelectControl). The data engine is unchanged: each select writes to the
 * `view` object (filters/sort), which filterSortAndPaginate consumes.
 *
 * Search, density (ViewConfig) and the table/grid switch (LayoutSwitcher) reuse
 * DataViews' own sub-components, which read the shared context.
 */
import { DataViews } from '@wordpress/dataviews/wp';
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const OPERATION_OPTIONS = [
	{ value: '', label: __( 'All operations', 'albert-ai-butler' ) },
	{ value: 'read', label: __( 'Read', 'albert-ai-butler' ) },
	{ value: 'write', label: __( 'Write', 'albert-ai-butler' ) },
	{ value: 'delete', label: __( 'Delete', 'albert-ai-butler' ) },
];

const STATUS_OPTIONS = [
	{ value: '', label: __( 'Any status', 'albert-ai-butler' ) },
	{ value: 'enabled', label: __( 'Enabled', 'albert-ai-butler' ) },
	{ value: 'disabled', label: __( 'Disabled', 'albert-ai-butler' ) },
];

const SORT_OPTIONS = [
	{ value: 'label', label: __( 'Sort: Name', 'albert-ai-butler' ) },
	{ value: 'category', label: __( 'Sort: Category', 'albert-ai-butler' ) },
	{ value: 'operation', label: __( 'Sort: Operation', 'albert-ai-butler' ) },
	{ value: 'lastUsed', label: __( 'Sort: Last used', 'albert-ai-butler' ) },
];

/**
 * Build SelectControl options with an "all" sentinel first.
 *
 * @param {Array}  elements    Filter elements ({ value, label }).
 * @param {string} allLabel    Label for the "no filter" option.
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
 * @param {Array}    props.categories   Category filter elements.
 * @param {Array}    props.suppliers    Supplier filter elements.
 * @param {Array}    props.badges       Badge filter elements.
 * @return {Element} The toolbar.
 */
export default function Toolbar( {
	view,
	onChangeView,
	categories,
	suppliers,
	badges,
} ) {
	const filterValue = ( field ) =>
		view.filters?.find( ( filter ) => filter.field === field )?.value ?? '';

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

	// Badges live in an array on each row, so they use DataViews' `isAny`
	// operator (array filter value). Read back the single selected id and write
	// it as a one-element array.
	const badgeValue = () => {
		const value = filterValue( 'badges' );
		return Array.isArray( value ) ? value[ 0 ] ?? '' : '';
	};

	const setBadgeFilter = ( value ) => {
		const others = ( view.filters || [] ).filter(
			( filter ) => filter.field !== 'badges'
		);
		const filters =
			value === ''
				? others
				: [ ...others, { field: 'badges', operator: 'isAny', value: [ value ] } ];
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
					__( 'Filter by category', 'albert-ai-butler' ),
					'category',
					withAll( categories, __( 'All categories', 'albert-ai-butler' ) )
				) }
				{ select(
					__( 'Filter by operation', 'albert-ai-butler' ),
					'operation',
					OPERATION_OPTIONS
				) }
				{ select(
					__( 'Filter by status', 'albert-ai-butler' ),
					'status',
					STATUS_OPTIONS
				) }
				{ select(
					__( 'Filter by supplier', 'albert-ai-butler' ),
					'supplier',
					withAll( suppliers, __( 'All suppliers', 'albert-ai-butler' ) )
				) }
				{ badges?.length > 0 && (
					<SelectControl
						__nextHasNoMarginBottom
						hideLabelFromVision
						label={ __( 'Filter by badge', 'albert-ai-butler' ) }
						value={ badgeValue() }
						options={ withAll(
							badges,
							__( 'All badges', 'albert-ai-butler' )
						) }
						onChange={ setBadgeFilter }
					/>
				) }
			</div>
			<div className="albert-toolbar__group albert-toolbar__group--end">
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Sort abilities', 'albert-ai-butler' ) }
					value={ view.sort?.field || 'label' }
					options={ SORT_OPTIONS }
					onChange={ setSort }
				/>
				<DataViews.ViewConfig />
				<DataViews.LayoutSwitcher />
			</div>
		</div>
	);
}
