import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

const splitSetMealName = ( name ) => {
	const m = /^(.+?)\s+served\s+with\s+(.+)$/i.exec( name || '' );
	if ( m ) {
		return { title: m[ 1 ].trim(), sub: 'Served with ' + m[ 2 ].trim() };
	}
	return { title: name || '', sub: '' };
};

const matchesDiet = ( ingredient, diet ) => {
	if ( diet === 'all' ) return true;
	const tags = ingredient.allergens || [];
	if ( diet === 'vegan' ) return tags.includes( 'vegan' );
	if ( diet === 'vegetarian' ) return tags.includes( 'vegetarian' ) || tags.includes( 'vegan' );
	return true;
};

function MealBuilder( { productId, minimal } ) {
	const [ config, setConfig ] = useState( null );
	const [ ingredients, setIngredients ] = useState( {
		protein: [],
		carb: [],
		greens: [],
		set_meal: [],
		sweet: [],
	} );
	const [ mode, setMode ] = useState( 'build' );
	const [ diet, setDiet ] = useState( 'all' );
	const [ lowCarb, setLowCarb ] = useState( false );
	const [ qty, setQty ] = useState( 1 );
	const [ selection, setSelection ] = useState( {
		protein_id: 0,
		carb_id: 0,
		greens_ids: [],
		set_meal_id: 0,
		sweet_id: 0,
		addons: [],
	} );

	useEffect( () => {
		document.body.classList.add( 'fn-meal-builder-active' );
		return () => document.body.classList.remove( 'fn-meal-builder-active' );
	}, [] );

	useEffect( () => {
		apiFetch( { path: `fastnutrition/v1/meal-config/${ productId }` } ).then( ( cfg ) => {
			setConfig( cfg );
			if ( cfg && cfg.config && cfg.config.allow_sweet_mode && ! cfg.config.allow_set_meal_mode ) {
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
		if ( ! config ) {
			return ingredients;
		}
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

	const totals = useMemo( () => {
		const zero = { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 };
		const lookup = ( id, list ) => list.find( ( i ) => i.id === id );
		const add = ( a, b ) => ( {
			kcal: a.kcal + ( b?.kcal || 0 ),
			protein_g: a.protein_g + ( b?.protein_g || 0 ),
			carbs_g: a.carbs_g + ( b?.carbs_g || 0 ),
			fat_g: a.fat_g + ( b?.fat_g || 0 ),
		} );
		let t = { ...zero };
		if ( mode === 'set' && selection.set_meal_id ) {
			t = add( t, lookup( selection.set_meal_id, ingredients.set_meal )?.macros );
		} else if ( mode === 'sweet' && selection.sweet_id ) {
			t = add( t, lookup( selection.sweet_id, ingredients.sweet || [] )?.macros );
		} else {
			t = add( t, lookup( selection.protein_id, ingredients.protein )?.macros );
			t = add( t, lookup( selection.carb_id, ingredients.carb )?.macros );
			selection.greens_ids.forEach( ( gid ) => {
				t = add( t, lookup( gid, ingredients.greens )?.macros );
			} );
		}
		return t;
	}, [ selection, mode, ingredients ] );

	const isValid = useMemo( () => {
		if ( ! config ) return false;
		if ( mode === 'set' ) return !! selection.set_meal_id;
		if ( mode === 'sweet' ) return !! selection.sweet_id;
		if ( ! selection.protein_id ) return false;
		if ( lowCarb ) return selection.greens_ids.length === 2;
		return ( !! selection.carb_id && selection.greens_ids.length >= 1 ) || selection.greens_ids.length === 2;
	}, [ config, mode, selection, lowCarb ] );

	const toggleGreens = ( id ) => {
		const max = lowCarb ? 2 : ( selection.greens_ids.length === 1 && ! selection.carb_id ? 2 : 1 );
		const current = selection.greens_ids.includes( id )
			? selection.greens_ids.filter( ( g ) => g !== id )
			: [ ...selection.greens_ids, id ].slice( -2 );
		const trimmed = current.slice( 0, lowCarb ? 2 : 2 );
		const nextCarb = trimmed.length === 2 ? 0 : selection.carb_id;
		setSelection( { ...selection, greens_ids: trimmed, carb_id: nextCarb } );
	};

	const toggleAddon = ( addon ) => {
		const has = selection.addons.some( ( a ) => a.id === addon.id );
		const addons = has ? selection.addons.filter( ( a ) => a.id !== addon.id ) : [ ...selection.addons, addon ];
		setSelection( { ...selection, addons } );
	};

	const toggleLowCarb = () => {
		const next = ! lowCarb;
		setLowCarb( next );
		if ( next ) {
			setSelection( { ...selection, carb_id: 0 } );
		}
	};

	const submit = () => {
		if ( ! isValid ) return;
		const payload = {
			mode,
			...selection,
			greens_ids: selection.greens_ids.filter( Boolean ),
		};
		const form = document.createElement( 'form' );
		form.method = 'post';
		form.action = '';
		form.className = 'fn-builder-form';
		form.style.display = 'none';
		const add = ( name, value ) => {
			const i = document.createElement( 'input' );
			i.type = 'hidden';
			i.name = name;
			i.value = value;
			form.appendChild( i );
		};
		add( 'add-to-cart', productId );
		add( 'quantity', Math.max( 1, parseInt( qty, 10 ) || 1 ) );
		add( 'fn_selection', JSON.stringify( payload ) );
		document.body.appendChild( form );
		form.submit();
	};

	if ( ! config ) {
		return <div>{ __( 'Loading…', 'fastnutrition-mealprep' ) }</div>;
	}

	if ( minimal ) {
		return <MinimalView
			config={ config }
			allowed={ allowed }
			mode={ mode }
			setMode={ setMode }
			selection={ selection }
			setSelection={ setSelection }
			doubleGreens={ lowCarb }
			setDoubleGreens={ setLowCarb }
			productId={ productId }
			qty={ qty }
			setQty={ setQty }
		/>;
	}

	const dietRelevant = mode === 'build' || mode === 'set' || mode === 'sweet';
	const hasDietTags = ( allergens ) => Object.values( ingredients ).some( ( list ) => list.some( ( i ) => ( i.allergens || [] ).some( ( t ) => allergens.includes( t ) ) ) );
	const showDietFilter = dietRelevant && hasDietTags( [ 'vegetarian', 'vegan' ] );

	return (
		<>
			<div className="fn-meal-builder">
				{ ( config.config.allow_set_meal_mode || config.config.allow_sweet_mode ) && (
					<div className="fn-mode-toggle">
						{ ! config.config.allow_sweet_mode && (
							<button type="button" className={ mode === 'build' ? 'is-active' : '' } onClick={ () => setMode( 'build' ) }>
								{ __( 'Build your meal', 'fastnutrition-mealprep' ) }
							</button>
						) }
						{ config.config.allow_set_meal_mode && (
							<button type="button" className={ mode === 'set' ? 'is-active' : '' } onClick={ () => setMode( 'set' ) }>
								{ __( 'Choose a set meal', 'fastnutrition-mealprep' ) }
							</button>
						) }
						{ config.config.allow_sweet_mode && (
							<button type="button" className={ mode === 'sweet' ? 'is-active' : '' } onClick={ () => setMode( 'sweet' ) }>
								{ __( 'Choose a sweet', 'fastnutrition-mealprep' ) }
							</button>
						) }
					</div>
				) }

				{ showDietFilter && (
					<div className="fn-section">
						<div className="fn-section-head">
							<h3>{ __( 'Diet', 'fastnutrition-mealprep' ) }</h3>
							<div className="fn-diet-filter">
								{ [
									[ 'all', __( 'All', 'fastnutrition-mealprep' ) ],
									[ 'vegetarian', __( 'Vegetarian', 'fastnutrition-mealprep' ) ],
									[ 'vegan', __( 'Vegan', 'fastnutrition-mealprep' ) ],
								].map( ( [ k, label ] ) => (
									<button key={ k } type="button" className={ diet === k ? 'is-active' : '' } onClick={ () => setDiet( k ) }>
										{ label }
									</button>
								) ) }
							</div>
						</div>
					</div>
				) }

				{ mode === 'set' ? (
					<div className="fn-section">
						<h3>{ __( 'Set meal', 'fastnutrition-mealprep' ) }</h3>
						<div className="fn-cards">
							{ allowed.set_meal.map( ( i ) => {
								const { title, sub } = splitSetMealName( i.name );
								return (
									<label key={ i.id } className={ selection.set_meal_id === i.id ? 'is-selected' : '' }>
										<input type="radio" name="set_meal" value={ i.id } checked={ selection.set_meal_id === i.id } onChange={ () => setSelection( { ...selection, set_meal_id: i.id } ) } />
										<span className="fn-card-title">{ title }</span>
										{ sub && <span className="fn-card-sub">{ sub }</span> }
									</label>
								);
							} ) }
						</div>
					</div>
				) : mode === 'sweet' ? (
					<div className="fn-section">
						<h3>{ __( 'Sweet', 'fastnutrition-mealprep' ) }</h3>
						<div className="fn-cards">
							{ ( allowed.sweet || [] ).map( ( i ) => (
								<label key={ i.id } className={ selection.sweet_id === i.id ? 'is-selected' : '' }>
									<input type="radio" name="sweet" value={ i.id } checked={ selection.sweet_id === i.id } onChange={ () => setSelection( { ...selection, sweet_id: i.id } ) } />
									<span className="fn-card-title">{ i.name }</span>
								</label>
							) ) }
						</div>
					</div>
				) : (
					<>
						<div className="fn-section">
							<h3>{ __( 'Protein', 'fastnutrition-mealprep' ) }</h3>
							<div className="fn-grid">
								{ allowed.protein.map( ( i ) => (
									<label key={ i.id } className={ selection.protein_id === i.id ? 'is-selected' : '' }>
										<input type="radio" name="protein" checked={ selection.protein_id === i.id } onChange={ () => setSelection( { ...selection, protein_id: i.id } ) } />
										{ i.name }
									</label>
								) ) }
							</div>
						</div>

						<div className="fn-section">
							<div className="fn-section-head">
								<h3>{ __( 'Carb', 'fastnutrition-mealprep' ) }</h3>
								{ config.config.allow_double_greens && (
									<label className="fn-section-toggle">
										<input type="checkbox" checked={ lowCarb } onChange={ toggleLowCarb } />
										{ __( 'Low carb (swap for 2 greens)', 'fastnutrition-mealprep' ) }
									</label>
								) }
							</div>
							<div className="fn-grid">
								{ allowed.carb.map( ( i ) => {
									const classes = [];
									if ( selection.carb_id === i.id ) classes.push( 'is-selected' );
									if ( lowCarb ) classes.push( 'is-disabled' );
									return (
										<label key={ i.id } className={ classes.join( ' ' ) }>
											<input type="radio" name="carb" disabled={ lowCarb } checked={ selection.carb_id === i.id } onChange={ () => setSelection( { ...selection, carb_id: i.id } ) } />
											{ i.name }
										</label>
									);
								} ) }
							</div>
						</div>

						<div className="fn-section">
							<h3>{ lowCarb ? __( 'Greens (pick 2)', 'fastnutrition-mealprep' ) : __( 'Greens', 'fastnutrition-mealprep' ) }</h3>
							<div className="fn-grid">
								{ allowed.greens.map( ( i ) => (
									<label key={ i.id } className={ selection.greens_ids.includes( i.id ) ? 'is-selected' : '' }>
										<input type="checkbox" checked={ selection.greens_ids.includes( i.id ) } onChange={ () => toggleGreens( i.id ) } />
										{ i.name }
									</label>
								) ) }
							</div>
						</div>
					</>
				) }

				{ config.addons && config.addons.length > 0 && (
					<div className="fn-section">
						<h3>{ __( 'Optional add-ons', 'fastnutrition-mealprep' ) }</h3>
						<div className="fn-addons">
							{ config.addons.map( ( addon ) => {
								const selected = selection.addons.some( ( a ) => a.id === addon.id );
								return (
									<label key={ addon.id } className={ selected ? 'is-selected' : '' }>
										<input type="checkbox" checked={ selected } onChange={ () => toggleAddon( addon ) } />
										{ addon.label } (+£{ Number( addon.price ).toFixed( 2 ) })
									</label>
								);
							} ) }
						</div>
					</div>
				) }
			</div>

			<div className="fn-meal-builder-footer">
				<div className="fn-meal-builder-footer-inner">
					<div className="fn-foot-macros">
						<strong>{ __( 'Macros', 'fastnutrition-mealprep' ) }</strong>
						{ totals.kcal.toFixed( 0 ) } kcal · P { totals.protein_g.toFixed( 1 ) }g · C { totals.carbs_g.toFixed( 1 ) }g · F { totals.fat_g.toFixed( 1 ) }g
					</div>
					<div className="fn-qty">
						<button type="button" onClick={ () => setQty( Math.max( 1, qty - 1 ) ) } aria-label="-">−</button>
						<input type="number" min="1" value={ qty } onChange={ ( e ) => setQty( Math.max( 1, parseInt( e.target.value, 10 ) || 1 ) ) } />
						<button type="button" onClick={ () => setQty( qty + 1 ) } aria-label="+">+</button>
					</div>
					<button type="button" className="fn-cta" disabled={ ! isValid } onClick={ submit }>
						{ __( 'Add to meal prep', 'fastnutrition-mealprep' ) }
					</button>
				</div>
			</div>
		</>
	);
}

function MinimalView( { config, allowed, mode, setMode, selection, setSelection, doubleGreens, setDoubleGreens, productId, qty, setQty } ) {
	const DOUBLE_GREENS_VALUE = 'double';
	const carbValue = doubleGreens ? DOUBLE_GREENS_VALUE : String( selection.carb_id || '' );

	const onCarbChange = ( e ) => {
		if ( e.target.value === DOUBLE_GREENS_VALUE ) {
			setDoubleGreens( true );
			setSelection( { ...selection, carb_id: 0 } );
		} else {
			setDoubleGreens( false );
			setSelection( { ...selection, carb_id: parseInt( e.target.value, 10 ) || 0, greens_ids: selection.greens_ids.slice( 0, 1 ) } );
		}
	};

	const onGreensChange = ( idx, value ) => {
		const id = parseInt( value, 10 ) || 0;
		const next = selection.greens_ids.slice();
		if ( id > 0 ) {
			next[ idx ] = id;
		} else {
			next.splice( idx, 1 );
		}
		setSelection( { ...selection, greens_ids: next.filter( Boolean ) } );
	};

	const toggleAddon = ( addon ) => {
		const has = selection.addons.some( ( a ) => a.id === addon.id );
		const addons = has ? selection.addons.filter( ( a ) => a.id !== addon.id ) : [ ...selection.addons, addon ];
		setSelection( { ...selection, addons } );
	};

	const priceStr = ( n ) => `£${ Number( n || 0 ).toFixed( 2 ) }`;

	const onSubmit = ( e ) => {
		e.preventDefault();
		const payload = {
			mode,
			...selection,
			greens_ids: selection.greens_ids.filter( Boolean ),
		};
		const form = e.target;
		const input = form.querySelector( 'input[name="fn_selection"]' );
		input.value = JSON.stringify( payload );
		form.submit();
	};

	return (
		<form className="fn-meal-builder-minimal fn-builder-form" method="post" action="" onSubmit={ onSubmit }>
			<input type="hidden" name="add-to-cart" value={ productId } />
			<input type="hidden" name="fn_selection" value="" />
			<input type="hidden" name="quantity" value={ qty } />

			{ ( config.config.allow_set_meal_mode || config.config.allow_sweet_mode ) && (
				<p>
					{ ! config.config.allow_sweet_mode && (
						<><label>
							<input type="radio" name="fn_mode" checked={ mode === 'build' } onChange={ () => setMode( 'build' ) } /> { __( 'Build your meal', 'fastnutrition-mealprep' ) }
						</label>{ ' ' }</>
					) }
					{ config.config.allow_set_meal_mode && (
						<><label>
							<input type="radio" name="fn_mode" checked={ mode === 'set' } onChange={ () => setMode( 'set' ) } /> { __( 'Choose a set meal', 'fastnutrition-mealprep' ) }
						</label>{ ' ' }</>
					) }
					{ config.config.allow_sweet_mode && (
						<label>
							<input type="radio" name="fn_mode" checked={ mode === 'sweet' } onChange={ () => setMode( 'sweet' ) } /> { __( 'Choose a sweet', 'fastnutrition-mealprep' ) }
						</label>
					) }
				</p>
			) }

			{ mode === 'set' ? (
				<p>
					<label><span style={ { color: 'red' } }>*</span> { __( 'Set meal', 'fastnutrition-mealprep' ) }{ ' ' }
						<select name="set_meal" required value={ selection.set_meal_id || '' } onChange={ ( e ) => setSelection( { ...selection, set_meal_id: parseInt( e.target.value, 10 ) || 0 } ) }>
							<option value="">— { __( 'Pick a set meal', 'fastnutrition-mealprep' ) } —</option>
							{ allowed.set_meal.map( ( i ) => (
								<option key={ i.id } value={ i.id }>{ i.name }</option>
							) ) }
						</select>
					</label>
				</p>
			) : mode === 'sweet' ? (
				<p>
					<label><span style={ { color: 'red' } }>*</span> { __( 'Sweet', 'fastnutrition-mealprep' ) }{ ' ' }
						<select name="sweet" required value={ selection.sweet_id || '' } onChange={ ( e ) => setSelection( { ...selection, sweet_id: parseInt( e.target.value, 10 ) || 0 } ) }>
							<option value="">— { __( 'Pick a sweet', 'fastnutrition-mealprep' ) } —</option>
							{ ( allowed.sweet || [] ).map( ( i ) => (
								<option key={ i.id } value={ i.id }>{ i.name }{ i.price_delta ? ` (+£${ Number( i.price_delta ).toFixed( 2 ) })` : '' }</option>
							) ) }
						</select>
					</label>
				</p>
			) : (
				<>
					<p>
						<label><span style={ { color: 'red' } }>*</span> { __( 'Pick a Protein', 'fastnutrition-mealprep' ) }{ ' ' }
							<select name="protein" required value={ selection.protein_id || '' } onChange={ ( e ) => setSelection( { ...selection, protein_id: parseInt( e.target.value, 10 ) || 0 } ) }>
								<option value="">{ __( 'Meat, Fish or Quorn', 'fastnutrition-mealprep' ) }</option>
								{ allowed.protein.map( ( i ) => (
									<option key={ i.id } value={ i.id }>{ i.name }{ i.price_delta ? ` (+${ priceStr( i.price_delta ) })` : '' }</option>
								) ) }
							</select>
						</label>
					</p>

					<p>
						<label><span style={ { color: 'red' } }>*</span> { __( 'Pick Your Carbs', 'fastnutrition-mealprep' ) }{ ' ' }
							<select name="carb" required value={ carbValue } onChange={ onCarbChange }>
								<option value="">{ config.config.allow_double_greens ? __( 'Carbs or Double Greens', 'fastnutrition-mealprep' ) : __( 'Pick a carb', 'fastnutrition-mealprep' ) }</option>
								{ allowed.carb.map( ( i ) => (
									<option key={ i.id } value={ i.id }>{ i.name }{ i.price_delta ? ` (+${ priceStr( i.price_delta ) })` : '' }</option>
								) ) }
								{ config.config.allow_double_greens && (
									<option value={ DOUBLE_GREENS_VALUE }>{ __( '— Double greens (no carb)', 'fastnutrition-mealprep' ) }</option>
								) }
							</select>
						</label>
					</p>

					<p>
						<label><span style={ { color: 'red' } }>*</span> { doubleGreens ? __( 'Pick Your First Greens', 'fastnutrition-mealprep' ) : __( 'Pick Your Greens', 'fastnutrition-mealprep' ) }{ ' ' }
							<select name="greens_1" required value={ selection.greens_ids[ 0 ] || '' } onChange={ ( e ) => onGreensChange( 0, e.target.value ) }>
								<option value="">{ __( 'Choose Your Greens', 'fastnutrition-mealprep' ) }</option>
								{ allowed.greens.map( ( i ) => (
									<option key={ i.id } value={ i.id }>{ i.name }{ i.price_delta ? ` (+${ priceStr( i.price_delta ) })` : '' }</option>
								) ) }
							</select>
						</label>
					</p>

					{ doubleGreens && (
						<p>
							<label><span style={ { color: 'red' } }>*</span> { __( 'Pick Your Second Greens', 'fastnutrition-mealprep' ) }{ ' ' }
								<select name="greens_2" required value={ selection.greens_ids[ 1 ] || '' } onChange={ ( e ) => onGreensChange( 1, e.target.value ) }>
									<option value="">{ __( 'Choose Your Second Greens', 'fastnutrition-mealprep' ) }</option>
									{ allowed.greens.filter( ( i ) => i.id !== selection.greens_ids[ 0 ] ).map( ( i ) => (
										<option key={ i.id } value={ i.id }>{ i.name }{ i.price_delta ? ` (+${ priceStr( i.price_delta ) })` : '' }</option>
									) ) }
								</select>
							</label>
						</p>
					) }
				</>
			) }

			{ config.addons && config.addons.length > 0 && (
				<fieldset style={ { border: 'none', padding: 0, margin: '1em 0' } }>
					<legend><strong>{ __( 'Optional add-ons', 'fastnutrition-mealprep' ) }</strong></legend>
					{ config.addons.map( ( addon ) => (
						<p key={ addon.id }>
							<label>
								<input type="checkbox" checked={ selection.addons.some( ( a ) => a.id === addon.id ) } onChange={ () => toggleAddon( addon ) } />
								{ ' ' }{ addon.label } — { priceStr( addon.price ) }
							</label>
						</p>
					) ) }
				</fieldset>
			) }

			<p>
				<label>{ __( 'Quantity', 'fastnutrition-mealprep' ) }{ ' ' }
					<input type="number" min="1" value={ qty } onChange={ ( e ) => setQty( Math.max( 1, parseInt( e.target.value, 10 ) || 1 ) ) } style={ { width: 80 } } />
				</label>
			</p>

			<p>
				<button type="submit" className="single_add_to_cart_button button alt">{ __( 'Add to basket', 'fastnutrition-mealprep' ) }</button>
			</p>
		</form>
	);
}

document.querySelectorAll( '[data-fn-meal-builder]' ).forEach( ( el ) => {
	const productId = parseInt( el.getAttribute( 'data-product-id' ) || '0', 10 );
	const minimal   = !! ( window.fnMealBuilder && window.fnMealBuilder.minimalStyling );
	if ( productId ) {
		createRoot( el ).render( <MealBuilder productId={ productId } minimal={ minimal } /> );
	}
} );
