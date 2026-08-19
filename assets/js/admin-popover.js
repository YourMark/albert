/**
 * Info Popover
 *
 * Click-to-toggle popover for info trigger buttons. Placement is the
 * stylesheet's job (.albert-info in albert-primitives.css); this only opens,
 * closes and manages focus.
 *
 * @package Albert
 * @since   1.0.0
 */
(function() {
	var sel = '.albert-tip__trigger[aria-expanded="true"]';

	function closeAll() {
		document.querySelectorAll(sel).forEach(function(t) {
			t.setAttribute('aria-expanded', 'false');
			var p = t.nextElementSibling;
			if (p) p.hidden = true;
		});
	}

	document.addEventListener('click', function(e) {
		var trigger = e.target.closest('.albert-tip__trigger');
		if (trigger) {
			var popover = trigger.nextElementSibling;
			if (!popover || !popover.classList.contains('albert-tip__popover')) return;
			var isOpen = trigger.getAttribute('aria-expanded') === 'true';
			closeAll();
			if (!isOpen) {
				trigger.setAttribute('aria-expanded', 'true');
				popover.hidden = false;
			}
			return;
		}
		if (!e.target.closest('.albert-tip__popover')) {
			closeAll();
		}
	});

	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			var open = document.querySelector(sel);
			closeAll();
			if (open) open.focus();
		}
	});
})();
