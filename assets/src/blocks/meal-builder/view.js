import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

function MealBuilder( { productId } ) {
	const [ config, setConfig ] = useState( null );
	const [ ingredients, setIngredients ] = useState( {
		protein: [],
		carb: [],
		greens: [],
		set_meal: [],
	} );
	const [ mode, setMode ] = useState( 'build' );
	const [ selection, setSelection ] = useState( {
		protein_id: 0,
		carb_id: 0,
		greens_ids: [],
		set_meal_id: 0,
		addons: [],
	} );

	useEffect( () => {
		apiFetch( { path: `fastnutrition/v1/meal-config/${ productId }` } ).then( setConfig );
		Promise.all( [
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=protein' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=carb' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=greens' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=set_meal' } ),
		] ).then( ( [ p, c, g, s ] ) => setIngredients( { protein: p, carb: c, greens: g, set_meal: s } ) );
	}, [ productId ] );

	const allowed = useMemo( () => {
		if ( ! config ) {
			return ingredients;
		}
		const filter = ( list, allowList ) =>
			! allowList || ! allowList.length ? list : list.filter( ( i ) => allowList.includes( i.id ) );
		return {
			protein: filter( ingredients.protein, config.config.allowed_proteins ),
			carb: filter( ingredients.carb, config.config.allowed_carbs ),
			greens: filter( ingredients.greens, config.config.allowed_greens ),
			set_meal: filter( ingredients.set_meal, config.config.allowed_set_meals ),
		};
	}, [ config, ingredients ] );

	const totals = useMemo( () => {
		const zero = { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0, fibre_g: 0 };
		const lookup = ( id, list ) => list.find( ( i ) => i.id === id );
		const add = ( a, b ) => ( {
			kcal: a.kcal + ( b?.kcal || 0 ),
			protein_g: a.protein_g + ( b?.protein_g || 0 ),
			carbs_g: a.carbs_g + ( b?.carbs_g || 0 ),
			fat_g: a.fat_g + ( b?.fat_g || 0 ),
			fibre_g: a.fibre_g + ( b?.fibre_g || 0 ),
		} );
		let t = { ...zero };
		if ( mode === 'set' && selection.set_meal_id ) {
			t = add( t, lookup( selection.set_meal_id, ingredients.set_meal )?.macros );
		} else {
			t = add( t, lookup( selection.protein_id, ingredients.protein )?.macros );
			t = add( t, lookup( selection.carb_id, ingredients.carb )?.macros );
			selection.greens_ids.forEach( ( gid ) => {
				t = add( t, lookup( gid, ingredients.greens )?.macros );
			} );
		}
		return t;
	}, [ selection, mode, ingredients ] );

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

	if ( ! config ) {
		return <div>{ __( 'Loading…', 'fastnutrition-mealprep' ) }</div>;
	}

	const toggleGreens = ( id ) => {
		const current = selection.greens_ids.includes( id )
			? selection.greens_ids.filter( ( g ) => g !== id )
			: [ ...selection.greens_ids, id ].slice( -2 );
		const nextCarb = current.length === 2 ? 0 : selection.carb_id;
		setSelection( { ...selection, greens_ids: current, carb_id: nextCarb } );
	};

	const toggleAddon = ( addon ) => {
		const has = selection.addons.some( ( a ) => a.id === addon.id );
		const addons = has ? selection.addons.filter( ( a ) => a.id !== addon.id ) : [ ...selection.addons, addon ];
		setSelection( { ...selection, addons } );
	};

	return (
		<form className="fn-meal-builder" method="post" action="" onSubmit={ onSubmit }>
			<input type="hidden" name="add-to-cart" value={ productId } />
			<input type="hidden" name="fn_selection" value="" />
			<input type="hidden" name="quantity" value="1" />

			{ config.config.allow_set_meal_mode && (
				<div className="fn-mode-toggle">
					<button type="button" className={ mode === 'build' ? 'is-active' : '' } onClick={ () => setMode( 'build' ) }>
						{ __( 'Build your meal', 'fastnutrition-mealprep' ) }
					</button>
					<button type="button" className={ mode === 'set' ? 'is-active' : '' } onClick={ () => setMode( 'set' ) }>
						{ __( 'Choose a set meal', 'fastnutrition-mealprep' ) }
					</button>
				</div>
			) }

			{ mode === 'set' ? (
				<div className="fn-section">
					<h3>{ __( 'Set meal', 'fastnutrition-mealprep' ) }</h3>
					<div className="fn-grid">
						{ allowed.set_meal.map( ( i ) => (
							<label key={ i.id } className={ selection.set_meal_id === i.id ? 'is-selected' : '' }>
								<input
									type="radio"
									name="set_meal"
									value={ i.id }
									checked={ selection.set_meal_id === i.id }
									onChange={ () => setSelection( { ...selection, set_meal_id: i.id } ) }
								/>
								{ i.name }
							</label>
						) ) }
					</div>
				</div>
			) : (
				<>
					<Section title={ __( 'Protein', 'fastnutrition-mealprep' ) }>
						{ allowed.protein.map( ( i ) => (
							<label key={ i.id } className={ selection.protein_id === i.id ? 'is-selected' : '' }>
								<input
									type="radio"
									name="protein"
									checked={ selection.protein_id === i.id }
									onChange={ () => setSelection( { ...selection, protein_id: i.id } ) }
								/>
								{ i.name }
							</label>
						) ) }
					</Section>
					<Section title={ __( 'Carb', 'fastnutrition-mealprep' ) }>
						{ allowed.carb.map( ( i ) => (
							<label key={ i.id } className={ selection.carb_id === i.id ? 'is-selected' : '' }>
								<input
									type="radio"
									name="carb"
									disabled={ selection.greens_ids.length === 2 }
									checked={ selection.carb_id === i.id }
									onChange={ () => setSelection( { ...selection, carb_id: i.id } ) }
								/>
								{ i.name }
							</label>
						) ) }
						{ config.config.allow_double_greens && (
							<p className="fn-hint">
								{ __( 'Prefer low carb? Pick a 2nd greens below to swap out the carb.', 'fastnutrition-mealprep' ) }
							</p>
						) }
					</Section>
					<Section title={ config.config.allow_double_greens ? __( 'Greens (pick 1 or 2)', 'fastnutrition-mealprep' ) : __( 'Greens', 'fastnutrition-mealprep' ) }>
						{ allowed.greens.map( ( i ) => (
							<label key={ i.id } className={ selection.greens_ids.includes( i.id ) ? 'is-selected' : '' }>
								<input
									type="checkbox"
									checked={ selection.greens_ids.includes( i.id ) }
									onChange={ () => toggleGreens( i.id ) }
								/>
								{ i.name }
							</label>
						) ) }
					</Section>
				</>
			) }

			{ config.addons && config.addons.length > 0 && (
				<Section title={ __( 'Add-ons', 'fastnutrition-mealprep' ) }>
					{ config.addons.map( ( addon ) => (
						<label key={ addon.id }>
							<input
								type="checkbox"
								checked={ selection.addons.some( ( a ) => a.id === addon.id ) }
								onChange={ () => toggleAddon( addon ) }
							/>
							{ addon.label } (+ £{ Number( addon.price ).toFixed( 2 ) })
						</label>
					) ) }
				</Section>
			) }

			<div className="fn-totals">
				<strong>{ __( 'Macros', 'fastnutrition-mealprep' ) }:</strong> { totals.kcal.toFixed( 0 ) } kcal · P { totals.protein_g.toFixed( 1 ) }g · C { totals.carbs_g.toFixed( 1 ) }g · F { totals.fat_g.toFixed( 1 ) }g
			</div>

			<button type="submit" className="button alt fn-add-to-cart">
				{ __( 'Add to cart', 'fastnutrition-mealprep' ) }
			</button>
		</form>
	);
}

function Section( { title, children } ) {
	return (
		<div className="fn-section">
			<h3>{ title }</h3>
			<div className="fn-grid">{ children }</div>
		</div>
	);
}

document.querySelectorAll( '[data-fn-meal-builder]' ).forEach( ( el ) => {
	const productId = parseInt( el.getAttribute( 'data-product-id' ) || '0', 10 );
	if ( productId ) {
		createRoot( el ).render( <MealBuilder productId={ productId } /> );
	}
} );
