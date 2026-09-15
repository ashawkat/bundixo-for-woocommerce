/**
 * REST client for the bundixo/v1 namespace.
 */

const config = window.bundixoAdmin || {};

async function request( method, path, body ) {
	const options = {
		method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce || '',
		},
	};

	if ( body !== undefined ) {
		options.body = JSON.stringify( body );
	}

	const response = await fetch( `${ config.restUrl }${ path }`, options );

	let data = null;

	try {
		data = await response.json();
	} catch {
		data = null;
	}

	if ( ! response.ok ) {
		const message =
			data && data.message
				? data.message
				: ( config.i18n && config.i18n.error ) || 'Request failed.';

		throw new Error( message );
	}

	// REST handlers respond with plain payloads via rest_ensure_response().
	return data;
}

export function apiGet( path ) {
	return request( 'GET', path );
}

export function apiPost( path, body ) {
	return request( 'POST', path, body );
}

export function apiDelete( path ) {
	return request( 'DELETE', path );
}
