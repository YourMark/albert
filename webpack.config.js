/**
 * Webpack config for Albert admin React surfaces.
 *
 * Compiles ONLY JavaScript. CSS is deliberately kept out of this pipeline —
 * it is authored as plain .css in assets/css/ (the plugin convention) and never
 * imported from JS. So there are no style entries, no preprocessor, and no
 * extracted stylesheet here; webpack emits just the JS bundle + its asset
 * manifest (dependencies + version).
 *
 * @wordpress/* packages externalize to the `wp.*` globals via the bundled
 * dependency-extraction plugin, EXCEPT @wordpress/dataviews, which that plugin
 * treats as a bundled package — so the DataViews React code compiles into our
 * bundle. Core registers no style handle for DataViews, so its separate
 * stylesheet is file-copied (NOT imported through JS) into assets/build/css/ by
 * the CopyWebpackPlugin below — a plain asset copy, run as part of the build.
 *
 * Output goes to assets/build/js/ (NOT the default root build/, which the
 * release workflow uses as its zip-staging dir). assets/ is copied wholesale
 * into the shipped zip, so output under it is shipped.
 */
const path = require( 'path' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const dataviewsDir = path.dirname(
	require.resolve( '@wordpress/dataviews/package.json' )
);

module.exports = {
	...defaultConfig,
	entry: {
		abilities: path.resolve( __dirname, 'assets/src/abilities/index.js' ),
		context: path.resolve( __dirname, 'assets/src/context/index.js' ),
		skills: path.resolve( __dirname, 'assets/src/skills/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build/js' ),
	},
	plugins: [
		...defaultConfig.plugins,
		new CopyWebpackPlugin( {
			patterns: [
				{
					from: path.join( dataviewsDir, 'build-style', 'style.css' ),
					to: path.resolve( __dirname, 'assets/build/css/dataviews.css' ),
				},
			],
		} ),
	],
};
