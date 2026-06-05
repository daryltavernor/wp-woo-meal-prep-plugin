/**
 * Quick Order — touch-first order entry for staff, in the WordPress admin.
 *
 * Talks to the plugin's own capability-protected endpoints
 * (fastnutrition/v1/instore/*) plus the /ingredients and /slots reads. Access
 * is governed by the WordPress login + manage_woocommerce capability; the order
 * is attributed to the signed-in user. A real WooCommerce order is created
 * server-side by InStore\OrderFactory.
 */
/* Labels in this kiosk form nest their own control (a valid a11y pattern); the
   strict label-has-associated-control rule is relaxed for this file. */
/* eslint-disable jsx-a11y/label-has-associated-control */
import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import './style.css';

const boot = window.fnQuickOrder || {};
const CURRENCY = boot.currency || '£';
const ROOT_V1 = boot.v1Url || ( boot.restUrl || '' ).replace( 'instore/', '' );
// 'order' builds a WooCommerce order; 'label' produces a labels PDF only.
const MODE = boot.mode === 'label' ? 'label' : 'order';
const IS_LABEL = MODE === 'label';

// Authenticate REST writes with the logged-in user's cookie nonce.
if ( boot.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( boot.nonce ) );
}

const money = ( n ) => `${ CURRENCY }${ Number( n || 0 ).toFixed( 2 ) }`;
const uid = () => Math.random().toString( 36 ).slice( 2 );

/* ---- Bundle pricing (mirrors Cart\BundlePricer::calculate) -------------- */
function bundleTotalFor( qty, bundles ) {
	let tier = null;
	( bundles || [] ).forEach( ( t ) => {
		const tq = Number( t.qty ) || 0;
		const tp = Number( t.price ) || 0;
		if ( tq <= 0 || tp <= 0 || qty < tq ) {
			return;
		}
		if ( ! tier || tq > Number( tier.qty ) ) {
			tier = t;
		}
	} );
	if ( ! tier ) {
		return null;
	}
	const thresh = Number( tier.qty );
	const bp = Number( tier.price );
	const extra = qty - thresh;
	const ratePence = Math.ceil( Math.round( bp * 100 ) / thresh );
	return bp + extra * ( ratePence / 100 );
}

/* Total the basket with bundle pricing applied per product, exactly as the
   server does: a bundle covers the meal base for every unit in the group, and
   per-line deltas (ingredient swaps + add-ons) are charged on top. */
function computeTotals( basket, config ) {
	const sets = ( config && config.sets ) || {};
	const groups = {};
	basket.forEach( ( l ) => {
		const g =
			groups[ l.product_id ] ||
			( groups[ l.product_id ] = { qty: 0, sumEst: 0, set: l.set } );
		g.qty += l.qty;
		g.sumEst += l.estimate;
	} );
	let total = 0;
	Object.keys( groups ).forEach( ( pid ) => {
		const g = groups[ pid ];
		const setCfg = sets[ g.set ] || {};
		const base = Number( setCfg.price ) || 0;
		const bt = bundleTotalFor( g.qty, setCfg.bundles );
		total += bt !== null ? bt + ( g.sumEst - base * g.qty ) : g.sumEst;
	} );
	const rack = basket.reduce( ( s, l ) => s + l.estimate, 0 );
	return { total, saving: Math.max( 0, rack - total ) };
}

/* ---- API helpers ------------------------------------------------------- */
const api = ( path, options = {} ) =>
	apiFetch( { url: boot.restUrl + path, ...options } );
const apiV1 = ( path ) => apiFetch( { url: ROOT_V1 + path } );

/* ---- Small UI atoms ---------------------------------------------------- */
function Pill( { active, children, onClick, sub } ) {
	return (
		<button
			type="button"
			className={ `fn-pill${ active ? ' is-active' : '' }` }
			onClick={ onClick }
		>
			<span className="fn-pill__label">{ children }</span>
			{ sub ? <span className="fn-pill__sub">{ sub }</span> : null }
		</button>
	);
}

function PillRow( { children } ) {
	return <div className="fn-pillrow">{ children }</div>;
}

function Stepper( { value, onChange, min = 1 } ) {
	return (
		<div className="fn-stepper">
			<button
				type="button"
				onClick={ () => onChange( Math.max( min, value - 1 ) ) }
				aria-label={ __( 'Decrease', 'fastnutrition-mealprep' ) }
			>
				−
			</button>
			<span className="fn-stepper__val">{ value }</span>
			<button
				type="button"
				onClick={ () => onChange( value + 1 ) }
				aria-label={ __( 'Increase', 'fastnutrition-mealprep' ) }
			>
				+
			</button>
		</div>
	);
}

/* ---- Ingredient filtering --------------------------------------------- */
function allowedFor( set, ingredients ) {
	if ( ! set ) {
		return { protein: [], carb: [], greens: [], set_meal: [], sweet: [] };
	}
	const cfg = set.config || {};
	const tier = cfg.tier || 'standard';
	const ov = set.overrides || {};
	const pick = ( list, allowList, override ) => {
		const allow = override && override.length ? override : allowList;
		const base = ( list || [] ).filter(
			( i ) => ! i.tier || i.tier === tier
		);
		return ! allow || ! allow.length
			? base
			: base.filter( ( i ) => allow.includes( i.id ) );
	};
	return {
		protein: pick( ingredients.protein, cfg.allowed_proteins, ov.proteins ),
		carb: pick( ingredients.carb, cfg.allowed_carbs, ov.carbs ),
		greens: pick( ingredients.greens, cfg.allowed_greens, ov.greens ),
		set_meal: pick(
			ingredients.set_meal,
			cfg.allowed_set_meals,
			ov.set_meals
		),
		sweet: pick( ingredients.sweet, cfg.allowed_sweets, ov.sweets ),
	};
}

/* ---- Build form (one item) -------------------------------------------- */
const emptyBuild = () => ( {
	mode: 'build',
	protein_id: 0,
	set_meal_id: 0,
	sweet_id: 0,
	carb_id: 0,
	greens_ids: [],
	addons: [],
	qty: 1,
} );

function BuildForm( { setKey, set, ingredients, onAdd } ) {
	const [ b, setB ] = useState( emptyBuild );
	const allow = useMemo(
		() => allowedFor( set, ingredients ),
		[ set, ingredients ]
	);
	const isSweets = setKey === 'sweets';
	const cfg = set ? set.config || {} : {};
	const addons = set ? set.addons || [] : [];

	// Reset when the chosen set changes.
	useEffect( () => setB( emptyBuild() ), [ setKey ] );

	const chooseProtein = ( id ) =>
		setB( { ...b, mode: 'build', protein_id: id, set_meal_id: 0 } );
	const chooseSetMeal = ( id ) =>
		setB( {
			...b,
			mode: 'set',
			set_meal_id: id,
			protein_id: 0,
			carb_id: 0,
			greens_ids: [],
		} );
	const chooseSweet = ( id ) => setB( { ...b, mode: 'sweet', sweet_id: id } );

	const toggleGreen = ( id ) => {
		let g = b.greens_ids.includes( id )
			? b.greens_ids.filter( ( x ) => x !== id )
			: [ ...b.greens_ids, id ];
		g = g.slice( -2 );
		setB( {
			...b,
			greens_ids: g,
			carb_id: g.length === 2 ? 0 : b.carb_id,
		} );
	};
	const chooseCarb = ( id ) =>
		setB( { ...b, carb_id: id, greens_ids: b.greens_ids.slice( 0, 1 ) } );
	const toggleAddon = ( a ) => {
		const has = b.addons.find( ( x ) => x.id === a.id );
		setB( { ...b, addons: has ? [] : [ a ] } ); // max 1
	};

	/* Validity mirrors the server-side Selections::validate. */
	const valid = ( () => {
		if ( isSweets || b.mode === 'sweet' ) {
			return !! b.sweet_id;
		}
		if ( b.mode === 'set' ) {
			return !! b.set_meal_id;
		}
		if ( ! b.protein_id ) {
			return false;
		}
		const g = b.greens_ids.length;
		if ( g === 2 ) {
			return cfg.allow_double_greens;
		}
		return g === 1 && !! b.carb_id;
	} )();

	const nameOf = ( list, id ) =>
		( list.find( ( i ) => i.id === id ) || {} ).name || '';
	const deltaOf = ( list, id ) =>
		( list.find( ( i ) => i.id === id ) || {} ).price_delta || 0;

	const estimate = ( () => {
		const base = set ? set.price || 0 : 0;
		let d = 0;
		if ( b.mode === 'set' ) {
			d += deltaOf( allow.set_meal, b.set_meal_id );
		} else if ( isSweets || b.mode === 'sweet' ) {
			d += deltaOf( allow.sweet, b.sweet_id );
		} else {
			d +=
				deltaOf( allow.protein, b.protein_id ) +
				deltaOf( allow.carb, b.carb_id );
			b.greens_ids.forEach(
				( id ) => ( d += deltaOf( allow.greens, id ) )
			);
		}
		b.addons.forEach( ( a ) => ( d += Number( a.price || 0 ) ) );
		return ( base + d ) * b.qty;
	} )();

	const buildLabel = ( () => {
		if ( b.mode === 'set' ) {
			return nameOf( allow.set_meal, b.set_meal_id );
		}
		if ( isSweets || b.mode === 'sweet' ) {
			return nameOf( allow.sweet, b.sweet_id );
		}
		const parts = [ nameOf( allow.protein, b.protein_id ) ];
		if ( b.carb_id ) {
			parts.push( nameOf( allow.carb, b.carb_id ) );
		}
		b.greens_ids.forEach( ( id ) =>
			parts.push( nameOf( allow.greens, id ) )
		);
		return parts.filter( Boolean ).join( ' · ' );
	} )();

	const buildSelection = () => {
		if ( isSweets || b.mode === 'sweet' ) {
			return { mode: 'sweet', sweet_id: b.sweet_id, addons: b.addons };
		}
		if ( b.mode === 'set' ) {
			return {
				mode: 'set',
				set_meal_id: b.set_meal_id,
				addons: b.addons,
			};
		}
		return {
			mode: 'build',
			protein_id: b.protein_id,
			carb_id: b.greens_ids.length === 2 ? 0 : b.carb_id,
			greens_ids: b.greens_ids,
			addons: b.addons,
		};
	};

	const add = () => {
		if ( ! valid ) {
			return;
		}
		const selection = buildSelection();
		onAdd( {
			id: uid(),
			set: setKey,
			product_id: set.product_id,
			qty: b.qty,
			selection,
			label: buildLabel,
			addonLabel: b.addons.map( ( a ) => a.label ).join( ', ' ),
			estimate,
		} );
		setB( emptyBuild() );
	};

	if ( ! set ) {
		return (
			<div className="fn-empty">
				{ __(
					'This product set is not configured. Set it under Meal Prep → Quick Order.',
					'fastnutrition-mealprep'
				) }
			</div>
		);
	}

	const showSides = ! isSweets && b.mode === 'build' && !! b.protein_id;

	return (
		<div className="fn-build">
			{ isSweets ? (
				<section>
					<h3>{ __( 'Sweet', 'fastnutrition-mealprep' ) }</h3>
					<PillRow>
						{ allow.sweet.map( ( i ) => (
							<Pill
								key={ i.id }
								active={ b.sweet_id === i.id }
								onClick={ () => chooseSweet( i.id ) }
								sub={
									i.price_delta
										? `+${ money( i.price_delta ) }`
										: null
								}
							>
								{ i.name }
							</Pill>
						) ) }
					</PillRow>
				</section>
			) : (
				<>
					<section>
						<h3>
							{ __(
								'Protein or set meal',
								'fastnutrition-mealprep'
							) }
						</h3>
						<PillRow>
							{ allow.protein.map( ( i ) => (
								<Pill
									key={ `p${ i.id }` }
									active={ b.protein_id === i.id }
									onClick={ () => chooseProtein( i.id ) }
									sub={
										i.price_delta
											? `+${ money( i.price_delta ) }`
											: null
									}
								>
									{ i.name }
								</Pill>
							) ) }
							{ cfg.allow_set_meal_mode &&
								allow.set_meal.map( ( i ) => (
									<Pill
										key={ `s${ i.id }` }
										active={ b.set_meal_id === i.id }
										onClick={ () => chooseSetMeal( i.id ) }
										sub={ __(
											'set meal',
											'fastnutrition-mealprep'
										) }
									>
										{ i.name }
									</Pill>
								) ) }
						</PillRow>
					</section>

					{ showSides && (
						<>
							<section>
								<h3>
									{ __( 'Greens', 'fastnutrition-mealprep' ) }{ ' ' }
									<span className="fn-hint">
										{ cfg.allow_double_greens
											? __(
													'(1, or 2 instead of a carb)',
													'fastnutrition-mealprep'
											  )
											: __(
													'(pick 1)',
													'fastnutrition-mealprep'
											  ) }
									</span>
								</h3>
								<PillRow>
									{ allow.greens.map( ( i ) => (
										<Pill
											key={ i.id }
											active={ b.greens_ids.includes(
												i.id
											) }
											onClick={ () =>
												toggleGreen( i.id )
											}
										>
											{ i.name }
										</Pill>
									) ) }
								</PillRow>
							</section>
							{ b.greens_ids.length < 2 && (
								<section>
									<h3>
										{ __(
											'Carb',
											'fastnutrition-mealprep'
										) }
									</h3>
									<PillRow>
										{ allow.carb.map( ( i ) => (
											<Pill
												key={ i.id }
												active={ b.carb_id === i.id }
												onClick={ () =>
													chooseCarb( i.id )
												}
												sub={
													i.price_delta
														? `+${ money(
																i.price_delta
														  ) }`
														: null
												}
											>
												{ i.name }
											</Pill>
										) ) }
									</PillRow>
								</section>
							) }
						</>
					) }
				</>
			) }

			{ addons.length > 0 &&
			( b.protein_id || b.set_meal_id || b.sweet_id ) ? (
				<section>
					<h3>
						{ __( 'Add-on', 'fastnutrition-mealprep' ) }{ ' ' }
						<span className="fn-hint">
							{ __( '(optional)', 'fastnutrition-mealprep' ) }
						</span>
					</h3>
					<PillRow>
						{ addons.map( ( a ) => (
							<Pill
								key={ a.id }
								active={
									!! b.addons.find( ( x ) => x.id === a.id )
								}
								onClick={ () => toggleAddon( a ) }
								sub={
									a.price ? `+${ money( a.price ) }` : null
								}
							>
								{ a.label }
							</Pill>
						) ) }
					</PillRow>
				</section>
			) : null }

			<div className="fn-build__foot">
				<Stepper
					value={ b.qty }
					onChange={ ( q ) => setB( { ...b, qty: q } ) }
				/>
				<button
					type="button"
					className="fn-primary"
					disabled={ ! valid }
					onClick={ add }
				>
					{ valid
						? `${ __(
								'Add',
								'fastnutrition-mealprep'
						  ) } · ${ money( estimate ) }`
						: __( 'Add to order', 'fastnutrition-mealprep' ) }
				</button>
			</div>
		</div>
	);
}

/* ---- Step 2: details --------------------------------------------------- */
function Details( { details, setDetails, payments } ) {
	const d = details;
	const set = ( patch ) => setDetails( { ...d, ...patch } );
	const [ slots, setSlots ] = useState( [] );
	const [ loadingSlots, setLoadingSlots ] = useState( false );

	const loadSlots = async () => {
		setLoadingSlots( true );
		try {
			const url = `${ boot.slotsUrl }?method=${
				d.fulfilmentType
			}&postcode=${ encodeURIComponent( d.postcode || '' ) }`;
			const res = await apiFetch( { url } );
			setSlots( res.options || [] );
		} catch ( e ) {
			setSlots( [] );
		}
		setLoadingSlots( false );
	};

	useEffect( () => {
		setSlots( [] );
		set( { profile_id: 0, date: '', slot: null } );
		if (
			d.fulfilmentType === 'collection' ||
			( d.fulfilmentType === 'delivery' &&
				( d.postcode || '' ).length >= 3 )
		) {
			loadSlots();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ d.fulfilmentType ] );

	return (
		<div className="fn-details">
			<section>
				<h3>{ __( 'Contact', 'fastnutrition-mealprep' ) }</h3>
				<div className="fn-row2">
					<label>
						{ __(
							'First name (required)',
							'fastnutrition-mealprep'
						) }
						<input
							value={ d.first_name }
							onChange={ ( e ) =>
								set( { first_name: e.target.value } )
							}
						/>
					</label>
					<label>
						{ __(
							'Last name (required)',
							'fastnutrition-mealprep'
						) }
						<input
							value={ d.last_name }
							onChange={ ( e ) =>
								set( { last_name: e.target.value } )
							}
						/>
					</label>
				</div>
				<label>
					{ __( 'Phone (optional)', 'fastnutrition-mealprep' ) }
					<input
						type="tel"
						inputMode="numeric"
						value={ d.phone }
						onChange={ ( e ) => set( { phone: e.target.value } ) }
					/>
				</label>
				{ ! IS_LABEL && (
					<label>
						{ __( 'Email (optional)', 'fastnutrition-mealprep' ) }
						<input
							type="email"
							value={ d.email }
							onChange={ ( e ) =>
								set( { email: e.target.value } )
							}
						/>
					</label>
				) }
			</section>

			<section>
				<h3>{ __( 'Fulfilment', 'fastnutrition-mealprep' ) }</h3>
				<PillRow>
					<Pill
						active={ d.fulfilmentType === 'collection' }
						onClick={ () =>
							set( { fulfilmentType: 'collection' } )
						}
					>
						{ __( 'Collection', 'fastnutrition-mealprep' ) }
					</Pill>
					<Pill
						active={ d.fulfilmentType === 'delivery' }
						onClick={ () => set( { fulfilmentType: 'delivery' } ) }
					>
						{ __( 'Delivery', 'fastnutrition-mealprep' ) }
					</Pill>
				</PillRow>
			</section>

			{ d.fulfilmentType === 'delivery' && (
				<section>
					<h3>
						{ __( 'Delivery address', 'fastnutrition-mealprep' ) }
					</h3>
					<label>
						{ __( 'Address line 1', 'fastnutrition-mealprep' ) }
						<input
							value={ d.address_1 }
							onChange={ ( e ) =>
								set( { address_1: e.target.value } )
							}
						/>
					</label>
					<label>
						{ __( 'Address line 2', 'fastnutrition-mealprep' ) }
						<input
							value={ d.address_2 }
							onChange={ ( e ) =>
								set( { address_2: e.target.value } )
							}
						/>
					</label>
					<div className="fn-row2">
						<label>
							{ __( 'Town/City', 'fastnutrition-mealprep' ) }
							<input
								value={ d.city }
								onChange={ ( e ) =>
									set( { city: e.target.value } )
								}
							/>
						</label>
						<label>
							{ __( 'Postcode', 'fastnutrition-mealprep' ) }
							<input
								value={ d.postcode }
								onChange={ ( e ) =>
									set( { postcode: e.target.value } )
								}
								onBlur={ loadSlots }
							/>
						</label>
					</div>
				</section>
			) }

			<section>
				<h3>
					{ d.fulfilmentType === 'delivery'
						? __( 'Delivery slot', 'fastnutrition-mealprep' )
						: __( 'Collection slot', 'fastnutrition-mealprep' ) }
				</h3>
				{ loadingSlots ? (
					<p>{ __( 'Loading slots…', 'fastnutrition-mealprep' ) }</p>
				) : null }
				{ ! loadingSlots && slots.length === 0 ? (
					<p className="fn-hint">
						{ d.fulfilmentType === 'delivery'
							? __(
									'No delivery slots for this postcode — check it, or that a delivery profile covers it.',
									'fastnutrition-mealprep'
							  )
							: __(
									'No collection slots available. Add a Collection profile under Meal Prep → Collection Profiles.',
									'fastnutrition-mealprep'
							  ) }
					</p>
				) : null }
				{ slots.map( ( opt ) => (
					<div
						key={ `${ opt.profile_id }-${ opt.date }` }
						className="fn-slotgroup"
					>
						<div className="fn-slotgroup__day">
							{ opt.day_label }{ ' ' }
							<span className="fn-hint">
								{ opt.profile_name }
							</span>
						</div>
						<PillRow>
							{ opt.slots.map( ( s ) => {
								const active =
									d.profile_id === opt.profile_id &&
									d.date === opt.date &&
									d.slot &&
									d.slot.start === s.start &&
									d.slot.end === s.end;
								return (
									<Pill
										key={ `${ s.start }-${ s.end }` }
										active={ active }
										onClick={ () =>
											set( {
												profile_id: opt.profile_id,
												date: opt.date,
												slot: {
													start: s.start,
													end: s.end,
												},
											} )
										}
									>
										{ s.start }
										{ s.end ? `–${ s.end }` : '' }
									</Pill>
								);
							} ) }
						</PillRow>
					</div>
				) ) }
			</section>

			<section>
				<h3>{ __( 'Payment', 'fastnutrition-mealprep' ) }</h3>
				<PillRow>
					<Pill
						active={ d.paid === true }
						onClick={ () => set( { paid: true } ) }
					>
						{ __( 'Paid', 'fastnutrition-mealprep' ) }
					</Pill>
					<Pill
						active={ d.paid === false }
						onClick={ () => set( { paid: false, payment: '' } ) }
					>
						{ __( 'Not paid', 'fastnutrition-mealprep' ) }
					</Pill>
				</PillRow>

				{ d.paid === true && (
					<>
						<h3>
							{ __( 'Payment method', 'fastnutrition-mealprep' ) }
						</h3>
						<PillRow>
							{ payments.map( ( p ) => (
								<Pill
									key={ p.slug }
									active={ d.payment === p.slug }
									onClick={ () => set( { payment: p.slug } ) }
								>
									{ p.label }
								</Pill>
							) ) }
						</PillRow>
					</>
				) }

				{ d.paid === false && (
					<p className="fn-note">
						{ d.fulfilmentType === 'delivery'
							? __(
									'Not paid — cash on delivery.',
									'fastnutrition-mealprep'
							  )
							: __(
									'Not paid — cash on collection.',
									'fastnutrition-mealprep'
							  ) }
					</p>
				) }
			</section>
		</div>
	);
}

/* ---- Step 3: review + submit ------------------------------------------ */
function Review( {
	basket,
	details,
	payments,
	totals,
	sendEmail,
	setSendEmail,
	onSubmit,
	busy,
	err,
} ) {
	let paymentText;
	if ( details.paid ) {
		const label =
			( payments.find( ( p ) => p.slug === details.payment ) || {} )
				.label || details.payment;
		paymentText = `${ label } · ${ __(
			'Paid',
			'fastnutrition-mealprep'
		) }`;
	} else if ( details.fulfilmentType === 'delivery' ) {
		paymentText = __(
			'Not paid — cash on delivery',
			'fastnutrition-mealprep'
		);
	} else {
		paymentText = __(
			'Not paid — cash on collection',
			'fastnutrition-mealprep'
		);
	}
	return (
		<div className="fn-review">
			<section>
				<h3>{ __( 'Order summary', 'fastnutrition-mealprep' ) }</h3>
				{ basket.map( ( l ) => (
					<div key={ l.id } className="fn-revline">
						<span>
							{ l.qty } × { l.label }
							{ l.addonLabel ? ` (+${ l.addonLabel })` : '' }
						</span>
						<span>{ money( l.estimate ) }</span>
					</div>
				) ) }
				{ totals.saving > 0 ? (
					<div className="fn-revline">
						<span>
							{ __( 'Bundle saving', 'fastnutrition-mealprep' ) }
						</span>
						<span>−{ money( totals.saving ) }</span>
					</div>
				) : null }
				<div className="fn-revline fn-revtotal">
					<span>{ __( 'Total', 'fastnutrition-mealprep' ) }</span>
					<span>{ money( totals.total ) }</span>
				</div>
			</section>
			<section>
				<div className="fn-revline">
					<span>
						{ __( 'Fulfilment', 'fastnutrition-mealprep' ) }
					</span>
					<span>
						{ details.fulfilmentType === 'delivery'
							? __( 'Delivery', 'fastnutrition-mealprep' )
							: __(
									'Collection',
									'fastnutrition-mealprep'
							  ) }{ ' ' }
						· { details.date }
					</span>
				</div>
				<div className="fn-revline">
					<span>{ __( 'Payment', 'fastnutrition-mealprep' ) }</span>
					<span>{ paymentText }</span>
				</div>
			</section>
			{ ! IS_LABEL && (
				<section>
					<label className="fn-check">
						<input
							type="checkbox"
							checked={ !! sendEmail }
							onChange={ ( e ) =>
								setSendEmail( e.target.checked )
							}
							disabled={ ! details.email }
						/>
						{ __(
							'Email confirmation to customer',
							'fastnutrition-mealprep'
						) }
						{ ! details.email ? (
							<span className="fn-hint">
								{ ' ' }
								{ __(
									'(needs an email)',
									'fastnutrition-mealprep'
								) }
							</span>
						) : null }
					</label>
				</section>
			) }
			{ err ? <div className="fn-error">{ err }</div> : null }
			<button
				type="button"
				className="fn-primary fn-submit"
				disabled={ busy }
				onClick={ onSubmit }
			>
				{ ( () => {
					if ( busy ) {
						return __( 'Working…', 'fastnutrition-mealprep' );
					}
					return IS_LABEL
						? __( 'Generate labels', 'fastnutrition-mealprep' )
						: __( 'Submit order', 'fastnutrition-mealprep' );
				} )() }
			</button>
		</div>
	);
}

/* ---- Sticky basket bar ------------------------------------------------- */
function BasketBar( {
	basket,
	expanded,
	onToggle,
	onRemove,
	onQty,
	total,
	primaryLabel,
	onPrimary,
	primaryDisabled,
} ) {
	const count = basket.reduce( ( n, l ) => n + l.qty, 0 );
	return (
		<div className="fn-bar">
			{ expanded && (
				<div className="fn-bar__list">
					{ basket.length === 0 ? (
						<div className="fn-empty">
							{ __( 'No items yet.', 'fastnutrition-mealprep' ) }
						</div>
					) : null }
					{ basket.map( ( l ) => (
						<div key={ l.id } className="fn-bar__line">
							<div className="fn-bar__lineinfo">
								<strong>{ l.label }</strong>
								{ l.addonLabel ? (
									<span className="fn-hint">
										{ ' ' }
										+{ l.addonLabel }
									</span>
								) : null }
							</div>
							<Stepper
								value={ l.qty }
								onChange={ ( q ) => onQty( l.id, q ) }
							/>
							<span className="fn-bar__lineprice">
								{ money( l.estimate ) }
							</span>
							<button
								type="button"
								className="fn-x"
								onClick={ () => onRemove( l.id ) }
								aria-label={ __(
									'Remove',
									'fastnutrition-mealprep'
								) }
							>
								×
							</button>
						</div>
					) ) }
				</div>
			) }
			<div className="fn-bar__main">
				<button
					type="button"
					className="fn-bar__count"
					onClick={ onToggle }
				>
					<span className="fn-bar__badge">{ count }</span>
					{ count === 1
						? __( 'item', 'fastnutrition-mealprep' )
						: __( 'items', 'fastnutrition-mealprep' ) }{ ' ' }
					· { money( total ) }
					<span className="fn-bar__chev">
						{ expanded ? '▾' : '▴' }
					</span>
				</button>
				<button
					type="button"
					className="fn-primary fn-bar__cta"
					disabled={ primaryDisabled }
					onClick={ onPrimary }
				>
					{ primaryLabel }
				</button>
			</div>
		</div>
	);
}

/* ---- App --------------------------------------------------------------- */
function App() {
	const [ phase, setPhase ] = useState( 'loading' ); // loading | ready | error
	const [ config, setConfig ] = useState( null );
	const [ ingredients, setIngredients ] = useState( {
		protein: [],
		carb: [],
		greens: [],
		set_meal: [],
		sweet: [],
	} );
	const [ step, setStep ] = useState( 'build' ); // build | details | review | done
	const [ setKey, setSetKey ] = useState( 'standard' );
	const [ basket, setBasket ] = useState( [] );
	const [ expanded, setExpanded ] = useState( false );
	const [ details, setDetails ] = useState( {
		phone: '',
		email: '',
		fulfilmentType: 'collection',
		first_name: '',
		last_name: '',
		address_1: '',
		address_2: '',
		city: '',
		postcode: '',
		profile_id: 0,
		date: '',
		slot: null,
		payment: '',
		paid: null,
	} );
	const [ sendEmail, setSendEmail ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState( '' );
	const [ done, setDone ] = useState( null );

	const loadConfig = async () => {
		const cfg = await api( 'config' );
		setConfig( cfg );
		setSendEmail( !! cfg.send_email );
		const [ p, c, g, s, sw ] = await Promise.all( [
			apiV1( 'ingredients?type=protein' ),
			apiV1( 'ingredients?type=carb' ),
			apiV1( 'ingredients?type=greens' ),
			apiV1( 'ingredients?type=set_meal' ),
			apiV1( 'ingredients?type=sweet' ),
		] );
		setIngredients( {
			protein: p,
			carb: c,
			greens: g,
			set_meal: s,
			sweet: sw,
		} );
		setPhase( 'ready' );
	};

	useEffect( () => {
		loadConfig().catch( ( e ) => {
			setErr(
				e.message ||
					__(
						'Could not load the Quick Order screen.',
						'fastnutrition-mealprep'
					)
			);
			setPhase( 'error' );
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const totals = useMemo(
		() => computeTotals( basket, config ),
		[ basket, config ]
	);

	const addLine = ( line ) => {
		setBasket( ( b ) => [ ...b, line ] );
	};
	const removeLine = ( id ) =>
		setBasket( ( b ) => b.filter( ( l ) => l.id !== id ) );
	const setQty = ( id, qty ) =>
		setBasket( ( b ) =>
			b.map( ( l ) =>
				l.id === id
					? { ...l, qty, estimate: ( l.estimate / l.qty ) * qty }
					: l
			)
		);

	const detailsValid = ( () => {
		if (
			! details.first_name.trim() ||
			! details.last_name.trim() ||
			! details.phone.trim() ||
			! details.slot
		) {
			return false;
		}
		// Paid / Not paid must be chosen; a method is required only when paid.
		if ( details.paid !== true && details.paid !== false ) {
			return false;
		}
		if ( details.paid === true && ! details.payment ) {
			return false;
		}
		if (
			details.fulfilmentType === 'delivery' &&
			! details.address_1.trim()
		) {
			return false;
		}
		return true;
	} )();

	const orderData = () => ( {
		lines: basket.map( ( l ) => ( {
			product_id: l.product_id,
			quantity: l.qty,
			selection: l.selection,
		} ) ),
		customer: {
			phone: details.phone,
			email: details.email,
			first_name: details.first_name,
			last_name: details.last_name,
			address_1: details.address_1,
			address_2: details.address_2,
			city: details.city,
			postcode: details.postcode,
		},
		fulfilment: {
			type: details.fulfilmentType,
			profile_id: details.profile_id,
			date: details.date,
			slot: details.slot,
		},
		payment: details.payment,
		paid: details.paid,
		send_email: sendEmail && !! details.email,
	} );

	const openPdf = ( blob ) => {
		const url = URL.createObjectURL( blob );
		const a = document.createElement( 'a' );
		a.href = url;
		a.target = '_blank';
		a.rel = 'noopener';
		document.body.appendChild( a );
		a.click();
		a.remove();
		setTimeout( () => URL.revokeObjectURL( url ), 60000 );
	};

	const submit = async () => {
		setBusy( true );
		setErr( '' );
		try {
			if ( IS_LABEL ) {
				const res = await apiFetch( {
					url: boot.restUrl + 'labels',
					method: 'POST',
					data: orderData(),
					parse: false,
				} );
				if ( ! res.ok ) {
					let msg = __(
						'Could not generate labels.',
						'fastnutrition-mealprep'
					);
					try {
						const j = await res.json();
						msg = j.message || msg;
					} catch ( e ) {
						// non-JSON error body; keep the default message.
					}
					throw new Error( msg );
				}
				openPdf( await res.blob() );
				setDone( { labels: true } );
				setStep( 'done' );
			} else {
				const res = await api( 'order', {
					method: 'POST',
					data: orderData(),
				} );
				setDone( res );
				setStep( 'done' );
			}
		} catch ( e ) {
			setErr(
				e.message ||
					__( 'Something went wrong.', 'fastnutrition-mealprep' )
			);
		}
		setBusy( false );
	};

	const reset = () => {
		setBasket( [] );
		setDetails( {
			phone: '',
			email: '',
			fulfilmentType: 'collection',
			first_name: '',
			last_name: '',
			address_1: '',
			address_2: '',
			city: '',
			postcode: '',
			profile_id: 0,
			date: '',
			slot: null,
			payment: '',
			paid: null,
		} );
		setDone( null );
		setErr( '' );
		setStep( 'build' );
		setSetKey( 'standard' );
		setSendEmail( config && config.send_email );
	};

	if ( phase === 'loading' ) {
		return (
			<div className="fn-loading">
				{ __( 'Loading…', 'fastnutrition-mealprep' ) }
			</div>
		);
	}
	if ( phase === 'error' ) {
		return (
			<div className="fn-loading">
				<div className="fn-error">{ err }</div>
			</div>
		);
	}

	const sets = config.sets || {};
	const tabs = [
		[ 'standard', __( 'Standard', 'fastnutrition-mealprep' ) ],
		[ 'bulk', __( 'Bulk', 'fastnutrition-mealprep' ) ],
		[ 'sweets', __( 'Sweets', 'fastnutrition-mealprep' ) ],
	];

	if ( step === 'done' ) {
		return (
			<div className="fn-done">
				<div className="fn-done__card">
					<div className="fn-done__tick">✓</div>
					{ done && done.labels ? (
						<>
							<h1>
								{ __(
									'Labels generated',
									'fastnutrition-mealprep'
								) }
							</h1>
							<p className="fn-done__num">
								{ __(
									'The labels PDF opened in a new tab — print it from there.',
									'fastnutrition-mealprep'
								) }
							</p>
						</>
					) : (
						<>
							<h1>
								{ __(
									'Order placed',
									'fastnutrition-mealprep'
								) }
							</h1>
							<p className="fn-done__num">
								{ __( 'Order', 'fastnutrition-mealprep' ) } #
								{ done.order_number }
							</p>
							<p className="fn-done__total">{ done.total }</p>
						</>
					) }
					<button
						type="button"
						className="fn-primary"
						onClick={ reset }
					>
						{ IS_LABEL
							? __( 'Start next batch', 'fastnutrition-mealprep' )
							: __(
									'Start next order',
									'fastnutrition-mealprep'
							  ) }
					</button>
				</div>
			</div>
		);
	}

	const submitLabel = IS_LABEL
		? __( 'Generate labels', 'fastnutrition-mealprep' )
		: __( 'Submit', 'fastnutrition-mealprep' );

	let primaryLabel = __( 'Next', 'fastnutrition-mealprep' );
	let primaryDisabled = basket.length === 0;
	let onPrimary = () => setStep( 'details' );
	if ( step === 'details' ) {
		primaryLabel = __( 'Review', 'fastnutrition-mealprep' );
		primaryDisabled = ! detailsValid;
		onPrimary = () => setStep( 'review' );
	} else if ( step === 'review' ) {
		primaryLabel = submitLabel;
		primaryDisabled = busy;
		onPrimary = submit;
	}

	return (
		<div className="fn-app">
			<header className="fn-head">
				<button
					type="button"
					className={ `fn-tab${
						step === 'build' ? ' is-active' : ''
					}` }
					onClick={ () => setStep( 'build' ) }
				>
					{ __( '1 · Build', 'fastnutrition-mealprep' ) }
				</button>
				<button
					type="button"
					className={ `fn-tab${
						step === 'details' ? ' is-active' : ''
					}` }
					disabled={ basket.length === 0 }
					onClick={ () => setStep( 'details' ) }
				>
					{ __( '2 · Details', 'fastnutrition-mealprep' ) }
				</button>
				<button
					type="button"
					className={ `fn-tab${
						step === 'review' ? ' is-active' : ''
					}` }
					disabled={ ! detailsValid }
					onClick={ () => setStep( 'review' ) }
				>
					{ IS_LABEL
						? __( '3 · Labels', 'fastnutrition-mealprep' )
						: __( '3 · Submit', 'fastnutrition-mealprep' ) }
				</button>
			</header>

			<main className="fn-main">
				{ step === 'build' && (
					<>
						<div className="fn-settabs">
							{ tabs.map( ( [ key, label ] ) => (
								<button
									key={ key }
									type="button"
									className={ `fn-settab${
										setKey === key ? ' is-active' : ''
									}` }
									onClick={ () => setSetKey( key ) }
								>
									{ label }
								</button>
							) ) }
						</div>
						<BuildForm
							setKey={ setKey }
							set={ sets[ setKey ] }
							ingredients={ ingredients }
							onAdd={ addLine }
						/>
					</>
				) }
				{ step === 'details' && (
					<Details
						details={ details }
						setDetails={ setDetails }
						payments={ config.payments || [] }
					/>
				) }
				{ step === 'review' && (
					<Review
						basket={ basket }
						details={ details }
						payments={ config.payments || [] }
						totals={ totals }
						sendEmail={ sendEmail }
						setSendEmail={ setSendEmail }
						onSubmit={ submit }
						busy={ busy }
						err={ err }
					/>
				) }
			</main>

			<BasketBar
				basket={ basket }
				expanded={ expanded }
				onToggle={ () => setExpanded( ! expanded ) }
				onRemove={ removeLine }
				onQty={ setQty }
				total={ totals.total }
				primaryLabel={ primaryLabel }
				onPrimary={ onPrimary }
				primaryDisabled={ primaryDisabled }
			/>
		</div>
	);
}

const mount = document.getElementById( 'fn-quick-order-root' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
