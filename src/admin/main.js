import { createApp } from 'vue';
import App from './App.vue';
// Styles are copied to assets/build/admin.css by scripts/build.mjs and
// enqueued by WordPress.

const mount = document.getElementById( 'bundixo-admin-app' );

if ( mount ) {
	const app = createApp( App, { page: mount.dataset.page || 'bundles' } );
	app.mount( mount );
}
