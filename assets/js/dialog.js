/**
 * The one dialog in this plugin.
 *
 * There were four overlay surfaces and no shared utility, which meant four different answers to the
 * same questions: does ESC close it, is focus trapped inside it, does focus come back to the button
 * that opened it, is it announced at all?
 *
 *   - hub-convert used a native <dialog> and showModal(). That one was right.
 *   - the redemption-store confirm panel was role="dialog" aria-modal="FALSE" -- a div wearing a
 *     dialog's clothes. Nothing trapped focus, so a keyboard user could tab straight out of the
 *     confirmation and into the page behind it, while a screen reader was told this was a modal.
 *   - the celebration overlay and the toast stack are not dialogs at all; they are announcements, and
 *     they need aria-live, not a focus trap. Treating them as dialogs would trap a member inside a
 *     congratulation.
 *
 * So: ONE utility, and it is a thin one, because the platform already does the hard parts.
 * `<dialog>.showModal()` gives ESC, a focus trap, and an inert background for free. Everything below
 * is the part the platform does NOT do: returning focus to whatever opened the dialog, closing on a
 * backdrop click, and making sure the thing has an accessible name.
 *
 * Rolling our own focus trap would be more code AND worse. The browsers already ship one.
 *
 * @package WB_Gamification
 * @since   1.6.4
 */
( function ( window, document ) {
	'use strict';

	/**
	 * Resolve where focus should go when the dialog closes.
	 *
	 * `opener` may be an Element OR a function returning one, and that is not over-engineering — it is
	 * the difference between focus return working and not.
	 *
	 * The redemption dialog lives inside an Interactivity API block, which RE-RENDERS the Redeem button
	 * when its state changes. So the button element captured at open time is detached from the document
	 * by the time the dialog closes, `document.contains()` says no, and focus is silently left inside a
	 * closed dialog — which is exactly where a keyboard user cannot escape from.
	 *
	 * Passing a function lets the caller re-find its button at CLOSE time, against the DOM as it
	 * actually is then.
	 *
	 * @param {Element|Function|null} opener Element, or a function returning one.
	 * @return {Element|null}
	 */
	function resolveOpener( opener ) {
		var el = typeof opener === 'function' ? opener() : opener;

		if ( ! el || typeof el.focus !== 'function' || ! document.contains( el ) ) {
			return null;
		}

		// A hidden element cannot take focus, and focus() on one fails SILENTLY — which is how focus
		// ends up stranded with nothing in the console to tell you.
		if ( el.hidden || el.offsetParent === null ) {
			return null;
		}

		return el;
	}

	/**
	 * Open a dialog modally.
	 *
	 * @param {HTMLDialogElement} dialog  The dialog.
	 * @param {Object}            options Optional. `opener` (Element or function returning one — focus
	 *                                    returns there), `initialFocus` (selector focused on open).
	 * @return {boolean} True when it opened.
	 */
	function open( dialog, options ) {
		if ( ! dialog || typeof dialog.showModal !== 'function' ) {
			return false;
		}

		var settings = options || {};

		// Where focus goes when we close. Default: whatever had it when we opened — which is almost
		// always the button the member just pressed, and is exactly where they expect to be put back.
		dialog._wbGamOpener = settings.opener || document.activeElement;

		if ( typeof settings.onClose === 'function' ) {
			dialog._wbGamOnClose = settings.onClose;
		}

		if ( dialog.open ) {
			return true;
		}

		dialog.showModal();

		var target = settings.initialFocus
			? dialog.querySelector( settings.initialFocus )
			: dialog.querySelector( '[autofocus], input, select, textarea, button' );

		if ( target && typeof target.focus === 'function' ) {
			target.focus();
		}

		return true;
	}

	/**
	 * Close a dialog and put focus back where it came from.
	 *
	 * The platform does NOT do this part. Without it, closing a dialog dumps focus at the top of the
	 * document, and a keyboard user has to tab all the way back to where they were.
	 *
	 * @param {HTMLDialogElement} dialog The dialog.
	 * @return {void}
	 */
	function close( dialog ) {
		if ( ! dialog || typeof dialog.close !== 'function' ) {
			return;
		}

		// Closing fires the native `close` event, and returnFocus() runs from there — so this does not
		// duplicate it. If the dialog was already closed, do it directly.
		if ( dialog.open ) {
			dialog.close();
			return;
		}

		returnFocus( dialog );
	}

	/**
	 * Tell the caller we closed, wait for them to re-render, THEN put focus back.
	 *
	 * The order matters, and getting it wrong is invisible until you try it with a keyboard.
	 *
	 * The redemption block hides its Redeem button while the confirmation is open
	 * (`data-wp-bind--hidden="context.confirming"`). So at the instant the dialog closes, the element we
	 * want to focus is still hidden — and calling focus() on a hidden element does nothing at all,
	 * silently. Focus stays stranded inside a dialog that is no longer on screen, which is the exact
	 * trap this whole utility exists to prevent.
	 *
	 * So: fire onClose (the block sets its state back), let the framework re-render on the next frame,
	 * and only then focus. Belt and braces, we also fall back to the document if the opener is gone.
	 *
	 * @param {HTMLDialogElement} dialog The dialog.
	 * @return {void}
	 */
	function returnFocus( dialog ) {
		var opener = dialog._wbGamOpener;

		dialog._wbGamOpener = null;

		if ( typeof dialog._wbGamOnClose === 'function' ) {
			dialog._wbGamOnClose();
		}

		window.requestAnimationFrame( function () {
			var el = resolveOpener( opener );

			if ( el ) {
				el.focus();
			}
		} );
	}

	/**
	 * Wire a dialog once: backdrop click, [data-wb-gam-dialog-close] buttons, and focus return on any
	 * close (including the ESC key, which the browser handles without telling us first).
	 *
	 * @param {HTMLDialogElement} dialog The dialog.
	 * @return {void}
	 */
	function bind( dialog ) {
		if ( ! dialog || dialog._wbGamDialogBound ) {
			return;
		}

		dialog._wbGamDialogBound = true;

		// ESC is handled by the browser and fires `close` without asking us first — so this is where
		// focus gets returned for it, and where a caller finds out the dialog went away.
		dialog.addEventListener( 'close', function () {
			returnFocus( dialog );
		} );

		// A click on the backdrop lands on the dialog element itself, not on its contents.
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				close( dialog );
			}
		} );

		dialog.querySelectorAll( '[data-wb-gam-dialog-close]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				close( dialog );
			} );
		} );
	}

	/**
	 * Bind every dialog on the page, and every opener that points at one.
	 *
	 * An opener is any element with `data-wb-gam-dialog-open="<dialog-id>"`.
	 *
	 * @return {void}
	 */
	function init() {
		document.querySelectorAll( 'dialog[data-wb-gam-dialog]' ).forEach( bind );

		document.addEventListener( 'click', function ( event ) {
			var opener = event.target.closest( '[data-wb-gam-dialog-open]' );
			if ( ! opener ) {
				return;
			}

			var dialog = document.getElementById( opener.getAttribute( 'data-wb-gam-dialog-open' ) );
			if ( ! dialog ) {
				return;
			}

			event.preventDefault();
			bind( dialog );
			open( dialog, { opener: opener } );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.wbGam        = window.wbGam || {};
	window.wbGam.dialog = {
		open: open,
		close: close,
		bind: bind,
	};
} )( window, document );
