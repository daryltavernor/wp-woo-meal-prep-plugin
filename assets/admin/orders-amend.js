/**
 * Orders screen — "Change fulfilment date" button.
 *
 * Adds a button to the orders list toolbar that collects the ticked orders
 * (HPOS uses name="id[]", the legacy screen uses name="post[]") and sends them
 * to the dedicated amend page. Avoids the bulk-actions dropdown entirely.
 */
( function () {
	'use strict';

	function collectIds() {
		var ids = [];
		document
			.querySelectorAll( 'input[name="id[]"]:checked, input[name="post[]"]:checked' )
			.forEach( function ( cb ) {
				if ( cb.value && /^\d+$/.test( cb.value ) ) {
					ids.push( cb.value );
				}
			} );
		return ids;
	}

	function makeButton() {
		var cfg = window.fnOrdersAmend || {};
		var btn = document.createElement( 'a' );
		btn.href = '#';
		btn.className = 'button';
		btn.style.marginLeft = '6px';
		btn.textContent = cfg.label || 'Change fulfilment date';
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var ids = collectIds();
			if ( ! ids.length ) {
				window.alert( cfg.none || 'Please tick one or more orders first.' );
				return;
			}
			window.location.href =
				cfg.url +
				'&ids=' +
				ids.join( ',' ) +
				'&return=' +
				encodeURIComponent( window.location.href );
		} );
		return btn;
	}

	function init() {
		if ( ! window.fnOrdersAmend ) {
			return;
		}
		// Place it in the top toolbar next to the bulk-actions controls.
		var host =
			document.querySelector( '.tablenav.top .bulkactions' ) ||
			document.querySelector( '.tablenav.top .alignleft.actions' ) ||
			document.querySelector( '.tablenav.top' );
		if ( ! host || host.querySelector( '.fn-amend-btn' ) ) {
			return;
		}
		var btn = makeButton();
		btn.classList.add( 'fn-amend-btn' );
		host.appendChild( btn );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
