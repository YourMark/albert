/**
 * DataViews field definitions for the Skills screen.
 *
 * Source and Enabled are filterable (single-select); Skill/summary feed
 * global search only. The Enabled column reuses Abilities' own toggle
 * (FormToggle, same component, same position, same word), always disabled
 * here: nothing on this screen is a setting yet, this is a live, site-computed
 * fact (does WooCommerce being active, or the block editor being in use,
 * currently hold), not something a person switches. A disabled toggle plus a
 * labelled reason is the established pattern for a control gated on a
 * condition elsewhere, clearer than a pill because it matches the real
 * Enabled toggle on Abilities pixel for pixel rather than inventing a second
 * visual language for the same kind of fact.
 */
import { FormToggle } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const STATUS_ELEMENTS = [
	{ value: 'enabled', label: __( 'Enabled', 'albert-ai-butler' ) },
	{ value: 'disabled', label: __( 'Disabled', 'albert-ai-butler' ) },
];

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
 * The enabled toggle: on when the skill's precondition currently holds, off
 * when it doesn't. Always disabled, there is no click handler, it reports a
 * live fact rather than taking an action.
 *
 * One label template regardless of state, same as Abilities' own
 * EnabledToggle: the switch's checked state already says on or off, so the
 * label only has to name what it controls, not repeat the state in words.
 *
 * @param {Object} props      Render props.
 * @param {Object} props.item The skill row.
 * @return {Element} The toggle.
 */
function EnabledToggle( { item } ) {
	const label = sprintf(
		// translators: %s: skill slug.
		__( '%s enabled', 'albert-ai-butler' ),
		item.slug
	);
	return (
		<FormToggle checked={ item.available } disabled aria-label={ label } />
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
		{
			id: 'status',
			label: __( 'Enabled', 'albert-ai-butler' ),
			enableHiding: false,
			elements: STATUS_ELEMENTS,
			filterBy: SINGLE_SELECT,
			getValue: ( { item } ) =>
				item.available ? 'enabled' : 'disabled',
			render: EnabledToggle,
		},
	];
}
