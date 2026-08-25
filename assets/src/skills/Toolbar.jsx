/**
 * Custom toolbar for the Skills DataViews screen.
 *
 * Same composition as the Abilities toolbar (search + aligned <select>
 * filters left, density/layout right), scaled down to what Skills actually
 * has to filter by: source.
 */
import { DataViews } from '@wordpress/dataviews/wp';
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { FilterSelect, setSort, withAll } from '../shared/ToolbarControls';

const SORT_OPTIONS = [
	{ value: 'slug', label: __( 'Sort: Name', 'albert-ai-butler' ) },
	{ value: 'source', label: __( 'Sort: Source', 'albert-ai-butler' ) },
];

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
	return (
		<div className="albert-toolbar">
			<div className="albert-toolbar__group">
				<DataViews.Search />
				<FilterSelect
					view={ view }
					onChangeView={ onChangeView }
					label={ __( 'Filter by source', 'albert-ai-butler' ) }
					field="source"
					options={ withAll(
						sources,
						__( 'All sources', 'albert-ai-butler' )
					) }
				/>
			</div>
			<div className="albert-toolbar__group albert-toolbar__group--end">
				<SelectControl
					__nextHasNoMarginBottom
					hideLabelFromVision
					label={ __( 'Sort skills', 'albert-ai-butler' ) }
					value={ view.sort?.field || 'slug' }
					options={ SORT_OPTIONS }
					onChange={ ( field ) => setSort( view, onChangeView, field ) }
				/>
				<DataViews.ViewConfig />
				<DataViews.LayoutSwitcher />
			</div>
		</div>
	);
}
