import './style.css';
import { __, sprintf } from '@wordpress/i18n';

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
		upsells:        Array.isArray( ext.upsells ) ? ext.upsells : [],
		surcharge:      ext.surcharge && typeof ext.surcharge === 'object' ? ext.surcharge : null,
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

function isOnCartPage() {
	return !! document.querySelector( '.wp-block-woocommerce-cart' );
}

function makeNote( extraClass, text ) {
	const row = document.createElement( 'div' );
	row.className = `fn-cart-note ${ extraClass }`;
	row.textContent = text;
	return row;
}

function renderNotes( ext ) {
	if ( ! isOnCartPage() ) {
		return;
	}
	const cartBlock = document.querySelector( '.wp-block-woocommerce-cart' );
	if ( ! cartBlock ) {
		return;
	}
	const summaryBlock = cartBlock.querySelector( '.wp-block-woocommerce-cart-order-summary-block' );
	const anchor       = summaryBlock?.querySelector( '.wp-block-woocommerce-cart-order-summary-coupon-form-block' )
		|| summaryBlock?.firstElementChild
		|| null;
	const container    = summaryBlock || cartBlock;

	container.querySelectorAll( ':scope > .fn-cart-notes-wrapper' ).forEach( ( el ) => el.remove() );

	const notes = [];
	if ( ext.surcharge && ext.surcharge.applies ) {
		const remaining = Number( ext.surcharge.remaining || 0 );
		const amount    = Number( ext.surcharge.amount || 0 );
		const label     = String( ext.surcharge.label || 'basket surcharge' ).toLowerCase();
		notes.push(
			makeNote(
				'fn-cart-note-surcharge',
				sprintf(
					/* translators: 1: remaining amount, 2: surcharge amount, 3: surcharge label */
					__( 'Spend %1$s more to skip the %2$s %3$s.', 'fastnutrition-mealprep' ),
					formatMoney( remaining, ext ),
					formatMoney( amount, ext ),
					label
				)
			)
		);
	}
	( ext.upsells || [] ).forEach( ( u ) => {
		const needed    = Number( u.needed || 0 );
		const nextQty   = Number( u.next_qty || 0 );
		const nextPrice = Number( u.next_price || 0 );
		const savings   = Number( u.total_savings || 0 );
		if ( needed <= 0 ) {
			return;
		}
		const text = savings > 0.0001
			? sprintf(
				/* translators: 1: meals to add, 2: tier qty, 3: tier price, 4: amount saved */
				__( 'Add %1$d more to unlock %2$d for %3$s — save %4$s.', 'fastnutrition-mealprep' ),
				needed,
				nextQty,
				formatMoney( nextPrice, ext ),
				formatMoney( savings, ext )
			)
			: sprintf(
				/* translators: 1: meals to add, 2: tier qty, 3: tier price */
				__( 'Add %1$d more to unlock %2$d for %3$s.', 'fastnutrition-mealprep' ),
				needed,
				nextQty,
				formatMoney( nextPrice, ext )
			);
		notes.push( makeNote( 'fn-cart-note-upsell', text ) );
	} );

	if ( ! notes.length ) {
		return;
	}

	const wrapper = document.createElement( 'div' );
	wrapper.className = 'fn-cart-notes-wrapper';
	notes.forEach( ( n ) => wrapper.appendChild( n ) );

	if ( anchor && anchor.parentElement === container ) {
		container.insertBefore( wrapper, anchor );
	} else {
		container.insertBefore( wrapper, container.firstChild );
	}
}

function render() {
	const ext = getExtensions();
	if ( ! ext ) {
		return;
	}

	renderNotes( ext );

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

let lastSig    = '';
let pendingRaf = null;

function scheduleRender() {
	if ( null !== pendingRaf ) {
		return;
	}
	pendingRaf = window.requestAnimationFrame( () => {
		pendingRaf = null;
		render();
	} );
}

function init() {
	const dataApi = window?.wp?.data;

	if ( dataApi && typeof dataApi.subscribe === 'function' ) {
		dataApi.subscribe( () => {
			const ext = getExtensions();
			if ( ! ext ) {
				return;
			}
			const surchargeSig = ext.surcharge
				? `${ ext.surcharge.applies ? 1 : 0 }|${ ext.surcharge.remaining }|${ ext.surcharge.amount }`
				: '';
			const upsellSig = ( ext.upsells || [] )
				.map( ( u ) => `${ u.product_id }:${ u.needed }:${ u.next_qty }` )
				.join( ',' );
			const sig = `${ ext.bundleSavings }|${ ext.addonTotal }|${ surchargeSig }|${ upsellSig }`;
			if ( sig !== lastSig ) {
				lastSig = sig;
				scheduleRender();
			}
		} );
	}

	// Initial render after the cart block has had a chance to hydrate.
	scheduleRender();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
