import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

const SET_MEAL_PREFIX = 'set:';
const CARB_PREFIX     = 'carb:';
const GREENS_PREFIX   = 'greens:';

const matchesDiet = ( ingredient, diet ) => {
	if ( diet === 'all' ) return true;
	const tags = ingredient.allergens || [];
	if ( diet === 'vegan' ) return tags.includes( 'vegan' );
	if ( diet === 'vegetarian' ) return tags.includes( 'vegetarian' ) || tags.includes( 'vegan' );
	return true;
};

const priceLabel = ( n ) => `£${ Number( n || 0 ).toFixed( 2 ) }`;

function MealBuilder( { productId } ) {
	const [ config, setConfig ] = useState( null );
	const [ ingredients, setIngredients ] = useState( {
		protein: [], carb: [], greens: [], set_meal: [], sweet: [],
	} );
	const [ diet, setDiet ] = useState( 'all' );
	const [ qty, setQty ] = useState( 1 );
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
		document.body.classList.add( 'fn-meal-builder-active' );
		return () => document.body.classList.remove( 'fn-meal-builder-active' );
	}, [] );

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
		const byDiet = ( list ) => list.filter( ( i ) => matchesDiet( i, diet ) );
		const apply = ( list, allowList ) => byDiet( byTier( byAllow( list || [], allowList ) ) );
		return {
			protein: apply( ingredients.protein, config.config.allowed_proteins ),
			carb: apply( ingredients.carb, config.config.allowed_carbs ),
			greens: apply( ingredients.greens, config.config.allowed_greens ),
			set_meal: apply( ingredients.set_meal, config.config.allowed_set_meals ),
			sweet: apply( ingredients.sweet || [], config.config.allowed_sweets ),
		};
	}, [ config, ingredients, diet ] );

	const isSetMeal = !! selection.set_meal_id;

	const findById = ( id, list ) => list.find( ( i ) => i.id === id );

	const slot1Price = useMemo( () => {
		if ( isSetMeal ) return findById( selection.set_meal_id, ingredients.set_meal )?.price_delta || 0;
		if ( selection.protein_id ) return findById( selection.protein_id, ingredients.protein )?.price_delta || 0;
		return 0;
	}, [ selection, ingredients, isSetMeal ] );
	const slot2Price = useMemo( () => {
		if ( ! selection.slot2_id ) return 0;
		const list = selection.slot2_kind === 'carb' ? ingredients.carb : ingredients.greens;
		return findById( selection.slot2_id, list )?.price_delta || 0;
	}, [ selection, ingredients ] );
	const slot3Price = useMemo( () => {
		if ( ! selection.greens_id ) return 0;
		return findById( selection.greens_id, ingredients.greens )?.price_delta || 0;
	}, [ selection, ingredients ] );
	const sweetPrice = useMemo( () => {
		if ( ! selection.sweet_id ) return 0;
		return findById( selection.sweet_id, ingredients.sweet || [] )?.price_delta || 0;
	}, [ selection, ingredients ] );

	const totals = useMemo( () => {
		const zero = { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 };
		const add = ( a, b ) => ( {
			kcal: a.kcal + ( b?.kcal || 0 ),
			protein_g: a.protein_g + ( b?.protein_g || 0 ),
			carbs_g: a.carbs_g + ( b?.carbs_g || 0 ),
			fat_g: a.fat_g + ( b?.fat_g || 0 ),
		} );
		let t = { ...zero };
		if ( mode === 'sweet' && selection.sweet_id ) {
			return add( t, findById( selection.sweet_id, ingredients.sweet || [] )?.macros );
		}
		if ( isSetMeal ) return add( t, findById( selection.set_meal_id, ingredients.set_meal )?.macros );
		if ( selection.protein_id ) t = add( t, findById( selection.protein_id, ingredients.protein )?.macros );
		if ( selection.slot2_kind === 'carb' && selection.slot2_id ) t = add( t, findById( selection.slot2_id, ingredients.carb )?.macros );
		if ( selection.slot2_kind === 'greens' && selection.slot2_id ) t = add( t, findById( selection.slot2_id, ingredients.greens )?.macros );
		if ( selection.greens_id ) t = add( t, findById( selection.greens_id, ingredients.greens )?.macros );
		return t;
	}, [ selection, mode, ingredients, isSetMeal ] );

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

	const setAddon = ( addon, on ) => {
		const others = selection.addons.filter( ( a ) => a.id !== addon.id );
		const addons = on ? [ ...others, addon ] : others;
		setSelection( { ...selection, addons } );
	};

	const applyFragments = ( fragments ) => {
		if ( ! fragments || typeof fragments !== 'object' ) return;
		Object.keys( fragments ).forEach( ( sel ) => {
			document.querySelectorAll( sel ).forEach( ( node ) => {
				const wrap = document.createElement( 'div' );
				wrap.innerHTML = fragments[ sel ];
				const replacement = wrap.firstElementChild;
				if ( replacement ) node.replaceWith( replacement );
			} );
		} );
		document.body.dispatchEvent( new CustomEvent( 'wc_fragments_refreshed' ) );
	};

	const reset = () => {
		setSelection( { protein_id: 0, set_meal_id: 0, sweet_id: 0, slot2_kind: '', slot2_id: 0, greens_id: 0, addons: [] } );
		setQty( 1 );
	};

	const submit = async () => {
		if ( ! isValid || submitting ) return;
		setSubmitting( true );

		let payload;
		if ( mode === 'sweet' ) {
			payload = { mode: 'sweet', sweet_id: selection.sweet_id, addons: selection.addons };
		} else if ( isSetMeal ) {
			payload = { mode: 'set', set_meal_id: selection.set_meal_id, addons: selection.addons };
		} else {
			const greens_ids = [];
			if ( selection.slot2_kind === 'greens' && selection.slot2_id ) greens_ids.push( selection.slot2_id );
			if ( selection.greens_id ) greens_ids.push( selection.greens_id );
			payload = {
				mode: 'build',
				protein_id: selection.protein_id,
				carb_id: selection.slot2_kind === 'carb' ? selection.slot2_id : 0,
				greens_ids,
				addons: selection.addons,
			};
		}

		try {
			const result = await apiFetch( {
				path: 'fastnutrition/v1/cart/add',
				method: 'POST',
				data: {
					product_id: productId,
					quantity: Math.max( 1, parseInt( qty, 10 ) || 1 ),
					selection: payload,
				},
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

	if ( ! config ) {
		return <div>{ __( 'Loading…', 'fastnutrition-mealprep' ) }</div>;
	}

	const offerSweetMode = config.config.allow_sweet_mode && ( allowed.sweet || [] ).length > 0;
	const hasDietTags = Object.values( ingredients ).some( ( list ) => list.some( ( i ) => ( i.allergens || [] ).some( ( t ) => t === 'vegetarian' || t === 'vegan' ) ) );
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
				<PillRow
					label={ __( 'Pick a Sweet', 'fastnutrition-mealprep' ) }
					required
					price={ sweetPrice }
				>
					<select value={ selection.sweet_id || '' } onChange={ ( e ) => setSelection( { ...selection, sweet_id: parseInt( e.target.value, 10 ) || 0 } ) }>
						<option value="">{ __( 'Choose a sweet…', 'fastnutrition-mealprep' ) }</option>
						{ allowed.sweet.map( ( i ) => (
							<option key={ i.id } value={ i.id }>{ i.name }</option>
						) ) }
					</select>
				</PillRow>
			) : (
				<>
					{ hasDietTags && (
						<div className="fn-diet-filter">
							{ [
								[ 'all', __( 'All', 'fastnutrition-mealprep' ) ],
								[ 'vegetarian', __( 'Vegetarian', 'fastnutrition-mealprep' ) ],
								[ 'vegan', __( 'Vegan', 'fastnutrition-mealprep' ) ],
							].map( ( [ k, label ] ) => (
								<button key={ k } type="button" className={ diet === k ? 'is-active' : '' } onClick={ () => setDiet( k ) }>{ label }</button>
							) ) }
						</div>
					) }

					<PillRow label={ __( 'Pick a Protein', 'fastnutrition-mealprep' ) } required price={ slot1Price }>
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
								<optgroup label={ __( 'Set Meals (no carb / greens needed)', 'fastnutrition-mealprep' ) }>
									{ allowed.set_meal.map( ( i ) => (
										<option key={ 'sm' + i.id } value={ SET_MEAL_PREFIX + i.id }>{ i.name }</option>
									) ) }
								</optgroup>
							) }
						</select>
					</PillRow>

					{ ! isSetMeal && (
						<>
							<PillRow label={ __( 'Pick Your Carbs', 'fastnutrition-mealprep' ) } required price={ slot2Price } help={ __( 'Or pick a 2nd greens here to swap in 2× greens instead of a carb.', 'fastnutrition-mealprep' ) }>
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
							</PillRow>

							<PillRow
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
							</PillRow>
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
									<span className="fn-addon-label">{ __( 'Add to your meal?', 'fastnutrition-mealprep' ) }</span>
									<div className="fn-yesno" role="group" aria-label={ addon.label }>
										<button type="button" className={ `is-no ${ ! on ? 'is-active' : '' }` } onClick={ () => setAddon( addon, false ) }>{ __( 'No', 'fastnutrition-mealprep' ) }</button>
										<button type="button" className={ `is-yes ${ on ? 'is-active' : '' }` } onClick={ () => setAddon( addon, true ) }>{ __( 'Yes', 'fastnutrition-mealprep' ) }</button>
									</div>
									<span className="fn-addon-price">+{ priceLabel( addon.price ) }</span>
								</div>
							</div>
						);
					} ) }
				</div>
			) }

			<div className="fn-cta-row">
				<div className="fn-qty">
					<button type="button" onClick={ () => setQty( Math.max( 1, qty - 1 ) ) } aria-label="-">−</button>
					<input type="number" min="1" value={ qty } onChange={ ( e ) => setQty( Math.max( 1, parseInt( e.target.value, 10 ) || 1 ) ) } />
					<button type="button" onClick={ () => setQty( qty + 1 ) } aria-label="+">+</button>
				</div>
				<button type="button" className="fn-cta" disabled={ ! isValid || submitting } onClick={ submit }>
					{ submitting ? __( 'Adding…', 'fastnutrition-mealprep' ) : __( 'Add to basket', 'fastnutrition-mealprep' ) }
				</button>
			</div>

			{ ( totals.kcal > 0 || totals.protein_g > 0 ) && (
				<p className="fn-macros-line">
					{ totals.kcal.toFixed( 0 ) } kcal · P { totals.protein_g.toFixed( 1 ) }g · C { totals.carbs_g.toFixed( 1 ) }g · F { totals.fat_g.toFixed( 1 ) }g
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

function PillRow( { label, required, price, help, children } ) {
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
