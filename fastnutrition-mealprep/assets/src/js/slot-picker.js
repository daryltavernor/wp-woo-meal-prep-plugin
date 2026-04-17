/**
 * Slot picker — reads the postcode from the checkout form, fetches available slots, and writes
 * the chosen slot into the WC Store API via extensionCartUpdate so it's saved on the order.
 */
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const REST = window.FN_MEALPREP?.rest || '/wp-json/fastnutrition/v1/';
const NONCE = window.FN_MEALPREP?.nonce;
apiFetch.use( apiFetch.createNonceMiddleware( NONCE ) );

function groupByMethod( slots ) {
	return slots.reduce( ( acc, s ) => {
		acc[ s.method ] = acc[ s.method ] || {};
		acc[ s.method ][ s.date ] = acc[ s.method ][ s.date ] || [];
		acc[ s.method ][ s.date ].push( s );
		return acc;
	}, {} );
}

function SlotPicker() {
	const [ postcode, setPostcode ] = useState( '' );
	const [ slots, setSlots ] = useState( [] );
	const [ method, setMethod ] = useState( 'delivery' );
	const [ selected, setSelected ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		const read = () => {
			const input = document.querySelector( 'input[id$="-postcode"]' );
			if ( input?.value ) setPostcode( input.value.trim() );
		};
		read();
		document.body.addEventListener( 'change', read, true );
		return () => document.body.removeEventListener( 'change', read, true );
	}, [] );

	useEffect( () => {
		if ( ! postcode ) return;
		( async () => {
			setLoading( true );
			setError( '' );
			try {
				const res = await apiFetch( { path: `fastnutrition/v1/slots?postcode=${ encodeURIComponent( postcode ) }` } );
				setSlots( res );
			} catch ( e ) {
				setError( e.message || String( e ) );
			} finally {
				setLoading( false );
			}
		} )();
	}, [ postcode ] );

	const grouped = useMemo( () => groupByMethod( slots ), [ slots ] );

	const write = async ( s ) => {
		setSelected( s );
		try {
			await apiFetch( {
				path: '/wc/store/v1/batch',
				method: 'POST',
				data: {
					requests: [
						{
							path: '/wc/store/v1/cart/extensions',
							method: 'POST',
							body: {
								namespace: 'fastnutrition',
								data: {
									fulfilment: {
										type: s.method,
										profile_id: s.profile_id,
										date: s.date,
										slot: s.slot,
									},
								},
							},
						},
					],
				},
			} );
		} catch ( e ) {
			// Fallback: store intent in sessionStorage; server-side we accept it on the checkout submit hook.
			sessionStorage.setItem( 'fn_fulfilment', JSON.stringify( { type: s.method, profile_id: s.profile_id, date: s.date, slot: s.slot } ) );
		}
	};

	return (
		<div className="fn-slot-picker-root">
			<div className="fn-method-tabs">
				<button type="button" className={ method === 'delivery' ? 'is-active' : '' } onClick={ () => setMethod( 'delivery' ) }>{ __( 'Delivery', 'fastnutrition-mealprep' ) }</button>
				<button type="button" className={ method === 'collection' ? 'is-active' : '' } onClick={ () => setMethod( 'collection' ) }>{ __( 'Collection', 'fastnutrition-mealprep' ) }</button>
			</div>

			{ loading && <p>{ __( 'Loading slots…', 'fastnutrition-mealprep' ) }</p> }
			{ error && <p className="fn-error">{ error }</p> }
			{ ! loading && ! grouped[ method ] && postcode && <p>{ __( 'No slots available for this postcode.', 'fastnutrition-mealprep' ) }</p> }
			{ ! postcode && <p>{ __( 'Enter a postcode to see slots.', 'fastnutrition-mealprep' ) }</p> }

			{ grouped[ method ] && Object.entries( grouped[ method ] ).map( ( [ date, list ] ) => (
				<fieldset key={ date }>
					<legend>{ date }</legend>
					{ list.map( ( s, i ) => {
						const id = `${ date }-${ s.slot.start }-${ s.slot.end }`;
						const checked = selected && selected.date === s.date && selected.slot.start === s.slot.start && selected.method === s.method;
						return (
							<label key={ i }>
								<input type="radio" name="fn-slot" value={ id } checked={ !! checked } onChange={ () => write( s ) } />
								{ s.slot.start }–{ s.slot.end }
								{ s.slot.remaining !== null && s.slot.remaining !== undefined && ` (${ s.slot.remaining } left)` }
							</label>
						);
					} ) }
				</fieldset>
			) ) }
		</div>
	);
}

document.querySelectorAll( '.fn-slot-picker' ).forEach( ( el ) => {
	createRoot( el ).render( <SlotPicker /> );
} );
