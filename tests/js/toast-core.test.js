/**
 * toast-core.js: every toast closes by itself, Escape closes it, and it never covers a dialog or
 * the field a member is typing in. Runs the real file in jsdom with a fake clock, on both renderers:
 * this plugin's own stack (no BuddyNext) and BuddyNext's window.bnToast.
 *
 * Run: npm run test:js   (node --test tests/js/)
 */
const { test } = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync(
	path.join( __dirname, '../../assets/js/toast-core.js' ),
	'utf8'
);

function page( { bn = false, admin = false } = {} ) {
	const dom = new JSDOM(
		'<!doctype html><body><textarea id="f"></textarea></body>',
		{ runScripts: 'outside-only' }
	);
	const w = dom.window;
	if ( admin ) {
		w.document.body.classList.add( 'wp-admin' );
	}

	// Fake clock: timers run only on tick().
	let now = 0;
	let seq = 0;
	const timers = new Map();
	w.setTimeout = ( fn, ms ) => (
		timers.set( ++seq, { fn, at: now + ( ms || 0 ) } ), seq
	);
	w.setInterval = ( fn, ms ) => (
		timers.set( ++seq, { fn, at: now + ms, every: ms } ), seq
	);
	w.clearTimeout = w.clearInterval = ( id ) => timers.delete( id );
	w.requestAnimationFrame = ( fn ) => w.setTimeout( fn, 0 );
	w.matchMedia = () => ( { matches: false } );
	const tick = ( ms ) => {
		const end = now + ms;
		for (;;) {
			let next = null;
			for ( const [ id, t ] of timers ) {
				if ( t.at <= end && ( ! next || t.at < next[ 1 ].at ) ) {
					next = [ id, t ];
				}
			}
			if ( ! next ) {
				break;
			}
			const [ id, t ] = next;
			now = t.at;
			if ( t.every ) {
				t.at += t.every;
			} else {
				timers.delete( id );
			}
			t.fn();
		}
		now = end;
	};

	const bnCalls = [];
	if ( bn ) {
		// Stand-in for BuddyNext's bnToast: records what it was asked to draw.
		w.bnToast = function bnToast( o ) { // Named, like BuddyNext's real one (shell/dialog.js).
			const el = w.document.createElement( 'div' );
			el.className = 'bn-toast';
			w.document.body.appendChild( el );
			const call = { o, el, dismissed: false };
			bnCalls.push( call );
			return {
				el,
				update() {},
				dismiss() {
					call.dismissed = true;
					el.remove();
				},
			};
		};
	}

	// The document's parse state, settable: a page-load toast arrives while it is "loading".
	let readyState = 'complete';
	Object.defineProperty( w.document, 'readyState', {
		configurable: true,
		get: () => readyState,
	} );
	const setReadyState = ( s ) => {
		readyState = s;
	};

	w.eval( SRC );
	const shown = () =>
		w.document.querySelectorAll( '.wb-gam-toast:not(.wb-gam-toast--exit)' )
			.length;
	const esc = () =>
		w.document.dispatchEvent(
			new w.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
		);
	const openDialog = () => {
		const d = w.document.createElement( 'div' );
		d.setAttribute( 'role', 'dialog' );
		d.getBoundingClientRect = () => ( {
			width: 300,
			height: 300,
			top: 10,
			left: 10,
			right: 310,
			bottom: 310,
		} );
		w.document.body.appendChild( d );
		return d;
	};
	return {
		w,
		tick,
		shown,
		esc,
		openDialog,
		bnCalls,
		setReadyState,
		toast: ( o ) => w.wbGam.toast( o ),
	};
}

test( 'BuddyNext: a page-load toast waits for the real toast function, then closes on Escape', () => {
	const p = page( { bn: true } );
	const real = p.w.bnToast;
	const stubbed = [];
	// BuddyNext's early stub (shell/extras.js): queues the call, returns no handle.
	p.w.bnToast = ( o ) => {
		stubbed.push( o );
	};
	p.setReadyState( 'loading' );
	p.toast( { title: '+15 Points', body: 'Manual award' } );
	assert.strictEqual( stubbed.length, 0, 'not handed to the stub' );

	p.w.bnToast = real; // The module loaded.
	p.setReadyState( 'interactive' );
	p.tick( 600 );
	assert.strictEqual( p.bnCalls.length, 1, 'drawn by the real function' );
	p.esc();
	assert.strictEqual( p.bnCalls[ 0 ].dismissed, true, 'Escape reaches it' );
} );

const LINK = {
	title: 'You earned points',
	body: 'See your progress',
	href: '/progress/',
	hrefLabel: 'See my progress',
};

// The member is writing: focus in the field and text not yet sent.
function write( p, text ) {
	const f = p.w.document.getElementById( 'f' );
	f.focus();
	f.value = text;
}

test( 'a sent comment does not hold the toast: the box is empty though focus stays in it', () => {
	const p = page( { bn: true } );
	write( p, '' );
	p.toast( { title: '+3 Points', body: 'Leave a comment' } );
	assert.strictEqual( p.bnCalls.length, 1 );
} );

test( 'an empty box focused on page load does not hold toasts', () => {
	const p = page();
	p.w.document.getElementById( 'f' ).focus();
	p.toast( { title: '+10 Points' } );
	assert.strictEqual( p.shown(), 1 );
} );

test( 'own stack: a toast with a link closes by itself after 8s', () => {
	const p = page();
	p.toast( LINK );
	p.tick( 7000 );
	assert.strictEqual( p.shown(), 1, 'still up before 8s' );
	p.tick( 1500 );
	assert.strictEqual( p.shown(), 0, 'gone after 8s' );
} );

test( 'own stack: a plain toast closes after 4s', () => {
	const p = page();
	p.toast( { title: '+10 Points' } );
	p.tick( 4500 );
	assert.strictEqual( p.shown(), 0 );
} );

test( 'own stack: Escape closes the newest toast', () => {
	const p = page();
	p.toast( LINK );
	p.esc();
	assert.strictEqual( p.shown(), 0 );
} );

test( 'own stack: a toast waits while the member types, and shows when they leave the field', () => {
	const p = page();
	write( p, 'Saturday group run starts at 7' );
	p.toast( { title: '+10 Points' } );
	p.tick( 600 );
	assert.strictEqual( p.shown(), 0, 'held while typing' );
	p.w.document.getElementById( 'f' ).blur();
	p.tick( 600 );
	assert.strictEqual( p.shown(), 1, 'shown after leaving the field' );
} );

test( 'own stack: a showing toast is taken off screen when the member starts typing', () => {
	const p = page();
	p.toast( LINK );
	write( p, 'Saturday group run starts at 7' );
	p.tick( 600 );
	assert.strictEqual( p.shown(), 0 );
} );

test( 'wp-admin: typing does not hold the "Saved" toast', () => {
	const p = page( { admin: true } );
	write( p, 'Saturday group run starts at 7' );
	p.toast( { title: 'Saved' } );
	assert.strictEqual( p.shown(), 1 );
} );

test( 'BuddyNext: our toast is never made permanent; a link gets an 8s timeout', () => {
	const p = page( { bn: true } );
	p.toast( LINK );
	assert.strictEqual( p.bnCalls.length, 1 );
	assert.strictEqual( p.bnCalls[ 0 ].o.persist, false );
	assert.strictEqual( p.bnCalls[ 0 ].o.timeout, 8000 );
} );

test( 'BuddyNext: Escape closes our toast', () => {
	const p = page( { bn: true } );
	p.toast( LINK );
	p.esc();
	assert.strictEqual( p.bnCalls[ 0 ].dismissed, true );
} );

test( 'BuddyNext: a toast waits while a dialog is open and shows once it closes', () => {
	const p = page( { bn: true } );
	const d = p.openDialog();
	p.toast( LINK );
	p.tick( 600 );
	assert.strictEqual( p.bnCalls.length, 0, 'not drawn over the dialog' );
	d.remove();
	p.tick( 600 );
	assert.strictEqual( p.bnCalls.length, 1, 'drawn after the dialog closed' );
} );

test( 'BuddyNext: a toast waits while the member types in the composer', () => {
	const p = page( { bn: true } );
	write( p, 'Saturday group run starts at 7' );
	p.toast( { title: '+10 Points' } );
	p.tick( 600 );
	assert.strictEqual( p.bnCalls.length, 0 );
} );

test( 'Escape is left to an open dialog', () => {
	const p = page( { bn: true } );
	p.toast( LINK );
	p.openDialog();
	p.esc();
	assert.strictEqual( p.bnCalls[ 0 ].dismissed, false );
} );

// ── 1.7.0: shared toast standard (TOASTS.md) ────────────────────────────────

test( 'own stack: a fourth toast waits its turn instead of pushing one off screen', () => {
	const p = page();
	[ 'a', 'b', 'c', 'd' ].forEach( ( t ) => p.toast( { title: t } ) );
	assert.strictEqual( p.shown(), 2, 'two at most on a desktop' );
	p.tick( 4000 + 250 + 350 + 10 );
	const titles = [ ...p.w.document.querySelectorAll( '.wb-gam-toast:not(.wb-gam-toast--exit) .wb-gam-toast__message' ) ].map( ( e ) => e.textContent );
	assert.deepStrictEqual( titles, [ 'c', 'd' ], 'nothing dropped, in order' );
} );

test( 'own stack: no toast shows while the level-up celebration is open', () => {
	const p = page();
	const m = p.w.document.createElement( 'div' );
	m.className = 'wb-gam-moment';
	m.setAttribute( 'role', 'status' );
	m.getBoundingClientRect = () => ( { width: 400, height: 400, top: 0, left: 0, right: 400, bottom: 400 } );
	p.w.document.body.appendChild( m );
	p.toast( { title: '+30 Points' } );
	assert.strictEqual( p.shown(), 0, 'the celebration shows alone' );
	m.hidden = true;
	p.tick( 600 );
	assert.strictEqual( p.shown(), 1, 'the summary follows once it closes' );
} );

test( 'own stack: {count} reads as the final number and a badge picture fills the disc', () => {
	const p = page();
	p.toast( { title: '+{count} Points', count: { from: 10, to: 25 }, icon: '/badge.png' } );
	const t = p.w.document.querySelector( '.wb-gam-toast' );
	assert.match( t.querySelector( '.screen-reader-text' ).textContent, /^\+25 Points$/ );
	assert.ok( ! t.textContent.includes( '{count}' ) );
	assert.strictEqual( t.querySelector( '.wb-gam-disc img' ).getAttribute( 'src' ), '/badge.png' );
} );

test( 'BuddyNext before its rebuild: the final number goes in the title, never "{count}"', () => {
	const p = page( { bn: true } );
	p.toast( { title: '+{count} Points', count: { from: 0, to: 17 }, icon: '/badge.png' } );
	assert.strictEqual( p.bnCalls[ 0 ].o.title, '+17 Points' );
	assert.strictEqual( p.bnCalls[ 0 ].o.count, undefined );
	assert.strictEqual( p.bnCalls[ 0 ].o.icon, undefined, 'its own status icon until it supports pictures' );
} );

test( 'BuddyNext after its rebuild: count and the picture are passed through', () => {
	const p = page( { bn: true } );
	p.w.bnToast.supports = { count: true, icon: true, award: true };
	p.toast( { title: '+{count} Points', count: { from: 0, to: 17 }, icon: '/badge.png', tone: 'achievement' } );
	const o = p.bnCalls[ 0 ].o;
	assert.strictEqual( o.title, '+{count} Points' );
	assert.deepStrictEqual( JSON.parse( JSON.stringify( o.count ) ), { from: 0, to: 17 } );
	assert.strictEqual( o.icon, '/badge.png' );
	assert.strictEqual( o.type, 'award' );
} );

test( 'own stack: closing a toast whose button had focus returns focus to where it was', () => {
	const p = page();
	const before = p.w.document.getElementById( 'f' );
	const h = p.toast( { title: 'x', href: '/x/', hrefLabel: 'View' } );
	const link = h.el.querySelector( '.wb-gam-toast__link' );
	link.dispatchEvent( new p.w.FocusEvent( 'focusin', { bubbles: true, relatedTarget: before } ) );
	link.focus();
	h.dismiss();
	assert.strictEqual( p.w.document.activeElement, before );
} );

test( 'another plugin\'s text-only bnToast (Jetonomy without BuddyNext) is not used: our own stack draws', () => {
	const p = page();
	const seen = [];
	p.w.bnToast = function ( msg ) { // Anonymous, like Jetonomy's header.js and BuddyNext's early stub.
		seen.push( msg );
	};
	p.toast( { title: '+5 Points' } );
	assert.strictEqual( seen.length, 0, 'never handed an options object ("[object Object]")' );
	assert.strictEqual( p.shown(), 1 );
} );

test( 'no empty toast on either stack: nothing to say draws nothing, an empty title takes the body', () => {
	const own = page();
	own.toast( { title: '', body: '' } );
	assert.strictEqual( own.shown(), 0, 'own stack: nothing drawn' );
	own.toast( { title: '  ', body: 'Join a space' } );
	assert.strictEqual( own.w.document.querySelector( '.wb-gam-toast__message' ).textContent, 'Join a space' );

	const bn = page( { bn: true } );
	bn.toast( { title: '', body: '' } );
	assert.strictEqual( bn.bnCalls.length, 0, 'BuddyNext is never asked to draw an empty toast' );
	bn.toast( { title: '', body: 'Join a space', tone: 'achievement' } );
	assert.strictEqual( bn.bnCalls[ 0 ].o.title, 'Join a space' );
} );

test( 'an update with nothing to say keeps the toast on screen as it was', () => {
	const p = page();
	const h = p.toast( { title: '+10 Points', body: 'Join a space' } );
	h.update( { title: '', body: '' } );
	assert.strictEqual( p.w.document.querySelector( '.wb-gam-toast__message' ).textContent, '+10 Points' );
} );

test( 'BuddyNext still starting: a toast waits for its real stack instead of drawing ours', () => {
	const p = page( { bn: true } );
	const real = p.w.bnToast;
	p.w.bnToast = function ( msg, type ) { // BuddyNext's stub (shell/extras.js), after the page has parsed.
		( p.w.__bnToastQueue = p.w.__bnToastQueue || [] ).push( [ msg, type ] );
	};
	p.toast( { title: '+10 Points', body: 'Join a space' } );
	assert.strictEqual( p.shown(), 0, 'our stack does not draw on a BuddyNext page' );
	p.w.bnToast = real; // shell/dialog.js loaded.
	p.tick( 600 );
	assert.strictEqual( p.bnCalls.length, 1 );
	assert.strictEqual( p.bnCalls[ 0 ].o.title, '+10 Points' );
} );

test( 'own stack: a toast with a button puts it under the text, and loses that when the button goes', () => {
	const p = page();
	const h = p.toast( { ...LINK, key: 'action' } );
	const el = p.w.document.querySelector( '.wb-gam-toast' );
	assert.ok( el.classList.contains( 'wb-gam-toast--action' ), 'button toast gets the stacked layout' );
	assert.ok( el.querySelector( '.wb-gam-toast__text' ), 'the text column is styleable' );
	h.update( { title: 'You earned points' } ); // Own stack repaints from the options given.
	assert.ok( ! el.classList.contains( 'wb-gam-toast--action' ), 'no button, one row again' );
	p.toast( { title: '+10 Points' } );
	assert.ok( ! p.w.document.querySelectorAll( '.wb-gam-toast' )[ 1 ].classList.contains( 'wb-gam-toast--action' ), 'a plain toast stays one row' );
} );

test( 'the count-up number is grouped by the recipient locale, not the browser locale', () => {
	const read = ( locale ) => {
		const p = page();
		p.w.requestAnimationFrame = () => 0;
		p.toast( { title: '+{count} Punkte', count: { from: 6000, to: 6000, locale } } );
		return p.w.document.querySelector( '.wb-gam-toast' ).textContent;
	};
	assert.match( read( 'de-DE' ), /\+6\.000 Punkte/, 'German grouping for a de-DE recipient' );
	assert.match( read( 'en-US' ), /\+6,000 Punkte/ );
} );
