import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState, useMemo, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

const SET_MEAL_PREFIX = 'set:';
const CARB_PREFIX     = 'carb:';
const GREENS_PREFIX   = 'greens:';

const priceLabel = ( n ) => `£${ Number( n || 0 ).toFixed( 2 ) }`;

function MealBuilder( { productId } ) {
	const [ config, setConfig ] = useState( null );
	const [ ingredients, setIngredients ] = useState( {
		protein: [], carb: [], greens: [], set_meal: [], sweet: [],
	} );
	const [ mode, setMode ] = useState( 'meal' );
	const [ selection, setSelection ] = useState( {
		protein_id: 0,
		set_meal_id: 0,
		sweet_id: 0,
		slot2_kind: '',
		slot2_id: 0,
		greens_id: 0,
		addons: [],
	} );
	const [ submitting, setSubmitting ] = useState( false );
	const [ status, setStatus ] = useState( null );

	useEffect( () => {
		apiFetch( { path: `fastnutrition/v1/meal-config/${ productId }` } ).then( ( cfg ) => {
			setConfig( cfg );
			if ( cfg?.config?.allow_sweet_mode && ! cfg.config.allow_set_meal_mode && ( cfg.config.allowed_proteins || [] ).length === 0 ) {
				setMode( 'sweet' );
			}
		} );
		Promise.all( [
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=protein' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=carb' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=greens' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=set_meal' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=sweet' } ),
		] ).then( ( [ p, c, g, s, sw ] ) => setIngredients( { protein: p, carb: c, greens: g, set_meal: s, sweet: sw } ) );
	}, [ productId ] );

	const allowed = useMemo( () => {
		if ( ! config ) return ingredients;
		const tier = config.config.tier || 'standard';
		const byAllow = ( list, allowList ) =>
			! allowList || ! allowList.length ? list : list.filter( ( i ) => allowList.includes( i.id ) );
		const byTier = ( list ) => list.filter( ( i ) => ! i.tier || i.tier === tier );
		const apply = ( list, allowList ) => byTier( byAllow( list || [], allowList ) );
		return {
			protein: apply( ingredients.protein, config.config.allowed_proteins ),
			carb: apply( ingredients.carb, config.config.allowed_carbs ),
			greens: apply( ingredients.greens, config.config.allowed_greens ),
			set_meal: apply( ingredients.set_meal, config.config.allowed_set_meals ),
			sweet: apply( ingredients.sweet || [], config.config.allowed_sweets ),
		};
	}, [ config, ingredients ] );

	const isSetMeal = !! selection.set_meal_id;
	const findById = ( id, list ) => list.find( ( i ) => i.id === id );

	const slot1Price = isSetMeal
		? ( findById( selection.set_meal_id, ingredients.set_meal )?.price_delta || 0 )
		: ( findById( selection.protein_id, ingredients.protein )?.price_delta || 0 );
	const slot2Price = ! selection.slot2_id
		? 0
		: ( findById( selection.slot2_id, selection.slot2_kind === 'carb' ? ingredients.carb : ingredients.greens )?.price_delta || 0 );
	const slot3Price = findById( selection.greens_id, ingredients.greens )?.price_delta || 0;
	const sweetPrice = findById( selection.sweet_id, ingredients.sweet || [] )?.price_delta || 0;

	const isValid = useMemo( () => {
		if ( ! config ) return false;
		if ( mode === 'sweet' ) return !! selection.sweet_id;
		if ( isSetMeal ) return true;
		if ( ! selection.protein_id ) return false;
		if ( ! selection.slot2_id || ! selection.slot2_kind ) return false;
		if ( ! selection.greens_id ) return false;
		if ( selection.slot2_kind === 'greens' && selection.slot2_id === selection.greens_id ) return false;
		return true;
	}, [ config, mode, selection, isSetMeal ] );

	// --- Theme add-to-cart integration ---------------------------------------
	const isValidRef    = useRef( isValid );
	const selectionRef  = useRef( selection );
	const modeRef       = useRef( mode );
	const submittingRef = useRef( submitting );
	const submitRef     = useRef( null );

	useEffect( () => { isValidRef.current = isValid; }, [ isValid ] );
	useEffect( () => { selectionRef.current = selection; }, [ selection ] );
	useEffect( () => { modeRef.current = mode; }, [ mode ] );
	useEffect( () => { submittingRef.current = submitting; }, [ submitting ] );

	const applyFragments = ( fragments ) => {
		if ( ! fragments || typeof fragments !== 'object' ) return;
		Object.keys( fragments ).forEach( ( sel ) => {
			document.querySelectorAll( sel ).forEach( ( node ) => {
				const wrap = document.createElement( 'div' );
				wrap.innerHTML = fragments[ sel ];
				if ( wrap.firstElementChild ) node.replaceWith( wrap.firstElementChild );
			} );
		} );
		document.body.dispatchEvent( new CustomEvent( 'wc_fragments_refreshed' ) );
	};

	const reset = () => {
		setSelection( { protein_id: 0, set_meal_id: 0, sweet_id: 0, slot2_kind: '', slot2_id: 0, greens_id: 0, addons: [] } );
	};

	const buildPayload = ( sel, m ) => {
		if ( m === 'sweet' ) return { mode: 'sweet', sweet_id: sel.sweet_id, addons: sel.addons };
		if ( sel.set_meal_id ) return { mode: 'set', set_meal_id: sel.set_meal_id, addons: sel.addons };
		const greens_ids = [];
		if ( sel.slot2_kind === 'greens' && sel.slot2_id ) greens_ids.push( sel.slot2_id );
		if ( sel.greens_id ) greens_ids.push( sel.greens_id );
		return {
			mode: 'build',
			protein_id: sel.protein_id,
			carb_id: sel.slot2_kind === 'carb' ? sel.slot2_id : 0,
			greens_ids,
			addons: sel.addons,
		};
	};

	const submitAjax = async ( qty ) => {
		if ( ! isValidRef.current || submittingRef.current ) return;
		setSubmitting( true );
		const payload = buildPayload( selectionRef.current, modeRef.current );
		try {
			const result = await apiFetch( {
				path: 'fastnutrition/v1/cart/add',
				method: 'POST',
				data: { product_id: productId, quantity: Math.max( 1, parseInt( qty, 10 ) || 1 ), selection: payload },
			} );
			applyFragments( result?.fragments );
			setStatus( {
				type: 'ok',
				text: __( 'Added to basket', 'fastnutrition-mealprep' ),
				count: result?.cart_count || 0,
				cartUrl: result?.cart_url || '',
			} );
			reset();
		} catch ( err ) {
			setStatus( { type: 'err', text: err?.message || __( 'Could not add to cart.', 'fastnutrition-mealprep' ) } );
		} finally {
			setSubmitting( false );
			window.setTimeout( () => setStatus( null ), 5000 );
		}
	};
	submitRef.current = submitAjax;

	// Hook into the theme's add-to-cart form and button.
	useEffect( () => {
		if ( ! config ) return undefined;
		const form   = document.querySelector( 'form.cart' );
		const button = form ? form.querySelector( '.single_add_to_cart_button, button[type="submit"]' ) : null;
		if ( ! form || ! button ) return undefined;

		const onSubmit = ( e ) => {
			e.preventDefault();
			e.stopImmediatePropagation();
			if ( ! isValidRef.current ) return;
			const qtyInput = form.querySelector( 'input.qty, input[name="quantity"]' );
			const qty = qtyInput ? parseInt( qtyInput.value, 10 ) || 1 : 1;
			if ( submitRef.current ) submitRef.current( qty );
		};
		const onClick = ( e ) => {
			if ( ! isValidRef.current ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				return;
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			const qtyInput = form.querySelector( 'input.qty, input[name="quantity"]' );
			const qty = qtyInput ? parseInt( qtyInput.value, 10 ) || 1 : 1;
			if ( submitRef.current ) submitRef.current( qty );
		};

		form.addEventListener( 'submit', onSubmit, true );
		button.addEventListener( 'click', onClick, true );

		return () => {
			form.removeEventListener( 'submit', onSubmit, true );
			button.removeEventListener( 'click', onClick, true );
		};
	}, [ config ] );

	// Reflect validity onto the theme button.
	useEffect( () => {
		const button = document.querySelector( 'form.cart .single_add_to_cart_button, form.cart button[type="submit"]' );
		if ( ! button ) return;
		if ( ! isValid || submitting ) {
			button.setAttribute( 'disabled', 'disabled' );
			button.classList.add( 'disabled' );
			button.setAttribute( 'aria-disabled', 'true' );
		} else {
			button.removeAttribute( 'disabled' );
			button.classList.remove( 'disabled' );
			button.removeAttribute( 'aria-disabled' );
		}
		if ( submitting ) {
			if ( ! button.dataset.fnOriginalText ) {
				button.dataset.fnOriginalText = button.textContent.trim();
			}
			button.textContent = __( 'Adding…', 'fastnutrition-mealprep' );
		} else if ( button.dataset.fnOriginalText ) {
			button.textContent = button.dataset.fnOriginalText;
			delete button.dataset.fnOriginalText;
		}
	}, [ isValid, submitting ] );

	// -------------------------------------------------------------------------

	const onSlot1Change = ( value ) => {
		if ( value.startsWith( SET_MEAL_PREFIX ) ) {
			const id = parseInt( value.slice( SET_MEAL_PREFIX.length ), 10 ) || 0;
			setSelection( { ...selection, protein_id: 0, set_meal_id: id, slot2_kind: '', slot2_id: 0, greens_id: 0 } );
		} else {
			const id = parseInt( value, 10 ) || 0;
			setSelection( { ...selection, protein_id: id, set_meal_id: 0 } );
		}
	};

	const onSlot2Change = ( value ) => {
		if ( value.startsWith( CARB_PREFIX ) ) {
			const id = parseInt( value.slice( CARB_PREFIX.length ), 10 ) || 0;
			setSelection( { ...selection, slot2_kind: 'carb', slot2_id: id } );
		} else if ( value.startsWith( GREENS_PREFIX ) ) {
			const id = parseInt( value.slice( GREENS_PREFIX.length ), 10 ) || 0;
			const greens_id = id === selection.greens_id ? 0 : selection.greens_id;
			setSelection( { ...selection, slot2_kind: 'greens', slot2_id: id, greens_id } );
		} else {
			setSelection( { ...selection, slot2_kind: '', slot2_id: 0 } );
		}
	};

	const toggleAddon = ( addon ) => {
		const on = selection.addons.some( ( a ) => a.id === addon.id );
		const addons = on ? selection.addons.filter( ( a ) => a.id !== addon.id ) : [ ...selection.addons, addon ];
		setSelection( { ...selection, addons } );
	};

	if ( ! config ) {
		return <div className="fn-meal-builder">{ __( 'Loading…', 'fastnutrition-mealprep' ) }</div>;
	}

	const offerSweetMode = config.config.allow_sweet_mode && ( allowed.sweet || [] ).length > 0;
	const slot1Value = isSetMeal ? SET_MEAL_PREFIX + selection.set_meal_id : ( selection.protein_id ? String( selection.protein_id ) : '' );
	const slot2Value = selection.slot2_id ? ( selection.slot2_kind === 'carb' ? CARB_PREFIX : GREENS_PREFIX ) + selection.slot2_id : '';

	return (
		<div className="fn-meal-builder">
			{ offerSweetMode && ( config.config.allow_set_meal_mode || ( allowed.protein || [] ).length > 0 ) && (
				<div className="fn-mode-toggle">
					<button type="button" className={ mode === 'meal' ? 'is-active' : '' } onClick={ () => setMode( 'meal' ) }>
						{ __( 'Meal', 'fastnutrition-mealprep' ) }
					</button>
					<button type="button" className={ mode === 'sweet' ? 'is-active' : '' } onClick={ () => setMode( 'sweet' ) }>
						{ __( 'Sweet', 'fastnutrition-mealprep' ) }
					</button>
				</div>
			) }

			{ mode === 'sweet' ? (
				<Row label={ __( 'Pick a Sweet', 'fastnutrition-mealprep' ) } required price={ sweetPrice }>
					<select value={ selection.sweet_id || '' } onChange={ ( e ) => setSelection( { ...selection, sweet_id: parseInt( e.target.value, 10 ) || 0 } ) }>
						<option value="">{ __( 'Choose a sweet…', 'fastnutrition-mealprep' ) }</option>
						{ allowed.sweet.map( ( i ) => (
							<option key={ i.id } value={ i.id }>{ i.name }</option>
						) ) }
					</select>
				</Row>
			) : (
				<>
					<Row label={ __( 'Pick a Protein', 'fastnutrition-mealprep' ) } required price={ slot1Price }>
						<select value={ slot1Value } onChange={ ( e ) => onSlot1Change( e.target.value ) }>
							<option value="">{ __( 'Meat, Fish or Quorn', 'fastnutrition-mealprep' ) }</option>
							{ allowed.protein.length > 0 && (
								<optgroup label={ __( 'Proteins', 'fastnutrition-mealprep' ) }>
									{ allowed.protein.map( ( i ) => (
										<option key={ 'p' + i.id } value={ i.id }>{ i.name }</option>
									) ) }
								</optgroup>
							) }
							{ config.config.allow_set_meal_mode && allowed.set_meal.length > 0 && (
								<optgroup label={ __( 'Set Meals', 'fastnutrition-mealprep' ) }>
									{ allowed.set_meal.map( ( i ) => (
										<option key={ 'sm' + i.id } value={ SET_MEAL_PREFIX + i.id }>{ i.name }</option>
									) ) }
								</optgroup>
							) }
						</select>
					</Row>

					{ ! isSetMeal && (
						<>
							<Row
								label={ __( 'Pick Your Carbs', 'fastnutrition-mealprep' ) }
								required
								price={ slot2Price }
								help={ config.config.allow_double_greens ? __( 'Or pick a 2nd greens here to swap in 2× greens instead of a carb.', 'fastnutrition-mealprep' ) : '' }
							>
								<select value={ slot2Value } onChange={ ( e ) => onSlot2Change( e.target.value ) }>
									<option value="">{ __( 'Carbs or Double Greens', 'fastnutrition-mealprep' ) }</option>
									{ allowed.carb.length > 0 && (
										<optgroup label={ __( 'Carbs', 'fastnutrition-mealprep' ) }>
											{ allowed.carb.map( ( i ) => (
												<option key={ 'c' + i.id } value={ CARB_PREFIX + i.id }>{ i.name }</option>
											) ) }
										</optgroup>
									) }
									{ config.config.allow_double_greens && allowed.greens.length > 0 && (
										<optgroup label={ __( 'Greens (low carb option)', 'fastnutrition-mealprep' ) }>
											{ allowed.greens.map( ( i ) => (
												<option key={ 'g2' + i.id } value={ GREENS_PREFIX + i.id }>{ i.name }</option>
											) ) }
										</optgroup>
									) }
								</select>
							</Row>

							<Row
								label={ selection.slot2_kind === 'greens' ? __( 'Pick Your 2nd Greens', 'fastnutrition-mealprep' ) : __( 'Pick Your Greens', 'fastnutrition-mealprep' ) }
								required
								price={ slot3Price }
							>
								<select value={ selection.greens_id || '' } onChange={ ( e ) => setSelection( { ...selection, greens_id: parseInt( e.target.value, 10 ) || 0 } ) }>
									<option value="">{ __( 'Choose Your Greens', 'fastnutrition-mealprep' ) }</option>
									{ allowed.greens
										.filter( ( i ) => ! ( selection.slot2_kind === 'greens' && selection.slot2_id === i.id ) )
										.map( ( i ) => (
											<option key={ 'g3' + i.id } value={ i.id }>{ i.name }</option>
										) ) }
								</select>
							</Row>
						</>
					) }
				</>
			) }

			{ config.addons && config.addons.length > 0 && (
				<div className="fn-addons">
					{ config.addons.map( ( addon ) => {
						const on = selection.addons.some( ( a ) => a.id === addon.id );
						return (
							<div key={ addon.id }>
								<p className="fn-addons-title">{ addon.label }</p>
								<div className="fn-addon-row">
									<label>
										<input type="checkbox" checked={ on } onChange={ () => toggleAddon( addon ) } />
										{ __( 'Add', 'fastnutrition-mealprep' ) } { addon.label }
									</label>
									<span className="fn-addon-price">{ priceLabel( addon.price ) }</span>
								</div>
							</div>
						);
					} ) }
				</div>
			) }

			{ ! isValid && (
				<p className="fn-row-help" style={ { paddingLeft: 0, marginTop: '0.6rem' } }>
					{ __( 'Finish your selection to enable Add to Basket.', 'fastnutrition-mealprep' ) }
				</p>
			) }

			{ status && (
				<div className={ `fn-status ${ status.type === 'ok' ? 'is-ok' : 'is-err' }` } role="status" aria-live="polite">
					{ status.text }
					{ status.type === 'ok' && status.cartUrl && (
						<a href={ status.cartUrl }>{ __( 'View basket', 'fastnutrition-mealprep' ) } ({ status.count })</a>
					) }
				</div>
			) }
		</div>
	);
}

function Row( { label, required, price, help, children } ) {
	return (
		<div className="fn-row">
			<span className="fn-row-label">
				{ required && <span className="fn-required">*</span> }
				{ label }
			</span>
			<span className="fn-row-control">{ children }</span>
			<span className="fn-row-price">{ priceLabel( price || 0 ) }</span>
			{ help && <span className="fn-row-help">{ help }</span> }
		</div>
	);
}

document.querySelectorAll( '[data-fn-meal-builder]' ).forEach( ( el ) => {
	const productId = parseInt( el.getAttribute( 'data-product-id' ) || '0', 10 );
	if ( productId ) {
		createRoot( el ).render( <MealBuilder productId={ productId } /> );
	}
} );
