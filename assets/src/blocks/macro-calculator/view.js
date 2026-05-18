import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

const STORAGE_KEY = 'fn-custom-ingredients';
const emptyMacros = { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 };

function MacroCalculator() {
	const [ catalog, setCatalog ] = useState( [] );
	const [ custom, setCustom ] = useState( () => {
		try {
			return JSON.parse( localStorage.getItem( STORAGE_KEY ) || '[]' );
		} catch ( e ) {
			return [];
		}
	} );
	const [ rows, setRows ] = useState( [ { id: 1, ingredientId: '', qty: 1 } ] );
	const [ draftCustom, setDraftCustom ] = useState( null );
	const [ targets, setTargets ] = useState( { kcal: 0, protein_g: 0 } );

	useEffect( () => {
		apiFetch( { path: 'fastnutrition/v1/ingredients' } ).then( setCatalog );
		if ( window.fnMacroCalc?.loggedIn ) {
			apiFetch( { path: 'fastnutrition/v1/custom-ingredients' } ).then( ( items ) => {
				if ( Array.isArray( items ) && items.length ) {
					setCustom( ( prev ) => {
						const merged = [ ...prev ];
						items.forEach( ( item ) => {
							if ( ! merged.some( ( m ) => m.id === item.id ) ) {
								merged.push( item );
							}
						} );
						return merged;
					} );
				}
			} );
		}
	}, [] );

	useEffect( () => {
		localStorage.setItem( STORAGE_KEY, JSON.stringify( custom ) );
		if ( window.fnMacroCalc?.loggedIn ) {
			apiFetch( {
				path: 'fastnutrition/v1/custom-ingredients',
				method: 'POST',
				data: { items: custom },
			} ).catch( () => {} );
		}
	}, [ custom ] );

	const options = useMemo( () => {
		const fromCatalog = catalog.map( ( i ) => ( {
			value: `c:${ i.id }`,
			label: `${ i.name } (${ i.type })`,
			macros: i.macros,
		} ) );
		const fromCustom = custom.map( ( i ) => ( {
			value: `u:${ i.id }`,
			label: `${ i.name } (custom)`,
			macros: i,
		} ) );
		return [ ...fromCatalog, ...fromCustom ];
	}, [ catalog, custom ] );

	const totals = useMemo( () => {
		return rows.reduce( ( acc, row ) => {
			const opt = options.find( ( o ) => o.value === row.ingredientId );
			if ( ! opt ) {
				return acc;
			}
			const factor = Number( row.qty ) || 0;
			return {
				kcal: acc.kcal + ( opt.macros.kcal || 0 ) * factor,
				protein_g: acc.protein_g + ( opt.macros.protein_g || 0 ) * factor,
				carbs_g: acc.carbs_g + ( opt.macros.carbs_g || 0 ) * factor,
				fat_g: acc.fat_g + ( opt.macros.fat_g || 0 ) * factor,
			};
		}, { ...emptyMacros } );
	}, [ rows, options ] );

	const addRow = () => {
		setRows( [ ...rows, { id: Date.now(), ingredientId: '', qty: 1 } ] );
	};
	const removeRow = ( id ) => setRows( rows.filter( ( r ) => r.id !== id ) );
	const updateRow = ( id, patch ) => setRows( rows.map( ( r ) => ( r.id === id ? { ...r, ...patch } : r ) ) );

	const saveCustom = () => {
		if ( ! draftCustom || ! draftCustom.name ) {
			return;
		}
		const id = `custom-${ Date.now() }`;
		setCustom( [ ...custom, { id, ...draftCustom } ] );
		setDraftCustom( null );
	};

	const MACRO_FIELDS = [ 'kcal', 'protein_g', 'carbs_g', 'fat_g' ];

	return (
		<div className="fn-macro-calc">
			<h3>{ __( 'Macro Calculator', 'fastnutrition-mealprep' ) }</h3>
			<table className="fn-macro-rows">
				<thead>
					<tr>
						<th>{ __( 'Ingredient / Meal', 'fastnutrition-mealprep' ) }</th>
						<th>{ __( 'Qty', 'fastnutrition-mealprep' ) }</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( r ) => (
						<tr key={ r.id }>
							<td>
								<select value={ r.ingredientId } onChange={ ( e ) => updateRow( r.id, { ingredientId: e.target.value } ) }>
									<option value="">— { __( 'Select', 'fastnutrition-mealprep' ) } —</option>
									{ options.map( ( o ) => (
										<option key={ o.value } value={ o.value }>{ o.label }</option>
									) ) }
								</select>
							</td>
							<td>
								<input type="number" min="0" step="0.5" value={ r.qty } onChange={ ( e ) => updateRow( r.id, { qty: e.target.value } ) } />
							</td>
							<td>
								<button type="button" onClick={ () => removeRow( r.id ) }>&times;</button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<div className="fn-macro-actions">
				<button type="button" onClick={ addRow }>{ __( 'Add row', 'fastnutrition-mealprep' ) }</button>
				<button type="button" onClick={ () => setDraftCustom( { name: '', kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 } ) }>
					{ __( 'Add custom ingredient', 'fastnutrition-mealprep' ) }
				</button>
			</div>

			{ draftCustom && (
				<div className="fn-macro-custom">
					<h4>{ __( 'New custom ingredient', 'fastnutrition-mealprep' ) }</h4>
					<label>{ __( 'Name', 'fastnutrition-mealprep' ) }<input type="text" value={ draftCustom.name || '' } onChange={ ( e ) => setDraftCustom( { ...draftCustom, name: e.target.value } ) } /></label>
					{ MACRO_FIELDS.map( ( k ) => (
						<label key={ k }>{ k }<input type="number" value={ draftCustom[ k ] } onChange={ ( e ) => setDraftCustom( { ...draftCustom, [ k ]: Number( e.target.value ) } ) } /></label>
					) ) }
					<button type="button" onClick={ saveCustom }>{ __( 'Save', 'fastnutrition-mealprep' ) }</button>
					<button type="button" onClick={ () => setDraftCustom( null ) }>{ __( 'Cancel', 'fastnutrition-mealprep' ) }</button>
				</div>
			) }

			<div className="fn-macro-totals">
				<strong>{ __( 'Totals', 'fastnutrition-mealprep' ) }:</strong> { totals.kcal.toFixed( 0 ) } kcal · P { totals.protein_g.toFixed( 1 ) }g · C { totals.carbs_g.toFixed( 1 ) }g · F { totals.fat_g.toFixed( 1 ) }g
			</div>

			<div className="fn-macro-targets">
				<h4>{ __( 'Daily targets (optional)', 'fastnutrition-mealprep' ) }</h4>
				<label>kcal <input type="number" value={ targets.kcal } onChange={ ( e ) => setTargets( { ...targets, kcal: Number( e.target.value ) } ) } /></label>
				<label>Protein (g) <input type="number" value={ targets.protein_g } onChange={ ( e ) => setTargets( { ...targets, protein_g: Number( e.target.value ) } ) } /></label>
				{ targets.kcal > 0 && (
					<div className="fn-macro-progress">
						<progress value={ Math.min( totals.kcal, targets.kcal ) } max={ targets.kcal }></progress>
						<span>{ totals.kcal.toFixed( 0 ) } / { targets.kcal } kcal</span>
					</div>
				) }
			</div>
		</div>
	);
}

document.querySelectorAll( '[data-fn-macro-calc]' ).forEach( ( el ) => {
	createRoot( el ).render( <MacroCalculator /> );
} );
