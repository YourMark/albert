/**
 * Extended Abilities Admin Settings Scripts
 *
 * @package ExtendedAbilities
 * @since   1.0.0
 */

(function ($) {
	'use strict';

	/**
	 * Initialize settings page functionality.
	 */
	function init() {
		handleToggleAll();
		handleSubgroupToggleAll();
		handleIndividualToggles();
		handleCollapseToggle();
	}

	/**
	 * Handle "Toggle All" functionality for ability groups.
	 */
	function handleToggleAll() {
		$('.toggle-all-abilities').on('change', function () {
			const $toggleAll = $(this);
			const group = $toggleAll.data('group');
			const isChecked = $toggleAll.prop('checked');

			// Toggle all checkboxes in this group (including subgroups)
			$('.ability-checkbox[data-group="' + group + '"]').prop('checked', isChecked);

			// Update all subgroup toggle states
			$('.toggle-subgroup-abilities').each(function () {
				updateSubgroupToggleState($(this).data('subgroup'));
			});
		});
	}

	/**
	 * Handle "Toggle All" functionality for ability subgroups.
	 */
	function handleSubgroupToggleAll() {
		$('.toggle-subgroup-abilities').on('change', function () {
			const $toggleAll = $(this);
			const subgroup = $toggleAll.data('subgroup');
			const isChecked = $toggleAll.prop('checked');

			// Toggle all checkboxes in this subgroup
			$('.' + subgroup).prop('checked', isChecked);

			// Update parent group toggle state
			const $firstCheckbox = $('.' + subgroup).first();
			if ($firstCheckbox.length > 0) {
				const group = $firstCheckbox.data('group');
				updateToggleAllState(group);
			}
		});
	}

	/**
	 * Handle individual ability toggles to update "Toggle All" state.
	 */
	function handleIndividualToggles() {
		$('.ability-checkbox').on('change', function () {
			const $checkbox = $(this);
			const group = $checkbox.data('group');

			// Update parent group toggle state
			updateToggleAllState(group);

			// If this is part of a subgroup, update subgroup toggle state
			if ($checkbox.hasClass('ability-checkbox-subgroup')) {
				const subgroupClass = getSubgroupClass($checkbox);
				if (subgroupClass) {
					updateSubgroupToggleState(subgroupClass);
				}
			}
		});

		// Initialize toggle all states on page load
		const groups = [];
		$('.toggle-all-abilities').each(function () {
			const group = $(this).data('group');
			if (groups.indexOf(group) === -1) {
				groups.push(group);
				updateToggleAllState(group);
			}
		});

		// Initialize subgroup toggle states
		$('.toggle-subgroup-abilities').each(function () {
			const subgroup = $(this).data('subgroup');
			updateSubgroupToggleState(subgroup);
		});
	}

	/**
	 * Get the subgroup class from a checkbox element.
	 *
	 * @param {jQuery} $checkbox The checkbox element.
	 * @return {string|null} The subgroup class or null.
	 */
	function getSubgroupClass($checkbox) {
		const classes = $checkbox.attr('class').split(/\s+/);
		for (let i = 0; i < classes.length; i++) {
			if (classes[i].startsWith('subgroup-')) {
				return classes[i];
			}
		}
		return null;
	}

	/**
	 * Update the "Toggle All" checkbox state based on individual checkboxes.
	 *
	 * @param {string} group The group identifier.
	 */
	function updateToggleAllState(group) {
		const $groupCheckboxes = $('.ability-checkbox[data-group="' + group + '"]');
		const $toggleAll = $('.toggle-all-abilities[data-group="' + group + '"]');

		if ($groupCheckboxes.length === 0) {
			return;
		}

		const totalCheckboxes = $groupCheckboxes.length;
		const checkedCheckboxes = $groupCheckboxes.filter(':checked').length;

		// Set toggle all to checked if all are checked, unchecked otherwise
		$toggleAll.prop('checked', totalCheckboxes === checkedCheckboxes);
	}

	/**
	 * Update the subgroup "Toggle All" checkbox state.
	 *
	 * @param {string} subgroup The subgroup class.
	 */
	function updateSubgroupToggleState(subgroup) {
		const $subgroupCheckboxes = $('.' + subgroup);
		const $toggleAll = $('.toggle-subgroup-abilities[data-subgroup="' + subgroup + '"]');

		if ($subgroupCheckboxes.length === 0) {
			return;
		}

		const totalCheckboxes = $subgroupCheckboxes.length;
		const checkedCheckboxes = $subgroupCheckboxes.filter(':checked').length;

		// Set toggle all to checked if all are checked, unchecked otherwise
		$toggleAll.prop('checked', totalCheckboxes === checkedCheckboxes);
	}

	/**
	 * Handle collapse/expand functionality for groups and subgroups.
	 */
	function handleCollapseToggle() {
		// Handle main group collapse
		$('.ability-group-collapse-toggle').on('click', function () {
			const $button = $(this);
			const targetId = $button.attr('aria-controls');
			const $target = $('#' + targetId);
			const isExpanded = $button.attr('aria-expanded') === 'true';

			// Toggle state
			$button.attr('aria-expanded', !isExpanded);
			$target.toggleClass('collapsed');
		});

		// Handle subgroup collapse
		$('.ability-subgroup-collapse-toggle').on('click', function () {
			const $button = $(this);
			const targetId = $button.attr('aria-controls');
			const $target = $('#' + targetId);
			const isExpanded = $button.attr('aria-expanded') === 'true';

			// Toggle state
			$button.attr('aria-expanded', !isExpanded);
			$target.toggleClass('collapsed');
		});
	}

	// Initialize on document ready
	$(document).ready(function () {
		init();
	});

})(jQuery);
