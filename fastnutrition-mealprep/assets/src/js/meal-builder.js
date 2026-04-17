/**
 * Meal Builder front-end view script.
 * Mounts a React UI into any .fn-meal-builder container, fetches ingredient options from REST,
 * and posts add-to-cart via the WC Store API.
 */
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const REST = window.FN_MEALPREP?.rest || '/wp-json/fastnutrition/v1/';
const NONCE = window.FN_MEALPREP?.nonce;

apiFetch.use( apiFetch.createNonceMiddleware( NONCE ) );

function Chip( { label, tags } ) {
	return (
		<span className="fn-chip">
			{ label }
			{ tags?.length > 0 && <em className="fn-chip-tags"> · { tags.join( ', ' ) }</em> }
		</span>
	);
}

function TypePicker( { type, options, value, onChange, multiple = false, max = 1, disabledIds = [] } ) {
	const arrayValue = Array.isArray( value ) ? value : value ? [ value ] : [];
	const toggle = ( id ) => {
		if ( multiple ) {
			const exists = arrayValue.includes( id );
			const next = exists
				? arrayValue.filter( ( x ) => x !== id )
				: [ ...arrayValue, id ].slice( -max );
			onChange( next );
		} else {
			onChange( id );
		}
	};

	return (
		<fieldset className={ `fn-type-picker fn-type-${ type }` }>
			<legend>{ type }</legend>
			<ul>
				{ options.map( ( opt ) => {
					const active = arrayValue.includes( opt.id );
					const disabled = disabledIds.includes( opt.id );
					return (
						<li key={ opt.id }>
							<button
								type="button"
								aria-pressed={ active }
								className={ `fn-option${ active ? ' is-active' : '' }` }
								onClick={ () => ! disabled && toggle( opt.id ) }
								disabled={ disabled }
							>
								<Chip label={ opt.title } tags={ opt.allergens } />
							</button>
						</li>
					);
				} ) }
			</ul>
		</fieldset>
	);
}

function MealBuilder( { container } ) {
	const config = useMemo( () => {
		try {
			return JSON.parse( container.getAttribute( 'data-config' ) || '{}' );
		} catch {
			return {};
		}
	}, [ container ] );

	const productId = config.productId;
	const meal = config.config || {};
	const addonDefs = config.addons || [];

	const [ ingredients, setIngredients ] = useState( { protein: [], carb: [], greens: [], set_meal: [] } );
	const [ mode, setMode ] = useState( 'build' );
	const [ doubleGreens, setDoubleGreens ] = useState( false );
	const [ proteinId, setProteinId ] = useState( null );
	const [ carbId, setCarbId ] = useState( null );
	const [ greensIds, setGreensIds ] = useState( [] );
	const [ setMealId, setSetMealId ] = useState( null );
	const [ addons, setAddons ] = useState( [] );
	const [ qty, setQty ] = useState( 1 );
	const [ pending, setPending ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		( async () => {
			const res = await apiFetch( { path: `fastnutrition/v1/ingredients?product=${ productId }` } );
			setIngredients( res );
		} )();
		setAddons( addonDefs.filter( ( a ) => a.default ).map( ( a ) => a.id ) );
	}, [ productId ] );

	const macros = useMemo( () => {
		const sum = { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 };
		const add = ( list, id ) => {
			const ing = list.find( ( i ) => i.id === id );
			if ( ing?.macros ) Object.keys( sum ).forEach( ( k ) => ( sum[ k ] += ing.macros[ k ] || 0 ) );
		};
		if ( mode === 'set' && setMealId ) {
			add( ingredients.set_meal, setMealId );
		} else {
			if ( proteinId ) add( ingredients.protein, proteinId );
			if ( carbId ) add( ingredients.carb, carbId );
			greensIds.forEach( ( g ) => add( ingredients.greens, g ) );
		}
		return sum;
	}, [ mode, proteinId, carbId, greensIds, setMealId, ingredients ] );

	const isValid = () => {
		if ( mode === 'set' ) return !! setMealId;
		if ( ! proteinId ) return false;
		if ( doubleGreens ) return greensIds.length === 2;
		return !! carbId && greensIds.length === 1;
	};

	const onAdd = async () => {
		if ( ! isValid() ) {
			setError( __( 'Please complete your meal.', 'fastnutrition-mealprep' ) );
			return;
		}
		setPending( true );
		setError( '' );
		try {
			const selection = {
				mode,
				protein_id: mode === 'set' ? null : proteinId,
				carb_id: mode === 'set' || doubleGreens ? null : carbId,
				greens_ids: mode === 'set' ? [] : greensIds,
				set_meal_id: mode === 'set' ? setMealId : null,
				addons: addons.map( ( id ) => addonDefs.find( ( a ) => a.id === id ) ).filter( Boolean ),
				tier: meal.tier,
			};
			await apiFetch( {
				path: '/wc/store/v1/cart/add-item',
				method: 'POST',
				data: {
					id: productId,
					quantity: qty,
					variation: [],
					extensions: { fastnutrition: { selection } },
				},
			} );
			// Fallback classic add-to-cart for non-Store-API themes.
			const form = new FormData();
			form.append( 'add-to-cart', productId );
			form.append( 'quantity', qty );
			form.append( 'fn_selection', JSON.stringify( selection ) );
			await fetch( window.location.href, { method: 'POST', body: form } );
			window.location.href = '/cart/';
		} catch ( e ) {
			setError( e.message || String( e ) );
		} finally {
			setPending( false );
		}
	};

	const allowedFilter = ( list, allowed ) =>
		! allowed?.length ? list : list.filter( ( i ) => allowed.includes( i.id ) );

	const proteinOptions = allowedFilter( ingredients.protein, meal.allowed_protein );
	const carbOptions = allowedFilter( ingredients.carb, meal.allowed_carb );
	const greensOptions = allowedFilter( ingredients.greens, meal.allowed_greens );
	const setMealOptions = allowedFilter( ingredients.set_meal, meal.allowed_set_meal );

	return (
		<div className="fn-builder-root">
			{ meal.allow_set_meal && (
				<div className="fn-mode-toggle">
					<button type="button" className={ mode === 'build' ? 'is-active' : '' } onClick={ () => setMode( 'build' ) }>
						{ __( 'Build your meal', 'fastnutrition-mealprep' ) }
					</button>
					<button type="button" className={ mode === 'set' ? 'is-active' : '' } onClick={ () => setMode( 'set' ) }>
						{ __( 'Choose a set meal', 'fastnutrition-mealprep' ) }
					</button>
				</div>
			) }

			{ mode === 'build' ? (
				<>
					<TypePicker type={ __( 'Protein', 'fastnutrition-mealprep' ) } options={ proteinOptions } value={ proteinId } onChange={ setProteinId } />

					{ ! doubleGreens && (
						<TypePicker type={ __( 'Carb', 'fastnutrition-mealprep' ) } options={ carbOptions } value={ carbId } onChange={ setCarbId } />
					) }

					{ meal.allow_double && (
						<label className="fn-double-toggle">
							<input
								type="checkbox"
								checked={ doubleGreens }
								onChange={ ( e ) => {
									setDoubleGreens( e.target.checked );
									setCarbId( null );
									setGreensIds( [] );
								} }
							/>
							{ __( 'Swap carb for second greens', 'fastnutrition-mealprep' ) }
						</label>
					) }

					<TypePicker
						type={ doubleGreens ? __( 'Greens (pick 2)', 'fastnutrition-mealprep' ) : __( 'Greens', 'fastnutrition-mealprep' ) }
						options={ greensOptions }
						value={ greensIds }
						onChange={ setGreensIds }
						multiple={ doubleGreens }
						max={ doubleGreens ? 2 : 1 }
					/>
				</>
			) : (
				<TypePicker type={ __( 'Set Meals', 'fastnutrition-mealprep' ) } options={ setMealOptions } value={ setMealId } onChange={ setSetMealId } />
			) }

			{ addonDefs.length > 0 && (
				<fieldset className="fn-addons">
					<legend>{ __( 'Add-ons', 'fastnutrition-mealprep' ) }</legend>
					{ addonDefs.map( ( a ) => (
						<label key={ a.id }>
							<input
								type="checkbox"
								checked={ addons.includes( a.id ) }
								onChange={ ( e ) => setAddons( e.target.checked ? [ ...addons, a.id ] : addons.filter( ( x ) => x !== a.id ) ) }
							/>
							{ a.label } (+£{ Number( a.price ).toFixed( 2 ) })
						</label>
					) ) }
				</fieldset>
			) }

			<div className="fn-macros-live">
				<strong>{ __( 'Macros:', 'fastnutrition-mealprep' ) } </strong>
				{ sprintf( '%d kcal · P %d · C %d · F %d', Math.round( macros.kcal ), Math.round( macros.protein_g ), Math.round( macros.carbs_g ), Math.round( macros.fat_g ) ) }
			</div>

			<div className="fn-add-row">
				<input type="number" min="1" value={ qty } onChange={ ( e ) => setQty( Math.max( 1, parseInt( e.target.value, 10 ) || 1 ) ) } />
				<button type="button" className="button alt fn-add-to-cart" disabled={ pending || ! isValid() } onClick={ onAdd }>
					{ pending ? __( 'Adding…', 'fastnutrition-mealprep' ) : __( 'Add to cart', 'fastnutrition-mealprep' ) }
				</button>
			</div>
			{ error && <p className="fn-error">{ error }</p> }
		</div>
	);
}

document.querySelectorAll( '.fn-meal-builder' ).forEach( ( el ) => {
	createRoot( el ).render( <MealBuilder container={ el } /> );
} );
