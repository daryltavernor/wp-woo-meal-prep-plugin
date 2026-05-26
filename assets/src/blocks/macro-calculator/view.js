import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

/**
 * Macro Calculator — same picking-experience as the meal builder on a
 * product page (protein / carbs / greens dropdowns), without the
 * Add-to-Cart wiring. Picks an ingredient, sums the macros, done.
 */
function MacroCalculator() {
	const [ ingredients, setIngredients ] = useState( {
		protein: [], carb: [], greens: [],
	} );
	const [ selection, setSelection ] = useState( {
		protein_id: 0, carb_id: 0, greens_id: 0,
	} );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=protein' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=carb' } ),
			apiFetch( { path: 'fastnutrition/v1/ingredients?type=greens' } ),
		] ).then( ( [ p, c, g ] ) => setIngredients( {
			protein: Array.isArray( p ) ? p : [],
			carb: Array.isArray( c ) ? c : [],
			greens: Array.isArray( g ) ? g : [],
		} ) );
	}, [] );

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
		if ( selection.protein_id ) t = add( t, findById( selection.protein_id, ingredients.protein )?.macros );
		if ( selection.carb_id )    t = add( t, findById( selection.carb_id, ingredients.carb )?.macros );
		if ( selection.greens_id )  t = add( t, findById( selection.greens_id, ingredients.greens )?.macros );
		return t;
	}, [ selection, ingredients ] );

	const anyPicked = totals.kcal > 0 || totals.protein_g > 0 || totals.carbs_g > 0 || totals.fat_g > 0;

	const onChange = ( field ) => ( e ) => setSelection( {
		...selection,
		[ field ]: parseInt( e.target.value, 10 ) || 0,
	} );

	const reset = () => setSelection( { protein_id: 0, carb_id: 0, greens_id: 0 } );

	return (
		<div className="fn-macro-calc">
			<Row label={ __( 'Pick a Protein', 'fastnutrition-mealprep' ) }>
				<select value={ selection.protein_id || '' } onChange={ onChange( 'protein_id' ) }>
					<option value="">{ __( 'Meat, Fish or Quorn', 'fastnutrition-mealprep' ) }</option>
					{ ingredients.protein.map( ( i ) => (
						<option key={ i.id } value={ i.id }>{ i.name }</option>
					) ) }
				</select>
			</Row>
			<Row label={ __( 'Pick Your Carbs', 'fastnutrition-mealprep' ) }>
				<select value={ selection.carb_id || '' } onChange={ onChange( 'carb_id' ) }>
					<option value="">{ __( 'Choose carbs', 'fastnutrition-mealprep' ) }</option>
					{ ingredients.carb.map( ( i ) => (
						<option key={ i.id } value={ i.id }>{ i.name }</option>
					) ) }
				</select>
			</Row>
			<Row label={ __( 'Pick Your Greens', 'fastnutrition-mealprep' ) }>
				<select value={ selection.greens_id || '' } onChange={ onChange( 'greens_id' ) }>
					<option value="">{ __( 'Choose greens', 'fastnutrition-mealprep' ) }</option>
					{ ingredients.greens.map( ( i ) => (
						<option key={ i.id } value={ i.id }>{ i.name }</option>
					) ) }
				</select>
			</Row>

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

document.querySelectorAll( '[data-fn-macro-calc]' ).forEach( ( el ) => {
	createRoot( el ).render( <MacroCalculator /> );
} );
