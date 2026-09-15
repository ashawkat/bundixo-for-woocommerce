/**
 * Access to the localized admin configuration.
 */

const config = window.bundixoAdmin || {};

/**
 * Returns a localized string, falling back to the English default.
 *
 * @param {string} key      Localization key.
 * @param {string} fallback English default.
 * @return {string}
 */
export function t( key, fallback = '' ) {
	if ( config.i18n && config.i18n[ key ] ) {
		return config.i18n[ key ];
	}

	return fallback || key;
}

export default config;
