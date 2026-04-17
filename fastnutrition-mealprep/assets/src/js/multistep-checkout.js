/**
 * Multi-step checkout controller.
 *
 * The Blocks checkout is composed of inner blocks. We take each inner section and route it to
 * one of three steps using DOM classes. Navigation is done with Previous/Next buttons injected
 * into the wrapper.
 *
 * Step 1: contact + shipping address
 * Step 2: slot picker
 * Step 3: payment + order summary + place order
 */

const STEP_MAP = {
	1: [ 'wc-block-checkout__contact-fields', 'wc-block-checkout__shipping-fields', 'wc-block-checkout__billing-fields' ],
	2: [ 'fn-slot-picker', 'wc-block-checkout__shipping-methods' ],
	3: [ 'wc-block-checkout__payment-methods', 'wc-block-checkout__actions', 'wc-block-components-order-summary' ],
};

function mount() {
	const root = document.querySelector( '.fn-multistep-checkout' );
	if ( ! root ) return;
	if ( root.dataset.fnMounted ) return;
	root.dataset.fnMounted = '1';

	let currentStep = 1;

	const classify = () => {
		[ 1, 2, 3 ].forEach( ( step ) => {
			STEP_MAP[ step ].forEach( ( cls ) => {
				root.querySelectorAll( `.${ cls }` ).forEach( ( el ) => el.setAttribute( 'data-fn-step', step ) );
			} );
		} );
	};

	const render = () => {
		root.querySelectorAll( '[data-fn-step]' ).forEach( ( el ) => {
			el.style.display = parseInt( el.getAttribute( 'data-fn-step' ), 10 ) === currentStep ? '' : 'none';
		} );
		nav.querySelector( '.fn-prev' ).disabled = currentStep === 1;
		nav.querySelector( '.fn-next' ).style.display = currentStep === 3 ? 'none' : '';
		nav.querySelector( '.fn-step-label' ).textContent = `Step ${ currentStep } / 3`;
	};

	const nav = document.createElement( 'div' );
	nav.className = 'fn-step-nav';
	nav.innerHTML = `
		<button type="button" class="fn-prev button">Back</button>
		<span class="fn-step-label"></span>
		<button type="button" class="fn-next button button-primary">Next</button>
	`;
	root.prepend( nav );

	const canAdvance = () => {
		if ( currentStep === 1 ) {
			const pc = root.querySelector( 'input[id$="-postcode"]' )?.value?.trim();
			if ( ! pc ) {
				alert( 'Please enter a postcode to continue.' );
				return false;
			}
		}
		if ( currentStep === 2 ) {
			const selected = root.querySelector( '.fn-slot-picker input[name="fn-slot"]:checked' );
			if ( ! selected ) {
				alert( 'Please pick a delivery or collection slot.' );
				return false;
			}
		}
		return true;
	};

	nav.querySelector( '.fn-prev' ).addEventListener( 'click', () => {
		currentStep = Math.max( 1, currentStep - 1 );
		render();
	} );
	nav.querySelector( '.fn-next' ).addEventListener( 'click', () => {
		if ( ! canAdvance() ) return;
		currentStep = Math.min( 3, currentStep + 1 );
		render();
	} );

	// The DOM may settle after the Blocks checkout hydrates — rerun classify/render on mutation.
	const observer = new MutationObserver( () => {
		classify();
		render();
	} );
	observer.observe( root, { childList: true, subtree: true } );

	classify();
	render();
}

if ( document.readyState !== 'loading' ) {
	mount();
} else {
	document.addEventListener( 'DOMContentLoaded', mount );
}
