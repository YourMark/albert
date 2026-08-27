/**
 * Albert Admin Settings Scripts
 *
 * @package Albert
 * @since   1.0.0
 */

/**
 * Clipboard bindings for inline copy-text spans and explicit copy buttons.
 *
 * Both flows share `Albert.clipboard` (copy + flashButton). Inline text
 * uses a `data-copied` attribute (CSS renders a tooltip); buttons swap
 * their label for the duration of the feedback.
 */
const ClipboardModule = {
	init() {
		document.addEventListener( 'click', async ( e ) => {
			const copyText = e.target.closest( '.albert-copy-text' );
			if ( copyText ) {
				const ok = await Albert.clipboard.copy( copyText.textContent.trim() );
				if ( ok ) {
					Albert.clipboard.flashButton( copyText, { label: ClipboardModule.label() } );
				}
				return;
			}

			const button = e.target.closest( '.albert-copy-button' );
			if ( ! button ) {
				return;
			}

			const target = document.getElementById( button.dataset.copyTarget );
			if ( ! target ) {
				return;
			}

			const text = target.value !== undefined && null !== target.value
				? target.value
				: target.textContent.trim();

			const ok = await Albert.clipboard.copy( text );
			if ( ok ) {
				Albert.clipboard.flashButton( button, { label: ClipboardModule.label(), swap: true } );
			}
		} );
	},

	label() {
		return window.albertAdmin?.i18n?.copied || 'Copied!';
	},
};

/**
 * Disconnect dialog: populates and shows a native dialog for disconnect actions.
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

			// Remember who opened it: a native dialog traps focus while it is
			// open but does not put focus back when it closes.
			this.opener = trigger;

			const template =
				window.albertAdmin?.i18n?.disconnectTitle || 'Disconnect %s?';
			this.title.textContent = template.replace(
				'%s',
				trigger.dataset.clientName || ''
			);
			this.connLink.href = trigger.dataset.revokeUrl;
			this.sessLink.href = trigger.dataset.revokeFullUrl;

			this.dialog.showModal();
		} );

		this.dialog.addEventListener( 'close', () => {
			if ( this.opener && document.contains( this.opener ) ) {
				this.opener.focus();
			}
			this.opener = null;
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
 * Initialize all modules when DOM is ready.
 */
function init() {
	Albert.liveRegion.ensure();
	ClipboardModule.init();
	DisconnectModule.init();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}

/**
 * Dismissing an attention item on the Dashboard.
 *
 * The row is removed optimistically and put back if the request fails, because
 * the alternative is a button that appears to do nothing for a round trip. When
 * the last item goes, the card swaps to its empty state rather than emptying
 * out: a card with a heading and nothing under it reads as broken.
 */
( function () {
	'use strict';

	var card = document.querySelector( '.albert-attention' );

	if ( ! card || typeof window.albertAdmin === 'undefined' ) {
		return;
	}

	card.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-albert-dismiss-attention]' );

		if ( ! button ) {
			return;
		}

		var item = button.closest( '.albert-attention__item' );
		var id = button.getAttribute( 'data-albert-dismiss-attention' );

		if ( ! item || ! id ) {
			return;
		}

		var list = item.parentNode;
		var next = item.nextElementSibling;

		button.disabled = true;
		item.remove();

		if ( ! list.querySelector( '.albert-attention__item' ) ) {
			showEmptyState();
		}

		var body = new FormData();
		body.append( 'action', 'albert_dismiss_attention' );
		body.append( 'nonce', window.albertAdmin.dismissNonce );
		body.append( 'id', id );

		window
			.fetch( window.albertAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.ok ? response.json() : { success: false };
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					throw new Error( 'dismiss failed' );
				}
			} )
			.catch( function () {
				// Put it back exactly where it was, so the page still describes
				// the site truthfully.
				button.disabled = false;
				list.insertBefore( item, next );
			} );
	} );

	function showEmptyState() {
		var body = card.querySelector( '.albert-card__body' );
		var description = card.querySelector( '.albert-card__description' );

		if ( description ) {
			description.remove();
		}

		if ( ! body ) {
			return;
		}

		body.className = 'albert-card__body albert-attention__empty';
		body.innerHTML = '';

		var icon = document.createElement( 'span' );
		icon.className = 'dashicons dashicons-yes-alt';
		icon.setAttribute( 'aria-hidden', 'true' );

		var text = document.createElement( 'p' );
		text.textContent = card.getAttribute( 'data-empty-text' ) || '';

		body.appendChild( icon );
		body.appendChild( text );
	}
} )();
