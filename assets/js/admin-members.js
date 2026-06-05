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
		renderSkeleton();

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

	// Skeleton placeholder shown while the roster is fetched post-paint.
	// Mirrors the real table's column structure (incl. the optional Level/Badges
	// columns) so the layout does not jump when the data lands. The shimmer is
	// frozen by the prefers-reduced-motion guard in utilities.css.
	function renderSkeleton() {
		var scroll = el( 'div', 'wbgam-table-scroll' );
		scroll.setAttribute( 'aria-hidden', 'true' );
		var table = el( 'table', 'wbgam-table wbgam-table--priority wb-gam-members__grid' );
		var tbody = el( 'tbody' );
		var optional = { 2: true, 3: true, 4: true };
		var i;
		var c;
		for ( i = 0; i < 5; i++ ) {
			var tr = el( 'tr' );
			for ( c = 0; c < 6; c++ ) {
				var td = el( 'td', optional[ c ] ? 'wbgam-col--optional' : null );
				td.appendChild( el( 'span', 'wbgam-skeleton wbgam-skeleton--text' ) );
				tr.appendChild( td );
			}
			tbody.appendChild( tr );
		}
		table.appendChild( tbody );
		scroll.appendChild( table );
		tableEl.appendChild( scroll );
	}

	function render( items ) {
		clear( tableEl );

		if ( ! items.length ) {
			var empty = el( 'div', 'wbgam-empty' );
			var emptyIcon = el( 'div', 'wbgam-empty-icon' );
			emptyIcon.appendChild( el( 'span', 'icon-users wbgam-icon-xl wbgam-icon-xl--muted' ) );
			empty.appendChild( emptyIcon );
			empty.appendChild( el( 'div', 'wbgam-empty-title', i18n.empty ) );
			empty.appendChild( el( 'p', null, i18n.emptyBody || '' ) );
			tableEl.appendChild( empty );
			return;
		}

		var scroll = el( 'div', 'wbgam-table-scroll' );
		var table = el( 'table', 'wbgam-table wbgam-table--priority wb-gam-members__grid' );
		var thead = el( 'thead' );
		var hr = el( 'tr' );
		// Column priority: Level, Badges and Status are dropped at <=640px so
		// Member/Points/Actions fill 390px with no horizontal scroll (measured:
		// keeping Status left a 447px row in a 311px viewport). Exclusion state
		// stays reachable via the row's Exclude/Include button and the badge at
		// >640px.
		[
			{ label: i18n.member, optional: false },
			{ label: i18n.points, optional: false },
			{ label: i18n.level, optional: true },
			{ label: i18n.badges, optional: true },
			{ label: i18n.status, optional: true },
			{ label: i18n.actions, optional: false },
		].forEach( function ( col ) {
			hr.appendChild( el( 'th', col.optional ? 'wbgam-col--optional' : null, col.label ) );
		} );
		thead.appendChild( hr );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		items.forEach( function ( m ) {
			tbody.appendChild( row( m ) );
		} );
		table.appendChild( tbody );
		scroll.appendChild( table );
		tableEl.appendChild( scroll );
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
		tr.appendChild( el( 'td', 'wbgam-col--optional', m.level || '-' ) );
		tr.appendChild( el( 'td', 'wbgam-col--optional', m.badges || 0 ) );

		var statusTd = el( 'td', 'wbgam-col--optional' );
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

		var awardLink = el( 'a', 'wbgam-btn wbgam-btn--sm wbgam-btn--secondary', i18n.award );
		awardLink.href = cfg.awardUrl;
		actTd.appendChild( awardLink );

		var toggleBtn = el( 'button', 'wbgam-btn wbgam-btn--sm wbgam-btn--secondary', m.excluded ? i18n.include : i18n.exclude );
		toggleBtn.type = 'button';
		toggleBtn.addEventListener( 'click', function () {
			toggleExclude( m, toggleBtn );
		} );
		actTd.appendChild( toggleBtn );

		var resetBtn = el( 'button', 'wbgam-btn wbgam-btn--sm wbgam-btn--danger', i18n.reset );
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
