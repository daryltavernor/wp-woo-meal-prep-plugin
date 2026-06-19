import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

/* Add-on labels wrap their own checkbox (a valid a11y pattern); relax the
   strict label-has-associated-control rule for this file, as the Quick Order
   app does for the same reason. */
/* eslint-disable jsx-a11y/label-has-associated-control */

const SET_MEAL_PREFIX = 'set:';
const CARB_PREFIX = 'carb:';
const GREENS_PREFIX = 'greens:';

const priceLabel = ( n ) => `£${ Number( n || 0 ).toFixed( 2 ) }`;
const ZERO = { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 };
const addMacros = ( a, b ) => ( {
	kcal: a.kcal + ( b?.kcal || 0 ),
	protein_g: a.protein_g + ( b?.protein_g || 0 ),
	carbs_g: a.carbs_g + ( b?.carbs_g || 0 ),
	fat_g: a.fat_g + ( b?.fat_g || 0 ),
} );
const findById = ( id, list ) => ( list || [] ).find( ( i ) => i.id === id );

/*
 * Shared theme Add-to-Cart integration. The theme owns the actual submission;
 * this hook (a) syncs the selection JSON into a hidden field inside form.cart,
 * (b) reflects validity onto the theme's add-to-cart button, and (c) pushes the
 * live macro total to any [data-fn-macros] elements on the page. Used by both
 * the meal builder and the standalone product picker so they behave identically.
 */
function useCartSync( payload, isValid, totals ) {
	useEffect( () => {
		const form = document.querySelector( 'form.cart' );
		if ( ! form ) {
			return;
		}
		let hidden = form.querySelector( 'input[name="fn_selection"]' );
		if ( ! hidden ) {
			hidden = document.createElement( 'input' );
			hidden.type = 'hidden';
			hidden.name = 'fn_selection';
			form.appendChild( hidden );
		}
		hidden.value = isValid ? JSON.stringify( payload ) : '';
	}, [ payload, isValid ] );

	useEffect( () => {
		const button = document.querySelector(
			'form.cart .single_add_to_cart_button, form.cart button[type="submit"]'
		);
		if ( ! button ) {
			return;
		}
		if ( ! isValid ) {
			button.setAttribute( 'disabled', 'disabled' );
			button.classList.add( 'disabled' );
			button.setAttribute( 'aria-disabled', 'true' );
		} else {
			button.removeAttribute( 'disabled' );
			button.classList.remove( 'disabled' );
			button.removeAttribute( 'aria-disabled' );
		}
	}, [ isValid ] );

	useEffect( () => {
		const nodes = document.querySelectorAll( '[data-fn-macros]' );
		if ( ! nodes.length ) {
			return;
		}
		const anyPicked =
			totals.kcal > 0 ||
			totals.protein_g > 0 ||
			totals.carbs_g > 0 ||
			totals.fat_g > 0;
		nodes.forEach( ( node ) => {
			if ( ! anyPicked ) {
				const empty = node.getAttribute( 'data-fn-macros-empty' ) || '';
				node.innerHTML = `<span class="fn-macro-empty">${ empty }</span>`;
				return;
			}
			const label = node.getAttribute( 'data-fn-macros-label' ) || '';
			node.innerHTML =
				( label
					? `<strong class="fn-macro-label">${ label }</strong> `
					: '' ) +
				`<span class="fn-macro-kv"><span class="fn-macro-n">${ totals.kcal.toFixed(
					0
				) }</span> kcal</span>` +
				`<span class="fn-macro-kv"><span class="fn-macro-n">${ totals.protein_g.toFixed(
					1
				) }g</span> protein</span>` +
				`<span class="fn-macro-kv"><span class="fn-macro-n">${ totals.carbs_g.toFixed(
					1
				) }g</span> carbs</span>` +
				`<span class="fn-macro-kv"><span class="fn-macro-n">${ totals.fat_g.toFixed(
					1
				) }g</span> fat</span>`;
		} );
	}, [ totals ] );
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

function Addons( { addons, selected, onToggle } ) {
	if ( ! addons || addons.length === 0 ) {
		return null;
	}
	return (
		<div className="fn-addons">
			{ addons.map( ( addon ) => {
				const on = selected.some( ( a ) => a.id === addon.id );
				return (
					<div key={ addon.id }>
						{ addon.heading && (
							<p className="fn-addons-title">{ addon.heading }</p>
						) }
						<div className="fn-addon-row">
							<label>
								<input
									type="checkbox"
									checked={ on }
									onChange={ () => onToggle( addon ) }
								/>
								{ __( 'Add', 'fastnutrition-mealprep' ) }{ ' ' }
								{ addon.label }
							</label>
							<span className="fn-addon-price">
								{ priceLabel( addon.price ) }
							</span>
						</div>
					</div>
				);
			} ) }
		</div>
	);
}

/*
 * Standalone product: the customer buys a single pre-made item (a set meal or a
 * sweet). One configured item → no choice (auto-selected); two or more → a
 * dropdown styled like the meal builder's.
 */
function StandalonePicker( { config, ingredients } ) {
	const standalone = config.config.standalone;
	const addonsList = config.addons || [];
	const items = useMemo( () => {
		const list = ingredients[ standalone.type ] || [];
		const ids = standalone.ids || [];
		return ids.length ? list.filter( ( i ) => ids.includes( i.id ) ) : list;
	}, [ ingredients, standalone ] );

	const single = items.length === 1 ? items[ 0 ] : null;
	const [ itemId, setItemId ] = useState( 0 );
	const [ addons, setAddons ] = useState( [] );

	// Auto-select the lone item.
	useEffect( () => {
		if ( single ) {
			setItemId( single.id );
		}
	}, [ single ] );

	const chosen = findById( itemId, items );
	const itemPrice = chosen?.price_delta || 0;

	const totals = useMemo( () => {
		let t = addMacros( { ...ZERO }, chosen?.macros );
		addons.forEach( ( a ) => ( t = addMacros( t, a ) ) );
		return t;
	}, [ chosen, addons ] );

	const isValid = !! itemId;
	const payload = useMemo(
		() => ( {
			mode: 'standalone',
			item_id: itemId,
			item_type: standalone.type,
			addons,
		} ),
		[ itemId, addons, standalone.type ]
	);

	useCartSync( payload, isValid, totals );

	const toggleAddon = ( addon ) => {
		const on = addons.some( ( a ) => a.id === addon.id );
		setAddons(
			on
				? addons.filter( ( a ) => a.id !== addon.id )
				: [ ...addons, addon ]
		);
	};

	const label =
		'sweet' === standalone.type
			? __( 'Your sweet', 'fastnutrition-mealprep' )
			: __( 'Your meal', 'fastnutrition-mealprep' );

	if ( items.length === 0 ) {
		return (
			<div className="fn-meal-builder">
				{ __(
					'This product has no items configured yet.',
					'fastnutrition-mealprep'
				) }
			</div>
		);
	}

	return (
		<div className="fn-meal-builder fn-standalone">
			{ single ? (
				<Row label={ label } price={ itemPrice }>
					<span className="fn-standalone-fixed">{ single.name }</span>
				</Row>
			) : (
				<Row label={ label } required price={ itemPrice }>
					<select
						value={ itemId || '' }
						onChange={ ( e ) =>
							setItemId( parseInt( e.target.value, 10 ) || 0 )
						}
					>
						<option value="">
							{ __(
								'Choose an option…',
								'fastnutrition-mealprep'
							) }
						</option>
						{ items.map( ( i ) => (
							<option key={ i.id } value={ i.id }>
								{ i.name }
							</option>
						) ) }
					</select>
				</Row>
			) }

			<Addons
				addons={ addonsList }
				selected={ addons }
				onToggle={ toggleAddon }
			/>

			{ ! isValid && (
				<p
					className="fn-row-help"
					style={ { paddingLeft: 0, marginTop: '0.6rem' } }
				>
					{ __(
						'Choose an option to enable Add to Basket.',
						'fastnutrition-mealprep'
					) }
				</p>
			) }
		</div>
	);
}

// Meal builder: Protein + Carb + Greens (or 2 Greens instead of a carb), or a Set Meal.
function MealBuilder( { config, ingredients } ) {
	const [ selection, setSelection ] = useState( {
		protein_id: 0,
		set_meal_id: 0,
		slot2_kind: '',
		slot2_id: 0,
		greens_id: 0,
		addons: [],
	} );

	const allowed = useMemo( () => {
		const tier = config.config.tier || 'standard';
		const byAllow = ( list, allowList ) =>
			! allowList || ! allowList.length
				? list
				: list.filter( ( i ) => allowList.includes( i.id ) );
		const byTier = ( list ) =>
			list.filter( ( i ) => ! i.tier || i.tier === tier );
		const apply = ( list, allowList ) =>
			byTier( byAllow( list || [], allowList ) );
		return {
			protein: apply(
				ingredients.protein,
				config.config.allowed_proteins
			),
			carb: apply( ingredients.carb, config.config.allowed_carbs ),
			greens: apply( ingredients.greens, config.config.allowed_greens ),
			set_meal: apply(
				ingredients.set_meal,
				config.config.allowed_set_meals
			),
		};
	}, [ config, ingredients ] );

	const isSetMeal = !! selection.set_meal_id;

	const slot1Price = isSetMeal
		? findById( selection.set_meal_id, ingredients.set_meal )
				?.price_delta || 0
		: findById( selection.protein_id, ingredients.protein )?.price_delta ||
		  0;
	const slot2Price = ! selection.slot2_id
		? 0
		: findById(
				selection.slot2_id,
				selection.slot2_kind === 'carb'
					? ingredients.carb
					: ingredients.greens
		  )?.price_delta || 0;
	const slot3Price =
		findById( selection.greens_id, ingredients.greens )?.price_delta || 0;

	const totals = useMemo( () => {
		let t = { ...ZERO };
		if ( isSetMeal ) {
			t = addMacros(
				t,
				findById( selection.set_meal_id, ingredients.set_meal )?.macros
			);
		} else {
			if ( selection.protein_id ) {
				t = addMacros(
					t,
					findById( selection.protein_id, ingredients.protein )
						?.macros
				);
			}
			if ( selection.slot2_kind === 'carb' && selection.slot2_id ) {
				t = addMacros(
					t,
					findById( selection.slot2_id, ingredients.carb )?.macros
				);
			}
			if ( selection.slot2_kind === 'greens' && selection.slot2_id ) {
				t = addMacros(
					t,
					findById( selection.slot2_id, ingredients.greens )?.macros
				);
			}
			if ( selection.greens_id ) {
				t = addMacros(
					t,
					findById( selection.greens_id, ingredients.greens )?.macros
				);
			}
		}
		( selection.addons || [] ).forEach(
			( a ) => ( t = addMacros( t, a ) )
		);
		return t;
	}, [ selection, ingredients, isSetMeal ] );

	const isValid = useMemo( () => {
		if ( isSetMeal ) {
			return true;
		}
		if ( ! selection.protein_id ) {
			return false;
		}
		if ( ! selection.slot2_id || ! selection.slot2_kind ) {
			return false;
		}
		if ( ! selection.greens_id ) {
			return false;
		}
		// Two of the same green is allowed (e.g. double green beans), so we
		// don't reject slot2 === slot3 here.
		return true;
	}, [ selection, isSetMeal ] );

	const payload = useMemo( () => {
		if ( selection.set_meal_id ) {
			return {
				mode: 'set',
				set_meal_id: selection.set_meal_id,
				addons: selection.addons,
			};
		}
		const greensIds = [];
		if ( selection.slot2_kind === 'greens' && selection.slot2_id ) {
			greensIds.push( selection.slot2_id );
		}
		if ( selection.greens_id ) {
			greensIds.push( selection.greens_id );
		}
		return {
			mode: 'build',
			protein_id: selection.protein_id,
			carb_id: selection.slot2_kind === 'carb' ? selection.slot2_id : 0,
			greens_ids: greensIds,
			addons: selection.addons,
		};
	}, [ selection ] );

	useCartSync( payload, isValid, totals );

	const onSlot1Change = ( value ) => {
		if ( value.startsWith( SET_MEAL_PREFIX ) ) {
			const id =
				parseInt( value.slice( SET_MEAL_PREFIX.length ), 10 ) || 0;
			setSelection( {
				...selection,
				protein_id: 0,
				set_meal_id: id,
				slot2_kind: '',
				slot2_id: 0,
				greens_id: 0,
			} );
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
			const greensId =
				id === selection.greens_id ? 0 : selection.greens_id;
			setSelection( {
				...selection,
				slot2_kind: 'greens',
				slot2_id: id,
				greens_id: greensId,
			} );
		} else {
			setSelection( { ...selection, slot2_kind: '', slot2_id: 0 } );
		}
	};

	const toggleAddon = ( addon ) => {
		const on = selection.addons.some( ( a ) => a.id === addon.id );
		const addons = on
			? selection.addons.filter( ( a ) => a.id !== addon.id )
			: [ ...selection.addons, addon ];
		setSelection( { ...selection, addons } );
	};

	let slot1Value = '';
	if ( isSetMeal ) {
		slot1Value = SET_MEAL_PREFIX + selection.set_meal_id;
	} else if ( selection.protein_id ) {
		slot1Value = String( selection.protein_id );
	}
	const slot2Value = selection.slot2_id
		? ( selection.slot2_kind === 'carb' ? CARB_PREFIX : GREENS_PREFIX ) +
		  selection.slot2_id
		: '';

	return (
		<div className="fn-meal-builder">
			<Row
				label={ __( 'Pick a Protein', 'fastnutrition-mealprep' ) }
				required
				price={ slot1Price }
			>
				<select
					value={ slot1Value }
					onChange={ ( e ) => onSlot1Change( e.target.value ) }
				>
					<option value="">
						{ __(
							'Meat, Quorn or Set Meal',
							'fastnutrition-mealprep'
						) }
					</option>
					{ allowed.protein.length > 0 && (
						<optgroup
							label={ __( 'Proteins', 'fastnutrition-mealprep' ) }
						>
							{ allowed.protein.map( ( i ) => (
								<option key={ 'p' + i.id } value={ i.id }>
									{ i.name }
								</option>
							) ) }
						</optgroup>
					) }
					{ config.config.allow_set_meal_mode &&
						allowed.set_meal.length > 0 && (
							<optgroup
								label={ __(
									'Set Meals',
									'fastnutrition-mealprep'
								) }
							>
								{ allowed.set_meal.map( ( i ) => (
									<option
										key={ 'sm' + i.id }
										value={ SET_MEAL_PREFIX + i.id }
									>
										{ i.name }
									</option>
								) ) }
							</optgroup>
						) }
				</select>
			</Row>

			{ ! isSetMeal && (
				<>
					<Row
						label={ __(
							'Pick Your Carbs',
							'fastnutrition-mealprep'
						) }
						required
						price={ slot2Price }
						help={
							config.config.allow_double_greens
								? __(
										'Or pick a 2nd greens here to swap in 2× greens instead of a carb.',
										'fastnutrition-mealprep'
								  )
								: ''
						}
					>
						<select
							value={ slot2Value }
							onChange={ ( e ) =>
								onSlot2Change( e.target.value )
							}
						>
							<option value="">
								{ __(
									'Carbs or Double Greens',
									'fastnutrition-mealprep'
								) }
							</option>
							{ allowed.carb.length > 0 && (
								<optgroup
									label={ __(
										'Carbs',
										'fastnutrition-mealprep'
									) }
								>
									{ allowed.carb.map( ( i ) => (
										<option
											key={ 'c' + i.id }
											value={ CARB_PREFIX + i.id }
										>
											{ i.name }
										</option>
									) ) }
								</optgroup>
							) }
							{ config.config.allow_double_greens &&
								allowed.greens.length > 0 && (
									<optgroup
										label={ __(
											'Greens (low carb option)',
											'fastnutrition-mealprep'
										) }
									>
										{ allowed.greens.map( ( i ) => (
											<option
												key={ 'g2' + i.id }
												value={ GREENS_PREFIX + i.id }
											>
												{ i.name }
											</option>
										) ) }
									</optgroup>
								) }
						</select>
					</Row>

					<Row
						label={
							selection.slot2_kind === 'greens'
								? __(
										'Pick Your 2nd Greens',
										'fastnutrition-mealprep'
								  )
								: __(
										'Pick Your Greens',
										'fastnutrition-mealprep'
								  )
						}
						required
						price={ slot3Price }
					>
						<select
							value={ selection.greens_id || '' }
							onChange={ ( e ) =>
								setSelection( {
									...selection,
									greens_id:
										parseInt( e.target.value, 10 ) || 0,
								} )
							}
						>
							<option value="">
								{ __(
									'Choose Your Greens',
									'fastnutrition-mealprep'
								) }
							</option>
							{ /* Show every green here, including the one picked in
								 slot 2 — two of the same green is allowed. */ }
							{ allowed.greens.map( ( i ) => (
								<option key={ 'g3' + i.id } value={ i.id }>
									{ i.name }
								</option>
							) ) }
						</select>
					</Row>
				</>
			) }

			<Addons
				addons={ config.addons || [] }
				selected={ selection.addons }
				onToggle={ toggleAddon }
			/>

			{ ! isValid && (
				<p
					className="fn-row-help"
					style={ { paddingLeft: 0, marginTop: '0.6rem' } }
				>
					{ __(
						'Finish your selection to enable Add to Basket.',
						'fastnutrition-mealprep'
					) }
				</p>
			) }
		</div>
	);
}

/*
 * Popular Combinations: a dropdown of the shop's most-ordered build combos
 * (protein + carb + greens), produced weekly by Stats\PopularCombos. We resolve
 * each ranked combo against the ingredients this product actually offers (and
 * that are still available), then show the top 7 plus a few rotating picks from
 * ranks 8+ for discovery. A chosen combo is a normal `build` selection, so the
 * cart/pricing/labels pipeline is unchanged.
 */
const COMBO_TOP = 7;
const COMBO_EXTRA = 3;

function PopularCombosPicker( { config, ingredients } ) {
	const cfg = config.config;
	const addonsList = config.addons || [];

	// Resolve + filter the ranked combos to those valid for this product.
	const resolved = useMemo( () => {
		const inAllow = ( id, allow ) =>
			! allow || ! allow.length || allow.includes( id );
		const out = [];
		( config.popular_combos || [] ).forEach( ( c ) => {
			const protein = findById( c.protein_id, ingredients.protein );
			if ( ! protein || ! inAllow( c.protein_id, cfg.allowed_proteins ) ) {
				return;
			}
			let carb = null;
			if ( c.carb_id ) {
				carb = findById( c.carb_id, ingredients.carb );
				if ( ! carb || ! inAllow( c.carb_id, cfg.allowed_carbs ) ) {
					return;
				}
			}
			const greens = [];
			let ok = true;
			( c.greens_ids || [] ).forEach( ( gid ) => {
				const g = findById( gid, ingredients.greens );
				if ( ! g || ! inAllow( gid, cfg.allowed_greens ) ) {
					ok = false;
					return;
				}
				greens.push( g );
			} );
			if ( ! ok || greens.length === 0 ) {
				return;
			}

			let macros = addMacros( { ...ZERO }, protein.macros );
			if ( carb ) {
				macros = addMacros( macros, carb.macros );
			}
			greens.forEach( ( g ) => ( macros = addMacros( macros, g.macros ) ) );

			const delta =
				( protein.price_delta || 0 ) +
				( carb ? carb.price_delta || 0 : 0 ) +
				greens.reduce( ( s, g ) => s + ( g.price_delta || 0 ), 0 );

			out.push( {
				key:
					c.protein_id +
					':' +
					( c.carb_id || 0 ) +
					':' +
					( c.greens_ids || [] ).join( ',' ),
				label: [
					protein.name,
					carb ? carb.name : null,
					greens.map( ( g ) => g.name ).join( ' & ' ),
				]
					.filter( Boolean )
					.join( ' + ' ),
				delta,
				macros,
				payload: {
					protein_id: c.protein_id,
					carb_id: c.carb_id || 0,
					greens_ids: c.greens_ids || [],
				},
			} );
		} );
		return out;
	}, [ config, ingredients, cfg ] );

	// Top 7 always, plus a few rotating picks from ranks 8+ (per page load).
	const display = useMemo( () => {
		if ( resolved.length <= COMBO_TOP + COMBO_EXTRA ) {
			return resolved;
		}
		const top = resolved.slice( 0, COMBO_TOP );
		const pool = resolved.slice( COMBO_TOP );
		for ( let n = 0; n < COMBO_EXTRA && pool.length; n++ ) {
			const r = Math.floor( Math.random() * pool.length );
			top.push( pool.splice( r, 1 )[ 0 ] );
		}
		return top;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ resolved ] );

	const [ idx, setIdx ] = useState( -1 );
	const [ addons, setAddons ] = useState( [] );
	const chosen = idx >= 0 ? display[ idx ] : null;
	const isValid = !! chosen;

	const totals = useMemo( () => {
		let t = chosen ? { ...chosen.macros } : { ...ZERO };
		addons.forEach( ( a ) => ( t = addMacros( t, a ) ) );
		return t;
	}, [ chosen, addons ] );

	const payload = useMemo(
		() => ( {
			mode: 'build',
			protein_id: chosen?.payload.protein_id || 0,
			carb_id: chosen?.payload.carb_id || 0,
			greens_ids: chosen?.payload.greens_ids || [],
			addons,
		} ),
		[ chosen, addons ]
	);

	useCartSync( payload, isValid, totals );

	const toggleAddon = ( addon ) => {
		const on = addons.some( ( a ) => a.id === addon.id );
		setAddons(
			on ? addons.filter( ( a ) => a.id !== addon.id ) : [ ...addons, addon ]
		);
	};

	if ( display.length === 0 ) {
		return (
			<div className="fn-meal-builder">
				{ __(
					'No popular combinations are available yet — please check back soon.',
					'fastnutrition-mealprep'
				) }
			</div>
		);
	}

	return (
		<div className="fn-meal-builder fn-standalone">
			<Row
				label={ __( 'Popular combinations', 'fastnutrition-mealprep' ) }
				required
				price={ chosen?.delta || 0 }
			>
				<select
					value={ idx >= 0 ? idx : '' }
					onChange={ ( e ) =>
						setIdx(
							e.target.value === ''
								? -1
								: parseInt( e.target.value, 10 )
						)
					}
				>
					<option value="">
						{ __( 'Choose a combination…', 'fastnutrition-mealprep' ) }
					</option>
					{ display.map( ( combo, i ) => (
						<option key={ combo.key } value={ i }>
							{ combo.label }
						</option>
					) ) }
				</select>
			</Row>

			<Addons
				addons={ addonsList }
				selected={ addons }
				onToggle={ toggleAddon }
			/>

			{ ! isValid && (
				<p
					className="fn-row-help"
					style={ { paddingLeft: 0, marginTop: '0.6rem' } }
				>
					{ __(
						'Choose a combination to enable Add to Basket.',
						'fastnutrition-mealprep'
					) }
				</p>
			) }
		</div>
	);
}

// Loads the product config, then renders the right configurator for the product.
function Configurator( { productId } ) {
	const [ config, setConfig ] = useState( null );
	const [ ingredients, setIngredients ] = useState( {
		protein: [],
		carb: [],
		greens: [],
		set_meal: [],
		sweet: [],
	} );

	useEffect( () => {
		apiFetch( {
			path: `fastnutrition/v1/meal-config/${ productId }`,
		} ).then( setConfig );
		Promise.all( [
			apiFetch( {
				path: 'fastnutrition/v1/ingredients?type=protein&channel=builder',
			} ),
			apiFetch( {
				path: 'fastnutrition/v1/ingredients?type=carb&channel=builder',
			} ),
			apiFetch( {
				path: 'fastnutrition/v1/ingredients?type=greens&channel=builder',
			} ),
			apiFetch( {
				path: 'fastnutrition/v1/ingredients?type=set_meal&channel=builder',
			} ),
			apiFetch( {
				path: 'fastnutrition/v1/ingredients?type=sweet&channel=builder',
			} ),
		] ).then( ( [ p, c, g, s, sw ] ) =>
			setIngredients( {
				protein: p,
				carb: c,
				greens: g,
				set_meal: s,
				sweet: sw,
			} )
		);
	}, [ productId ] );

	if ( ! config ) {
		return (
			<div className="fn-meal-builder">
				{ __( 'Loading…', 'fastnutrition-mealprep' ) }
			</div>
		);
	}

	if ( config.config && config.config.popular_combos_enabled ) {
		return (
			<PopularCombosPicker
				config={ config }
				ingredients={ ingredients }
			/>
		);
	}
	const standalone = config.config && config.config.standalone;
	if ( standalone && standalone.enabled ) {
		return (
			<StandalonePicker config={ config } ingredients={ ingredients } />
		);
	}
	return <MealBuilder config={ config } ingredients={ ingredients } />;
}

document.querySelectorAll( '[data-fn-meal-builder]' ).forEach( ( el ) => {
	const productId = parseInt(
		el.getAttribute( 'data-product-id' ) || '0',
		10
	);
	if ( productId ) {
		createRoot( el ).render( <Configurator productId={ productId } /> );
	}
} );
