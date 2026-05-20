import './style.css';
import { createRoot, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const extensionCartUpdate = ( args ) => {
	const fn = window?.wc?.blocksCheckout?.extensionCartUpdate;
	return typeof fn === 'function' ? fn( args ) : Promise.resolve();
};

const STEPS = [
	{
		key: 'address',
		label: __( 'Your details', 'fastnutrition-mealprep' ),
		selectors: [
			'.wp-block-woocommerce-checkout-express-payment-block',
			'.wp-block-woocommerce-checkout-contact-information-block',
			'.wp-block-woocommerce-checkout-shipping-address-block',
			'.wp-block-woocommerce-checkout-billing-address-block',
			'.wp-block-woocommerce-checkout-shipping-method-block',
			'.wp-block-woocommerce-checkout-pickup-options-block',
		],
	},
	{
		key: 'slot',
		label: __( 'Delivery or collection', 'fastnutrition-mealprep' ),
		selectors: [ '.fn-slot-picker-mount' ],
	},
	{
		key: 'payment',
		label: __( 'Payment', 'fastnutrition-mealprep' ),
		selectors: [
			'.wp-block-woocommerce-checkout-payment-block',
			'.wp-block-woocommerce-checkout-additional-information-block',
			'.wp-block-woocommerce-checkout-order-note-block',
			'.wp-block-woocommerce-checkout-terms-block',
			'.wp-block-woocommerce-checkout-actions-block',
		],
	},
];

function defaultBillingSameAsShipping( checkout ) {
	let done = false;
	const trySetChecked = () => {
		if ( done ) {
			return true;
		}
		const cb = checkout.querySelector(
			'.wc-block-checkout__use-address-for-billing input[type="checkbox"], input[type="checkbox"][id*="use-shipping-as-billing" i], input[type="checkbox"][name*="useShippingAsBilling" i]'
		);
		if ( ! cb ) {
			return false;
		}
		if ( ! cb.checked ) {
			cb.click();
		}
		done = true;
		return true;
	};
	if ( trySetChecked() ) {
		return;
	}
	const observer = new MutationObserver( () => {
		if ( trySetChecked() ) {
			observer.disconnect();
		}
	} );
	observer.observe( checkout, { childList: true, subtree: true } );
	window.setTimeout( () => observer.disconnect(), 8000 );
}

function mountSlotPicker( fieldsBlock ) {
	if ( fieldsBlock.querySelector( '.fn-slot-picker-mount' ) ) {
		return;
	}
	const mount = document.createElement( 'div' );
	mount.className = 'fn-slot-picker-mount';
	const anchor = fieldsBlock.querySelector( '.wp-block-woocommerce-checkout-shipping-method-block' )
		|| fieldsBlock.querySelector( '.wp-block-woocommerce-checkout-shipping-address-block' )
		|| fieldsBlock.querySelector( '.wp-block-woocommerce-checkout-billing-address-block' );
	if ( anchor && anchor.parentElement ) {
		anchor.parentElement.insertBefore( mount, anchor.nextSibling );
	} else {
		fieldsBlock.appendChild( mount );
	}
	createRoot( mount ).render( <SlotPicker /> );
}

function SlotPicker() {
	const [ method, setMethod ] = useState( 'delivery' );
	const [ postcode, setPostcode ] = useState( '' );
	const [ options, setOptions ] = useState( [] );
	const [ selected, setSelected ] = useState( null );
	const [ loading, setLoading ] = useState( false );

	useEffect( () => {
		const readPostcode = () => {
			const input = document.querySelector( 'input[autocomplete="shipping postal-code"], input[autocomplete="billing postal-code"], input[id*="postcode"]' );
			if ( input ) {
				setPostcode( input.value );
			}
		};
		readPostcode();
		const id = window.setInterval( readPostcode, 1500 );
		return () => window.clearInterval( id );
	}, [] );

	useEffect( () => {
		if ( ! postcode ) {
			setOptions( [] );
			return;
		}
		setLoading( true );
		apiFetch( { path: `fastnutrition/v1/slots?postcode=${ encodeURIComponent( postcode ) }&method=${ method }` } )
			.then( ( r ) => setOptions( r.options || [] ) )
			.finally( () => setLoading( false ) );
	}, [ postcode, method ] );

	useEffect( () => {
		if ( ! selected ) {
			return;
		}
		extensionCartUpdate( {
			namespace: 'fastnutrition-mealprep',
			data: {
				action: 'set_fulfilment',
				fulfilment: {
					type: method,
					profile_id: selected.profile_id,
					date: selected.date,
					slot: selected.slot,
				},
			},
		} );
	}, [ selected, method ] );

	return (
		<div className="fn-slot-picker">
			<h3 className="fn-slot-heading">{ __( 'Choose your delivery or collection', 'fastnutrition-mealprep' ) }</h3>
			<p className="fn-slot-help">{ __( 'Delivery availability is based on your postcode above. Collection is open to everyone.', 'fastnutrition-mealprep' ) }</p>
			<div className="fn-slot-tabs">
				<button type="button" className={ method === 'delivery' ? 'is-active' : '' } onClick={ () => setMethod( 'delivery' ) }>
					{ __( 'Delivery', 'fastnutrition-mealprep' ) }
				</button>
				<button type="button" className={ method === 'collection' ? 'is-active' : '' } onClick={ () => setMethod( 'collection' ) }>
					{ __( 'Collection', 'fastnutrition-mealprep' ) }
				</button>
			</div>
			{ loading && <p>{ __( 'Checking availability…', 'fastnutrition-mealprep' ) }</p> }
			{ ! loading && options.length === 0 && postcode && (
				<p className="fn-slot-empty">{ __( 'No slots available for this postcode. Try collection, or pick a different address.', 'fastnutrition-mealprep' ) }</p>
			) }
			{ ! loading && ! postcode && (
				<p className="fn-slot-empty">{ __( 'Enter your postcode above and availability will appear here.', 'fastnutrition-mealprep' ) }</p>
			) }
			{ ! loading && ! selected && options.length > 0 && (
				<p className="fn-slot-hint">{ __( 'Pick a date and time below to continue.', 'fastnutrition-mealprep' ) }</p>
			) }
			<div className="fn-slot-dates">
				{ options.map( ( day ) => (
					<div key={ `${ day.date }-${ day.profile_id }` } className="fn-slot-day">
						<h4>{ day.day_label } <small>({ day.profile_name })</small></h4>
						<div className="fn-slot-rows">
							{ day.slots.map( ( slot ) => {
								const isActive = selected && selected.date === day.date && selected.slot.start === slot.start && selected.profile_id === day.profile_id;
								return (
									<button
										key={ `${ slot.start }-${ slot.end }` }
										type="button"
										className={ isActive ? 'is-active' : '' }
										onClick={ () => setSelected( { date: day.date, profile_id: day.profile_id, slot } ) }
									>
										{ slot.start }–{ slot.end }
										{ slot.remaining !== null && <small> ({ slot.remaining })</small> }
									</button>
								);
							} ) }
						</div>
					</div>
				) ) }
			</div>
		</div>
	);
}

function apply( root ) {
	const checkout = root.querySelector( '.wp-block-woocommerce-checkout' );
	if ( ! checkout ) {
		return false;
	}
	if ( checkout.dataset.fnMultistep === 'applied' ) {
		return true;
	}
	const fields = checkout.querySelector( '.wp-block-woocommerce-checkout-fields-block' );
	if ( ! fields ) {
		return false;
	}

	mountSlotPicker( fields );
	defaultBillingSameAsShipping( checkout );
	checkout.dataset.fnMultistep = 'applied';

	const nav = document.createElement( 'ol' );
	nav.className = 'fn-steps-nav';
	STEPS.forEach( ( s, i ) => {
		const li = document.createElement( 'li' );
		li.textContent = `${ i + 1 }. ${ s.label }`;
		li.dataset.step = s.key;
		nav.appendChild( li );
	} );
	checkout.prepend( nav );

	const actions = document.createElement( 'div' );
	actions.className = 'fn-step-actions';
	actions.innerHTML = `<button type="button" class="fn-step-back">${ __( 'Back', 'fastnutrition-mealprep' ) }</button> <button type="button" class="fn-step-next">${ __( 'Next', 'fastnutrition-mealprep' ) }</button>`;
	fields.appendChild( actions );

	let active = 'address';
	const render = () => {
		nav.querySelectorAll( 'li' ).forEach( ( li ) => li.classList.toggle( 'is-active', li.dataset.step === active ) );
		STEPS.forEach( ( step ) => {
			const show = step.key === active;
			step.selectors.forEach( ( sel ) => {
				fields.querySelectorAll( sel ).forEach( ( el ) => {
					el.style.display = show ? '' : 'none';
				} );
			} );
		} );
		const idx = STEPS.findIndex( ( s ) => s.key === active );
		const back = checkout.querySelector( '.fn-step-back' );
		const next = checkout.querySelector( '.fn-step-next' );
		if ( back ) {
			back.disabled = idx === 0;
		}
		if ( next ) {
			next.style.display = idx === STEPS.length - 1 ? 'none' : '';
		}
	};

	nav.addEventListener( 'click', ( e ) => {
		if ( e.target.tagName === 'LI' ) {
			active = e.target.dataset.step;
			render();
		}
	} );
	checkout.addEventListener( 'click', ( e ) => {
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

	const observer = new MutationObserver( () => render() );
	observer.observe( fields, { childList: true, subtree: true } );

	render();
	return true;
}

function boot() {
	if ( apply( document ) ) {
		return;
	}
	const observer = new MutationObserver( () => {
		if ( apply( document ) ) {
			observer.disconnect();
		}
	} );
	observer.observe( document.body, { childList: true, subtree: true } );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
