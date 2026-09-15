import { createApp } from 'vue';
import BundleWidget from './BundleWidget.vue';
// Styles are copied to assets/build/frontend.css by scripts/build.mjs and
// enqueued by WordPress.

document.querySelectorAll( '.bundixo-bundle-wrapper[data-bundixo-payload]' ).forEach( ( el ) => {
	let payload = null;

	try {
		payload = JSON.parse( el.dataset.bundixoPayload );
	} catch {
		payload = null;
	}

	if ( payload && payload.bundle ) {
		createApp( BundleWidget, { payload } ).mount( el );
	}
} );
