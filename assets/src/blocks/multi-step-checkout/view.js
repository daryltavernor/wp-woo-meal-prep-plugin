import './style.css';
import { createRoot, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const extensionCartUpdate = ( args ) => {
	const fn = window?.wc?.blocksCheckout?.extensionCartUpdate;
	return typeof fn === 'function' ? fn( args ) : Promise.resolve();
};

const STEPS = [
	{
		key: 'address',
		label: __( 'Your details', 'fastnutrition-mealprep' ),
		selectors: [
			'.wp-block-woocommerce-checkout-contact-information-block',
			'.wp-block-woocommerce-checkout-shipping-address-block',
			'.wp-block-woocommerce-checkout-billing-address-block',
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
			'.wp-block-woocommerce-checkout-express-payment-block',
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

function mountSlotPicker( container ) {
	// Only a mount that is a DIRECT child of `container` is canonical.
	// Anything else (left over from older plugin versions that mounted
	// the slot picker inside the form or elsewhere in the page) is
	// cleaned up so the page never ends up with duplicate slot pickers.
	const canonical = Array.from( container.children ).find(
		( el ) =>
			el.classList && el.classList.contains( 'fn-slot-picker-mount' )
	);
	document.querySelectorAll( '.fn-slot-picker-mount' ).forEach( ( el ) => {
		if ( el !== canonical ) {
			el.remove();
		}
	} );
	if ( canonical ) {
		return canonical;
	}
	const mount = document.createElement( 'div' );
	mount.className = 'fn-slot-picker-mount';
	container.appendChild( mount );
	createRoot( mount ).render( <SlotPicker /> );
	return mount;
}

const VISIBLE_LIMIT = 4;

function SlotPicker() {
	// Collection is shown first and is the default the customer lands on.
	const [ method, setMethod ] = useState( 'collection' );
	const [ postcode, setPostcode ] = useState( '' );
	const [ options, setOptions ] = useState( [] );
	const [ selected, setSelected ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ showAll, setShowAll ] = useState( false );
	// Fees advertised on the tabs. Collection is always free; delivery is the
	// zone's flat rate for the entered postcode (pre-formatted string, or null
	// when it can't be reduced to a single figure).
	const [ deliveryFee, setDeliveryFee ] = useState( null );

	useEffect( () => {
		const readPostcode = () => {
			const input = document.querySelector(
				'input[autocomplete="shipping postal-code"], input[autocomplete="billing postal-code"], input[id*="postcode"]'
			);
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
		apiFetch( {
			path: `fastnutrition/v1/slots?postcode=${ encodeURIComponent(
				postcode
			) }&method=${ method }`,
		} )
			.then( ( r ) => {
				setOptions( r.options || [] );
				setDeliveryFee( r.fees?.delivery ?? null );
			} )
			.finally( () => setLoading( false ) );
	}, [ postcode, method ] );

	// Collapse the list — and clear any prior pick — whenever the method or
	// postcode changes: the available slots differ, so a previous selection is
	// no longer valid. Clearing it also removes the order-summary delivery line
	// (gated on data-fn-fulfilled) until the customer picks again.
	useEffect( () => {
		setShowAll( false );
		setSelected( null );
	}, [ method, postcode ] );

	// Mirror "a slot has been chosen" onto the checkout root so CSS can reveal
	// the order-summary shipping line ONLY after the customer has actually
	// picked delivery/collection. Before that there is nothing meaningful to
	// show; after it, the line appears with the method + cost.
	useEffect( () => {
		const root = document.querySelector( '.wp-block-woocommerce-checkout' );
		if ( root ) {
			root.dataset.fnFulfilled = selected ? '1' : '0';
		}
	}, [ selected ] );

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
			<h3 className="fn-slot-heading">
				{ __(
					'Choose your delivery or collection',
					'fastnutrition-mealprep'
				) }
			</h3>
			<p className="fn-slot-help">
				{ __(
					'Delivery availability is based on your postcode above. Collection is open to everyone.',
					'fastnutrition-mealprep'
				) }
			</p>
			<div className="fn-slot-tabs">
				<div className="fn-slot-tab">
					<button
						type="button"
						className={ method === 'collection' ? 'is-active' : '' }
						onClick={ () => setMethod( 'collection' ) }
					>
						{ __( 'Collection', 'fastnutrition-mealprep' ) }
					</button>
					<span className="fn-fee-free">
						{ __( 'Free', 'fastnutrition-mealprep' ) }
					</span>
				</div>
				<div className="fn-slot-tab">
					<button
						type="button"
						className={ method === 'delivery' ? 'is-active' : '' }
						onClick={ () => setMethod( 'delivery' ) }
					>
						{ __( 'Delivery', 'fastnutrition-mealprep' ) }
					</button>
					{ deliveryFee && (
						<small className="fn-fee">{ deliveryFee }</small>
					) }
				</div>
			</div>
			{ loading && (
				<p>
					{ __( 'Checking availability…', 'fastnutrition-mealprep' ) }
				</p>
			) }
			{ ! loading && options.length === 0 && postcode && (
				<p className="fn-slot-empty">
					{ __(
						'No slots available for this postcode. Try collection, or pick a different address.',
						'fastnutrition-mealprep'
					) }
				</p>
			) }
			{ ! loading && ! postcode && (
				<p className="fn-slot-empty">
					{ __(
						'Enter your postcode above and availability will appear here.',
						'fastnutrition-mealprep'
					) }
				</p>
			) }
			{ ! loading && ! selected && options.length > 0 && (
				<p className="fn-slot-hint">
					{ __(
						'Pick a date and time below to continue.',
						'fastnutrition-mealprep'
					) }
				</p>
			) }
			<div className="fn-slot-dates">
				{ ( showAll ? options : options.slice( 0, VISIBLE_LIMIT ) ).map(
					( day ) => (
						<div
							key={ `${ day.date }-${ day.profile_id }` }
							className="fn-slot-day"
						>
							<h4>
								{ day.day_label }{ ' ' }
								<small>({ day.profile_name })</small>
							</h4>
							<div className="fn-slot-rows">
								{ day.slots.map( ( slot ) => {
									const isActive =
										selected &&
										selected.date === day.date &&
										selected.slot.start === slot.start &&
										selected.profile_id === day.profile_id;
									return (
										<button
											key={ `${ slot.start }-${ slot.end }` }
											type="button"
											className={
												isActive ? 'is-active' : ''
											}
											onClick={ () =>
												setSelected( {
													date: day.date,
													profile_id: day.profile_id,
													slot,
												} )
											}
										>
											{ slot.start }–{ slot.end }
											{ slot.remaining !== null && (
												<small>
													{ ' ' }
													({ slot.remaining })
												</small>
											) }
										</button>
									);
								} ) }
							</div>
						</div>
					)
				) }
			</div>
			{ options.length > VISIBLE_LIMIT && (
				<button
					type="button"
					className="fn-slot-more"
					onClick={ () => setShowAll( ( v ) => ! v ) }
				>
					{ showAll
						? __( 'Show fewer', 'fastnutrition-mealprep' )
						: sprintf(
								/* translators: %d: number of additional dates */
								__( 'Show %d more', 'fastnutrition-mealprep' ),
								options.length - VISIBLE_LIMIT
						  ) }
				</button>
			) }
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
	const fields = checkout.querySelector(
		'.wp-block-woocommerce-checkout-fields-block'
	);
	if ( ! fields ) {
		return false;
	}

	// Slot picker mount lives at the checkout root (same level as nav and
	// the action bar). The inner fields block is React-managed by WC, so
	// even direct children of it get stripped on iOS during reconciliation.
	// Layout is fixed by CSS Grid on the checkout root + display:contents
	// on the inner sidebar-layout — see style.css.
	const slotMount = mountSlotPicker( checkout );
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
	actions.innerHTML = `<button type="button" class="fn-step-back">${ __(
		'Back',
		'fastnutrition-mealprep'
	) }</button> <button type="button" class="fn-step-next">${ __(
		'Next',
		'fastnutrition-mealprep'
	) }</button>`;
	// Append to the checkout root (sibling of the fields and totals blocks),
	// not inside fields. WC's React reconciliation of the fields block on
	// iOS Safari was stripping any manually-appended child — including this
	// action bar. Living at the checkout-root level means React's per-block
	// trees can't touch it.
	checkout.appendChild( actions );

	let active = 'address';
	// Hoist the Stripe Express Checkout component to the top of the fields
	// column so on the payment step it sits above the card form, not below
	// it. Idempotent: only moves when not already first, so the
	// MutationObserver below doesn't loop.
	const hoistExpress = () => {
		const express = fields.querySelector(
			'.wp-block-woocommerce-checkout-express-payment-block, .wc-block-components-express-payment'
		);
		if ( express && fields.firstChild !== express ) {
			fields.insertBefore( express, fields.firstChild );
		}
	};
	// WC's React tree re-renders blocks on focus/blur/keyboard events
	// (significantly more aggressive on iOS Safari) and strips children
	// it didn't render. Re-attach our nav + action bar + slot picker
	// mount at the start of every render. Idempotent: only re-inserts
	// when a node has actually been detached from the checkout root.
	const ensureChrome = () => {
		if ( ! checkout.contains( nav ) ) {
			checkout.prepend( nav );
		}
		// Slot mount goes BEFORE the action bar so the grid auto-places it
		// in the same row as fields-block / totals; the action bar then
		// drops to its own row at the bottom.
		if ( slotMount && slotMount.parentElement !== checkout ) {
			if ( actions.parentElement === checkout ) {
				checkout.insertBefore( slotMount, actions );
			} else {
				checkout.appendChild( slotMount );
			}
		}
		if ( ! checkout.contains( actions ) ) {
			checkout.appendChild( actions );
		}
	};
	const render = () => {
		ensureChrome();
		hoistExpress();
		// Mirror the active step on the checkout root so CSS can target
		// elements the JS selector loop doesn't reach (e.g. Stripe-rendered
		// express checkout that has no `wp-block-...` wrapper class).
		checkout.dataset.fnStep = active;
		nav.querySelectorAll( 'li' ).forEach( ( li ) =>
			li.classList.toggle( 'is-active', li.dataset.step === active )
		);
		STEPS.forEach( ( step ) => {
			const show = step.key === active;
			step.selectors.forEach( ( sel ) => {
				// Query from `checkout`, not `fields`: WC renders the
				// express payment block above the fields column, so a
				// fields-scoped query would miss it.
				checkout.querySelectorAll( sel ).forEach( ( el ) => {
					el.style.display = show ? '' : 'none';
				} );
			} );
		} );
		const idx = STEPS.findIndex( ( s ) => s.key === active );
		const back = checkout.querySelector( '.fn-step-back' );
		const next = checkout.querySelector( '.fn-step-next' );
		if ( back ) {
			// Step 1's Back leaves checkout for the basket, so it stays enabled.
			back.disabled = false;
		}
		if ( next ) {
			next.style.display = idx === STEPS.length - 1 ? 'none' : '';
		}

		// On the final (payment) step, host our BACK button inside WC's
		// actions row so it sits beside Place Order. Otherwise restore it
		// to our own action bar at the bottom of the form.
		const wcActionsRow = checkout.querySelector(
			'.wc-block-checkout__actions_row'
		);
		const onLastStep = idx === STEPS.length - 1;
		if ( onLastStep && wcActionsRow && back ) {
			if ( ! wcActionsRow.contains( back ) ) {
				wcActionsRow.insertBefore( back, wcActionsRow.firstChild );
			}
			if ( actions && actions.parentElement ) {
				// Inline !important so this beats the .fn-step-actions
				// CSS rule, which uses !important to defend against
				// theme rules that would otherwise hide the bar on
				// steps 1-2.
				actions.style.setProperty( 'display', 'none', 'important' );
			}
		} else {
			if ( back && actions && ! actions.contains( back ) ) {
				actions.insertBefore( back, actions.firstChild );
			}
			if ( actions ) {
				actions.style.removeProperty( 'display' );
			}
		}
	};

	const slotIsSelected = () =>
		!! checkout.querySelector( '.fn-slot-rows button.is-active' );
	const SLOT_STEP_IDX = STEPS.findIndex( ( s ) => s.key === 'slot' );
	const ADDRESS_STEP_IDX = STEPS.findIndex( ( s ) => s.key === 'address' );
	const ADDRESS_SELECTORS = STEPS[ ADDRESS_STEP_IDX ].selectors;

	const isVisible = ( el ) =>
		!! (
			el &&
			( el.offsetWidth || el.offsetHeight || el.getClientRects().length )
		);

	// Validate the contact + address step before letting the customer advance.
	// WC Blocks renders required inputs with `required` / aria-required="true";
	// we check every visible, enabled required field in the step's containers,
	// plus the phone number explicitly (merchants often leave it optional, but
	// orders need a contact number). Returns true only when everything's valid;
	// on failure it nudges WC to surface its inline errors and focuses the first
	// offending field.
	const flagInvalid = ( field, bag ) => {
		if ( ! bag.first ) {
			bag.first = field;
		}
		// Best-effort: make WC Blocks show its own inline error for the field.
		[ 'input', 'change', 'blur', 'focusout' ].forEach( ( type ) => {
			field.dispatchEvent( new Event( type, { bubbles: true } ) );
		} );
	};
	const validateAddressStep = () => {
		const bag = { first: null };

		ADDRESS_SELECTORS.forEach( ( sel ) => {
			checkout.querySelectorAll( sel ).forEach( ( container ) => {
				if ( ! isVisible( container ) ) {
					return;
				}
				container
					.querySelectorAll( 'input, select, textarea' )
					.forEach( ( field ) => {
						if (
							field.disabled ||
							field.type === 'hidden' ||
							! isVisible( field )
						) {
							return;
						}
						const required =
							field.required ||
							field.getAttribute( 'aria-required' ) === 'true';
						if ( ! required ) {
							return;
						}
						const value = ( field.value || '' ).trim();
						const valid =
							'' !== value &&
							( typeof field.checkValidity !== 'function' ||
								field.checkValidity() );
						if ( ! valid ) {
							flagInvalid( field, bag );
						}
					} );
			} );
		} );

		// Phone is enforced regardless of the WC "optional" setting.
		checkout
			.querySelectorAll(
				'input[type="tel"], input[autocomplete*="tel"], input[id$="-phone"], input[id*="phone" i]'
			)
			.forEach( ( field ) => {
				if ( field.disabled || ! isVisible( field ) ) {
					return;
				}
				if ( '' === ( field.value || '' ).trim() ) {
					flagInvalid( field, bag );
				}
			} );

		if ( bag.first ) {
			bag.first.focus();
			bag.first.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			return false;
		}
		return true;
	};
	const flashSlotRequired = () => {
		const picker = checkout.querySelector( '.fn-slot-picker' );
		if ( ! picker ) {
			return;
		}
		picker.classList.add( 'fn-slot-required' );
		picker.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		window.setTimeout(
			() => picker.classList.remove( 'fn-slot-required' ),
			2400
		);
	};

	nav.addEventListener( 'click', ( e ) => {
		if ( e.target.tagName !== 'LI' ) {
			return;
		}
		const target = e.target.dataset.step;
		const targetIdx = STEPS.findIndex( ( s ) => s.key === target );
		// Block jumping past the address step with required fields empty.
		if ( targetIdx > ADDRESS_STEP_IDX && ! validateAddressStep() ) {
			active = 'address';
			render();
			return;
		}
		// Block jumping past the slot step without a selection.
		if ( targetIdx > SLOT_STEP_IDX && ! slotIsSelected() ) {
			active = 'slot';
			render();
			flashSlotRequired();
			return;
		}
		active = target;
		render();
	} );
	checkout.addEventListener( 'click', ( e ) => {
		if ( e.target.classList.contains( 'fn-step-next' ) ) {
			const idx = STEPS.findIndex( ( s ) => s.key === active );
			// Contact + address fields (incl. phone) must be valid to advance.
			if ( active === 'address' && ! validateAddressStep() ) {
				return;
			}
			// Slot picker is mandatory: can't leave the slot step without a pick.
			if ( active === 'slot' && ! slotIsSelected() ) {
				flashSlotRequired();
				return;
			}
			if ( idx < STEPS.length - 1 ) {
				active = STEPS[ idx + 1 ].key;
				render();
			}
		}
		if ( e.target.classList.contains( 'fn-step-back' ) ) {
			const idx = STEPS.findIndex( ( s ) => s.key === active );
			if ( idx === 0 ) {
				// First step: leave checkout and return to the basket.
				const cartUrl = window.fnMultiStep?.cartUrl;
				if ( cartUrl ) {
					window.location.href = cartUrl;
				}
				return;
			}
			active = STEPS[ idx - 1 ].key;
			render();
		}
	} );

	const observer = new MutationObserver( () => render() );
	observer.observe( checkout, { childList: true, subtree: true } );

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
