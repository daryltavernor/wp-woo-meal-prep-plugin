import './style.css';

/**
 * Groups the Cart/Checkout blocks DOM into 3 visible steps:
 * 1. Address (contact + shipping)
 * 2. Delivery/Collection slot
 * 3. Payment + order summary
 *
 * Works against the Woo Checkout block structure (post 9.0). If the DOM
 * does not match what we expect we bail so the normal checkout shows.
 */
const STEPS = [
	{
		key: 'address',
		label: 'Your details',
		matchers: [
			'.wp-block-woocommerce-checkout-contact-information-block',
			'.wp-block-woocommerce-checkout-shipping-address-block',
			'.wp-block-woocommerce-checkout-billing-address-block',
		],
	},
	{
		key: 'slot',
		label: 'Delivery or collection',
		matchers: [ '.wp-block-fastnutrition-slot-picker' ],
	},
	{
		key: 'payment',
		label: 'Payment',
		matchers: [
			'.wp-block-woocommerce-checkout-payment-block',
			'.wp-block-woocommerce-checkout-order-summary-block',
			'.wp-block-woocommerce-checkout-order-note-block',
			'.wp-block-woocommerce-checkout-terms-block',
			'.wp-block-woocommerce-checkout-actions-block',
		],
	},
];

function build() {
	const root = document.querySelector( '[data-fn-multistep="1"]' );
	if ( ! root ) {
		return;
	}
	const checkout = root.querySelector( '.wp-block-woocommerce-checkout' );
	if ( ! checkout ) {
		return;
	}

	const nav = document.createElement( 'ol' );
	nav.className = 'fn-steps-nav';
	STEPS.forEach( ( s, i ) => {
		const li = document.createElement( 'li' );
		li.textContent = `${ i + 1 }. ${ s.label }`;
		li.dataset.step = s.key;
		nav.appendChild( li );
	} );
	root.prepend( nav );

	const fields = checkout.querySelector( '.wp-block-woocommerce-checkout-fields-block' );
	if ( ! fields ) {
		return;
	}

	const mark = ( node, step ) => {
		node.classList.add( `fn-step-${ step }` );
	};

	STEPS.forEach( ( step ) => {
		step.matchers.forEach( ( sel ) => {
			fields.querySelectorAll( sel ).forEach( ( n ) => mark( n, step.key ) );
		} );
	} );

	let active = 'address';
	const render = () => {
		nav.querySelectorAll( 'li' ).forEach( ( li ) => li.classList.toggle( 'is-active', li.dataset.step === active ) );
		STEPS.forEach( ( step ) => {
			fields.querySelectorAll( `.fn-step-${ step.key }` ).forEach( ( el ) => {
				el.style.display = step.key === active ? '' : 'none';
			} );
		} );
		if ( ! root.querySelector( '.fn-step-actions' ) ) {
			const actions = document.createElement( 'div' );
			actions.className = 'fn-step-actions';
			actions.innerHTML = '<button type="button" class="fn-step-back">Back</button> <button type="button" class="fn-step-next">Next</button>';
			fields.appendChild( actions );
		}
		const idx = STEPS.findIndex( ( s ) => s.key === active );
		root.querySelector( '.fn-step-back' ).disabled = idx === 0;
		root.querySelector( '.fn-step-next' ).style.display = idx === STEPS.length - 1 ? 'none' : '';
	};

	nav.addEventListener( 'click', ( e ) => {
		if ( e.target.tagName === 'LI' ) {
			active = e.target.dataset.step;
			render();
		}
	} );
	root.addEventListener( 'click', ( e ) => {
		if ( e.target.classList.contains( 'fn-step-next' ) ) {
			const idx = STEPS.findIndex( ( s ) => s.key === active );
			if ( idx < STEPS.length - 1 ) {
				active = STEPS[ idx + 1 ].key;
				render();
			}
		}
		if ( e.target.classList.contains( 'fn-step-back' ) ) {
			const idx = STEPS.findIndex( ( s ) => s.key === active );
			if ( idx > 0 ) {
				active = STEPS[ idx - 1 ].key;
				render();
			}
		}
	} );

	render();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', build );
} else {
	build();
}
