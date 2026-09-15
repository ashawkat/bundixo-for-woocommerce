/**
 * Price formatting for the storefront widget, mirroring store currency
 * settings provided through wp_localize_script.
 */

const config = window.bundixoFrontend || {};

export function formatPrice( value ) {
	const currency = config.currency || {};
	const decimals = Number.isFinite( currency.decimals ) ? currency.decimals : 2;
	const number = Number( value || 0 )
		.toFixed( decimals )
		.split( '.' );

	const sign = number[ 0 ].startsWith( '-' ) ? '-' : '';
	const digits = sign ? number[ 0 ].slice( 1 ) : number[ 0 ];
	const grouped = digits.replace( /\B(?=(\d{3})+(?!\d))/g, currency.thousand_sep || ',' );
	const amount = number[ 1 ]
		? `${ grouped }${ currency.decimal_sep || '.' }${ number[ 1 ] }`
		: grouped;

	const symbol = currency.symbol || '$';
	const position = currency.position || 'left';

	if ( position === 'left' ) {
		return `${ sign }${ symbol }${ amount }`;
	}
	if ( position === 'right' ) {
		return `${ sign }${ amount }${ symbol }`;
	}
	if ( position === 'left_space' ) {
		return `${ sign }${ symbol } ${ amount }`;
	}
	if ( position === 'right_space' ) {
		return `${ sign }${ amount } ${ symbol }`;
	}

	return `${ sign }${ symbol }${ amount }`;
}
