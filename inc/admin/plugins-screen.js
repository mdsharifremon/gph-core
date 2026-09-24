/**
 * GPH Core — confirm before deactivating (Plugins screen only).
 *
 * Covers both paths WordPress offers:
 * - the "Deactivate" link in the GPH Core row
 * - Bulk actions → Deactivate with GPH Core ticked
 *
 * Data comes from inc/admin/plugins-screen.php (window.gphCorePluginsScreen).
 */
( function () {
	'use strict';

	var data = window.gphCorePluginsScreen;
	if ( ! data || ! data.basename ) {
		return;
	}

	var key = window.CSS && CSS.escape ? CSS.escape( data.basename ) : data.basename;

	// 1. Row link.
	var link = document.querySelector( 'tr[data-plugin="' + key + '"] .row-actions .deactivate a' );
	if ( link ) {
		link.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( data.message ) ) {
				event.preventDefault();
			}
		} );
	}

	// 2. Bulk action.
	var form = document.getElementById( 'bulk-action-form' );
	if ( ! form ) {
		return;
	}

	form.addEventListener( 'submit', function ( event ) {
		var box = form.querySelector( 'input[name="checked[]"][value="' + key + '"]' );
		if ( ! box || ! box.checked ) {
			return;
		}

		// Use the dropdown next to the Apply button that was clicked;
		// fall back to checking both if the browser doesn't report it.
		var top    = form.querySelector( '#bulk-action-selector-top' );
		var bottom = form.querySelector( '#bulk-action-selector-bottom' );
		var submitter = event.submitter;
		var selects;

		if ( submitter && submitter.id === 'doaction' ) {
			selects = [ top ];
		} else if ( submitter && submitter.id === 'doaction2' ) {
			selects = [ bottom ];
		} else {
			selects = [ top, bottom ];
		}

		var deactivating = selects.some( function ( select ) {
			return select && select.value === 'deactivate-selected';
		} );

		if ( deactivating && ! window.confirm( data.message ) ) {
			event.preventDefault();
		}
	} );
} )();
