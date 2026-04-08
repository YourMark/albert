/**
 * Albert Admin Settings Scripts
 *
 * @package Albert
 * @since   1.0.0
 */

/**
 * Dirty state tracking — warns users about unsaved changes.
 */
const DirtyStateModule = {
	isDirty: false,

	init() {
		this.form = document.getElementById( 'albert-form' );
		if ( ! this.form ) {
			return;
		}

		this.saveButtons = document.querySelectorAll( '#submit, #submit-mobile' );

		this.form.addEventListener( 'change', () => {
			this.markDirty();
		} );

		this.form.addEventListener( 'submit', () => {
			this.isDirty = false;
		} );

		window.addEventListener( 'beforeunload', ( e ) => {
			if ( this.isDirty ) {
				e.preventDefault();
			}
		} );
	},

	markDirty() {
		if ( this.isDirty ) {
			return;
		}
		this.isDirty = true;

		this.saveButtons.forEach( ( btn ) => {
			btn.classList.add( 'albert-save-dirty' );
		} );
	},
};

/**
 * Flat abilities list: filtering, view toggle, pagination, row expand,
 * destructive-confirmation, and stats updates.
 *
 * Every ability row is rendered once by the server inside #albert-abilities-list.
 * All navigation is client-side via the `hidden` attribute so form submit still
 * includes every row regardless of the current filter/page.
 */
const AbilitiesListModule = {
	init() {
		this.list = document.getElementById( 'albert-abilities-list' );
		if ( ! this.list ) {
			return;
		}

		this.rows = Array.from( this.list.querySelectorAll( '.ability-row' ) );
		this.emptyState = this.list.querySelector( '.albert-abilities-empty' );
		this.searchInput = document.getElementById( 'albert-abilities-search' );
		this.categoryFilter = document.getElementById( 'albert-abilities-filter-category' );
		this.supplierFilter = document.getElementById( 'albert-abilities-filter-supplier' );
		this.statsNode = document.getElementById( 'albert-abilities-stats' );
		this.pagination = document.querySelector( '.albert-abilities-pagination' );
		this.pagesNode = this.pagination ? this.pagination.querySelector( '.albert-pagination-pages' ) : null;
		this.viewButtons = Array.from( document.querySelectorAll( '.albert-view-toggle-btn' ) );

		this.total = parseInt( this.statsNode?.dataset.total || String( this.rows.length ), 10 );
		this.enabled = parseInt( this.statsNode?.dataset.enabled || '0', 10 );
		this.statsTemplate = this.statsNode?.dataset.templateAll || 'Showing %1$s of %2$s · %3$s enabled';

		// Initial view mode + rows-per-page come from data attributes the
		// server rendered, so the page paints in the correct state with no
		// flicker or JS-driven re-layout. localStorage is intentionally NOT
		// consulted — the preference lives in wp_options.
		this.viewMode = AbilitiesListModule.normalizeViewMode( this.list.dataset.viewMode );
		this.rowsPerPage = parseInt( this.list.dataset.rowsPerPage, 10 );
		this.currentPage = 1;

		this.bindSearch();
		this.bindFilters();
		this.bindViewToggle();
		this.bindRowExpand();
		this.bindRowToggle();
		this.bindPagination();
		this.bindChipDismiss();

		// Server already pre-rendered the correct view mode (toggle button
		// state, pagination nav visibility, rows beyond page 1 hidden when
		// paginated), so we don't call applyViewMode on init — calling it
		// would trigger renderPaginationWindow() which writes `hidden` on
		// every row and causes the visible flash we're trying to avoid.
		// We do still need an initial filter/stats pass to set things like
		// the enabled count and the pagination pager numbers.
		if ( 'paginated' === this.viewMode ) {
			this.renderPaginationWindow();
		} else {
			this.updateStats( this.rows.length );
		}
	},

	/**
	 * Coerce an arbitrary string to a valid view mode.
	 *
	 * Mirrors the PHP `AbilitiesPage::normalize_view_mode()` so the two
	 * sides apply identical validation. Anything that isn't `paginated`
	 * collapses to `list`.
	 */
	normalizeViewMode( mode ) {
		return 'paginated' === mode ? 'paginated' : 'list';
	},

	/**
	 * Escape dismisses any visible chip tooltip (WCAG 1.4.13 dismissible).
	 *
	 * Pressing Escape while a chip is focused hides its overlay until the
	 * user moves away; moving focus or pointer away clears the dismissed
	 * state so the next hover/focus shows the tooltip again.
	 */
	bindChipDismiss() {
		if ( ! this.list ) {
			return;
		}

		this.list.addEventListener( 'keydown', ( e ) => {
			if ( e.key !== 'Escape' ) {
				return;
			}
			const chip = e.target.closest( '.ability-chip' );
			if ( ! chip ) {
				return;
			}
			chip.classList.add( 'is-dismissed' );
		} );

		// Clear the dismissed state when the chip loses focus/hover, so the
		// tooltip is available again the next time the user lands on it.
		this.list.addEventListener(
			'focusout',
			( e ) => {
				const chip = e.target.closest( '.ability-chip' );
				if ( chip ) {
					chip.classList.remove( 'is-dismissed' );
				}
			},
			true
		);

		this.list.addEventListener(
			'mouseleave',
			( e ) => {
				const chip = e.target.closest( '.ability-chip' );
				if ( chip ) {
					chip.classList.remove( 'is-dismissed' );
				}
			},
			true
		);
	},

	/**
	 * Persist the view-mode preference to wp_options via admin-ajax.
	 *
	 * Fire-and-forget — failures are logged but don't block the UI. The
	 * preference is server-rendered on next page load, so a failed save
	 * just means the current session keeps the new mode but the next page
	 * load reverts to the previous one.
	 */
	saveViewMode( mode ) {
		const cfg = window.albertAdmin || {};
		if ( ! cfg.ajaxUrl || ! cfg.viewModeNonce ) {
			return;
		}
		const body = new URLSearchParams();
		body.set( 'action', 'albert_save_view_mode' );
		body.set( 'nonce', cfg.viewModeNonce );
		body.set( 'mode', mode );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} ).catch( ( err ) => {
			// eslint-disable-next-line no-console
			console.warn( 'Albert: failed to persist view mode', err );
		} );
	},

	bindSearch() {
		if ( ! this.searchInput ) {
			return;
		}
		let searchDebounceTimer;
		this.searchInput.addEventListener( 'input', () => {
			clearTimeout( searchDebounceTimer );
			searchDebounceTimer = setTimeout( () => {
				this.currentPage = 1;
				this.applyFilters();
			}, 120 );
		} );
	},

	bindFilters() {
		[ this.categoryFilter, this.supplierFilter ].forEach( ( select ) => {
			if ( ! select ) {
				return;
			}
			select.addEventListener( 'change', () => {
				this.currentPage = 1;
				this.applyFilters();
			} );
		} );
	},

	bindViewToggle() {
		this.viewButtons.forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				this.applyViewMode( AbilitiesListModule.normalizeViewMode( btn.dataset.view ) );
			} );
		} );
	},

	bindRowExpand() {
		this.list.addEventListener( 'click', ( e ) => {
			const button = e.target.closest( '.ability-row-expand' );
			if ( ! button ) {
				return;
			}
			const row = button.closest( '.ability-row' );
			const targetId = button.getAttribute( 'aria-controls' );
			const target = targetId ? document.getElementById( targetId ) : null;
			if ( ! row || ! target ) {
				return;
			}
			const isExpanded = button.getAttribute( 'aria-expanded' ) === 'true';
			button.setAttribute( 'aria-expanded', String( ! isExpanded ) );
			target.hidden = isExpanded;
			row.classList.toggle( 'is-expanded', ! isExpanded );
		} );
	},

	bindRowToggle() {
		this.list.addEventListener( 'change', ( e ) => {
			const checkbox = e.target.closest( '.ability-row-checkbox' );
			if ( ! checkbox ) {
				return;
			}
			const row = checkbox.closest( '.ability-row' );
			const i18n = window.albertAdmin?.i18n || {};
			const confirmText = i18n.destructiveConfirm || 'This ability can permanently delete data. Are you sure you want to enable it?';

			if ( checkbox.checked && row?.dataset.destructive === '1' ) {
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( confirmText ) ) {
					checkbox.checked = false;
					return;
				}
			}

			// Update enabled count without a full re-filter.
			if ( checkbox.checked ) {
				this.enabled += 1;
			} else {
				this.enabled -= 1;
			}
			this.updateStats();
		} );
	},

	bindPagination() {
		if ( ! this.pagination ) {
			return;
		}
		this.pagination.addEventListener( 'click', ( e ) => {
			const button = e.target.closest( 'button[data-direction], button[data-page]' );
			if ( ! button ) {
				return;
			}
			if ( button.dataset.direction === 'prev' ) {
				this.currentPage = Math.max( 1, this.currentPage - 1 );
			} else if ( button.dataset.direction === 'next' ) {
				this.currentPage = Math.min( this.totalPages(), this.currentPage + 1 );
			} else if ( button.dataset.page ) {
				this.currentPage = parseInt( button.dataset.page, 10 );
			}
			this.renderPaginationWindow();
		} );
	},

	applyViewMode( mode ) {
		this.viewMode = mode;
		this.viewButtons.forEach( ( btn ) => {
			const active = btn.dataset.view === mode;
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-pressed', String( active ) );
		} );
		if ( this.pagination ) {
			this.pagination.hidden = mode !== 'paginated';
		}
		this.saveViewMode( mode );
		this.currentPage = 1;
		this.renderPaginationWindow();
	},

	applyFilters() {
		const query = ( this.searchInput?.value || '' ).trim().toLowerCase();
		const categoryFilter = this.categoryFilter?.value || '';
		const supplierFilter = this.supplierFilter?.value || '';

		this.rows.forEach( ( row ) => {
			const haystack = row.dataset.search || '';
			const matchesSearch = '' === query || haystack.includes( query );
			const matchesCategory = '' === categoryFilter || row.dataset.category === categoryFilter;
			const matchesSupplier = '' === supplierFilter || row.dataset.supplier === supplierFilter;

			const visible = matchesSearch && matchesCategory && matchesSupplier;
			if ( visible ) {
				row.classList.remove( 'is-filtered-out' );
			} else {
				row.classList.add( 'is-filtered-out' );
			}
		} );

		this.renderPaginationWindow();
	},

	filteredRows() {
		return this.rows.filter( ( row ) => ! row.classList.contains( 'is-filtered-out' ) );
	},

	totalPages() {
		const visible = this.filteredRows().length;
		return Math.max( 1, Math.ceil( visible / this.rowsPerPage ) );
	},

	renderPaginationWindow() {
		const visible = this.filteredRows();

		// In list mode, show every filtered row and hide the rest.
		if ( this.viewMode !== 'paginated' ) {
			this.rows.forEach( ( row ) => {
				const isFilteredOut = row.classList.contains( 'is-filtered-out' );
				row.hidden = isFilteredOut;
			} );
			this.toggleEmptyState( visible.length === 0 );
			this.updateStats( visible.length );
			return;
		}

		const pages = this.totalPages();
		if ( this.currentPage > pages ) {
			this.currentPage = pages;
		}
		const start = ( this.currentPage - 1 ) * this.rowsPerPage;
		const end = start + this.rowsPerPage;

		// Hide everything first, then reveal the current slice.
		this.rows.forEach( ( row ) => {
			row.hidden = true;
		} );
		visible.slice( start, end ).forEach( ( row ) => {
			row.hidden = false;
		} );

		this.toggleEmptyState( visible.length === 0 );
		this.updateStats( visible.length );
		this.renderPager( pages );
	},

	renderPager( pages ) {
		if ( ! this.pagesNode ) {
			return;
		}
		this.pagesNode.innerHTML = '';

		for ( let i = 1; i <= pages; i++ ) {
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button albert-pagination-page';
			btn.textContent = String( i );
			btn.dataset.page = String( i );
			if ( i === this.currentPage ) {
				btn.classList.add( 'is-current' );
				btn.setAttribute( 'aria-current', 'page' );
			}
			this.pagesNode.appendChild( btn );
		}

		const prev = this.pagination.querySelector( '.albert-pagination-prev' );
		const next = this.pagination.querySelector( '.albert-pagination-next' );
		if ( prev ) {
			prev.disabled = this.currentPage <= 1;
		}
		if ( next ) {
			next.disabled = this.currentPage >= pages;
		}
	},

	toggleEmptyState( isEmpty ) {
		if ( this.emptyState ) {
			this.emptyState.hidden = ! isEmpty;
		}
	},

	/**
	 * Update the stats line.
	 *
	 * The stats node is `aria-live="polite"`, so every textContent change
	 * would queue a screen-reader announcement — spammy while the user is
	 * typing in the search box. We split the write into two steps:
	 *   - The visible text is updated immediately (sighted users need to
	 *     see the filter results live).
	 *   - The announcement is debounced (~400ms): we temporarily remove
	 *     aria-live while updating, then restore it after a short delay so
	 *     only the settled value is announced.
	 */
	updateStats( visibleCount ) {
		if ( ! this.statsNode ) {
			return;
		}
		const visible = typeof visibleCount === 'number' ? visibleCount : this.filteredRows().length;
		const text = this.statsTemplate
			.replace( '%1$s', String( visible ) )
			.replace( '%2$s', String( this.total ) )
			.replace( '%3$s', String( this.enabled ) );

		// Suppress the live announcement while the value is changing.
		this.statsNode.setAttribute( 'aria-live', 'off' );
		this.statsNode.textContent = text;

		clearTimeout( this.statsAnnounceTimer );
		this.statsAnnounceTimer = setTimeout( () => {
			this.statsNode.setAttribute( 'aria-live', 'polite' );
		}, 400 );
	},
};

/**
 * Clipboard functionality for copy buttons and text.
 */
const ClipboardModule = {
	init() {
		this.handleCopyText();
		this.handleCopyButton();
	},

	async copyToClipboard( text ) {
		try {
			await navigator.clipboard.writeText( text );
			return true;
		} catch {
			const textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.select();
			document.execCommand( 'copy' );
			document.body.removeChild( textarea );
			return true;
		}
	},

	showCopiedFeedback( element, originalText = null ) {
		const i18n = window.albertAdmin?.i18n || { copied: 'Copied!' };

		const liveRegion = document.getElementById( 'albert-copy-status' );
		if ( liveRegion ) {
			liveRegion.textContent = i18n.copied;
		}

		if ( originalText !== null ) {
			element.textContent = i18n.copied;
			element.classList.add( 'copied' );

			setTimeout( () => {
				element.textContent = originalText;
				element.classList.remove( 'copied' );
			}, 2000 );
		} else {
			element.classList.add( 'copied' );
			element.setAttribute( 'data-copied', i18n.copied );

			setTimeout( () => {
				element.classList.remove( 'copied' );
				element.removeAttribute( 'data-copied' );
			}, 2000 );
		}
	},

	handleCopyText() {
		document.addEventListener( 'click', async ( e ) => {
			const copyText = e.target.closest( '.albert-copy-text' );
			if ( ! copyText ) {
				return;
			}

			const text = copyText.textContent.trim();
			await this.copyToClipboard( text );
			this.showCopiedFeedback( copyText );
		} );
	},

	handleCopyButton() {
		document.addEventListener( 'click', async ( e ) => {
			const button = e.target.closest( '.albert-copy-button' );
			if ( ! button ) {
				return;
			}

			const targetId = button.dataset.copyTarget;
			const target = document.getElementById( targetId );
			if ( ! target ) {
				return;
			}

			const text = target.value !== undefined && null !== target.value ? target.value : target.textContent.trim();
			const originalText = button.textContent;

			await this.copyToClipboard( text );
			this.showCopiedFeedback( button, originalText );
		} );
	},
};

/**
 * Disconnect dialog — populates and shows a native dialog for disconnect actions.
 */
const DisconnectModule = {
	init() {
		this.dialog = document.getElementById( 'albert-disconnect-dialog' );
		if ( ! this.dialog ) {
			return;
		}

		this.title = document.getElementById( 'albert-disconnect-dialog-title' );
		this.connLink = document.getElementById( 'albert-disconnect-connection' );
		this.sessLink = document.getElementById( 'albert-disconnect-session' );

		document.addEventListener( 'click', ( e ) => {
			const trigger = e.target.closest( '.albert-disconnect-trigger' );
			if ( ! trigger ) {
				return;
			}

			e.preventDefault();

			this.title.textContent = 'Disconnect ' + ( trigger.dataset.clientName || '' ) + '?';
			this.connLink.href = trigger.dataset.revokeUrl;
			this.sessLink.href = trigger.dataset.revokeFullUrl;

			this.dialog.showModal();
		} );

		this.dialog.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '.albert-disconnect-dialog-close' ) || e.target.closest( '.albert-disconnect-cancel' ) ) {
				this.dialog.close();
			}
		} );

		this.dialog.addEventListener( 'click', ( e ) => {
			if ( e.target === this.dialog ) {
				this.dialog.close();
			}
		} );
	},
};

/**
 * Initialize a live region for screen reader announcements.
 */
function initLiveRegion() {
	if ( document.getElementById( 'albert-copy-status' ) ) {
		return;
	}
	const liveRegion = document.createElement( 'div' );
	liveRegion.setAttribute( 'aria-live', 'polite' );
	liveRegion.setAttribute( 'aria-atomic', 'true' );
	liveRegion.setAttribute( 'role', 'status' );
	liveRegion.className = 'screen-reader-text';
	liveRegion.id = 'albert-copy-status';
	document.body.appendChild( liveRegion );
}

/**
 * Initialize all modules when DOM is ready.
 */
function init() {
	initLiveRegion();
	AbilitiesListModule.init();
	ClipboardModule.init();
	DirtyStateModule.init();
	DisconnectModule.init();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
