import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

/**
 * Macro Calculator — full meal-builder pattern, without Add-to-Cart wiring.
 *
 * Picker behaviour mirrors the product-page builder exactly:
 *   - Tier dropdown (Standard / Bulk) filters every ingredient list.
 *   - Optional Meal/Sweet mode toggle, only shown when there are sweets
 *     in the catalogue.
 *   - Meal mode: protein dropdown has two optgroups — Proteins and Set
 *     Meals. Picking a set meal collapses the carb + greens rows because
 *     a set meal carries its own complete macros. Picking a protein
 *     shows the carb + greens rows.
 *   - Sweet mode: single dropdown, macros come from the sweet alone.
 *   - Live macros banner sums whatever's selected.
 */

const SET_MEAL_PREFIX = 'set:';

function MacroCalculator() {
	const [ ingredients, setIngredients ] = useState( {
		protein: [], carb: [], greens: [], set_meal: [], sweet: [],
	} );
	const [ tier, setTier ] = useState( 'standard' );
	const [ mode, setMode ] = useState( 'meal' );
	const [ selection, setSelection ] = useState( emptySelection() );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=protein' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=carb' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=greens' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=set_meal' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=sweet' } ),
		] ).then( ( [ p, c, g, sm, sw ] ) => setIngredients( {
			protein: ensureArray( p ),
			carb: ensureArray( c ),
			greens: ensureArray( g ),
			set_meal: ensureArray( sm ),
			sweet: ensureArray( sw ),
		} ) );
	}, [] );

	// Tier-filter every catalogue, matching the meal-builder rule:
	// an ingredient with no tier is treated as "standard".
	const allowed = useMemo( () => {
		const byTier = ( list ) => list.filter( ( i ) => ! i.tier || i.tier === tier );
		return {
			protein: byTier( ingredients.protein ),
			carb: byTier( ingredients.carb ),
			greens: byTier( ingredients.greens ),
			set_meal: byTier( ingredients.set_meal ),
			sweet: byTier( ingredients.sweet ),
		};
	}, [ ingredients, tier ] );

	const isSetMeal = !! selection.set_meal_id;
	const findById = ( id, list ) => list.find( ( i ) => i.id === id );

	const totals = useMemo( () => {
		const zero = { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 };
		const add  = ( a, b ) => ( {
			kcal: a.kcal + ( b?.kcal || 0 ),
			protein_g: a.protein_g + ( b?.protein_g || 0 ),
			carbs_g: a.carbs_g + ( b?.carbs_g || 0 ),
			fat_g: a.fat_g + ( b?.fat_g || 0 ),
		} );
		let t = { ...zero };
		if ( mode === 'sweet' ) {
			if ( selection.sweet_id ) {
				t = add( t, findById( selection.sweet_id, ingredients.sweet )?.macros );
			}
		} else if ( isSetMeal ) {
			t = add( t, findById( selection.set_meal_id, ingredients.set_meal )?.macros );
		} else {
			if ( selection.protein_id ) t = add( t, findById( selection.protein_id, ingredients.protein )?.macros );
			if ( selection.carb_id )    t = add( t, findById( selection.carb_id, ingredients.carb )?.macros );
			if ( selection.greens_id )  t = add( t, findById( selection.greens_id, ingredients.greens )?.macros );
		}
		return t;
	}, [ selection, ingredients, mode, isSetMeal ] );

	const anyPicked = totals.kcal > 0 || totals.protein_g > 0 || totals.carbs_g > 0 || totals.fat_g > 0;
	const offerSweetMode = allowed.sweet.length > 0;

	const reset = () => setSelection( emptySelection() );

	const onTierChange = ( value ) => {
		setTier( value );
		reset();
	};

	const onModeChange = ( next ) => {
		setMode( next );
		reset();
	};

	// Slot 1 carries either a protein id or a "set:<id>" sentinel — same
	// encoding the meal builder uses, so the optgroup pattern just works.
	const onSlot1Change = ( value ) => {
		if ( value.startsWith( SET_MEAL_PREFIX ) ) {
			const id = parseInt( value.slice( SET_MEAL_PREFIX.length ), 10 ) || 0;
			setSelection( { ...emptySelection(), set_meal_id: id } );
		} else {
			const id = parseInt( value, 10 ) || 0;
			setSelection( { ...selection, protein_id: id, set_meal_id: 0 } );
		}
	};

	const slot1Value = isSetMeal
		? SET_MEAL_PREFIX + selection.set_meal_id
		: ( selection.protein_id ? String( selection.protein_id ) : '' );

	return (
		<div className="fn-macro-calc">
			<Row label={ __( 'Meal Type', 'fastnutrition-mealprep' ) }>
				<select value={ tier } onChange={ ( e ) => onTierChange( e.target.value ) }>
					<option value="standard">{ __( 'Standard', 'fastnutrition-mealprep' ) }</option>
					<option value="bulk">{ __( 'Bulk', 'fastnutrition-mealprep' ) }</option>
				</select>
			</Row>

			{ offerSweetMode && (
				<div className="fn-mode-toggle">
					<button
						type="button"
						className={ mode === 'meal' ? 'is-active' : '' }
						onClick={ () => onModeChange( 'meal' ) }
					>
						{ __( 'Meal', 'fastnutrition-mealprep' ) }
					</button>
					<button
						type="button"
						className={ mode === 'sweet' ? 'is-active' : '' }
						onClick={ () => onModeChange( 'sweet' ) }
					>
						{ __( 'Sweet', 'fastnutrition-mealprep' ) }
					</button>
				</div>
			) }

			{ mode === 'sweet' ? (
				<Row label={ __( 'Pick a Sweet', 'fastnutrition-mealprep' ) }>
					<select
						value={ selection.sweet_id || '' }
						onChange={ ( e ) => setSelection( { ...emptySelection(), sweet_id: parseInt( e.target.value, 10 ) || 0 } ) }
					>
						<option value="">{ __( 'Choose a sweet…', 'fastnutrition-mealprep' ) }</option>
						{ allowed.sweet.map( ( i ) => (
							<option key={ i.id } value={ i.id }>{ i.name }</option>
						) ) }
					</select>
				</Row>
			) : (
				<>
					<Row label={ __( 'Pick a Protein', 'fastnutrition-mealprep' ) }>
						<select value={ slot1Value } onChange={ ( e ) => onSlot1Change( e.target.value ) }>
							<option value="">{ __( 'Meat, Fish or Quorn', 'fastnutrition-mealprep' ) }</option>
							{ allowed.protein.length > 0 && (
								<optgroup label={ __( 'Proteins', 'fastnutrition-mealprep' ) }>
									{ allowed.protein.map( ( i ) => (
										<option key={ 'p' + i.id } value={ i.id }>{ i.name }</option>
									) ) }
								</optgroup>
							) }
							{ allowed.set_meal.length > 0 && (
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
							<Row label={ __( 'Pick Your Carbs', 'fastnutrition-mealprep' ) }>
								<select
									value={ selection.carb_id || '' }
									onChange={ ( e ) => setSelection( { ...selection, carb_id: parseInt( e.target.value, 10 ) || 0 } ) }
								>
									<option value="">{ __( 'Choose carbs', 'fastnutrition-mealprep' ) }</option>
									{ allowed.carb.map( ( i ) => (
										<option key={ i.id } value={ i.id }>{ i.name }</option>
									) ) }
								</select>
							</Row>
							<Row label={ __( 'Pick Your Greens', 'fastnutrition-mealprep' ) }>
								<select
									value={ selection.greens_id || '' }
									onChange={ ( e ) => setSelection( { ...selection, greens_id: parseInt( e.target.value, 10 ) || 0 } ) }
								>
									<option value="">{ __( 'Choose greens', 'fastnutrition-mealprep' ) }</option>
									{ allowed.greens.map( ( i ) => (
										<option key={ i.id } value={ i.id }>{ i.name }</option>
									) ) }
								</select>
							</Row>
						</>
					) }
				</>
			) }

			<div className="fn-macro-calc-totals">
				<strong className="fn-macro-calc-label">{ __( 'Macros', 'fastnutrition-mealprep' ) }</strong>
				{ anyPicked ? (
					<span className="fn-macro-calc-values">
						<span><span className="fn-n">{ totals.kcal.toFixed( 0 ) }</span> Kcals</span>
						<span><span className="fn-n">{ totals.protein_g.toFixed( 1 ) }g</span> Protein</span>
						<span><span className="fn-n">{ totals.carbs_g.toFixed( 1 ) }g</span> Carbs</span>
						<span><span className="fn-n">{ totals.fat_g.toFixed( 1 ) }g</span> Fat</span>
					</span>
				) : (
					<span className="fn-macro-calc-empty">
						{ __( 'Pick ingredients to see macros', 'fastnutrition-mealprep' ) }
					</span>
				) }
			</div>

			{ anyPicked && (
				<button type="button" className="fn-macro-calc-reset" onClick={ reset }>
					{ __( 'Reset', 'fastnutrition-mealprep' ) }
				</button>
			) }
		</div>
	);
}

function Row( { label, children } ) {
	return (
		<div className="fn-row">
			<span className="fn-row-label">{ label }</span>
			<span className="fn-row-control">{ children }</span>
		</div>
	);
}

function emptySelection() {
	return { protein_id: 0, set_meal_id: 0, carb_id: 0, greens_id: 0, sweet_id: 0 };
}

function ensureArray( v ) {
	return Array.isArray( v ) ? v : [];
}

document.querySelectorAll( '[data-fn-macro-calc]' ).forEach( ( el ) => {
	createRoot( el ).render( <MacroCalculator /> );
} );
