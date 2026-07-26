<template>
	<div class="filter-list">
		<input
			v-model="query"
			type="search"
			class="filter-list__search"
			:placeholder="t('merlin', 'Search website…')">

		<ul class="filter-list__items">
			<li v-for="filter in visible" :key="filter.domain">
				<button
					class="filter-list__item"
					:class="{ 'filter-list__item--active': filter.domain === selected }"
					:disabled="busy"
					@click="$emit('select', filter.domain)">
					<span class="filter-list__domain">{{ filter.domain }}</span>
					<span class="filter-list__badges">
						<span v-if="filter.hasBundle" class="filter-list__badge" :title="t('merlin', 'Shipped with the app')">
							{{ t('merlin', 'built-in') }}
						</span>
						<span v-if="filter.hasCustom" class="filter-list__badge filter-list__badge--own" :title="t('merlin', 'Has your own rules')">
							{{ t('merlin', 'own') }}
						</span>
						<span
							v-if="filter.userOverrideCount"
							class="filter-list__badge filter-list__badge--users"
							:title="t('merlin', 'Users with their own private override for this website (content not visible to admins)')">
							{{ n('merlin', '%n user override', '%n user overrides', filter.userOverrideCount) }}
						</span>
					</span>
				</button>
			</li>
			<li v-if="!visible.length" class="filter-list__empty">
				{{ t('merlin', 'No match.') }}
			</li>
		</ul>

		<div class="filter-list__actions">
			<form @submit.prevent="submitNew">
				<label class="filter-list__label" for="merlin-new-domain">
					{{ t('merlin', 'Add website') }}
				</label>
				<div class="filter-list__row">
					<input
						id="merlin-new-domain"
						v-model="newDomain"
						type="text"
						placeholder="beispiel.de">
					<button type="submit" :disabled="!newDomain.trim()">
						<Plus :size="18" />
					</button>
				</div>
			</form>

			<template v-if="showImport">
				<label class="filter-list__label">{{ t('merlin', 'Import XML file') }}</label>
				<input
					ref="file"
					type="file"
					accept=".xml,application/xml,text/xml"
					@change="onFile">
			</template>
		</div>
	</div>
</template>

<script>
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'FilterList',

	components: { Plus },

	props: {
		filters: { type: Array, required: true },
		selected: { type: String, default: null },
		busy: { type: Boolean, default: false },
		// Personal-Settings-UI hat keinen Import-Endpunkt (kein Domain-aus-
		// name-Attribut-Konflikt zu klären, nur die eigene Zeile) – dort
		// ausgeblendet, in der Admin-Oberfläche unverändert sichtbar.
		showImport: { type: Boolean, default: true },
	},

	emits: ['select', 'create', 'import'],

	data() {
		return { query: '', newDomain: '' }
	},

	computed: {
		visible() {
			const q = this.query.trim().toLowerCase()
			if (!q) {
				return this.filters
			}
			return this.filters.filter(f => f.domain.includes(q))
		},
	},

	methods: {
		submitNew() {
			this.$emit('create', this.newDomain)
			this.newDomain = ''
		},

		onFile(event) {
			const file = event.target.files && event.target.files[0]
			if (!file) {
				return
			}
			const reader = new FileReader()
			reader.onload = () => {
				this.$emit('import', { xml: String(reader.result), domain: null })
				// Zurücksetzen, damit dieselbe Datei erneut gewählt werden kann.
				this.$refs.file.value = ''
			}
			reader.readAsText(file)
		},
	},
}
</script>

<style scoped>
.filter-list {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 10px;
	background-color: var(--color-main-background);
}

.filter-list__search {
	width: 100%;
	margin-bottom: 8px;
}

.filter-list__items {
	list-style: none;
	margin: 0 0 10px;
	padding: 0;
	max-height: 420px;
	overflow-y: auto;
}

.filter-list__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
	width: 100%;
	text-align: start;
	background: transparent;
	border: none;
	border-radius: var(--border-radius, 4px);
	padding: 6px 8px;
	cursor: pointer;
	color: var(--color-main-text);
}

.filter-list__item:hover {
	background-color: var(--color-background-hover);
}

.filter-list__item--active {
	background-color: var(--color-primary-element-light, var(--color-background-dark));
	font-weight: bold;
}

.filter-list__domain {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.filter-list__badges {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.filter-list__badge {
	font-size: 0.7rem;
	line-height: 1.4;
	padding: 0 5px;
	border-radius: 8px;
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.filter-list__badge--own {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.filter-list__badge--users {
	background-color: var(--color-warning, #f0ad4e);
	color: var(--color-primary-element-text, #fff);
}

.filter-list__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px;
}

.filter-list__actions {
	border-top: 1px solid var(--color-border);
	padding-top: 10px;
}

.filter-list__label {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin: 8px 0 2px;
}

.filter-list__row {
	display: flex;
	gap: 4px;
}

.filter-list__row input {
	flex: 1;
	min-width: 0;
}
</style>
