/**
 * DataViews field definitions for the Abilities screen.
 *
 * Operation is the read/write/delete value derived server-side from the
 * ability's annotations. Category, Operation, Supplier and Status are filterable
 * (single-select); Ability/ID/Description feed global search only.
 */
import { FormToggle } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { OPERATION_LABELS } from './constants';

const OPERATION_ELEMENTS = [
	{ value: 'read', label: OPERATION_LABELS.read },
	{ value: 'write', label: OPERATION_LABELS.write },
	{ value: 'delete', label: OPERATION_LABELS.delete },
];

const STATUS_ELEMENTS = [
	{ value: 'enabled', label: __( 'Enabled', 'albert-ai-butler' ) },
	{ value: 'disabled', label: __( 'Disabled', 'albert-ai-butler' ) },
];

const SINGLE_SELECT = { operators: [ 'is' ], isPrimary: true };

/**
 * Render an ability's badges as small pills.
 *
 * Generic row indicators contributed by add-ons through the PHP
 * `albert/abilities/payload_row` filter. Each badge is
 * `{ id, label, tone, title? }`. Renders nothing when the array is empty or
 * missing.
 *
 * @param {Object} props        Render props.
 * @param {Array}  props.badges The row's badges.
 * @return {Element|null} The pills, or null when there are none.
 */
export function Badges( { badges } ) {
	if ( ! Array.isArray( badges ) || badges.length === 0 ) {
		return null;
	}
	return (
		<span className="albert-badges">
			{ badges.map( ( badge ) => (
				<span
					key={ badge.id }
					className={ `albert-badge albert-badge--${ badge.tone }` }
					title={ badge.title }
				>
					{ badge.label }
				</span>
			) ) }
		</span>
	);
}

/**
 * The "Ability" cell: label, monospace id, a one-line description, and any
 * add-on badges.
 *
 * @param {Object} props      Render props.
 * @param {Object} props.item The ability row.
 * @return {Element} The cell.
 */
function AbilityCell( { item } ) {
	return (
		<div className="albert-ability-cell">
			<span className="albert-ability-cell__label">{ item.label }</span>
			<code className="albert-ability-cell__id">{ item.id }</code>
			<span className="albert-ability-cell__desc">
				{ item.description }
			</span>
			<Badges badges={ item.badges } />
		</div>
	);
}

/**
 * The colored operation badge (read / write / delete).
 *
 * @param {Object} props      Render props.
 * @param {Object} props.item The ability row.
 * @return {Element} The badge.
 */
function OperationBadge( { item } ) {
	return (
		<span
			className={ `albert-op-badge albert-op-badge--${ item.operation }` }
		>
			<span className="albert-op-badge__dot" aria-hidden="true" />
			{ OPERATION_LABELS[ item.operation ] || item.operation }
		</span>
	);
}

/**
 * The "Last used" cell: status dot + relative time, or "Never".
 *
 * @param {Object} props      Render props.
 * @param {Object} props.item The ability row.
 * @return {Element} The cell.
 */
function LastUsed( { item } ) {
	const last = item.lastUsed;
	if ( ! last ) {
		return (
			<span className="albert-lastused albert-lastused--never">
				{ __( 'Never', 'albert-ai-butler' ) }
			</span>
		);
	}
	return (
		<span className="albert-lastused">
			<span
				className={ `albert-lastused__dot albert-lastused__dot--${ last.status }` }
				aria-hidden="true"
			/>
			{ last.human }
		</span>
	);
}

/**
 * The in-row enabled toggle. Stops propagation so it never opens the detail view.
 *
 * @param {Object}   props          Render props.
 * @param {Object}   props.item     The ability row.
 * @param {Function} props.onToggle Called with (item, nextEnabled).
 * @return {Element} The toggle.
 */
function EnabledToggle( { item, onToggle } ) {
	const label = sprintf(
		// translators: %s: ability label.
		__( 'Enable %s', 'albert-ai-butler' ),
		item.label
	);
	return (
		<FormToggle
			checked={ item.enabled }
			aria-label={ label }
			onClick={ ( event ) => event.stopPropagation() }
			onChange={ () => onToggle( item, ! item.enabled ) }
		/>
	);
}

// A badge id can appear on many rows, so the badge filter matches membership in
// the row's badge-id array. DataViews' `isAny` operator is the one that checks
// `fieldValue.includes( … )` when getValue returns an array (scalar `is` would
// never match an array-valued field). Its filter value is an array, so the
// toolbar wraps the selected id as `[ id ]`.
const BADGE_FILTER = { operators: [ 'isAny' ], isPrimary: true };

/**
 * Build the DataViews fields array.
 *
 * @param {Object}   options            Options.
 * @param {Array}    options.categories Category filter elements ({ value, label }).
 * @param {Array}    options.suppliers  Supplier filter elements ({ value, label }).
 * @param {Array}    options.badges     Badge filter elements ({ value, label }).
 * @param {Function} options.onToggle   Toggle handler (item, nextEnabled).
 * @return {Array} DataViews fields.
 */
export function getFields( { categories, suppliers, badges, onToggle } ) {
	return [
		{
			id: 'label',
			label: __( 'Ability', 'albert-ai-butler' ),
			enableHiding: false,
			enableSorting: true,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => item.label,
			render: AbilityCell,
		},
		// identifier + description are search-only: never listed in DEFAULT_VIEW.fields
		// and have no render, so they feed global search but show no column.
		// enableHiding:false keeps them out of the column-visibility menu (a field
		// that can't be hidden isn't offered as a toggle) — the id/description must
		// not become a showable raw-text column.
		{
			id: 'identifier',
			label: __( 'Ability ID', 'albert-ai-butler' ),
			enableHiding: false,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => item.id,
		},
		{
			id: 'description',
			label: __( 'Description', 'albert-ai-butler' ),
			enableHiding: false,
			enableGlobalSearch: true,
			filterBy: false,
			getValue: ( { item } ) => item.description,
		},
		{
			id: 'category',
			label: __( 'Category', 'albert-ai-butler' ),
			enableSorting: true,
			elements: categories,
			filterBy: SINGLE_SELECT,
			getValue: ( { item } ) => item.category,
			render: ( { item } ) => item.categoryLabel,
		},
		{
			id: 'operation',
			label: __( 'Operation', 'albert-ai-butler' ),
			enableSorting: true,
			elements: OPERATION_ELEMENTS,
			filterBy: SINGLE_SELECT,
			getValue: ( { item } ) => item.operation,
			render: OperationBadge,
		},
		{
			id: 'supplier',
			label: __( 'Supplier', 'albert-ai-butler' ),
			elements: suppliers,
			filterBy: SINGLE_SELECT,
			getValue: ( { item } ) => item.supplier,
			render: ( { item } ) => item.supplierLabel,
		},
		// Generic add-on indicator dimension. getValue returns the row's badge
		// ids so the `contains` operator can match rows carrying a given badge.
		{
			id: 'badges',
			label: __( 'Badges', 'albert-ai-butler' ),
			enableHiding: false,
			enableSorting: false,
			elements: badges,
			filterBy: BADGE_FILTER,
			getValue: ( { item } ) =>
				Array.isArray( item.badges )
					? item.badges.map( ( badge ) => badge.id )
					: [],
		},
		{
			id: 'lastUsed',
			label: __( 'Last used', 'albert-ai-butler' ),
			enableSorting: true,
			filterBy: false,
			getValue: ( { item } ) => item.lastUsed?.timestamp || 0,
			render: LastUsed,
		},
		{
			id: 'status',
			label: __( 'Enabled', 'albert-ai-butler' ),
			enableHiding: false,
			elements: STATUS_ELEMENTS,
			filterBy: SINGLE_SELECT,
			getValue: ( { item } ) => ( item.enabled ? 'enabled' : 'disabled' ),
			render: ( { item } ) => (
				<EnabledToggle item={ item } onToggle={ onToggle } />
			),
		},
	];
}
