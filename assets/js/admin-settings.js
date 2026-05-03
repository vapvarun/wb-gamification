/**
 * WB Gamification — Admin Settings AJAX save + Tab switching.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
(function() {
	'use strict';

	/* ── Tab switching ─────────────────────────────────────────────── */
	document.querySelectorAll( '.wbgam-tab[data-tab]' ).forEach( function( tab ) {
		tab.addEventListener( 'click', function( e ) {
			e.preventDefault();

			document.querySelectorAll( '.wbgam-tab' ).forEach( function( t ) {
				t.classList.remove( 'wbgam-tab--active' );
			} );
			document.querySelectorAll( '.wbgam-tab-panel' ).forEach( function( p ) {
				p.style.display = 'none';
			} );

			tab.classList.add( 'wbgam-tab--active' );
			var panel = document.getElementById( tab.getAttribute( 'data-tab' ) );
			if ( panel ) {
				panel.style.display = 'block';
			}

			// Update URL hash without scrolling.
			history.replaceState( null, '', '#' + tab.getAttribute( 'data-tab' ) );
		} );
	} );

	// Restore tab from URL hash on load.
	var hash = window.location.hash.replace( '#', '' );
	if ( hash ) {
		var active = document.querySelector( '.wbgam-tab[data-tab="' + hash + '"]' );
		if ( active ) {
			active.click();
		}
	}

	/* ── AJAX save ─────────────────────────────────────────────────── */
	document.querySelectorAll( '.wbgam-settings-form' ).forEach( function( form ) {
		form.addEventListener( 'submit', function( e ) {
			e.preventDefault();

			var formData = new FormData( form );
			formData.append( 'action', 'wb_gam_save_settings' );

			var btn = form.querySelector( 'button[type="submit"]' );
			if ( btn ) {
				btn.disabled = true;
				btn.textContent = 'Saving...';
			}

			fetch( ajaxurl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			} )
				.then( function( r ) {
					return r.json();
				} )
				.then( function( data ) {
					if ( btn ) {
						btn.disabled = false;
						btn.textContent = 'Save Settings';
					}
					showToast( data.success ? 'Settings saved' : 'Error saving settings', data.success ? 'success' : 'error' );
				} )
				.catch( function() {
					if ( btn ) {
						btn.disabled = false;
						btn.textContent = 'Save Settings';
					}
					showToast( 'Network error', 'error' );
				} );
		} );
	} );

	/* ── Toast helper ──────────────────────────────────────────────── */
	function showToast( message, type ) {
		var container = document.querySelector( '.wbgam-toast-container' );
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.className = 'wbgam-toast-container';
			document.body.appendChild( container );
		}

		var toast = document.createElement( 'div' );
		toast.className = 'wbgam-toast wbgam-toast--' + ( type || 'success' );
		toast.textContent = message;
		container.appendChild( toast );

		setTimeout( function() {
			toast.style.animation = 'wbgam-slide-out 0.3s ease forwards';
			setTimeout( function() {
				toast.remove();
			}, 300 );
		}, 4000 );
	}

	/* ── Toggle all in category ────────────────────────────────────── */
	document.querySelectorAll( '.wbgam-category-toggle' ).forEach( function( toggle ) {
		toggle.addEventListener( 'change', function() {
			var card = toggle.closest( '.wbgam-card' );
			if ( card ) {
				card.querySelectorAll( '.wbgam-toggle input' ).forEach( function( input ) {
					input.checked = toggle.checked;
				} );
			}
		} );
	} );

	/* ── Confirm-before-submit (replaces inline onclick="return confirm(...)") ─ *
	 * Uses the shared promise-based modal from admin-rest-utils.js when
	 * available; falls back to a one-frame async cycle so the form re-submits
	 * after the user confirms. */
	document.addEventListener(
		'submit',
		function( e ) {
			var form = e.target.closest( 'form[data-wb-gam-confirm]' );
			if ( ! form ) {
				return;
			}
			// If we already accepted on this form (re-entrant submit), let it through.
			if ( form.dataset.wbGamConfirmed === '1' ) {
				delete form.dataset.wbGamConfirmed;
				return;
			}
			e.preventDefault();
			var prompt = form.getAttribute( 'data-wb-gam-confirm' ) || 'Are you sure?';
			var modal  = window.wbGamAdminRest && window.wbGamAdminRest.confirmAction;
			Promise.resolve( modal ? modal({ message: prompt, tone: 'danger' }) : true ).then( function ( ok ) {
				if ( ok ) {
					form.dataset.wbGamConfirmed = '1';
					form.submit();
				}
			} );
		},
		true
	);

	/* Keyboard parity for the tab switcher above (was click-only). */
	document.querySelectorAll( '.wbgam-tab[data-tab]' ).forEach( function( tab ) {
		tab.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				tab.click();
			}
		} );
	} );
})();
