/**
 * WB Gamification - Members roster table.
 *
 * Renders the admin member-management table from the plugin's own REST
 * collection endpoint (GET wb-gamification/v1/members) and wires the per-member
 * actions (exclude/include, reset points, award). Vanilla JS, no jQuery; all
 * member-supplied text is set via textContent so the table is XSS-safe.
 */
( function () {
	'use strict';

	var cfg = window.wbGamMembers;
	var rest = window.wbGamAdminRest;
	if ( ! cfg || ! rest ) {
		return;
	}

	var i18n = cfg.i18n || {};
	var state = { page: 1, search: '', pages: 1, loading: false };

	var searchEl = document.getElementById( 'wb-gam-members-search' );
	var tableEl = document.getElementById( 'wb-gam-members-table' );
	var pagerEl = document.getElementById( 'wb-gam-members-pager' );
	if ( ! tableEl ) {
		return;
	}

	if ( searchEl ) {
		searchEl.placeholder = i18n.search || '';
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text && null !== text ) {
			node.textContent = String( text );
		}
		return node;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function sprintf2( tpl, a, b ) {
		return String( tpl ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	async function load() {
		if ( state.loading ) {
			return;
		}
		state.loading = true;
		clear( tableEl );
		tableEl.appendChild( el( 'p', 'wb-gam-members__status', i18n.loading ) );

		var path =
			'/members?page=' +
			encodeURIComponent( state.page ) +
			'&per_page=20&search=' +
			encodeURIComponent( state.search );

		var res = await rest.apiFetch( 'GET', path, null, cfg );
		state.loading = false;

		if ( ! res || ! res.ok || ! res.data ) {
			clear( tableEl );
			tableEl.appendChild( el( 'p', 'wb-gam-members__status', i18n.error ) );
			return;
		}

		state.pages = res.data.pages || 1;
		render( res.data.items || [] );
		renderPager();
	}

	function render( items ) {
		clear( tableEl );

		if ( ! items.length ) {
			tableEl.appendChild( el( 'p', 'wb-gam-members__status', i18n.empty ) );
			return;
		}

		var table = el( 'table', 'widefat striped wb-gam-members__grid' );
		var thead = el( 'thead' );
		var hr = el( 'tr' );
		[ i18n.member, i18n.points, i18n.level, i18n.badges, i18n.status, i18n.actions ].forEach( function ( label ) {
			hr.appendChild( el( 'th', null, label ) );
		} );
		thead.appendChild( hr );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		items.forEach( function ( m ) {
			tbody.appendChild( row( m ) );
		} );
		table.appendChild( tbody );
		tableEl.appendChild( table );
	}

	function row( m ) {
		var tr = el( 'tr' );
		tr.dataset.userId = m.id;

		// Member cell: avatar + name + login.
		var memberTd = el( 'td', 'wb-gam-members__member' );
		if ( m.avatar ) {
			var img = el( 'img', 'wb-gam-members__avatar' );
			img.src = m.avatar;
			img.alt = '';
			img.width = 32;
			img.height = 32;
			memberTd.appendChild( img );
		}
		var nameWrap = el( 'span', 'wb-gam-members__name-wrap' );
		var nameNode = m.profile_url ? el( 'a', 'wb-gam-members__name', m.name ) : el( 'span', 'wb-gam-members__name', m.name );
		if ( m.profile_url ) {
			nameNode.href = m.profile_url;
		}
		nameWrap.appendChild( nameNode );
		nameWrap.appendChild( el( 'span', 'wb-gam-members__login', '@' + m.login ) );
		memberTd.appendChild( nameWrap );
		tr.appendChild( memberTd );

		tr.appendChild( el( 'td', null, Number( m.points || 0 ).toLocaleString() ) );
		tr.appendChild( el( 'td', null, m.level || '-' ) );
		tr.appendChild( el( 'td', null, m.badges || 0 ) );

		var statusTd = el( 'td' );
		statusTd.appendChild(
			el(
				'span',
				'wb-gam-members__badge ' + ( m.excluded ? 'is-excluded' : 'is-active' ),
				m.excluded ? i18n.excluded : i18n.active
			)
		);
		tr.appendChild( statusTd );

		// Actions.
		var actTd = el( 'td', 'wb-gam-members__actions' );

		var awardLink = el( 'a', 'button button-small', i18n.award );
		awardLink.href = cfg.awardUrl;
		actTd.appendChild( awardLink );

		var toggleBtn = el( 'button', 'button button-small', m.excluded ? i18n.include : i18n.exclude );
		toggleBtn.type = 'button';
		toggleBtn.addEventListener( 'click', function () {
			toggleExclude( m, toggleBtn );
		} );
		actTd.appendChild( toggleBtn );

		var resetBtn = el( 'button', 'button button-small button-link-delete', i18n.reset );
		resetBtn.type = 'button';
		resetBtn.addEventListener( 'click', function () {
			resetPoints( m, resetBtn );
		} );
		actTd.appendChild( resetBtn );

		tr.appendChild( actTd );
		return tr;
	}

	async function toggleExclude( m, btn ) {
		btn.disabled = true;
		var next = ! m.excluded;
		var res = await rest.apiFetch( 'POST', '/members/' + m.id + '/exclude', { excluded: next }, cfg );
		btn.disabled = false;
		if ( res && res.ok ) {
			m.excluded = next;
			rest.toast && rest.toast( next ? i18n.excludedToast : i18n.includedToast, 'success' );
			load();
		} else {
			rest.toast && rest.toast( i18n.actionError, 'error' );
		}
	}

	async function resetPoints( m, btn ) {
		var ok = rest.confirmAction ? await rest.confirmAction( i18n.resetConfirm ) : true;
		if ( ! ok ) {
			return;
		}
		btn.disabled = true;
		var res = await rest.apiFetch( 'POST', '/members/' + m.id + '/reset-points', {}, cfg );
		btn.disabled = false;
		if ( res && res.ok ) {
			rest.toast && rest.toast( i18n.resetToast, 'success' );
			load();
		} else {
			rest.toast && rest.toast( i18n.actionError, 'error' );
		}
	}

	function renderPager() {
		clear( pagerEl );
		if ( state.pages <= 1 ) {
			return;
		}

		var prev = el( 'button', 'button', i18n.prev );
		prev.type = 'button';
		prev.disabled = state.page <= 1;
		prev.addEventListener( 'click', function () {
			if ( state.page > 1 ) {
				state.page--;
				load();
			}
		} );

		var next = el( 'button', 'button', i18n.next );
		next.type = 'button';
		next.disabled = state.page >= state.pages;
		next.addEventListener( 'click', function () {
			if ( state.page < state.pages ) {
				state.page++;
				load();
			}
		} );

		pagerEl.appendChild( prev );
		pagerEl.appendChild( el( 'span', 'wb-gam-members__pageinfo', sprintf2( i18n.pageOf, state.page, state.pages ) ) );
		pagerEl.appendChild( next );
	}

	// Debounced search.
	var searchTimer = null;
	if ( searchEl ) {
		searchEl.addEventListener( 'input', function () {
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( function () {
				state.search = searchEl.value.trim();
				state.page = 1;
				load();
			}, 300 );
		} );
	}

	load();
} )();
