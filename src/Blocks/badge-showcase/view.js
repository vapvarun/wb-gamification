/**
 * Badge Showcase — filter tabs (All / Earned / Locked).
 *
 * Not a modal. The badge-showcase block renders a `role="tablist"`
 * navigation that switches between three filter views in-place; there
 * is no full-screen / overlay surface to dismiss. ESC / focus-trap
 * a11y handling is therefore not applicable — the tabs are native
 * <button role="tab"> elements with keyboard parity guaranteed by the
 * browser.
 *
 * The block is sometimes rendered inside the hub block's flyout panel,
 * which uses the WordPress Interactivity API directive
 * `data-wp-on--click="actions.stopPropagation"` on its dialog wrapper
 * to keep panel-internal clicks from closing the backdrop. That kills
 * document-level delegation, so we wire listeners directly to each
 * showcase root the moment it mounts. The wrapping hub panel itself
 * IS the dialog, with its own ESC + focus management (see
 * assets/interactivity/hub.js callbacks.init); this filter-tab handler
 * does not need to duplicate that logic.
 *
 * MutationObserver watches the whole body so the hub panel's template
 * clone is bound the instant it lands in `.gam-panel__body`.
 *
 * @since 1.4.0
 */

( function () {
	'use strict';

	var BOUND = '__wbGamBadgeBound';

	function bindRoot( root ) {
		if ( ! root || root[ BOUND ] ) {
			return;
		}
		root[ BOUND ] = true;
		// keyboard-accessible: filter tabs are native <button role="tab"> elements (render.php:218,223,228).
		root.addEventListener( 'click', function ( event ) {
			var tab = event.target.closest( '[data-wb-gam-filter]' );
			if ( ! tab || ! root.contains( tab ) ) {
				return;
			}
			var value = tab.getAttribute( 'data-wb-gam-filter' );
			if ( ! value ) {
				return;
			}
			root.setAttribute( 'data-filter', value );
			root.querySelectorAll( '[data-wb-gam-filter]' ).forEach( function ( other ) {
				var active = other === tab;
				other.classList.toggle( 'is-active', active );
				other.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );
		} );
	}

	function bindAll() {
		document.querySelectorAll( '[data-wb-gam-badge-showcase]' ).forEach( bindRoot );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bindAll );
	} else {
		bindAll();
	}

	// Catch late-mounted instances (hub flyout template clone).
	if ( typeof MutationObserver !== 'undefined' ) {
		var observer = new MutationObserver( function () {
			bindAll();
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}
}() );
