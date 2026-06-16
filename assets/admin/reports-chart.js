/**
 * Fulfilment Reports — dependency-free SVG line chart.
 *
 * Reads the JSON payload printed in #fn-reports-data and plots up to two metrics
 * along the report's timeline buckets. Two <select> controls (#fn-chart-m1 and
 * the optional #fn-chart-m2) choose which metrics to draw; a second metric gets
 * its own right-hand axis so different scales (e.g. revenue vs meals) coexist.
 */
( function () {
	'use strict';

	var SVG_NS = 'http://www.w3.org/2000/svg';
	var W = 960;
	var H = 380;
	var M = { top: 20, right: 64, bottom: 64, left: 64 };
	var COLORS = [ '#2271b1', '#d63638' ];

	function el( name, attrs, text ) {
		var node = document.createElementNS( SVG_NS, name );
		for ( var k in attrs ) {
			if ( Object.prototype.hasOwnProperty.call( attrs, k ) ) {
				node.setAttribute( k, attrs[ k ] );
			}
		}
		if ( text !== undefined && text !== null ) {
			node.appendChild( document.createTextNode( String( text ) ) );
		}
		return node;
	}

	function niceMax( max ) {
		if ( max <= 0 ) {
			return 1;
		}
		var pow = Math.pow( 10, Math.floor( Math.log( max ) / Math.LN10 ) );
		var n = max / pow;
		var step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
		return step * pow;
	}

	function init() {
		var dataEl = document.getElementById( 'fn-reports-data' );
		var host = document.getElementById( 'fn-chart' );
		if ( ! dataEl || ! host ) {
			return;
		}
		var data;
		try {
			data = JSON.parse( dataEl.textContent );
		} catch ( e ) {
			return;
		}
		var points = data.points || [];
		var metrics = data.metrics || {};
		var currency = data.currency || { symbol: '', position: 'left' };

		var m1sel = document.getElementById( 'fn-chart-m1' );
		var m2sel = document.getElementById( 'fn-chart-m2' );

		function money( v ) {
			var n = ( Math.round( v * 100 ) / 100 ).toLocaleString();
			return currency.position === 'right' || currency.position === 'right_space'
				? n + currency.symbol
				: currency.symbol + n;
		}

		function fmt( v, key ) {
			return metrics[ key ] && metrics[ key ].money ? money( v ) : v.toLocaleString();
		}

		function draw() {
			var k1 = m1sel ? m1sel.value : 'meals';
			var k2 = m2sel ? m2sel.value : '';
			host.innerHTML = '';

			var svg = el( 'svg', {
				viewBox: '0 0 ' + W + ' ' + H,
				width: '100%',
				role: 'img',
				'font-family': 'sans-serif',
				'font-size': '12',
			} );

			var plotW = W - M.left - M.right;
			var plotH = H - M.top - M.bottom;
			var n = points.length;
			var keys = [ k1 ];
			if ( k2 && k2 !== k1 ) {
				keys.push( k2 );
			}

			// Independent max per axis.
			var maxes = keys.map( function ( key ) {
				var mx = 0;
				points.forEach( function ( p ) {
					mx = Math.max( mx, Number( p[ key ] ) || 0 );
				} );
				return niceMax( mx );
			} );

			function xAt( i ) {
				return n <= 1 ? M.left + plotW / 2 : M.left + ( plotW * i ) / ( n - 1 );
			}
			function yAt( v, axis ) {
				return M.top + plotH - ( plotH * ( Number( v ) || 0 ) ) / maxes[ axis ];
			}

			// Horizontal gridlines + left axis ticks (axis 0).
			var ticks = 5;
			for ( var t = 0; t <= ticks; t++ ) {
				var gv = ( maxes[ 0 ] * t ) / ticks;
				var gy = yAt( gv, 0 );
				svg.appendChild( el( 'line', { x1: M.left, y1: gy, x2: M.left + plotW, y2: gy, stroke: '#e0e0e0' } ) );
				svg.appendChild( el( 'text', { x: M.left - 8, y: gy + 4, 'text-anchor': 'end', fill: COLORS[ 0 ] }, fmt( gv, k1 ) ) );
				if ( keys.length > 1 ) {
					svg.appendChild(
						el( 'text', { x: M.left + plotW + 8, y: gy + 4, 'text-anchor': 'start', fill: COLORS[ 1 ] }, fmt( ( maxes[ 1 ] * t ) / ticks, k2 ) )
					);
				}
			}

			// X labels (thinned so they never crowd).
			var every = Math.ceil( n / 12 );
			points.forEach( function ( p, i ) {
				if ( i % every !== 0 && i !== n - 1 ) {
					return;
				}
				svg.appendChild(
					el(
						'text',
						{ x: xAt( i ), y: M.top + plotH + 18, 'text-anchor': 'middle', fill: '#50575e' },
						p.bucket
					)
				);
			} );

			// Each metric: line + points.
			keys.forEach( function ( key, axis ) {
				var d = '';
				points.forEach( function ( p, i ) {
					d += ( i === 0 ? 'M' : 'L' ) + xAt( i ).toFixed( 1 ) + ' ' + yAt( p[ key ], axis ).toFixed( 1 ) + ' ';
				} );
				svg.appendChild( el( 'path', { d: d.trim(), fill: 'none', stroke: COLORS[ axis ], 'stroke-width': '2' } ) );
				points.forEach( function ( p, i ) {
					var c = el( 'circle', { cx: xAt( i ), cy: yAt( p[ key ], axis ), r: '3', fill: COLORS[ axis ] } );
					c.appendChild( el( 'title', {}, p.bucket + ' — ' + ( metrics[ key ] ? metrics[ key ].label : key ) + ': ' + fmt( Number( p[ key ] ) || 0, key ) ) );
					svg.appendChild( c );
				} );
			} );

			// Legend.
			var lx = M.left;
			keys.forEach( function ( key, axis ) {
				svg.appendChild( el( 'rect', { x: lx, y: H - 18, width: 12, height: 12, fill: COLORS[ axis ] } ) );
				var label = metrics[ key ] ? metrics[ key ].label : key;
				svg.appendChild( el( 'text', { x: lx + 18, y: H - 8, fill: '#1d2327' }, label ) );
				lx += 30 + label.length * 8;
			} );

			host.appendChild( svg );
		}

		if ( m1sel ) {
			m1sel.addEventListener( 'change', draw );
		}
		if ( m2sel ) {
			m2sel.addEventListener( 'change', draw );
		}
		draw();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
