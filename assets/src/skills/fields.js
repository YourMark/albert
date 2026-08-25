/**
 * DataViews field definitions for the Skills screen.
 *
 * Source is filterable (single-select); Skill/summary feed global search
 * only. No status column or filter yet either: whether a skill's
 * precondition currently holds is computed (see Skill::status()) and rides
 * along in the REST payload, but 1.4.0 only lists skills Albert itself
 * ships, so there's nothing yet worth distinguishing on this screen. That
 * changes once doc 24's non-Albert sources exist.
 */
import { __ } from '@wordpress/i18n';
import { SINGLE_SELECT } from '../shared/ToolbarControls';

/**
 * The "Skill" cell: name and a one-line summary.
 *
 * @param {Object} props      Render props.
 * @param {Object} props.item The skill row.
 * @return {Element} The cell.
 */
function SkillCell( { item } ) {
	return (
		<div className="albert-skill-cell">
			<span className="albert-skill-cell__slug">{ item.slug }</span>
			{ item.summary && (
				<span className="albert-skill-cell__desc">
					{ item.summary }
				</span>
			) }
		</div>
	);
}

/**
 * Build the DataViews fields array.
 *
 * @param {Object} options          Options.
 * @param {Array}  options.sources  Source filter elements ({ value, label }).
 * @return {Array} DataViews fields.
 */
export function getFields( { sources } ) {
	return [
		{
			id: 'slug',
			label: __( 'Skill', 'albert-ai-butler' ),
			enableHiding: false,
			enableSorting: true,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => item.slug,
			render: SkillCell,
		},
		// summary is search-only: never listed in DEFAULT_VIEW.fields and has no
		// render, so it feeds global search but shows no column of its own.
		{
			id: 'summary',
			label: __( 'Description', 'albert-ai-butler' ),
			enableHiding: false,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => item.summary,
		},
		{
			id: 'source',
			label: __( 'Source', 'albert-ai-butler' ),
			enableSorting: true,
			elements: sources,
			filterBy: SINGLE_SELECT,
			getValue: ( { item } ) => item.source,
			render: ( { item } ) => item.source,
		},
	];
}
