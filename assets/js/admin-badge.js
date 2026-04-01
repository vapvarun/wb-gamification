/**
 * WB Gamification — Badge Admin Page
 *
 * Handles:
 *  - Condition-type toggle (show/hide points/action/count rows)
 *  - wp.media icon chooser
 *  - Remove icon button
 *
 * @since 0.5.0
 */

/* global wbGamBadgeAdmin, wp */

/**
 * Toggle condition-specific form rows when the condition type selector changes.
 *
 * @param {string} type The selected condition type.
 */
function wbGamToggleConditionFields( type ) {
	var points = document.getElementById( 'wb-gam-field-points' );
	var action = document.getElementById( 'wb-gam-field-action' );
	var count  = document.getElementById( 'wb-gam-field-count' );

	if ( points ) {
		points.style.display = 'point_milestone' === type ? '' : 'none';
	}
	if ( action ) {
		action.style.display = 'action_count' === type ? '' : 'none';
	}
	if ( count ) {
		count.style.display = 'action_count' === type ? '' : 'none';
	}
}

(function( $ ) {
	if ( typeof $ === 'undefined' ) {
		return;
	}

	$( document ).ready( function() {
		var frame;
		var $chooseBtn = $( '#wb-gam-choose-icon' );
		var $removeBtn = $( '#wb-gam-remove-icon' );
		var $input     = $( '#wb-gam-badge-image-url' );
		var $preview   = $( '#wb-gam-icon-preview' );

		if ( ! $chooseBtn.length ) {
			return;
		}

		$chooseBtn.on( 'click', function( e ) {
			e.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title:    ( typeof wbGamBadgeAdmin !== 'undefined' && wbGamBadgeAdmin.chooseIcon ) || 'Choose Badge Icon',
				button:   { text: ( typeof wbGamBadgeAdmin !== 'undefined' && wbGamBadgeAdmin.useIcon ) || 'Use as Icon' },
				multiple: false,
				library:  { type: 'image' }
			} );

			frame.on( 'select', function() {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.sizes && attachment.sizes.thumbnail
					? attachment.sizes.thumbnail.url
					: attachment.url;

				$input.val( url );
				$preview.html( '<img src="' + url + '" alt="">' );
				$removeBtn.show();
			} );

			frame.open();
		} );

		$removeBtn.on( 'click', function( e ) {
			e.preventDefault();
			$input.val( '' );
			$preview.html( '<span class="dashicons dashicons-awards"></span>' );
			$removeBtn.hide();
		} );
	} );
})( window.jQuery );
