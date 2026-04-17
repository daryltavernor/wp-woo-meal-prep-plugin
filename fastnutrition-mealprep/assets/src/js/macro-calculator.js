/**
 * Macro Calculator front-end. Fetches ingredients from REST, manages user-added custom
 * ingredients in localStorage (with optional sync when logged in), and sums macros as the user
 * builds rows.
 */
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const REST = window.FN_MEALPREP?.rest || '/wp-json/fastnutrition/v1/';
const NONCE = window.FN_MEALPREP?.nonce;
apiFetch.use( apiFetch.createNonceMiddleware( NONCE ) );

const LOCAL_KEY = 'fn_custom_ingredients_v1';

function loadCustom() {
	try {
		return JSON.parse( localStorage.getItem( LOCAL_KEY ) || '[]' );
	} catch {
		return [];
	}
}
function saveCustom( list ) {
	localStorage.setItem( LOCAL_KEY, JSON.stringify( list ) );
}

function Calculator( { allowCustom, showTargets } ) {
	const [ ingredients, setIngredients ] = useState( [] );
	const [ customList, setCustomList ] = useState( loadCustom() );
	const [ rows, setRows ] = useState( [] );
	const [ targets, setTargets ] = useState( { kcal: 2000, protein_g: 150, carbs_g: 200, fat_g: 60 } );
	const [ showForm, setShowForm ] = useState( false );
	const [ draft, setDraft ] = useState( { name: '', kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0, fibre_g: 0 } );

	useEffect( () => {
		( async () => {
			const res = await apiFetch( { path: 'fastnutrition/v1/ingredients' } );
			const flat = [];
			Object.entries( res ).forEach( ( [ type, list ] ) => list.forEach( ( i ) => flat.push( { ...i, type } ) ) );
			setIngredients( flat );
		} )();
	}, [] );

	const allOptions = useMemo( () => {
		return [
			...ingredients.map( ( i ) => ( { id: `ing:${ i.id }`, label: `${ i.title } (${ i.type })`, macros: i.macros } ) ),
			...customList.map( ( c ) => ( { id: `custom:${ c.id }`, label: `${ c.name } (custom)`, macros: c.macros } ) ),
		];
	}, [ ingredients, customList ] );

	const addRow = () => setRows( [ ...rows, { id: allOptions[ 0 ]?.id || '', qty: 1 } ] );
	const removeRow = ( idx ) => setRows( rows.filter( ( _, i ) => i !== idx ) );
	const updateRow = ( idx, patch ) => setRows( rows.map( ( r, i ) => ( i === idx ? { ...r, ...patch } : r ) ) );

	const totals = useMemo( () => {
		const sum = { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 };
		rows.forEach( ( r ) => {
			const opt = allOptions.find( ( o ) => o.id === r.id );
			if ( ! opt?.macros ) return;
			Object.keys( sum ).forEach( ( k ) => ( sum[ k ] += ( opt.macros[ k ] || 0 ) * ( r.qty || 0 ) ) );
		} );
		return sum;
	}, [ rows, allOptions ] );

	const addCustom = () => {
		const item = { id: crypto.randomUUID(), name: draft.name.trim(), macros: {
			kcal: parseFloat( draft.kcal ) || 0,
			protein_g: parseFloat( draft.protein_g ) || 0,
			carbs_g: parseFloat( draft.carbs_g ) || 0,
			fat_g: parseFloat( draft.fat_g ) || 0,
			fibre_g: parseFloat( draft.fibre_g ) || 0,
		} };
		if ( ! item.name ) return;
		const next = [ ...customList, item ];
		setCustomList( next );
		saveCustom( next );
		setDraft( { name: '', kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0, fibre_g: 0 } );
		setShowForm( false );
	};

	return (
		<div className="fn-macro-calc-root">
			<table className="fn-macro-rows">
				<thead>
					<tr>
						<th>{ __( 'Item', 'fastnutrition-mealprep' ) }</th>
						<th>{ __( 'Servings', 'fastnutrition-mealprep' ) }</th>
						<th>{ __( 'kcal', 'fastnutrition-mealprep' ) }</th>
						<th>{ __( 'P', 'fastnutrition-mealprep' ) }</th>
						<th>{ __( 'C', 'fastnutrition-mealprep' ) }</th>
						<th>{ __( 'F', 'fastnutrition-mealprep' ) }</th>
						<th />
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row, i ) => {
						const opt = allOptions.find( ( o ) => o.id === row.id );
						const m = opt?.macros || {};
						return (
							<tr key={ i }>
								<td>
									<select value={ row.id } onChange={ ( e ) => updateRow( i, { id: e.target.value } ) }>
										{ allOptions.map( ( o ) => (
											<option key={ o.id } value={ o.id }>
												{ o.label }
											</option>
										) ) }
									</select>
								</td>
								<td><input type="number" min="0" step="0.5" value={ row.qty } onChange={ ( e ) => updateRow( i, { qty: parseFloat( e.target.value ) || 0 } ) } /></td>
								<td>{ Math.round( ( m.kcal || 0 ) * row.qty ) }</td>
								<td>{ Math.round( ( m.protein_g || 0 ) * row.qty ) }</td>
								<td>{ Math.round( ( m.carbs_g || 0 ) * row.qty ) }</td>
								<td>{ Math.round( ( m.fat_g || 0 ) * row.qty ) }</td>
								<td><button type="button" onClick={ () => removeRow( i ) }>×</button></td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
			<div className="fn-macro-actions">
				<button type="button" className="button" onClick={ addRow }>{ __( 'Add row', 'fastnutrition-mealprep' ) }</button>
				{ allowCustom && <button type="button" className="button" onClick={ () => setShowForm( ! showForm ) }>{ __( 'Add custom ingredient', 'fastnutrition-mealprep' ) }</button> }
			</div>

			{ showForm && allowCustom && (
				<div className="fn-custom-form">
					<input placeholder={ __( 'Name', 'fastnutrition-mealprep' ) } value={ draft.name } onChange={ ( e ) => setDraft( { ...draft, name: e.target.value } ) } />
					{ [ 'kcal', 'protein_g', 'carbs_g', 'fat_g', 'fibre_g' ].map( ( k ) => (
						<input key={ k } type="number" min="0" step="0.1" placeholder={ k } value={ draft[ k ] } onChange={ ( e ) => setDraft( { ...draft, [ k ]: e.target.value } ) } />
					) ) }
					<button type="button" className="button button-primary" onClick={ addCustom }>{ __( 'Save', 'fastnutrition-mealprep' ) }</button>
				</div>
			) }

			<div className="fn-macro-totals">
				<h4>{ __( 'Totals', 'fastnutrition-mealprep' ) }</h4>
				<p>{ sprintf( '%d kcal · P %dg · C %dg · F %dg', Math.round( totals.kcal ), Math.round( totals.protein_g ), Math.round( totals.carbs_g ), Math.round( totals.fat_g ) ) }</p>
				{ showTargets && (
					<div className="fn-macro-targets">
						{ Object.keys( targets ).map( ( k ) => (
							<div key={ k } className="fn-bar">
								<label>{ k }: { Math.round( totals[ k ] ) } / <input type="number" min="0" value={ targets[ k ] } onChange={ ( e ) => setTargets( { ...targets, [ k ]: parseFloat( e.target.value ) || 0 } ) } /></label>
								<div className="fn-bar-track"><div className="fn-bar-fill" style={ { width: `${ Math.min( 100, ( totals[ k ] / targets[ k ] ) * 100 || 0 ) }%` } } /></div>
							</div>
						) ) }
					</div>
				) }
			</div>
		</div>
	);
}

document.querySelectorAll( '.fn-macro-calculator' ).forEach( ( el ) => {
	const allowCustom = el.getAttribute( 'data-allow-custom' ) === '1';
	const showTargets = el.getAttribute( 'data-show-targets' ) === '1';
	createRoot( el ).render( <Calculator allowCustom={ allowCustom } showTargets={ showTargets } /> );
} );
