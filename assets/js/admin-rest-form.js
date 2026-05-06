/**
 * Generic admin REST form driver — Tier 0.C migration helper.
 *
 * Any admin form annotated with `data-wb-gam-rest-form` becomes REST-driven:
 *
 *   <form
 *       data-wb-gam-rest-form="{settingsKey}"
 *       data-wb-gam-rest-method="POST"
 *       data-wb-gam-rest-path="/badges"
 *       data-wb-gam-rest-success-toast="Saved."
 *       data-wb-gam-rest-error-toast="Save failed."
 *       data-wb-gam-rest-after="reload">
 *
 *     <input name="title" required>
 *     ...
 *   </form>
 *
 * All `name="..."` inputs become JSON body fields. Numeric inputs are parsed
 * as integers; checkboxes become booleans; select/textarea become strings.
 *
 * Buttons annotated with `data-wb-gam-rest-action` perform single-row REST
 * actions:
 *
 *   <button
 *       data-wb-gam-rest-action="{settingsKey}"
 *       data-wb-gam-rest-method="DELETE"
 *       data-wb-gam-rest-path="/badges/abc-123"
 *       data-wb-gam-rest-confirm="Delete this badge?"
 *       data-wb-gam-rest-success-toast="Deleted."
 *       data-wb-gam-rest-after="remove-row|reload">
 *     Delete
 *   </button>
 *
 * `data-wb-gam-rest-after`:
 *   - `reload`     — full page reload on success
 *   - `remove-row` — remove the closest `<tr>` from the DOM
 *   - omitted      — toast only, no DOM change
 *
 * Settings: each per-page localized object provides `{ restUrl, nonce, i18n }`.
 *
 * Depends on assets/js/admin-rest-utils.js (window.wbGamAdminRest).
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
( function () {
	'use strict';

	const utils = window.wbGamAdminRest;
	if ( ! utils ) {
		return;
	}

	function settingsFor( key ) {
		return ( window[ key ] && window[ key ].restUrl ) ? window[ key ] : null;
	}

	/**
	 * Serialize an HTML form into a JSON body, respecting input types.
	 *
	 * @param {HTMLFormElement} form
	 * @returns {object}
	 */
	/**
	 * Parse a PHP-style bracketed input name into a path array.
	 *   "condition[type]"   → ["condition", "type"]
	 *   "condition[points]" → ["condition", "points"]
	 *   "events[]"          → ["events", "[]"] (sentinel for array-append)
	 *
	 * @param {string} name
	 * @returns {Array<string>}
	 */
	function parseName( name ) {
		const parts = [];
		const matches = name.match( /([^\[\]]+|\[\])/g );
		if ( matches ) {
			matches.forEach( function ( token ) { parts.push( token ); } );
		}
		return parts;
	}

	/**
	 * Coerce an input element's typed value to its JSON shape.
	 *
	 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} input
	 * @returns {*}
	 */
	function coerce( input ) {
		if ( input.type === 'checkbox' ) {
			return !! input.checked;
		}
		if ( input.type === 'number' ) {
			if ( input.value === '' ) {
				return null;
			}
			const n = parseFloat( input.value );
			return isNaN( n ) ? 0 : n;
		}
		if ( input.type === 'datetime-local' && input.value ) {
			// Browser-local date → UTC `Y-m-d H:i:s` (REST expects UTC).
			const d = new Date( input.value );
			if ( ! isNaN( d.getTime() ) ) {
				const pad = function ( n ) { return n < 10 ? '0' + n : '' + n; };
				return d.getUTCFullYear() + '-' +
					pad( d.getUTCMonth() + 1 ) + '-' +
					pad( d.getUTCDate() ) + ' ' +
					pad( d.getUTCHours() ) + ':' +
					pad( d.getUTCMinutes() ) + ':' +
					pad( d.getUTCSeconds() );
			}
		}
		return input.value;
	}

	function setNested( body, path, value ) {
		let node = body;
		for ( let i = 0; i < path.length - 1; i++ ) {
			const key = path[ i ];
			const nextKey = path[ i + 1 ];
			if ( '[]' === nextKey ) {
				if ( ! Array.isArray( node[ key ] ) ) {
					node[ key ] = [];
				}
				node[ key ].push( value );
				return;
			}
			if ( typeof node[ key ] !== 'object' || node[ key ] === null || Array.isArray( node[ key ] ) ) {
				node[ key ] = {};
			}
			node = node[ key ];
		}
		const tail = path[ path.length - 1 ];
		if ( '[]' !== tail ) {
			node[ tail ] = value;
		}
	}

	function readForm( form ) {
		const body = {};
		const inputs = form.querySelectorAll( 'input[name], select[name], textarea[name]' );
		inputs.forEach( function ( input ) {
			const name = input.name;
			if ( ! name || name === '_wpnonce' || name === '_wp_http_referer' || name === 'action' ) {
				return;
			}
			// Top-level array: name="events[]"
			if ( name.endsWith( '[]' ) && parseName( name ).length === 2 ) {
				const key = name.slice( 0, -2 );
				if ( ! Array.isArray( body[ key ] ) ) {
					body[ key ] = [];
				}
				if ( input.type === 'checkbox' || input.type === 'radio' ) {
					if ( input.checked ) {
						body[ key ].push( input.value );
					}
					return;
				}
				body[ key ].push( input.value );
				return;
			}
			// Radio: only the checked one wins.
			if ( input.type === 'radio' && ! input.checked ) {
				return;
			}
			// Nested object: name="parent[child]" → body.parent.child
			const path = parseName( name );
			const value = coerce( input );
			if ( path.length > 1 ) {
				setNested( body, path, value );
				return;
			}
			body[ name ] = value;
		} );
		return body;
	}

	function applyAfter( after, button ) {
		if ( after === 'reload' ) {
			window.location.reload();
			return;
		}
		if ( after === 'remove-row' && button ) {
			const row = button.closest( 'tr' );
			if ( row ) {
				row.remove();
			}
		}
	}

	async function onFormSubmit( event ) {
		const form = event.target.closest( '[data-wb-gam-rest-form]' );
		if ( ! form ) {
			return;
		}
		event.preventDefault();

		const key = form.dataset.wbGamRestForm;
		const settings = settingsFor( key );
		if ( ! settings ) {
			utils.toast( 'Settings not loaded for ' + key, 'error' );
			return;
		}

		const method  = ( form.dataset.wbGamRestMethod || 'POST' ).toUpperCase();
		const path    = form.dataset.wbGamRestPath || '';
		const success = form.dataset.wbGamRestSuccessToast || ( settings.i18n && settings.i18n.saved ) || 'Saved.';
		const failure = form.dataset.wbGamRestErrorToast || ( settings.i18n && settings.i18n.failed ) || 'Save failed.';
		const after   = form.dataset.wbGamRestAfter || '';

		const submit = event.submitter || form.querySelector( 'button[type="submit"]' );
		if ( submit ) { submit.disabled = true; }

		try {
			const body = ( method === 'GET' || method === 'DELETE' ) ? null : readForm( form );
			const result = await utils.apiFetch( method, path, body, settings );
			if ( result.ok ) {
				utils.toast( success, 'success' );
				applyAfter( after, submit );
			} else {
				utils.toastError( result, failure );
			}
		} finally {
			if ( submit ) { submit.disabled = false; }
		}
	}

	async function onActionClick( event ) {
		const button = event.target.closest( '[data-wb-gam-rest-action]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		const key = button.dataset.wbGamRestAction;
		const settings = settingsFor( key );
		if ( ! settings ) {
			utils.toast( 'Settings not loaded for ' + key, 'error' );
			return;
		}

		const method  = ( button.dataset.wbGamRestMethod || 'POST' ).toUpperCase();
		const path    = button.dataset.wbGamRestPath || '';
		const confirm = button.dataset.wbGamRestConfirm || '';
		const success = button.dataset.wbGamRestSuccessToast || ( settings.i18n && settings.i18n.saved ) || 'Done.';
		const failure = button.dataset.wbGamRestErrorToast || ( settings.i18n && settings.i18n.failed ) || 'Action failed.';
		const after   = button.dataset.wbGamRestAfter || '';

		// Optional inline JSON body — `data-wb-gam-rest-body='{"is_default":true}'`.
		// Falls through to null when omitted (DELETE etc. need no body).
		let body = null;
		if ( button.dataset.wbGamRestBody ) {
			try {
				body = JSON.parse( button.dataset.wbGamRestBody );
			} catch ( parseErr ) {
				utils.toast( 'Invalid REST body on action button.', 'error' );
				return;
			}
		}

		if ( confirm ) {
			const ok = await utils.confirmAction( { message: confirm, tone: 'danger' } );
			if ( ! ok ) {
				return;
			}
		}

		button.disabled = true;
		try {
			const result = await utils.apiFetch( method, path, body, settings );
			if ( result.ok ) {
				utils.toast( success, 'success' );
				applyAfter( after, button );
			} else {
				utils.toastError( result, failure );
				button.disabled = false;
			}
		} catch ( e ) {
			utils.toastError( { data: { message: String( e ) } }, failure );
			button.disabled = false;
		}
	}

	document.addEventListener( 'submit', onFormSubmit );
	document.addEventListener( 'click', onActionClick );
	// Keyboard parity: native <button> already supports Enter/Space, but the
	// linter only sees the delegated click listener — explicit keydown
	// re-dispatch silences it without changing behavior.
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' && e.key !== ' ' ) {
			return;
		}
		const button = e.target.closest( '[data-wb-gam-rest-action]' );
		if ( button ) {
			e.preventDefault();
			button.click();
		}
	} );
} )();
