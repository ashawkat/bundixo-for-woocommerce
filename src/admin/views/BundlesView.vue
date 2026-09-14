<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { apiDelete, apiGet, apiPost } from '../api';
import config, { t } from '../config';
import Modal from '../components/Modal.vue';
import Switch from '../components/Switch.vue';
import { notify, notifyError } from '../toast';

const bundles = ref( [] );
const loading = ref( true );
const saving = ref( false );
const mode = ref( 'list' ); // 'list' | 'editor'
const activeTab = ref( 'products' );
const deleteTarget = ref( null );
const shortcodeCopiedFor = ref( 0 );
const thumbsMap = ref( {} );

const form = reactive( defaultForm() );
const selectedProducts = ref( [] );

const searchQuery = ref( '' );
const searchResults = ref( [] );
const searching = ref( false );
let searchTimer = null;

const dragIndex = ref( null );

const tabs = [
	{ id: 'products', key: 'tabProducts', fallback: 'Products' },
	{ id: 'discounts', key: 'tabDiscounts', fallback: 'Discounts' },
	{ id: 'design', key: 'tabDesign', fallback: 'Design' },
];

const placeholderImage =
	'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="%23f0f0f1"/></svg>';

const enabledCount = computed( () => bundles.value.filter( ( bundle ) => bundle.enabled ).length );
const hasProducts = computed( () => selectedProducts.value.length > 0 );
const hasTiers = computed( () => form.discount_tiers.length > 0 );
const canSave = computed( () => form.name.trim() !== '' && hasProducts.value && hasTiers.value && ! saving.value );
const shortcode = computed( () => `[pickpack_bundle id="${ form.bundle_id }"]` );

function defaultForm() {
	return {
		bundle_id: 0,
		name: '',
		description: '',
		enabled: true,
		use_quantity: false,
		max_quantity: 10,
		heading_text: 'Select Your Products Below',
		hint_text: 'Bundle 2, 3, 4 or 5 items and watch the savings grow.',
		button_text: 'Add Bundle to Cart',
		progress_text: 'Your Savings Progress',
		primary_color: '#6366f1',
		accent_color: '#4f46e5',
		hover_bg_color: '#eef2ff',
		hover_accent_color: '#4338ca',
		button_text_color: '#ffffff',
		cart_behavior: config.settings?.default_cart_behavior || 'sidecart',
		show_bundle_title: true,
		show_bundle_description: true,
		show_heading_text: true,
		show_hint_text: true,
		show_progress_text: true,
		discount_tiers: [ { quantity: 2, discount: 10 } ],
	};
}

async function loadBundles() {
	loading.value = true;

	try {
		bundles.value = await apiGet( '/bundles' );

		// One batched lookup so list cards can show real product thumbs.
		const ids = [ ...new Set( bundles.value.flatMap( ( bundle ) => bundle.product_ids || [] ) ) ];

		if ( ids.length ) {
			const products = await apiGet( `/products?ids=${ ids.slice( 0, 100 ).join( ',' ) }` );
			const map = {};
			products.forEach( ( product ) => {
				map[ product.id ] = product.image;
			} );
			thumbsMap.value = map;
		}
	} catch ( error ) {
		notifyError( error.message || t( 'loadError', 'Could not load data.' ) );
	} finally {
		loading.value = false;
	}
}

function resetForm() {
	Object.assign( form, defaultForm() );
	selectedProducts.value = [];
	searchQuery.value = '';
	searchResults.value = [];
}

function newBundle() {
	resetForm();
	activeTab.value = 'products';
	mode.value = 'editor';
}

function backToList() {
	mode.value = 'list';
}

async function editBundle( bundle ) {
	resetForm();

	Object.assign( form, {
		bundle_id: bundle.id,
		name: bundle.name,
		description: bundle.description,
		enabled: !! bundle.enabled,
		use_quantity: !! bundle.use_quantity,
		max_quantity: bundle.max_quantity,
		heading_text: bundle.heading_text,
		hint_text: bundle.hint_text,
		button_text: bundle.button_text,
		progress_text: bundle.progress_text,
		primary_color: bundle.primary_color,
		accent_color: bundle.accent_color,
		hover_bg_color: bundle.hover_bg_color,
		hover_accent_color: bundle.hover_accent_color,
		button_text_color: bundle.button_text_color,
		cart_behavior: bundle.cart_behavior,
		show_bundle_title: !! bundle.show_bundle_title,
		show_bundle_description: !! bundle.show_bundle_description,
		show_heading_text: !! bundle.show_heading_text,
		show_hint_text: !! bundle.show_hint_text,
		show_progress_text: !! bundle.show_progress_text,
		discount_tiers: ( bundle.discount_tiers || [] ).map( ( tier ) => ( {
			quantity: tier.quantity,
			discount: tier.discount,
		} ) ),
	} );

	const ids = bundle.product_ids || [];

	if ( ids.length ) {
		try {
			const products = await apiGet( `/products?ids=${ ids.join( ',' ) }` );
			const byId = new Map( products.map( ( product ) => [ product.id, product ] ) );
			selectedProducts.value = ids.map( ( id ) => byId.get( id ) ).filter( Boolean );
		} catch ( error ) {
			notifyError( error.message || t( 'loadError', 'Could not load data.' ) );
		}
	}

	activeTab.value = 'products';
	mode.value = 'editor';
}

function queueSearch() {
	clearTimeout( searchTimer );

	if ( searchQuery.value.trim().length < 2 ) {
		searchResults.value = [];
		searching.value = false;
		return;
	}

	searching.value = true;
	searchTimer = setTimeout( runSearch, 300 );
}

async function runSearch() {
	try {
		searchResults.value = await apiGet( `/products?search=${ encodeURIComponent( searchQuery.value.trim() ) }` );
	} catch ( error ) {
		searchResults.value = [];
		notifyError( error.message || t( 'loadError', 'Could not load data.' ) );
	} finally {
		searching.value = false;
	}
}

function addProduct( product ) {
	if ( selectedProducts.value.some( ( item ) => item.id === product.id ) ) {
		return;
	}

	selectedProducts.value.push( product );
}

function removeProduct( productId ) {
	selectedProducts.value = selectedProducts.value.filter( ( product ) => product.id !== productId );
}

function addTier() {
	const last = form.discount_tiers[ form.discount_tiers.length - 1 ];
	const nextQuantity = last ? last.quantity + 1 : 2;

	form.discount_tiers.push( { quantity: nextQuantity, discount: last ? last.discount : 10 } );
}

function removeTier( index ) {
	form.discount_tiers.splice( index, 1 );
}

function onDragStart( index ) {
	dragIndex.value = index;
}

function onDrop( index ) {
	if ( dragIndex.value === null || dragIndex.value === index ) {
		dragIndex.value = null;
		return;
	}

	const moved = selectedProducts.value.splice( dragIndex.value, 1 )[ 0 ];
	selectedProducts.value.splice( index, 0, moved );
	dragIndex.value = null;
}

function buildPayload() {
	return {
		...form,
		product_ids: selectedProducts.value.map( ( product ) => product.id ),
	};
}

async function saveBundle() {
	if ( ! canSave.value ) {
		return;
	}

	saving.value = true;

	try {
		const response = await apiPost( '/bundles', buildPayload() );

		notify( t( 'saved', 'Bundle saved.' ) );

		await loadBundles();

		form.bundle_id = response.bundle_id;
	} catch ( error ) {
		notifyError( error.message || t( 'error', 'Something went wrong. Please try again.' ) );
	} finally {
		saving.value = false;
	}
}

/**
 * Enabled toggle straight on the bundle cards: re-sends the full record
 * with only the flag flipped, matching the REST save contract.
 */
async function quickToggleEnabled( bundle ) {
	const next = ! bundle.enabled;

	const payload = {
		bundle_id: bundle.id,
		name: bundle.name,
		description: bundle.description,
		enabled: next,
		use_quantity: !! bundle.use_quantity,
		max_quantity: bundle.max_quantity,
		product_ids: bundle.product_ids || [],
		discount_tiers: bundle.discount_tiers || [],
		heading_text: bundle.heading_text,
		hint_text: bundle.hint_text,
		button_text: bundle.button_text,
		progress_text: bundle.progress_text,
		cart_behavior: bundle.cart_behavior,
		primary_color: bundle.primary_color,
		accent_color: bundle.accent_color,
		hover_bg_color: bundle.hover_bg_color,
		hover_accent_color: bundle.hover_accent_color,
		button_text_color: bundle.button_text_color,
		show_bundle_title: !! bundle.show_bundle_title,
		show_bundle_description: !! bundle.show_bundle_description,
		show_heading_text: !! bundle.show_heading_text,
		show_hint_text: !! bundle.show_hint_text,
		show_progress_text: !! bundle.show_progress_text,
	};

	try {
		await apiPost( '/bundles', payload );
		bundle.enabled = next ? 1 : 0;
		notify( t( 'updated', 'Bundle updated.' ) );
	} catch ( error ) {
		notifyError( error.message || t( 'error', 'Something went wrong. Please try again.' ) );
	}
}

async function deleteBundle() {
	if ( ! deleteTarget.value ) {
		return;
	}

	try {
		await apiDelete( `/bundles/${ deleteTarget.value.id }` );
		notify( t( 'deleted', 'Bundle deleted.' ) );

		if ( form.bundle_id === deleteTarget.value.id ) {
			backToList();
		}

		await loadBundles();
	} catch ( error ) {
		notifyError( error.message || t( 'error', 'Something went wrong. Please try again.' ) );
	} finally {
		deleteTarget.value = null;
	}
}

async function copyShortcode( bundle ) {
	try {
		await navigator.clipboard.writeText( `[pickpack_bundle id="${ bundle.id }"]` );
		shortcodeCopiedFor.value = bundle.id;
		notify( t( 'copied', 'Shortcode copied!' ) );
		setTimeout( () => {
			shortcodeCopiedFor.value = 0;
		}, 2000 );
	} catch {
		notifyError( t( 'error', 'Something went wrong. Please try again.' ) );
	}
}

onMounted( loadBundles );
</script>

<template>
	<div class="bc-app-page">
		<!-- ============================= LIST MODE ============================= -->
		<template v-if="mode === 'list'">
			<header class="bc-hero">
				<div>
					<h1>{{ t( 'yourBundles', 'Your Bundles' ) }}</h1>
					<p>{{ t( 'bundlesHero', 'Group products into promotions with tiered quantity discounts, then drop them anywhere with a shortcode.' ) }}</p>
				</div>
				<div class="bc-hero__meta">
					<span class="bc-hero__state bc-hero__state--idle">{{ bundles.length }} {{ t( 'total', 'total' ) }} · {{ enabledCount }} {{ t( 'enabledLabel', 'enabled' ) }}</span>
					<button type="button" class="button button-primary bc-cta" @click="newBundle">
						+ {{ t( 'newBundle', 'New Bundle' ) }}
					</button>
				</div>
			</header>

			<p v-if="loading" class="bc-muted">{{ t( 'loading', 'Loading…' ) }}</p>

			<div v-else-if="! bundles.length" class="bc-empty">
				<span class="bc-empty__icon" aria-hidden="true">🎁</span>
				<h2>{{ t( 'emptyTitle', 'No bundles yet' ) }}</h2>
				<p>{{ t( 'emptyHint', 'Create your first bundle: pick products, set quantity tiers, and publish it with a shortcode.' ) }}</p>
				<button type="button" class="button button-primary bc-cta" @click="newBundle">
					+ {{ t( 'newBundle', 'New Bundle' ) }}
				</button>
			</div>

			<div v-else class="bc-list-grid">
				<article
					v-for="bundle in bundles"
					:key="bundle.id"
					class="bc-list-card"
					:style="{ '--card-accent': bundle.primary_color }"
				>
					<div class="bc-list-card__head">
						<h3 class="bc-list-card__name">{{ bundle.name || t( 'unnamed', 'Untitled' ) }}</h3>
						<Switch
							:model-value="!! bundle.enabled"
							:label="t( 'enabled', 'Enabled' )"
							@update:model-value="quickToggleEnabled( bundle )"
						/>
					</div>

					<div class="bc-list-card__thumbs" aria-hidden="true">
						<img
							v-for="productId in ( bundle.product_ids || [] ).slice( 0, 5 )"
							:key="productId"
							:src="thumbsMap[ productId ] || placeholderImage"
							alt=""
						/>
						<span v-if="( bundle.product_ids || [] ).length > 5" class="bc-list-card__more">
							+{{ ( bundle.product_ids || [] ).length - 5 }}
						</span>
					</div>

					<p class="bc-list-card__meta">
						{{ ( bundle.product_ids || [] ).length }} {{ t( 'products', 'products' ) }}
						<template v-if="bundle.discount_tiers && bundle.discount_tiers.length">
							·
							{{ bundle.discount_tiers[ 0 ].quantity }}+ → {{ bundle.discount_tiers[ 0 ].discount }}%
							<template v-if="bundle.discount_tiers.length > 1">…</template>
						</template>
					</p>

					<div class="bc-list-card__actions">
						<button type="button" class="button button-small" @click="editBundle( bundle )">
							{{ t( 'edit', 'Edit' ) }}
						</button>
						<button type="button" class="button button-small" @click="copyShortcode( bundle )">
							{{ shortcodeCopiedFor === bundle.id ? t( 'copied', 'Shortcode copied!' ) : t( 'copyShortcode', 'Copy shortcode' ) }}
						</button>
						<button type="button" class="button-link bc-link-danger" @click="deleteTarget = bundle">
							{{ t( 'delete', 'Delete' ) }}
						</button>
					</div>
				</article>
			</div>
		</template>

		<!-- ============================ EDITOR MODE ============================ -->
		<template v-else>
			<header class="bc-editor-head">
				<button type="button" class="bc-editor-head__back" :title="t( 'backToList', 'All bundles' )" @click="backToList">
					←
				</button>
				<input
					v-model="form.name"
					type="text"
					class="bc-editor-head__name"
					:placeholder="t( 'bundleName', 'Bundle name' )"
					:aria-label="t( 'bundleName', 'Bundle name' )"
				/>
				<span v-if="form.bundle_id" class="bc-code">{{ shortcode }}</span>
				<div class="bc-editor-head__right">
					<label class="bc-editor-head__status">
						<Switch v-model="form.enabled" :label="t( 'enabled', 'Enabled' )" />
						<span>{{ form.enabled ? t( 'enabled', 'Enabled' ) : t( 'disabled', 'Disabled' ) }}</span>
					</label>
					<button type="button" class="button button-primary bc-cta" :disabled="! canSave" @click="saveBundle">
						{{ saving ? t( 'saving', 'Saving…' ) : t( 'save', 'Save Bundle' ) }}
					</button>
				</div>
			</header>

			<div class="bc-editor">
				<div class="bc-editor__main">
					<nav class="bc-tabs" role="tablist">
						<button
							v-for="tab in tabs"
							:key="tab.id"
							type="button"
							role="tab"
							:aria-selected="activeTab === tab.id ? 'true' : 'false'"
							:class="['bc-tab', { 'bc-tab--active': activeTab === tab.id }]"
							@click="activeTab = tab.id"
						>
							{{ t( tab.key, tab.fallback ) }}
						</button>
					</nav>

					<!-- -------- Products tab -------- -->
					<section v-if="activeTab === 'products'" class="bc-editor-section">
						<div class="bc-field">
							<label for="bc-search">{{ t( 'searchProducts', 'Search products' ) }}</label>
							<input
								id="bc-search"
								v-model="searchQuery"
								type="search"
								:placeholder="t( 'searchPlaceholder', 'Type at least 2 characters…' )"
								@input="queueSearch"
							/>
						</div>

						<div v-if="searching || searchResults.length" class="bc-product-grid">
							<p v-if="searching" class="bc-muted">{{ t( 'searching', 'Searching…' ) }}</p>
							<div
								v-for="product in searchResults"
								:key="product.id"
								class="bc-product-card"
								:class="{ 'bc-product-card--selected': selectedProducts.some( ( item ) => item.id === product.id ) }"
							>
								<img :src="product.image || placeholderImage" alt="" />
								<div class="bc-product-card__body">
									<span class="bc-product-card__name">{{ product.name }}</span>
									<span class="bc-product-card__price" v-html="product.price_html" />
								</div>
								<button
									type="button"
									class="bc-product-card__add"
									:disabled="selectedProducts.some( ( item ) => item.id === product.id )"
									@click="addProduct( product )"
								>
									{{ selectedProducts.some( ( item ) => item.id === product.id ) ? '✓' : '+' }}
								</button>
							</div>
						</div>

						<div class="bc-field">
							<label>{{ t( 'selectedProducts', 'Selected products (drag to reorder)' ) }}</label>
							<p v-if="! hasProducts" class="bc-muted">{{ t( 'noProductsSelected', 'No products selected yet.' ) }}</p>
							<ul v-else class="bc-selected-list">
								<li
									v-for="( product, index ) in selectedProducts"
									:key="product.id"
									class="bc-selected-item"
									draggable="true"
									@dragstart="onDragStart( index )"
									@dragover.prevent
									@drop="onDrop( index )"
								>
									<span class="bc-drag-handle" aria-hidden="true">⠿</span>
									<img :src="product.image || placeholderImage" alt="" class="bc-thumb" />
									<span class="bc-selected-item__name">{{ product.name }}</span>
									<span class="bc-selected-item__price" v-html="product.price_html" />
									<button type="button" class="button-link bc-link-danger" @click="removeProduct( product.id )">
										{{ t( 'remove', 'Remove' ) }}
									</button>
								</li>
							</ul>
						</div>
					</section>

					<!-- -------- Discounts tab -------- -->
					<section v-else-if="activeTab === 'discounts'" class="bc-editor-section">
						<div class="bc-editor-row">
							<div class="bc-switch-row bc-switch-row--inline">
								<div>
									<span class="bc-switch-row__label">{{ t( 'useQuantity', 'Quantity mode' ) }}</span>
									<p class="bc-switch-row__hint">{{ t( 'useQuantityHint', 'Steppers per product instead of a single checkbox.' ) }}</p>
								</div>
								<Switch v-model="form.use_quantity" :label="t( 'useQuantity', 'Use quantity mode (steppers instead of checkboxes)' )" />
							</div>
							<div class="bc-field bc-field--inline">
								<label for="bc-max-qty">{{ t( 'maxQuantity', 'Max quantity' ) }}</label>
								<input id="bc-max-qty" v-model.number="form.max_quantity" type="number" min="1" max="999" :disabled="! form.use_quantity" />
							</div>
						</div>

						<p class="bc-muted">{{ t( 'tiersHint', 'Tiers: buy at least this many items, unlock this percentage off. Items count total units in the bundle.' ) }}</p>

						<div class="bc-tier-builder">
							<div v-for="( tier, index ) in form.discount_tiers" :key="index" class="bc-tier-row">
								<span class="bc-tier-row__badge" :style="{ background: form.accent_color }">{{ index + 1 }}</span>
								<label>
									<input v-model.number="tier.quantity" type="number" min="1" />
									{{ t( 'tierItemsOrMore', 'items +' ) }}
								</label>
								<span class="bc-tier-row__arrow" aria-hidden="true">→</span>
								<label>
									<input v-model.number="tier.discount" type="number" min="0" max="100" step="0.5" />
									% {{ t( 'tierOff', 'off' ) }}
								</label>
								<button type="button" class="button-link bc-link-danger" @click="removeTier( index )">
									{{ t( 'remove', 'Remove' ) }}
								</button>
							</div>
							<button type="button" class="bc-ghost-add" @click="addTier">
								+ {{ t( 'addTier', 'Add tier' ) }}
							</button>
						</div>
					</section>

					<!-- -------- Design tab -------- -->
					<section v-else-if="activeTab === 'design'" class="bc-editor-section">
						<div class="bc-design-card">
							<div class="bc-design-card__head">
								<label for="bc-heading">{{ t( 'headingText', 'Heading text' ) }}</label>
								<Switch v-model="form.show_heading_text" :label="t( 'showHeading', 'Show heading' )" />
							</div>
							<input
								id="bc-heading"
								v-model="form.heading_text"
								type="text"
								:disabled="! form.show_heading_text"
							/>
						</div>

						<div class="bc-design-card">
							<div class="bc-design-card__head">
								<label for="bc-hint">{{ t( 'hintText', 'Hint text' ) }}</label>
								<Switch v-model="form.show_hint_text" :label="t( 'showHint', 'Show hint' )" />
							</div>
							<input
								id="bc-hint"
								v-model="form.hint_text"
								type="text"
								:disabled="! form.show_hint_text"
							/>
						</div>

						<div class="bc-design-card">
							<div class="bc-design-card__head">
								<label for="bc-progress">{{ t( 'progressText', 'Progress section text' ) }}</label>
								<Switch v-model="form.show_progress_text" :label="t( 'showProgress', 'Show progress' )" />
							</div>
							<input
								id="bc-progress"
								v-model="form.progress_text"
								type="text"
								:disabled="! form.show_progress_text"
							/>
						</div>

						<div class="bc-design-card">
							<div class="bc-design-card__head">
								<label for="bc-button-text">{{ t( 'buttonText', 'Button text' ) }}</label>
							</div>
							<input id="bc-button-text" v-model="form.button_text" type="text" />
						</div>

						<div class="bc-design-card">
							<div class="bc-design-card__head">
								<span class="bc-design-card__label">{{ t( 'colorsLabel', 'Colors' ) }}</span>
							</div>
							<div class="bc-swatches">
								<label
									v-for="[key, labelKey, fallback] in [
										[ 'primary_color', 'primaryColor', 'Primary' ],
										[ 'accent_color', 'accentColor', 'Accent' ],
										[ 'hover_bg_color', 'hoverBgColor', 'Card tint' ],
										[ 'hover_accent_color', 'hoverAccentColor', 'Button hover' ],
										[ 'button_text_color', 'buttonTextColor', 'Button text' ],
									]"
									:key="key"
									class="bc-swatch"
								>
									<input v-model="form[ key ]" type="color" />
									<span>{{ t( labelKey, fallback ) }}</span>
								</label>
							</div>
						</div>

						<div class="bc-design-card">
							<div class="bc-design-card__head">
								<span class="bc-design-card__label">{{ t( 'cartBehavior', 'After adding to cart' ) }}</span>
							</div>
							<div class="bc-segmented bc-segmented--wide" role="radiogroup" :aria-label="t( 'cartBehavior', 'After adding to cart' )">
								<button
									type="button"
									:class="['bc-segmented__option', { 'bc-segmented__option--active': form.cart_behavior === 'sidecart' }]"
									@click="form.cart_behavior = 'sidecart'"
								>
									{{ t( 'openSidecart', 'Open cart / sidecart' ) }}
								</button>
								<button
									type="button"
									:class="['bc-segmented__option', { 'bc-segmented__option--active': form.cart_behavior === 'redirect' }]"
									@click="form.cart_behavior = 'redirect'"
								>
									{{ t( 'redirectToCart', 'Redirect to cart' ) }}
								</button>
								<span
									class="bc-segmented__thumb"
									:style="{ transform: form.cart_behavior === 'redirect' ? 'translateX(100%)' : 'translateX(0)' }"
									aria-hidden="true"
								/>
							</div>
						</div>

						<div class="bc-switch-rows">
							<div class="bc-switch-row">
								<div>
									<span class="bc-switch-row__label">{{ t( 'showTitle', 'Show title' ) }}</span>
									<p class="bc-switch-row__hint">{{ t( 'showTitleHint', 'Display the bundle name above the widget.' ) }}</p>
								</div>
								<Switch v-model="form.show_bundle_title" :label="t( 'showTitle', 'Show title' )" />
							</div>
							<div class="bc-switch-row">
								<div>
									<span class="bc-switch-row__label">{{ t( 'showOnStorefront', 'Show on storefront' ) }}</span>
									<p class="bc-switch-row__hint">{{ t( 'showStorefrontHint', 'The bundle description, shown under the title.' ) }}</p>
								</div>
								<Switch v-model="form.show_bundle_description" :label="t( 'showOnStorefront', 'Show on storefront' )" />
							</div>
						</div>
					</section>
				</div>

				<!-- -------- Sticky live preview -------- -->
				<aside class="bc-editor__side">
					<div class="bc-preview-pane">
						<h3>{{ t( 'livePreview', 'Live preview' ) }}</h3>
						<div class="bcw-mock">
							<p v-if="form.show_bundle_title" class="bcw-mock__title">{{ form.name || t( 'unnamed', 'Untitled' ) }}</p>
							<p v-if="form.show_heading_text" class="bcw-mock__heading" :style="{ color: form.accent_color }">{{ form.heading_text }}</p>
							<p v-if="form.show_hint_text" class="bcw-mock__hint">{{ form.hint_text }}</p>

							<div class="bcw-mock__grid">
								<div
									v-for="( product, index ) in selectedProducts.slice( 0, 3 )"
									:key="product.id"
									class="bcw-mock__card"
									:style="{ borderColor: form.accent_color }"
								>
									<img :src="product.image || placeholderImage" alt="" />
									<span class="bcw-mock__bar" :style="{ background: form.hover_bg_color }" />
								</div>
								<div v-if="! selectedProducts.length" class="bcw-mock__card bcw-mock__card--empty">
									<span>?</span>
								</div>
							</div>

							<div v-if="form.show_progress_text && form.progress_text" class="bcw-mock__progress-title">{{ form.progress_text }}</div>
							<div class="bcw-mock__tiers">
								<span
									v-for="( tier, index ) in form.discount_tiers"
									:key="index"
									class="bcw-mock__tier"
									:style="index === 0 ? { background: form.hover_bg_color, borderColor: form.hover_accent_color, color: form.button_text_color === '#ffffff' ? '#fff' : form.accent_color } : {}"
								>
									{{ tier.quantity }}+ → {{ tier.discount }}%
								</span>
							</div>

							<button
								type="button"
								class="bcw-mock__button"
								:style="{ background: form.accent_color, color: form.button_text_color }"
							>
								{{ form.button_text || t( 'addToCart', 'Add to Cart' ) }}
							</button>
							<span class="bcw-mock__behavior">
								{{ form.cart_behavior === 'redirect' ? t( 'redirectToCart', 'Redirect to cart' ) : t( 'openSidecart', 'Open cart / sidecart' ) }}
							</span>
						</div>
						<p class="bc-muted bc-preview-pane__note">{{ t( 'previewNote', 'A rough sketch of the storefront widget — the real one inherits your theme styles.' ) }}</p>
					</div>
				</aside>
			</div>
		</template>

		<Modal
			v-if="deleteTarget"
			:title="t( 'deleteBundleTitle', 'Delete bundle?' )"
			:confirm-label="t( 'delete', 'Delete' )"
			:danger="true"
			@close="deleteTarget = null"
			@confirm="deleteBundle"
		>
			<p>{{ t( 'deleteConfirm', 'Delete this bundle? This cannot be undone.' ) }}</p>
			<p><strong>{{ deleteTarget.name }}</strong></p>
		</Modal>
	</div>
</template>
