/**
 * Settings page sidebar navigation.
 *
 * Handles section show/hide, hash-based routing, and hash preservation on save.
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
( function () {
	'use strict';

	var NAV_SELECTOR = '.wbgam-settings-nav-item[data-section]';
	var SECTION_SELECTOR = '.wbgam-settings-section';
	var ACTIVE_CLASS = 'is-active';

	function activateSection( sectionId ) {
		// Deactivate all nav items and sections.
		document.querySelectorAll( NAV_SELECTOR ).forEach( function ( el ) {
			el.classList.remove( ACTIVE_CLASS );
		} );
		document.querySelectorAll( SECTION_SELECTOR ).forEach( function ( el ) {
			el.classList.remove( ACTIVE_CLASS );
		} );

		// Activate target nav item and section.
		var navItem = document.querySelector( NAV_SELECTOR + '[data-section="' + sectionId + '"]' );
		var section = document.getElementById( 'section-' + sectionId );

		if ( navItem && section ) {
			navItem.classList.add( ACTIVE_CLASS );
			section.classList.add( ACTIVE_CLASS );
			markGroup( navItem );
		} else {
			// Fallback: activate the first section.
			var firstNav = document.querySelector( NAV_SELECTOR );
			var firstSection = document.querySelector( SECTION_SELECTOR );
			if ( firstNav ) {
				firstNav.classList.add( ACTIVE_CLASS );
			}
			if ( firstSection ) {
				firstSection.classList.add( ACTIVE_CLASS );
			}
		}
	}

	/**
	 * Open the side-menu group holding the current section and move the WordPress submenu
	 * highlight to that group. The server cannot see the #section part of the address, so it
	 * marks Overview on every Settings load; each WordPress entry opens its group's first link.
	 *
	 * @param {Element} navItem Active side-menu link.
	 */
	function markGroup( navItem ) {
		var group = navItem.closest( 'details' );
		var first = group ? group.querySelector( '.wbgam-settings-nav-item' ) : null;
		if ( ! first ) {
			return;
		}
		// Only the group holding the current section stays open: the server cannot see the
		// #section part of the address and rendered the Overview group open.
		document.querySelectorAll( '.wbgam-settings-sidebar details' ).forEach( function ( d ) {
			d.open = d === group;
		} );
		var href   = first.getAttribute( 'href' ) || '';
		var target = first.href;
		if ( 0 === href.indexOf( '#' ) ) {
			target = window.location.origin + window.location.pathname + '?page=wb-gamification' + ( '#dashboard' === href ? '' : href );
		}
		document.querySelectorAll( '#toplevel_page_wb-gamification .wp-submenu a' ).forEach( function ( a ) {
			var on = a.href === target;
			a.classList.toggle( 'current', on );
			if ( a.parentElement ) {
				a.parentElement.classList.toggle( 'current', on );
			}
			if ( on ) {
				a.setAttribute( 'aria-current', 'page' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		} );
	}

	function getHashSection() {
		var hash = window.location.hash.replace( '#', '' );
		return hash || '';
	}

	function init() {
		// Click + keyboard handler for sidebar nav items with data-section (hash-based).
		document.querySelectorAll( NAV_SELECTOR ).forEach( function ( item ) {
			var activate = function ( e ) {
				e.preventDefault();
				var sectionId = item.getAttribute( 'data-section' );
				activateSection( sectionId );
				history.replaceState( null, '', '#' + sectionId );
			};
			item.addEventListener( 'click', activate );
			item.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					activate( e );
				}
			} );
		} );

		// On load, activate section from hash or default to first.
		var hashSection = getHashSection();
		if ( hashSection ) {
			activateSection( hashSection );
		} else {
			var firstNav = document.querySelector( NAV_SELECTOR );
			if ( firstNav ) {
				activateSection( firstNav.getAttribute( 'data-section' ) );
			}
		}

		// Browser back/forward.
		window.addEventListener( 'hashchange', function () {
			var section = getHashSection();
			if ( section ) {
				activateSection( section );
			}
		} );

		// On form submit, inject hash into _wp_http_referer so WP redirects back to the same section.
		document.querySelectorAll( '.wbgam-settings-section form' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function () {
				var referer = form.querySelector( 'input[name="_wp_http_referer"]' );
				if ( referer && window.location.hash ) {
					// Strip any existing hash and append current one.
					referer.value = referer.value.replace( /#.*$/, '' ) + window.location.hash;
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
