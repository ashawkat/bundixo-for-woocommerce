<script setup>
import { computed, reactive, ref } from 'vue';
import { apiGet, apiPost } from '../api';
import { t } from '../config';
import Switch from '../components/Switch.vue';
import { notify, notifyError } from '../toast';

const settings = reactive( {
	enable_logging: false,
	default_cart_behavior: 'sidecart',
	coupon_lifetime_hours: 24,
} );

const loaded = ref( false );
const saving = ref( false );
const savedPulse = ref( false );
const dirty = ref( false );

let saveTimer = null;
let pulseTimer = null;

const lifetimeOptions = [
	{ value: 24, key: 'lifetime24', fallback: '24 hours' },
	{ value: 48, key: 'lifetime48', fallback: '48 hours' },
	{ value: 72, key: 'lifetime72', fallback: '3 days' },
	{ value: 168, key: 'lifetime168', fallback: '1 week' },
];

const stateLabel = computed( () => {
	if ( saving.value ) {
		return t( 'stateSaving', 'Saving…' );
	}
	if ( dirty.value ) {
		return t( 'stateUnsaved', 'Unsaved changes' );
	}
	return t( 'stateSaved', 'All changes saved' );
} );

async function load() {
	try {
		const stored = await apiGet( '/settings' );

		settings.enable_logging = !! stored.enable_logging;
		settings.default_cart_behavior = stored.default_cart_behavior === 'redirect' ? 'redirect' : 'sidecart';
		settings.coupon_lifetime_hours = [ 24, 48, 72, 168 ].includes( stored.coupon_lifetime_hours )
			? stored.coupon_lifetime_hours
			: 24;

		loaded.value = true;
	} catch ( error ) {
		notifyError( error.message || t( 'loadError', 'Could not load data.' ) );
	}
}

function scheduleSave() {
	dirty.value = true;

	clearTimeout( saveTimer );
	saveTimer = setTimeout( persist, 600 );
}

async function persist() {
	clearTimeout( pulseTimer );

	saving.value = true;

	try {
		await apiPost( '/settings', { ...settings } );

		dirty.value = false;
		savedPulse.value = true;

		pulseTimer = setTimeout( () => {
			savedPulse.value = false;
		}, 1800 );
	} catch ( error ) {
		notifyError( error.message || t( 'error', 'Something went wrong. Please try again.' ) );
	} finally {
		saving.value = false;
	}
}

load();
</script>

<template>
	<div class="bc-settings">
		<header class="bc-hero">
			<div>
				<h1>{{ t( 'settingsTitle', 'Settings' ) }}</h1>
				<p>{{ t( 'settingsHero', 'Tune how PickPack behaves on your store. Changes save instantly.' ) }}</p>
			</div>
			<transition name="bc-pop" mode="out-in">
				<span v-if="savedPulse" key="saved" class="bc-hero__state bc-hero__state--saved">✓ {{ t( 'stateSaved', 'All changes saved' ) }}</span>
				<span v-else-if="saving" key="saving" class="bc-hero__state bc-hero__state--saving"><span class="bc-spinner" /> {{ t( 'stateSaving', 'Saving…' ) }}</span>
				<span v-else-if="dirty" key="dirty" class="bc-hero__state">{{ t( 'stateUnsaved', 'Unsaved changes' ) }}</span>
				<span v-else key="idle" class="bc-hero__state bc-hero__state--idle">✓ {{ t( 'stateSaved', 'All changes saved' ) }}</span>
			</transition>
		</header>

		<section class="bc-card-group" :style="{ animationDelay: '60ms' }">
			<h2 class="bc-card-group__title">{{ t( 'cartGroup', 'Cart & discounts' ) }}</h2>

			<div class="bc-card">
				<div class="bc-card__text">
					<span class="bc-card__icon" aria-hidden="true">🛒</span>
					<div>
						<h3>{{ t( 'defaultBehavior', 'Default cart behavior' ) }}</h3>
						<p>{{ t( 'defaultBehaviorHint', 'Preselected for every new bundle you create. You can still change it per bundle in the editor.' ) }}</p>
					</div>
				</div>
				<div class="bc-segmented" role="radiogroup" :aria-label="t( 'defaultBehavior', 'Default cart behavior' )">
					<button
						type="button"
						:class="['bc-segmented__option', { 'bc-segmented__option--active': settings.default_cart_behavior === 'sidecart' }]"
						@click="settings.default_cart_behavior = 'sidecart'; scheduleSave()"
					>
						{{ t( 'openSidecart', 'Open cart / sidecart' ) }}
					</button>
					<button
						type="button"
						:class="['bc-segmented__option', { 'bc-segmented__option--active': settings.default_cart_behavior === 'redirect' }]"
						@click="settings.default_cart_behavior = 'redirect'; scheduleSave()"
					>
						{{ t( 'redirectToCart', 'Redirect to cart' ) }}
					</button>
					<span
						class="bc-segmented__thumb"
						:style="{ transform: settings.default_cart_behavior === 'redirect' ? 'translateX(100%)' : 'translateX(0)' }"
						aria-hidden="true"
					/>
				</div>
			</div>

			<div class="bc-card">
				<div class="bc-card__text">
					<span class="bc-card__icon" aria-hidden="true">🎟️</span>
					<div>
						<h3>{{ t( 'couponLifetime', 'Coupon lifetime' ) }}</h3>
						<p>{{ t( 'couponLifetimeHint', 'How long a bundle discount coupon stays valid before it is automatically cleaned up. Longer windows leave more unused coupons behind.' ) }}</p>
					</div>
				</div>
				<div class="bc-chips" role="radiogroup" :aria-label="t( 'couponLifetime', 'Coupon lifetime' )">
					<button
						v-for="option in lifetimeOptions"
						:key="option.value"
						type="button"
						:class="['bc-chip', { 'bc-chip--active': settings.coupon_lifetime_hours === option.value }]"
						@click="settings.coupon_lifetime_hours = option.value; scheduleSave()"
					>
						{{ t( option.key, option.fallback ) }}
					</button>
				</div>
			</div>
		</section>

		<section class="bc-card-group" :style="{ animationDelay: '140ms' }">
			<h2 class="bc-card-group__title">{{ t( 'generalGroup', 'General' ) }}</h2>

			<div class="bc-card">
				<div class="bc-card__text">
					<span class="bc-card__icon" aria-hidden="true">🐞</span>
					<div>
						<h3>{{ t( 'debugLogging', 'Debug logging' ) }}</h3>
						<p>{{ t( 'loggingHintShort', 'Write diagnostic messages to the WooCommerce log while troubleshooting.' ) }}</p>
					</div>
				</div>
				<Switch v-model="settings.enable_logging" :label="t( 'enableLogging', 'Enable debug logging' )" @update:model-value="scheduleSave" />
			</div>

			<p class="bc-settings__footnote">{{ t( 'loggingHint', 'When enabled, PickPack writes diagnostic messages to the WooCommerce log (WooCommerce → Status → Logs).' ) }}</p>
		</section>
	</div>
</template>
