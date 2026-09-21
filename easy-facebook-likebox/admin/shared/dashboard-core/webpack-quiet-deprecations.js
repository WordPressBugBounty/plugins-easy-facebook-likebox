/**
 * Shared webpack tweaks for module dashboards and admin React apps.
 *
 * sass-loader 12 (from @wordpress/scripts 27) still calls Dart Sass's legacy
 * render API. The shared dashboard shell also still uses @import for
 * connect-hero (it needs $primary-color from the parent file). Silence those
 * compiler deprecations instead of rewriting shared SCSS.
 *
 * Admin bundles are larger than webpack's public-page size defaults.
 *
 * @package Easy_Social_Feed
 */

const SASS_SILENCE_DEPRECATIONS = [ 'import', 'legacy-js-api' ];

const DASHBOARD_PERFORMANCE = {
	hints: 'warning',
	maxAssetSize: 512 * 1024,
	maxEntrypointSize: 1024 * 1024,
};

/**
 * @param {import('webpack').Configuration} config Webpack config.
 * @return {import('webpack').Configuration} Config with Sass silence options.
 */
function withSassQuietDeprecations( config ) {
	return {
		...config,
		module: {
			...config.module,
			rules: ( config.module?.rules || [] ).map( ( rule ) => {
				if ( ! Array.isArray( rule.use ) ) {
					return rule;
				}

				return {
					...rule,
					use: rule.use.map( ( entry ) => {
						if (
							! entry ||
							'object' !== typeof entry ||
							! String( entry.loader || '' ).includes(
								'sass-loader'
							)
						) {
							return entry;
						}

						return {
							...entry,
							options: {
								...( entry.options || {} ),
								sassOptions: {
									...( entry.options?.sassOptions || {} ),
									silenceDeprecations:
										SASS_SILENCE_DEPRECATIONS,
								},
							},
						};
					} ),
				};
			} ),
		},
	};
}

/**
 * @param {import('webpack').Configuration} config Webpack config.
 * @return {import('webpack').Configuration} Config with Sass + size defaults.
 */
function withDashboardBuildDefaults( config ) {
	const next = withSassQuietDeprecations( config );
	return {
		...next,
		performance: {
			...DASHBOARD_PERFORMANCE,
			...( config.performance || {} ),
		},
	};
}

module.exports = {
	DASHBOARD_PERFORMANCE,
	SASS_SILENCE_DEPRECATIONS,
	withSassQuietDeprecations,
	withDashboardBuildDefaults,
};
