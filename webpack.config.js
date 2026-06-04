/**
 * Extends the default @wordpress/scripts webpack config to add the standalone
 * In-Store Quick Order app as an extra entry point, while preserving the
 * automatic block.json entry detection used for the existing blocks.
 *
 * The Quick Order screen is not a block (it is a front-end kiosk app), so it
 * needs an explicit entry. Output lands at assets/build/quick-order/index.js
 * (+ index.asset.php + style-index.css), matching InStore\QuickOrderPage.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const baseEntry =
	typeof defaultConfig.entry === 'function'
		? defaultConfig.entry()
		: defaultConfig.entry;

module.exports = {
	...defaultConfig,
	entry: {
		...baseEntry,
		'quick-order/index': path.resolve(
			process.cwd(),
			'assets/src/quick-order/index.js'
		),
	},
};
