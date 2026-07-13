/**
 * Admin user picker — a searchable, REST-backed replacement for wp_dropdown_users().
 *
 * wp_dropdown_users() has no `number` argument in its default form: it loads EVERY
 * user on the site and renders one <option> each. On a 100k-member community that is
 * a full scan of wp_users and a multi-megabyte <select> — the page simply does not
 * return. It is also unusable long before that: nobody finds a person by scrolling
 * 100,000 options.
 *
 * This queries GET /members?search=&per_page=20 (already paginated and indexed) as
 * the admin types, so the cost is bounded by what they asked for rather than by how
 * many members exist.
 *
 * Progressive enhancement: the <select> carries the real `user_id` the form submits,
 * so if this script fails to load the field is still a valid (empty) control that
 * blocks submission rather than silently awarding points to the wrong person.
 */
( function () {
	'use strict';

	var DEBOUNCE_MS = 250;
	var PER_PAGE = 20;

	/**
	 * Bind every picker on the page, not one hardcoded set of element ids.
	 *
	 * This used to look up `wb_gam_award_user_search` / `_user` / `_status` by id, which made it a
	 * component that could only ever exist once. The Kudos Moderation screen needs TWO (a giver and a
	 * receiver), and the alternative to generalising this was `wp_dropdown_users()` -- which is the
	 * exact thing this file was written to replace, because it renders one <option> per member and
	 * takes the page down on a large site.
	 *
	 * A picker is now any element carrying `data-wb-gam-user-picker`, containing a search input, a
	 * select, and (optionally) a status node.
	 */
	function init() {
		var pickers = document.querySelectorAll( '[data-wb-gam-user-picker]' );

		if ( ! window.wbGamAdminRest || ! window.wbGamUserPicker ) {
			return;
		}

		Array.prototype.forEach.call( pickers, bind );
	}

	function bind( root ) {
		var search = root.querySelector( '[data-picker-search]' );
		var select = root.querySelector( '[data-picker-select]' );
		var status = root.querySelector( '[data-picker-status]' );

		if ( ! search || ! select ) {
			return;
		}

		var timer = null;
		var lastQuery = '';

		function setStatus( message ) {
			if ( status ) {
				status.textContent = message;
			}
		}

		/**
		 * Empty a node without innerHTML. Every option below is built with
		 * createElement + textContent, so no user-supplied string is ever parsed
		 * as markup — a display name containing "<script>" is inert text.
		 *
		 * @param {HTMLElement} node Node to empty.
		 */
		function clear( node ) {
			while ( node.firstChild ) {
				node.removeChild( node.firstChild );
			}
		}

		function reset( placeholder ) {
			clear( select );
			var opt = document.createElement( 'option' );
			opt.value = '0';
			opt.textContent = placeholder;
			select.appendChild( opt );
		}

		function run( term ) {
			if ( term.length < 2 ) {
				reset( window.wbGamUserPicker.i18n.typeToSearch );
				setStatus( '' );
				return;
			}

			setStatus( window.wbGamUserPicker.i18n.searching );

			window.wbGamAdminRest
				.apiFetch(
					'GET',
					'/members?per_page=' + PER_PAGE + '&search=' + encodeURIComponent( term ),
					null,
					window.wbGamUserPicker
				)
				.then( function ( res ) {
					// A slower earlier request must not overwrite a newer one.
					if ( term !== lastQuery ) {
						return;
					}

					if ( ! res || ! res.ok || ! res.data || ! res.data.items ) {
						reset( window.wbGamUserPicker.i18n.noResults );
						setStatus( '' );
						return;
					}

					var items = res.data.items;
					if ( ! items.length ) {
						reset( window.wbGamUserPicker.i18n.noResults );
						setStatus( window.wbGamUserPicker.i18n.noResults );
						return;
					}

					clear( select );
					var head = document.createElement( 'option' );
					head.value = '0';
					head.textContent = window.wbGamUserPicker.i18n.selectUser;
					select.appendChild( head );

					items.forEach( function ( item ) {
						var opt = document.createElement( 'option' );
						opt.value = String( item.id );
						opt.textContent = ( item.name || item.login || ( '#' + item.id ) ) +
							( item.login ? ' (' + item.login + ')' : '' );
						select.appendChild( opt );
					} );

					// Only one match: pre-select it. Saves the second interaction on
					// the common case of an admin who typed a full username.
					if ( 1 === items.length ) {
						select.value = String( items[ 0 ].id );
					}

					setStatus(
						window.wbGamUserPicker.i18n.resultsFound.replace( '%d', String( items.length ) )
					);
				} )
				.catch( function () {
					reset( window.wbGamUserPicker.i18n.noResults );
					setStatus( '' );
				} );
		}

		search.addEventListener( 'input', function () {
			var term = search.value.trim();
			lastQuery = term;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				run( term );
			}, DEBOUNCE_MS );
		} );

		// Enter in the search box must not submit the award form.
		search.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
			}
		} );

		reset( window.wbGamUserPicker.i18n.typeToSearch );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
