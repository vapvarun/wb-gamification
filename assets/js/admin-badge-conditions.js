/**
 * The badge condition repeater.
 *
 * A badge used to have exactly one condition, and four tenure badges plus three site-first badges
 * had no editable condition at all -- they were awarded by hardcoded engines while the library told
 * the owner they were "MANUAL". The only way to change what "2-Year Member" meant was to edit PHP.
 *
 * This is the screen where that stops being true.
 *
 * Two things it deliberately does NOT do:
 *
 *   - It does not build its own option lists. Actions, levels and badges are rendered by PHP into a
 *     template element, because level ids are site-specific and an action list hardcoded in
 *     JavaScript drifts the first moment somebody adds an integration.
 *   - It does not fight the simple case. One condition looks like one row, exactly as it did before
 *     the feature existed. The complex case became possible; the simple one did not get heavier.
 */
( function () {
	'use strict';

	var rows = document.getElementById( 'wb-gam-condition-rows' );
	var addButton = document.getElementById( 'wb-gam-add-condition' );
	var template = document.getElementById( 'wb-gam-condition-row-template' );

	if ( ! rows || ! addButton || ! template ) {
		return;
	}

	/**
	 * Show only the fields that belong to the selected condition type.
	 *
	 * @param {HTMLElement} row The condition row.
	 */
	function showFieldsFor( row ) {
		var select = row.querySelector( '.wb-gam-condition-type' );
		if ( ! select ) {
			return;
		}

		var type = select.value;
		var manual = 'admin_awarded' === type;

		row.querySelectorAll( '.wb-gam-condition-fields' ).forEach( function ( group ) {
			var mine = group.getAttribute( 'data-for' ) === type;
			group.hidden = ! mine;

			// A hidden field must not post. Otherwise a row switched from "10 posts" to "Champion"
			// would still send count=10, and the saved rule would carry a field its type does not
			// have -- harmless today, and exactly the kind of debris that makes a future migration
			// guess wrong.
			group.querySelectorAll( 'input, select' ).forEach( function ( field ) {
				field.disabled = ! mine;
			} );
		} );

		// "Admin awarded" is exclusive: a badge you grant by hand has no conditions to meet. Saying
		// so by disabling the rest of the repeater is clearer than letting someone build a rule that
		// the save path will silently throw away.
		var container = row.closest( '.wb-gam-conditions' );
		if ( container && manual && 1 === container.querySelectorAll( '.wb-gam-condition-row' ).length ) {
			container.classList.add( 'is-manual' );
		} else if ( container ) {
			container.classList.remove( 'is-manual' );
		}
	}

	/**
	 * Re-index rows after an add or a remove, so the posted array has no gaps.
	 */
	function reindex() {
		rows.querySelectorAll( '.wb-gam-condition-row' ).forEach( function ( row, i ) {
			row.setAttribute( 'data-index', String( i ) );

			row.querySelectorAll( '[name]' ).forEach( function ( field ) {
				field.name = field.name.replace(
					/condition\[conditions\]\[[^\]]*\]/,
					'condition[conditions][' + i + ']'
				);
			} );
		} );

		// The last remaining row keeps its remove button, but removing it would leave a badge with no
		// conditions -- which IS a manual badge, and a legitimate thing to want. So the button stays.
		var only = rows.querySelectorAll( '.wb-gam-condition-row' ).length;
		rows.classList.toggle( 'has-one', 1 === only );
	}

	rows.addEventListener( 'change', function ( event ) {
		if ( event.target.classList.contains( 'wb-gam-condition-type' ) ) {
			showFieldsFor( event.target.closest( '.wb-gam-condition-row' ) );
		}
	} );

	rows.addEventListener( 'click', function ( event ) {
		var remove = event.target.closest( '.wb-gam-condition-remove' );
		if ( ! remove ) {
			return;
		}

		event.preventDefault();

		var row = remove.closest( '.wb-gam-condition-row' );
		if ( ! row ) {
			return;
		}

		// Never leave the repeater empty and unrecoverable. Removing the last row turns the badge
		// manual, which is a real choice -- so it resets to "Admin awarded" rather than vanishing and
		// leaving the owner with nothing to click.
		if ( 1 === rows.querySelectorAll( '.wb-gam-condition-row' ).length ) {
			var select = row.querySelector( '.wb-gam-condition-type' );
			if ( select ) {
				select.value = 'admin_awarded';
				showFieldsFor( row );
			}
			return;
		}

		row.remove();
		reindex();
	} );

	addButton.addEventListener( 'click', function ( event ) {
		event.preventDefault();

		var index = rows.querySelectorAll( '.wb-gam-condition-row' ).length;

		// Clone the DOM out of the <template>. Deliberately NOT innerHTML: the markup is
		// server-rendered and PHP-escaped, so a string build would not actually be a hole -- but
		// cloning leaves nothing for the next reader, or a scanner, to have to reason about.
		var fragment = template.content.cloneNode( true );
		var row = fragment.querySelector( '.wb-gam-condition-row' );
		if ( ! row ) {
			return;
		}

		// The template posts under __INDEX__; give this row its real position.
		row.setAttribute( 'data-index', String( index ) );
		row.querySelectorAll( '[name]' ).forEach( function ( field ) {
			field.name = field.name.replace( '__INDEX__', String( index ) );
		} );

		// A badge that was manual and just gained its first real condition is no longer manual.
		var first = rows.querySelector( '.wb-gam-condition-row .wb-gam-condition-type' );
		if ( first && 'admin_awarded' === first.value && 1 === index ) {
			first.value = 'point_milestone';
			showFieldsFor( first.closest( '.wb-gam-condition-row' ) );
		}

		rows.appendChild( row );
		showFieldsFor( row );
		reindex();

		var select = row.querySelector( '.wb-gam-condition-type' );
		if ( select ) {
			select.focus();
		}
	} );

	// Initial paint.
	rows.querySelectorAll( '.wb-gam-condition-row' ).forEach( showFieldsFor );
	reindex();
} )();
