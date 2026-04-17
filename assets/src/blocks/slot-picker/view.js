import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

const extensionCartUpdate = ( args ) => {
	// Resolve at call time so the WC Blocks global is loaded before we run.
	const fn = window?.wc?.blocksCheckout?.extensionCartUpdate;
	if ( typeof fn === 'function' ) {
		return fn( args );
	}
	return Promise.resolve();
};

function SlotPicker() {
	const [ method, setMethod ] = useState( 'delivery' );
	const [ postcode, setPostcode ] = useState( '' );
	const [ options, setOptions ] = useState( [] );
	const [ selected, setSelected ] = useState( null );
	const [ loading, setLoading ] = useState( false );

	useEffect( () => {
		const input = document.querySelector( '#shipping-postcode, #billing-postcode, input[name="shippingAddress[postcode]"], input[autocomplete="postal-code"]' );
		if ( input ) {
			setPostcode( input.value );
			const listener = () => setPostcode( input.value );
			input.addEventListener( 'change', listener );
			input.addEventListener( 'blur', listener );
			return () => {
				input.removeEventListener( 'change', listener );
				input.removeEventListener( 'blur', listener );
			};
		}
		return undefined;
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
				<p className="fn-slot-empty">{ __( 'No slots available for this postcode.', 'fastnutrition-mealprep' ) }</p>
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

document.querySelectorAll( '[data-fn-slot-picker="1"]' ).forEach( ( el ) => {
	createRoot( el ).render( <SlotPicker /> );
} );
