<script setup>
import { computed, reactive, ref, watch } from 'vue';
import config, { t } from './config';
import { formatPrice } from './format';

const props = defineProps( {
	payload: { type: Object, required: true },
} );

const bundle = props.payload.bundle;
const products = props.payload.products || [];
const contextProductId = props.payload.context_product_id || 0;
const isCompact = bundle.layout === 'compact';

const quote = ref( null );
const quoting = ref( false );
const adding = ref( false );
const toast = ref( null );
let quoteTimer = null;
let toastTimer = null;

// Per-product selection state: { variationId, qty }.
const selection = reactive( {} );

products.forEach( ( product ) => {
	const preselect = isCompact && ( product.is_context || product.id === contextProductId );

	selection[ product.id ] = {
		variationId: product.is_variable ? ( product.variations[ 0 ] ? product.variations[ 0 ].id : 0 ) : 0,
		qty: preselect ? 1 : 0,
		variationPrice: product.is_variable && product.variations[ 0 ] ? product.variations[ 0 ].price : 0,
	};
} );

function variationOf( product ) {
	const state = selection[ product.id ];

	if ( ! product.is_variable || ! state ) {
		return null;
	}

	return product.variations.find( ( variation ) => variation.id === state.variationId ) || null;
}

function onVariationChange( product ) {
	const variation = variationOf( product );
	selection[ product.id ].variationPrice = variation ? variation.price : 0;
}

function productPrice( product ) {
	if ( product.is_variable ) {
		const variation = variationOf( product );

		return variation ? variation.price : 0;
	}

	return product.price;
}

function isChecked( product ) {
	return bundle.use_quantity ? ( selection[ product.id ]?.qty || 0 ) > 0 : !! selection[ product.id ]?.qty;
}

function toggleProduct( product ) {
	const state = selection[ product.id ];
	state.qty = state.qty > 0 ? 0 : 1;
}

function changeQty( product, delta ) {
	const state = selection[ product.id ];
	const next = Math.min( bundle.max_quantity, Math.max( 0, state.qty + delta ) );

	if ( delta > 0 && next === state.qty ) {
		showToast( 'warn', t( 'maxReached', 'Maximum of %d items per bundle reached.' ).replace( '%d', bundle.max_quantity ) );
		return;
	}

	state.qty = next;
}

const items = computed( () => {
	const list = [];

	products.forEach( ( product ) => {
		const state = selection[ product.id ];

		if ( ! state || state.qty < 1 ) {
			return;
		}

		if ( product.is_variable && ! state.variationId ) {
			return;
		}

		list.push( {
			product_id: product.id,
			variation_id: state.variationId,
			quantity: state.qty,
			// The injected "This item" product is added at full price and
			// never counts toward the discount tiers (server-enforced).
			no_discount: !! product.is_context,
		} );
	} );

	return list;
} );

const allVariationsChosen = computed( () =>
	items.value.every( ( item ) => {
		if ( ! item.variation_id ) {
			const product = products.find( ( candidate ) => candidate.id === item.product_id );
			return product ? ! product.is_variable : true;
		}

		return true;
	} )
);

const canAdd = computed( () => items.value.length > 0 && allVariationsChosen.value && ! adding.value );

const currentTier = computed( () => quote.value ? quote.value.tier : null );

const nextTier = computed( () => {
	const count = quote.value ? quote.value.item_count : 0;

	return ( bundle.discount_tiers || [] ).find( ( tier ) => tier.quantity > count ) || null;
} );

const progressPercent = computed( () => {
	if ( ! nextTier.value || nextTier.value.quantity < 1 ) {
		return 100;
	}

	return Math.min( 100, Math.round( ( ( quote.value ? quote.value.item_count : 0 ) / nextTier.value.quantity ) * 100 ) );
} );

// Optimistic totals for the compact footer while a quote is in flight;
// the server quote remains authoritative for pricing and cart adds.
const localTotal = computed( () =>
	products.reduce( ( sum, product ) => {
		const state = selection[ product.id ];
		return sum + ( state && state.qty > 0 ? productPrice( product ) * state.qty : 0 );
	}, 0 )
);

const compactTotal = computed( () => ( quote.value ? quote.value.total : localTotal.value ) );

const compactSavings = computed( () => ( quote.value && quote.value.discount_amount > 0 ? quote.value.discount_amount : 0 ) );

const compactButtonLabel = computed( () =>
	isCompact ? ( bundle.button_text || t( 'addSelected', 'Add Selected to Cart' ) ) : ( bundle.button_text || t( 'addToCart', 'Add to Cart' ) )
);

watch(
	items,
	() => {
		clearTimeout( quoteTimer );
		quoteTimer = setTimeout( refreshQuote, 250 );
	},
	{ immediate: true, deep: true }
);

async function refreshQuote() {
	if ( ! items.value.length ) {
		quote.value = null;
		return;
	}

	quoting.value = true;

	try {
		const response = await fetch( `${ config.restUrl }/quote`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { bundle_id: bundle.id, items: items.value } ),
		} );

		if ( response.ok ) {
			quote.value = await response.json();
		}
	} catch {
		// Keep the last good quote; the add-to-cart call re-validates anyway.
	} finally {
		quoting.value = false;
	}
}

function showToast( type, message ) {
	toast.value = { type, message };
	clearTimeout( toastTimer );
	toastTimer = setTimeout( () => {
		toast.value = null;
	}, 3500 );
}

async function addToCart() {
	if ( ! canAdd.value ) {
		return;
	}

	adding.value = true;

	try {
		const response = await fetch( `${ config.restUrl }/add-to-cart`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || '',
			},
			body: JSON.stringify( { bundle_id: bundle.id, items: items.value } ),
		} );

		const result = await response.json();

		if ( ! response.ok ) {
			showToast( 'error', ( result && result.message ) || t( 'addError', 'Could not add the bundle to your cart.' ) );
			return;
		}

		if ( bundle.cart_behavior === 'redirect' && result.cart_url ) {
			window.location.href = result.cart_url;
			return;
		}

		refreshClassicCart( result );
		pokeBlocksCart();
		tryOpenSidecart();

		if ( result.failed && result.failed.length ) {
			showToast( 'warn', t( 'someNotAdded', 'Some items could not be added to the cart.' ) );
			return;
		}

		showToast( 'success', t( 'added', 'Added to cart!' ) );
	} catch {
		showToast( 'error', t( 'addError', 'Could not add the bundle to your cart.' ) );
	} finally {
		adding.value = false;
	}
}

/**
 * Notifies classic WooCommerce themes so mini-carts refresh.
 */
function refreshClassicCart( result ) {
	if ( ! window.jQuery ) {
		return;
	}

	const $ = window.jQuery;

	if ( result.fragments ) {
		$.each( result.fragments, ( key, value ) => {
			$( key ).replaceWith( value );
		} );
	}

	$( document.body ).trigger( 'wc_fragments_refreshed' );
	$( document.body ).trigger( 'added_to_cart', [ result.fragments || {}, result.cart_hash || '', null ] );
}

/**
 * Asks the WooCommerce Blocks store to refresh its cart data.
 */
function pokeBlocksCart() {
	try {
		if ( window.wp && window.wp.data && window.wp.data.dispatch ) {
			const store = window.wp.data.dispatch( 'wc/store/cart' );

			if ( store && store.invalidateResolutionForStore ) {
				store.invalidateResolutionForStore();
			}
		}
	} catch {
		// Blocks cart not present; nothing to do.
	}
}

/**
 * Best-effort: open a theme or plugin sidecart after adding.
 */
function tryOpenSidecart() {
	const selectors = [
		'.xoo-wsc-cart-trigger',
		'.fkcart-cart-toggler',
		'.wc-block-mini-cart__button',
		'.elementor-menu-cart__toggle .elementor-button',
	];

	for ( const selector of selectors ) {
		const trigger = document.querySelector( selector );

		if ( trigger ) {
			trigger.click();
			return;
		}
	}
}

function summaryLine( product ) {
	const state = selection[ product.id ];
	const variation = variationOf( product );
	const name = variation
		? `${ product.name } — ${ variationLabel( variation ) }`
		: product.name;

	return { name, qty: state ? state.qty : 0 };
}

/**
 * Human-friendly variation label: capitalized attribute values, skipping
 * empty or meaningless flags.
 */
function variationLabel( variation ) {
	return ( variation.attributes ? Object.values( variation.attributes ) : [] )
		.filter( ( value ) => value !== '' && value !== '0' )
		.map( ( value ) => String( value ).charAt( 0 ).toUpperCase() + String( value ).slice( 1 ) )
		.join( ' · ' );
}
</script>

<template>
	<div
		class="bcw"
		:style="{
			'--bcw-primary': bundle.primary_color,
			'--bcw-accent': bundle.accent_color,
			'--bcw-hover-bg': bundle.hover_bg_color,
			'--bcw-hover-accent': bundle.hover_accent_color,
			'--bcw-button-text': bundle.button_text_color,
		}"
	>
		<header v-if="bundle.show_bundle_title || bundle.show_bundle_description" class="bcw-header">
			<h2 v-if="bundle.show_bundle_title" class="bcw-title">{{ bundle.name }}</h2>
			<p v-if="bundle.show_bundle_description && bundle.description" class="bcw-description">
				{{ bundle.description }}
			</p>
		</header>

		<!-- ===== Compact "frequently bought together" layout ===== -->
		<section v-if="isCompact" class="bcw-fbt">
			<div v-if="bundle.show_heading_text && bundle.heading_text" class="bcw-fbt__heading">
				{{ bundle.heading_text }}
			</div>
			<p v-if="bundle.show_hint_text && bundle.hint_text" class="bcw-fbt__hint">
				{{ bundle.hint_text }}
			</p>

			<ul class="bcw-fbt__list">
				<li
					v-for="product in products"
					:key="product.id"
					:class="['bcw-fbt__item', { 'bcw-fbt__item--checked': isChecked( product ) }]"
				>
					<label class="bcw-fbt__check">
						<input
							type="checkbox"
							:checked="!! selection[ product.id ].qty"
							:aria-label="product.name"
							@change="toggleProduct( product )"
						/>
					</label>

					<a v-if="product.permalink" :href="product.permalink" class="bcw-fbt__image">
						<img :src="product.image" :alt="product.name" loading="lazy" />
					</a>
					<div v-else class="bcw-fbt__image"><img :src="product.image" :alt="product.name" loading="lazy" /></div>

					<div class="bcw-fbt__body">
						<span v-if="product.is_context || product.id === contextProductId" class="bcw-fbt__badge">
							{{ t( 'thisItem', 'This item' ) }}
						</span>
						<a v-if="product.permalink" :href="product.permalink" class="bcw-fbt__name">{{ product.name }}</a>
						<span v-else class="bcw-fbt__name">{{ product.name }}</span>

						<select
							v-if="product.is_variable && product.variations.length"
							v-model="selection[ product.id ].variationId"
							class="bcw-variation"
							:aria-label="t( 'selectVariation', 'Select variation' )"
							@change="onVariationChange( product )"
						>
							<option v-for="variation in product.variations" :key="variation.id" :value="variation.id">
								{{ variationLabel( variation ) || ( '#' + variation.id ) }}
								{{ variation.in_stock ? '' : ` (${ t( 'outOfStock', 'Out of stock' ) })` }}
							</option>
						</select>
					</div>

					<div class="bcw-fbt__price">
						<span v-if="! product.is_variable" v-html="product.price_html" />
						<template v-else-if="selection[ product.id ] && selection[ product.id ].variationPrice">
							{{ formatPrice( selection[ product.id ].variationPrice ) }}
						</template>
						<template v-else-if="product.price_max && product.price_max !== product.price_min">
							{{ formatPrice( product.price_min ) }} – {{ formatPrice( product.price_max ) }}
						</template>
					</div>
				</li>
			</ul>

			<ul v-if="bundle.discount_tiers && bundle.discount_tiers.length" class="bcw-fbt__tiers">
				<li
					v-for="tier in bundle.discount_tiers"
					:key="tier.quantity"
					:class="[
						'bcw-fbt__tier',
						{
							'bcw-fbt__tier--active': currentTier && currentTier.quantity === tier.quantity,
							'bcw-fbt__tier--unlocked': quote && quote.item_count >= tier.quantity,
						},
					]"
				>
					{{ tier.quantity }}+ · {{ tier.discount }}% off
				</li>
			</ul>

			<div v-if="nextTier" class="bcw-fbt__progress">
				<div class="bcw-progress__bar">
					<div class="bcw-progress__fill" :style="{ width: progressPercent + '%' }" />
				</div>
				<p class="bcw-fbt__unlock">
					{{
						t( 'unlockMore', 'Add %1$s more item(s) to unlock %2$s off' )
							.replace( '%1$s', nextTier.quantity - ( quote ? quote.item_count : 0 ) )
							.replace( '%2$s', nextTier.discount + '%' )
					}}
				</p>
			</div>

			<div class="bcw-fbt__footer">
				<div class="bcw-fbt__totals">
					<span class="bcw-fbt__total-label">{{ t( 'bundlePrice', 'Bundle price' ) }}</span>
					<strong class="bcw-fbt__total">{{ formatPrice( compactTotal ) }}</strong>
					<span v-if="compactSavings > 0" class="bcw-fbt__savings">
						{{ t( 'youSave', 'You save' ) }} {{ formatPrice( compactSavings ) }}
					</span>
				</div>
				<button type="button" class="bcw-add" :disabled="! canAdd" @click="addToCart">
					{{ adding ? t( 'adding', 'Adding…' ) : compactButtonLabel }}
				</button>
			</div>

			<transition name="bcw-toast">
				<div v-if="toast" :class="['bcw-toast', `bcw-toast--${ toast.type }`]">
					<span>{{ toast.message }}</span>
					<a v-if="toast.type === 'success' && config.cartUrl" :href="config.cartUrl">
						{{ t( 'viewCart', 'View Cart' ) }}
					</a>
				</div>
			</transition>
		</section>

		<div v-else class="bcw-layout">
			<div class="bcw-products-area">
				<div v-if="bundle.show_heading_text && bundle.heading_text" class="bcw-heading">
					{{ bundle.heading_text }}
				</div>
				<p v-if="bundle.show_hint_text && bundle.hint_text" class="bcw-hint">
					{{ bundle.hint_text }}
				</p>

				<div class="bcw-grid">
					<article
						v-for="product in products"
						:key="product.id"
						:class="['bcw-card', { 'bcw-card--active': isChecked( product ) }]"
					>
						<span v-if="isChecked( product )" class="bcw-card__check" aria-hidden="true">✓</span>

						<a v-if="product.permalink" :href="product.permalink" class="bcw-card__image">
							<img :src="product.image" :alt="product.name" loading="lazy" />
						</a>
						<div v-else class="bcw-card__image"><img :src="product.image" :alt="product.name" loading="lazy" /></div>

						<div class="bcw-card__body">
							<h3 class="bcw-card__name">{{ product.name }}</h3>

							<div v-if="! product.is_variable" class="bcw-card__price" v-html="product.price_html" />
							<div v-else-if="selection[ product.id ] && selection[ product.id ].variationPrice" class="bcw-card__price">
								{{ formatPrice( selection[ product.id ].variationPrice ) }}
							</div>
							<div v-else-if="product.price_max && product.price_max !== product.price_min" class="bcw-card__price">
								{{ formatPrice( product.price_min ) }} – {{ formatPrice( product.price_max ) }}
							</div>

							<select
								v-if="product.is_variable && product.variations.length"
								v-model="selection[ product.id ].variationId"
								class="bcw-variation"
								:aria-label="t( 'selectVariation', 'Select variation' )"
								@change="onVariationChange( product )"
							>
								<option v-for="variation in product.variations" :key="variation.id" :value="variation.id">
									{{ variationLabel( variation ) || ( '#' + variation.id ) }}
									{{ variation.in_stock ? '' : ` (${ t( 'outOfStock', 'Out of stock' ) })` }}
								</option>
							</select>

							<div v-if="bundle.use_quantity" class="bcw-qty">
								<button type="button" class="bcw-qty__btn" :aria-label="'-'" @click="changeQty( product, -1 )">−</button>
								<input
									:value="selection[ product.id ].qty"
									type="number"
									min="0"
									:max="bundle.max_quantity"
									class="bcw-qty__input"
									@input="selection[ product.id ].qty = Math.min( bundle.max_quantity, Math.max( 0, parseInt( $event.target.value, 10 ) || 0 ) )"
								/>
								<button type="button" class="bcw-qty__btn" :aria-label="'+'" @click="changeQty( product, 1 )">+</button>
							</div>
							<label v-else class="bcw-select">
								<input
									type="checkbox"
									:checked="!! selection[ product.id ].qty"
									@change="toggleProduct( product )"
								/>
								{{ t( 'chooseProduct', 'Select this product' ) }}
							</label>
						</div>
					</article>
				</div>
			</div>

			<aside class="bcw-sidebar">
				<div v-if="bundle.show_progress_text && bundle.progress_text" class="bcw-progress-title">
					{{ bundle.progress_text }}
				</div>

				<ul class="bcw-tiers">
					<li
						v-for="tier in bundle.discount_tiers"
						:key="tier.quantity"
						:class="[
							'bcw-tier',
							{
								'bcw-tier--active': currentTier && currentTier.quantity === tier.quantity,
								'bcw-tier--unlocked': quote && quote.item_count >= tier.quantity,
							},
						]"
					>
						<span class="bcw-tier__qty">{{ tier.quantity }}+</span>
						<span class="bcw-tier__discount">{{ tier.discount }}% off</span>
					</li>
				</ul>

				<div v-if="nextTier" class="bcw-progress">
					<div class="bcw-progress__bar">
						<div class="bcw-progress__fill" :style="{ width: progressPercent + '%' }" />
					</div>
					<p class="bcw-progress__text">
						{{
							t( 'unlockMore', 'Add %1$s more item(s) to unlock %2$s off' )
								.replace( '%1$s', nextTier.quantity - ( quote ? quote.item_count : 0 ) )
								.replace( '%2$s', nextTier.discount + '%' )
						}}
					</p>
				</div>

				<div class="bcw-summary">
					<h3 class="bcw-summary__title">{{ t( 'summary', 'Summary' ) }}</h3>

					<ul v-if="quote && quote.products.length" class="bcw-summary__items">
						<li v-for="product in quote.products" :key="`${ product.product_id }-${ product.variation_id }`">
							<span>{{ product.name }} × {{ product.quantity }}</span>
							<span>{{ formatPrice( product.line_total ) }}</span>
						</li>
					</ul>
					<p v-else class="bcw-summary__empty">{{ t( 'emptySummary', 'Select products to see your bundle pricing.' ) }}</p>

					<div class="bcw-summary__rows">
						<div class="bcw-row">
							<span>{{ t( 'subtotal', 'Subtotal' ) }}</span>
							<strong>{{ formatPrice( quote ? quote.subtotal : 0 ) }}</strong>
						</div>
						<div v-if="quote && quote.discount_amount > 0" class="bcw-row bcw-row--discount">
							<span>{{ config.discountLabel || t( 'discount', 'Discount' ) }} ({{ quote.discount_percentage }}%)</span>
							<strong>−{{ formatPrice( quote.discount_amount ) }}</strong>
						</div>
						<div class="bcw-row bcw-row--total">
							<span>{{ t( 'total', 'Total' ) }}</span>
							<strong>{{ formatPrice( quote ? quote.total : 0 ) }}</strong>
						</div>
					</div>

					<button type="button" class="bcw-add" :disabled="! canAdd" @click="addToCart">
						{{ adding ? t( 'adding', 'Adding…' ) : ( bundle.button_text || t( 'addToCart', 'Add to Cart' ) ) }}
					</button>

					<transition name="bcw-toast">
						<div v-if="toast" :class="['bcw-toast', `bcw-toast--${ toast.type }`]">
							<span>{{ toast.message }}</span>
							<a v-if="toast.type === 'success' && config.cartUrl" :href="config.cartUrl">
								{{ t( 'viewCart', 'View Cart' ) }}
							</a>
						</div>
					</transition>
				</div>
			</aside>
		</div>

		<div v-if="! isCompact" class="bcw-sticky">
			<div class="bcw-sticky__info">
				<template v-if="nextTier">
					<span class="bcw-sticky__badge">{{ nextTier.discount }}% off</span>
					{{
						t( 'unlockMore', 'Add %1$s more item(s) to unlock %2$s off' )
							.replace( '%1$s', nextTier.quantity - ( quote ? quote.item_count : 0 ) )
							.replace( '%2$s', nextTier.discount + '%' )
					}}
				</template>
				<template v-else>
					{{ quote ? `${ quote.item_count } ${ quote.item_count === 1 ? t( 'item', 'item' ) : t( 'items', 'items' ) }` : '' }}
					· {{ formatPrice( quote ? quote.total : 0 ) }}
				</template>
			</div>
			<button type="button" class="bcw-add bcw-add--sticky" :disabled="! canAdd" @click="addToCart">
				{{ adding ? t( 'adding', 'Adding…' ) : ( bundle.button_text || t( 'addToCart', 'Add to Cart' ) ) }}
			</button>
		</div>
	</div>
</template>
