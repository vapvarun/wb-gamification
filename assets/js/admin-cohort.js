/**
 * Admin: Cohort Leagues — REST-driven (replaces admin-post.php form-post handler).
 *
 * Tier 0.C (REST migration) wired the Cohort Settings page to the canonical
 * /wb-gamification/v1/cohort-settings endpoint. Save sends a single POST.
 *
 * Depends on assets/js/admin-rest-utils.js (window.wbGamAdminRest).
 *
 * @package WB_Gamification
 * @since   1.0.0
 */
( function () {
	'use strict';

	const settings = window.wbGamCohortSettings;
	const utils    = window.wbGamAdminRest;
	if ( ! settings || ! utils ) {
		return;
	}

	const form = document.querySelector( '[data-wb-gam-cohort-form]' );
	if ( ! form ) {
		return;
	}

	const i18n = settings.i18n || {};

	async function onSubmit( event ) {
		event.preventDefault();

		const enabledField = form.querySelector( '[name="cohort_enabled"]' );
		const payload = {
			tier_1:      ( form.querySelector( '[name="tier_1"]' ) || {} ).value || 'Bronze',
			tier_2:      ( form.querySelector( '[name="tier_2"]' ) || {} ).value || 'Silver',
			tier_3:      ( form.querySelector( '[name="tier_3"]' ) || {} ).value || 'Gold',
			tier_4:      ( form.querySelector( '[name="tier_4"]' ) || {} ).value || 'Diamond',
			promote_pct: parseInt( ( form.querySelector( '[name="promote_pct"]' ) || {} ).value, 10 ) || 20,
			demote_pct:  parseInt( ( form.querySelector( '[name="demote_pct"]' ) || {} ).value, 10 ) || 20,
			duration:    ( form.querySelector( '[name="duration"]' ) || {} ).value || 'weekly',
			enabled:     enabledField ? enabledField.value === '1' : true,
		};

		const button = event.submitter || form.querySelector( '[data-wb-gam-cohort-save]' );
		if ( button ) {
			button.disabled = true;
		}

		try {
			const result = await utils.apiFetch( 'POST', '/cohort-settings', payload, settings );
			if ( result.ok ) {
				utils.toast( i18n.saved || 'Cohort settings saved.', 'success' );
			} else {
				utils.toastError( result, i18n.failed || 'Failed to save settings.' );
			}
		} finally {
			if ( button ) {
				button.disabled = false;
			}
		}
	}

	form.addEventListener( 'submit', onSubmit );
} )();
