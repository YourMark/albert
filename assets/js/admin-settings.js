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
