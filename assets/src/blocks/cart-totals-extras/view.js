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
		currencySymbol: cart?.totals?.currency_symbol || '£',
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

// Find every "Total" / "Estimated total" footer row in the page.
// Its parent .wc-block-components-totals-wrapper is the row we insert before,
// so our additions sit just above the bottom-line total — works for both
// the cart block (no subtotal row at all) and the checkout block (full breakdown).
function findInjectionPoints() {
	const points = [];
	document.querySelectorAll( '.wc-block-components-totals-footer-item' ).forEach( ( footer ) => {
		const wrapper = footer.closest( '.wc-block-components-totals-wrapper' );
		if ( wrapper && wrapper.parentElement ) {
			points.push( { container: wrapper.parentElement, anchor: wrapper } );
		}
	} );
	return points;
}

function makeItem( extraClass, label, value ) {
	const item = document.createElement( 'div' );
	item.className = `wc-block-components-totals-item fn-totals-row ${ extraClass }`;
	const labelEl = document.createElement( 'span' );
	labelEl.className = 'wc-block-components-totals-item__label';
	labelEl.textContent = label;
	const valueEl = document.createElement( 'div' );
	valueEl.className = 'wc-block-components-totals-item__value';
	const strong = document.createElement( 'strong' );
	strong.textContent = value;
	valueEl.appendChild( strong );
	item.appendChild( labelEl );
	item.appendChild( valueEl );
	return item;
}

function makeWrapper() {
	const wrapper = document.createElement( 'div' );
	wrapper.className = 'wc-block-components-totals-wrapper fn-totals-wrapper';
	return wrapper;
}

function hideShippingInCart() {
	// Only act inside the Cart block (not the Checkout block).
	const cartBlock = document.querySelector( '.wp-block-woocommerce-cart' );
	if ( ! cartBlock ) {
		return;
	}
	cartBlock.querySelectorAll( '.wc-block-components-totals-shipping' ).forEach( ( el ) => {
		const parentWrapper = el.closest( '.wc-block-components-totals-wrapper' );
		if ( parentWrapper && parentWrapper.children.length === 1 && parentWrapper.contains( el ) ) {
			parentWrapper.style.display = 'none';
		} else {
			el.style.display = 'none';
		}
	} );
}

function render() {
	hideShippingInCart();

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

	points.forEach( ( { container, anchor } ) => {
		// Remove our previous wrapper(s) in this container so we don't stack duplicates.
		container.querySelectorAll( ':scope > .fn-totals-wrapper' ).forEach( ( el ) => el.remove() );

		if ( ! showSavings && ! showAddons ) {
			return;
		}

		const wrapper = makeWrapper();
		if ( showSavings ) {
			wrapper.appendChild(
				makeItem(
					'fn-totals-savings',
					__( 'You saved (bundle)', 'fastnutrition-mealprep' ),
					`-${ formatMoney( ext.bundleSavings, ext ) }`
				)
			);
		}
		if ( showAddons ) {
			wrapper.appendChild(
				makeItem(
					'fn-totals-addons',
					__( 'Add-ons total', 'fastnutrition-mealprep' ),
					formatMoney( ext.addonTotal, ext )
				)
			);
		}
		container.insertBefore( wrapper, anchor );
	} );
}

let lastSig = '';

function init() {
	const dataApi = window?.wp?.data;

	if ( dataApi && typeof dataApi.subscribe === 'function' ) {
		dataApi.subscribe( () => {
			const ext = getExtensions();
			const sig = ext ? `${ ext.bundleSavings }|${ ext.addonTotal }` : '';
			if ( sig !== lastSig ) {
				lastSig = sig;
				render();
			} else if ( ! document.querySelector( '.fn-totals-wrapper' ) ) {
				render();
			}
		} );
	}

	// Watch the DOM in case WC re-renders the totals area.
	const observer = new MutationObserver( () => {
		if ( ! document.querySelector( '.fn-totals-wrapper' ) ) {
			render();
		}
		// And re-hide shipping if it gets re-rendered.
		hideShippingInCart();
	} );
	observer.observe( document.body, { childList: true, subtree: true } );

	render();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
