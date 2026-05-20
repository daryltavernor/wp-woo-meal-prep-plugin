import './style.css';
import { __ } from '@wordpress/i18n';

const CART_STORE = 'wc/store/cart';
const NS = 'fastnutrition-mealprep';

function getExtensions() {
	const dataApi = window?.wp?.data;
	if ( ! dataApi || typeof dataApi.select !== 'function' ) {
		return null;
	}
	const store = dataApi.select( CART_STORE );
	if ( ! store || typeof store.getCartData !== 'function' ) {
		return null;
	}
	const cart = store.getCartData();
	const ext = cart?.extensions?.[ NS ];
	if ( ! ext ) {
		return null;
	}
	return {
		addonTotal:     Number( ext.addon_total || 0 ),
		bundleSavings:  Number( ext.bundle_savings || 0 ),
		currencySymbol: cart?.totals?.currency_symbol || cart?.totals?.currency_prefix || '£',
		currencyMinor:  Number( cart?.totals?.currency_minor_unit ?? 2 ),
		currencyDec:    cart?.totals?.currency_decimal_separator || '.',
		currencyThou:   cart?.totals?.currency_thousand_separator || ',',
		currencyPrefix: cart?.totals?.currency_prefix ?? null,
		currencySuffix: cart?.totals?.currency_suffix ?? null,
	};
}

function formatMoney( value, currency ) {
	if ( ! currency ) {
		return value.toFixed( 2 );
	}
	const fixed = Math.abs( value ).toFixed( currency.currencyMinor );
	const [ whole, dec ] = fixed.split( '.' );
	const wholeWithSep = whole.replace( /\B(?=(\d{3})+(?!\d))/g, currency.currencyThou );
	const num = dec ? `${ wholeWithSep }${ currency.currencyDec }${ dec }` : wholeWithSep;
	const symbol = currency.currencySymbol || '';
	const sign = value < 0 ? '-' : '';
	if ( currency.currencyPrefix != null && currency.currencyPrefix !== '' ) {
		return `${ sign }${ currency.currencyPrefix }${ num }${ currency.currencySuffix || '' }`;
	}
	return `${ sign }${ symbol }${ num }${ currency.currencySuffix || '' }`;
}

function findInjectionPoints() {
	// Each summary section block contains a .wc-block-components-totals-wrapper.
	// We anchor next to the subtotal block so our rows sit just under it.
	const subtotalBlocks = document.querySelectorAll(
		'.wp-block-woocommerce-cart-order-summary-subtotal-block, .wp-block-woocommerce-checkout-order-summary-subtotal-block'
	);
	const points = [];
	subtotalBlocks.forEach( ( block ) => {
		const wrapper = block.querySelector( '.wc-block-components-totals-wrapper' );
		const summaryContainer = block.parentElement;
		if ( summaryContainer ) {
			points.push( { container: summaryContainer, after: block } );
		} else if ( wrapper && wrapper.parentElement ) {
			points.push( { container: wrapper.parentElement, after: wrapper } );
		}
	} );
	return points;
}

function makeRow( extraClass, label, value ) {
	const row = document.createElement( 'div' );
	row.className = `fn-totals-row ${ extraClass }`;
	row.dataset.fnRow = '1';
	const labelEl = document.createElement( 'span' );
	labelEl.className = 'fn-totals-label';
	labelEl.textContent = label;
	const valueEl = document.createElement( 'span' );
	valueEl.className = 'fn-totals-value';
	valueEl.textContent = value;
	row.appendChild( labelEl );
	row.appendChild( valueEl );
	return row;
}

let lastSerialized = '';

function render() {
	const ext = getExtensions();
	if ( ! ext ) {
		return;
	}

	const showSavings = ext.bundleSavings > 0.0001;
	const showAddons  = ext.addonTotal > 0.0001;

	const points = findInjectionPoints();
	if ( ! points.length ) {
		return;
	}

	points.forEach( ( { container, after } ) => {
		// Wipe previous rows we own in this container before re-rendering.
		container.querySelectorAll( ':scope > .fn-totals-row' ).forEach( ( el ) => el.remove() );

		const fragments = [];
		if ( showSavings ) {
			fragments.push(
				makeRow(
					'fn-totals-savings',
					__( 'You saved (bundle)', 'fastnutrition-mealprep' ),
					`-${ formatMoney( ext.bundleSavings, ext ) }`
				)
			);
		}
		if ( showAddons ) {
			fragments.push(
				makeRow(
					'fn-totals-addons',
					__( 'Add-ons total', 'fastnutrition-mealprep' ),
					formatMoney( ext.addonTotal, ext )
				)
			);
		}
		// Insert in order, immediately after the subtotal block.
		let cursor = after;
		fragments.forEach( ( frag ) => {
			cursor.parentElement.insertBefore( frag, cursor.nextSibling );
			cursor = frag;
		} );
	} );
}

function init() {
	const dataApi = window?.wp?.data;
	if ( ! dataApi ) {
		return;
	}

	// 1) Re-render whenever the cart data changes (debounced via signature).
	if ( typeof dataApi.subscribe === 'function' ) {
		dataApi.subscribe( () => {
			const ext = getExtensions();
			if ( ! ext ) {
				return;
			}
			const sig = `${ ext.bundleSavings }|${ ext.addonTotal }`;
			if ( sig !== lastSerialized ) {
				lastSerialized = sig;
				render();
			} else {
				// DOM may have been re-rendered by WC; re-attach if our rows are gone.
				if ( ! document.querySelector( '.fn-totals-row' ) && ( ext.bundleSavings > 0 || ext.addonTotal > 0 ) ) {
					render();
				}
			}
		} );
	}

	// 2) Watch the DOM in case Woo blocks remount without a wp.data change.
	const observer = new MutationObserver( () => {
		if ( ! document.querySelector( '.fn-totals-row' ) ) {
			render();
		}
	} );
	observer.observe( document.body, { childList: true, subtree: true } );

	render();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
