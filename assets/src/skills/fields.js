/**
 * DataViews field definitions for the Skills screen.
 *
 * Source is filterable (single-select); Skill/summary feed global search
 * only. No enable/disable control yet: whether a skill's precondition
 * currently holds is computed (see Skill::status()) and still rides along in
 * the REST payload for a later version to build on, but this screen doesn't
 * surface it as a toggle, column, or filter until there's a real, enforceable
 * on/off to show.
 */
import { __ } from '@wordpress/i18n';

const SINGLE_SELECT = { operators: [ 'is' ], isPrimary: true };

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
 * The source badge: who ships this skill (Albert, or an add-on's own name).
 * Doc 23a calls for this explicitly, as a badge, not plain text: source is a
 * trust signal (built-in vs. third-party), not incidental metadata, so it
 * gets the same badge treatment as other source/category labels in this
 * design system, not a plain-text column.
 *
 * @param {Object} props      Render props.
 * @param {Object} props.item The skill row.
 * @return {Element} The badge.
 */
function SourceBadge( { item } ) {
	return (
		<span className="albert-badge albert-badge--outline">
			{ item.source }
		</span>
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
			render: SourceBadge,
		},
	];
}
