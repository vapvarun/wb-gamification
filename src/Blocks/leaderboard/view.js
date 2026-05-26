/**
 * Leaderboard block — live updates via the wb-gamification realtime broker.
 *
 * Reads the board signature from data attributes the server stamped on
 * the wrapper, registers the board with the broker, then on every tick
 * compares the freshly-returned rows against what's in the DOM and
 * mutates ranks in place. Pure DOM (createElement / textContent) so
 * there is no innerHTML and no XSS surface from server-supplied names.
 *
 * @since 1.4.0
 */

( function () {
	'use strict';

	function init() {
		var boards = document.querySelectorAll( '[data-wb-gam-board="leaderboard"][data-wb-gam-board-sig]' );
		if ( ! boards.length ) {
			return;
		}

		var perBoard = new Map();

		boards.forEach( function ( root ) {
			var sig = root.getAttribute( 'data-wb-gam-board-sig' );
			if ( sig ) {
				perBoard.set( sig, root );
			}
		} );

		function ensureRegistered() {
			if ( ! window.wbGamRealtime ) {
				return false;
			}
			perBoard.forEach( function ( root, sig ) {
				window.wbGamRealtime.registerBoard( {
					sig:        sig,
					period:     root.getAttribute( 'data-wb-gam-period' ) || 'all',
					scope_type: root.getAttribute( 'data-wb-gam-scope-type' ) || '',
					scope_id:   parseInt( root.getAttribute( 'data-wb-gam-scope-id' ) || '0', 10 ),
					limit:      parseInt( root.getAttribute( 'data-wb-gam-limit' ) || '10', 10 ),
					point_type: root.getAttribute( 'data-wb-gam-point-type' ) || '',
				} );
			} );
			window.wbGamRealtime.subscribe( 'leaderboards', applyPayload );
			return true;
		}

		if ( ! ensureRegistered() ) {
			document.addEventListener( 'wbGamRealtimeReady', ensureRegistered, { once: true } );
		}

		// Unregister boards that get removed from the DOM (e.g. SPA
		// navigation). Keeps the per-tick payload bounded on long-lived
		// sessions.
		var observer = new MutationObserver( function () {
			perBoard.forEach( function ( root, sig ) {
				if ( ! document.body.contains( root ) ) {
					perBoard.delete( sig );
					if ( window.wbGamRealtime ) {
						window.wbGamRealtime.unregisterBoard( sig );
					}
				}
			} );
		} );
		observer.observe( document.body, { childList: true, subtree: true } );

		function applyPayload( payload ) {
			if ( ! payload || typeof payload !== 'object' ) {
				return;
			}
			perBoard.forEach( function ( root, sig ) {
				var rows = payload[ sig ];
				if ( Array.isArray( rows ) ) {
					patchBoard( root, rows );
				}
			} );
		}

		/**
		 * Reorder + retext the rendered <ol> to match the new row payload.
		 *
		 * Existing rows for the same user are reused so CSS state survives
		 * the update; new users are appended; dropped users are removed.
		 */
		function patchBoard( root, rows ) {
			var list = root.querySelector( '.wb-gam-leaderboard__list' );
			if ( ! list ) {
				return;
			}
			var pointsLabel = root.getAttribute( 'data-wb-gam-points-label' ) || '';

			var existing = new Map();
			Array.from( list.children ).forEach( function ( li ) {
				var uid = li.getAttribute( 'data-user-id' );
				if ( uid ) {
					existing.set( uid, li );
				}
			} );

			// Cache a template row (first existing child) so net-new
			// members from the heartbeat payload inherit the same structure
			// — icons, badge wrapper, avatar slot — without view.js having
			// to rebuild that markup from scratch.
			var templateRow = list.firstElementChild || null;

			var fragment = document.createDocumentFragment();

			rows.forEach( function ( row, idx ) {
				var rank = idx + 1;
				var uid  = String( row.user_id || 0 );
				var li   = existing.get( uid );
				if ( li ) {
					existing.delete( uid );
				} else {
					li = buildRow( row, templateRow );
				}
				updateRow( li, row, rank, pointsLabel );
				fragment.appendChild( li );
			} );

			// Drop rows that fell off the limit window.
			existing.forEach( function ( li ) { li.remove(); } );

			// Re-append in the new order. appendChild() inside a fragment
			// preserves identity, so we can just replace the list children.
			while ( list.firstChild ) {
				list.removeChild( list.firstChild );
			}
			list.appendChild( fragment );
		}

		/**
		 * Build a new <li> for a member who didn't have a server-rendered
		 * row. Clones the structure of an existing row in the same list
		 * so the icon nodes (sparkles + medal SVGs) and badge span are
		 * preserved without duplicating their markup here. Falls back to
		 * a minimal skeleton on first-ever render (no existing rows yet).
		 */
		function buildRow( row, templateRow ) {
			if ( templateRow ) {
				var clone = templateRow.cloneNode( true );
				clone.setAttribute( 'data-user-id', String( row.user_id || 0 ) );
				clone.className = 'wb-gam-leaderboard__entry';
				// New row from heartbeat has no avatar context — server
				// avatars are URL-specific; let the next full page render
				// fill them. We just preserve the slot.
				return clone;
			}

			// First-render fallback. The server always emits structured
			// markup, so this path should be rare (only fires when a board
			// is hydrated before its first server render — e.g. an SPA
			// route swap). Mirror the server structure so the IA store sees
			// consistent classes; icons will fill in on the next full
			// render of the host page.
			var li = document.createElement( 'li' );
			li.className = 'wb-gam-leaderboard__entry';
			li.setAttribute( 'data-user-id', String( row.user_id || 0 ) );

			var rankSpan = document.createElement( 'span' );
			rankSpan.className = 'wb-gam-leaderboard__rank';
			li.appendChild( rankSpan );

			var nameSpan = document.createElement( 'span' );
			nameSpan.className = 'wb-gam-leaderboard__name';
			li.appendChild( nameSpan );

			var pointsSpan = document.createElement( 'span' );
			pointsSpan.className = 'wb-gam-leaderboard__points';
			var ptsNumber = document.createElement( 'span' );
			ptsNumber.className = 'wb-gam-leaderboard__points-number';
			pointsSpan.appendChild( ptsNumber );
			li.appendChild( pointsSpan );

			var badgesSpan = document.createElement( 'span' );
			badgesSpan.className = 'wb-gam-leaderboard__badges';
			badgesSpan.hidden = true;
			var badgesCount = document.createElement( 'span' );
			badgesCount.className = 'wb-gam-leaderboard__badges-count';
			badgesSpan.appendChild( badgesCount );
			li.appendChild( badgesSpan );

			return li;
		}

		function updateRow( li, row, rank, pointsLabel ) {
			// Rank class for podium colours.
			li.className = ( 'wb-gam-leaderboard__entry wb-gam-rank-' + rank ).trim();
			li.dataset.rank = String( rank );

			var rankCell = li.querySelector( '.wb-gam-leaderboard__rank' );
			if ( rankCell ) {
				// Patch only the leading text node — preserves the
				// server-emitted <span class="wb-gam-leaderboard__rank-ordinal">
				// child for podium rows so "1st place" doesn't disappear on
				// the first heartbeat tick.
				var rankText = String( rank );
				var first    = rankCell.firstChild;
				if ( first && first.nodeType === 3 ) {
					if ( first.nodeValue.trim() !== rankText ) {
						first.nodeValue = rankText;
					}
				} else {
					rankCell.insertBefore( document.createTextNode( rankText ), rankCell.firstChild );
				}
			}

			var nameCell = li.querySelector( '.wb-gam-leaderboard__name' );
			if ( nameCell ) {
				// Only patch when the row carries a fresh display_name.
				// Skip when payload omits it — preserves the server-rendered
				// <a> link to the member profile that the heartbeat payload
				// doesn't ship.
				if ( row.display_name ) {
					var newName = String( row.display_name );
					if ( nameCell.textContent !== newName ) {
						nameCell.textContent = newName;
					}
				}
			}

			var ptsNumber = li.querySelector( '.wb-gam-leaderboard__points-number' );
			if ( ptsNumber ) {
				var ptsText = formatPoints( row.points, pointsLabel );
				if ( ptsNumber.textContent !== ptsText ) {
					ptsNumber.textContent = ptsText;
					bump( ptsNumber );
				}
			}

			// Badges — show/hide the wrapper based on count; patch the
			// inner count span only. Leaves the medal SVG icon untouched
			// (it lives in a sibling node inside the wrapper).
			if ( typeof row.badge_count !== 'undefined' ) {
				var badgesWrap  = li.querySelector( '.wb-gam-leaderboard__badges' );
				var badgesCount = li.querySelector( '.wb-gam-leaderboard__badges-count' );
				var n           = parseInt( row.badge_count, 10 ) || 0;
				if ( badgesWrap ) {
					badgesWrap.hidden = ( n <= 0 );
				}
				if ( badgesCount ) {
					var badgesText = n + ' ' + ( n === 1 ? 'badge' : 'badges' );
					if ( badgesCount.textContent !== badgesText ) {
						badgesCount.textContent = badgesText;
					}
				}
			}
		}

		function formatPoints( n, label ) {
			var num = parseInt( n, 10 ) || 0;
			return num.toLocaleString() + ( label ? ' ' + label : '' );
		}

		function bump( el ) {
			el.classList.remove( 'wb-gam-leaderboard__bump' );
			// reflow trigger
			// eslint-disable-next-line no-unused-expressions
			el.offsetWidth;
			el.classList.add( 'wb-gam-leaderboard__bump' );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
