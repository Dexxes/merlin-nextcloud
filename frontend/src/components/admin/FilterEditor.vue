<template>
	<div class="filter-editor">
		<header class="filter-editor__header">
			<div>
				<h3>{{ detail.domain }}</h3>
				<p class="filter-editor__origin">
					<span v-if="detail.bundle">{{ t('merlin', 'Built-in filter present') }}</span>
					<span v-else>{{ t('merlin', 'No built-in filter') }}</span>
					·
					<span v-if="detail.custom">{{ t('merlin', 'own rules present') }}</span>
					<span v-else>{{ t('merlin', 'no own rules yet') }}</span>
				</p>
			</div>
			<div class="filter-editor__actions">
				<a class="filter-editor__link" :href="exportHref" download>
					{{ t('merlin', 'Download XML') }}
				</a>
				<button :disabled="saving || !writable" @click="$emit('save', local)">
					{{ saving ? t('merlin', 'Saving…') : t('merlin', 'Save') }}
				</button>
				<button :disabled="saving" @click="$emit('discard')">
					{{ t('merlin', 'Discard') }}
				</button>
				<button
					v-if="detail.custom"
					class="filter-editor__danger"
					:disabled="saving"
					@click="confirmRemove">
					{{ t('merlin', 'Delete own rules') }}
				</button>
			</div>
		</header>

		<p v-if="detail.mergeError" class="filter-editor__mergeerror">
			{{ t('merlin', 'The merged filter could not be built:') }} {{ detail.mergeError }}
		</p>

		<label class="filter-editor__note">
			<span>{{ t('merlin', 'Note (stored in the file, for your own reference)') }}</span>
			<textarea v-model="local.note" rows="2" />
		</label>

		<RuleSection
			v-for="section in listSections"
			:key="section"
			:section="section"
			:schema="schema"
			:rules="local[section] || []"
			:bundle-rules="bundleRulesFor(section)"
			:draft="local"
			:section-disabled="sectionDisabled(section)"
			@change="setSection(section, $event)"
			@toggle-rule="toggleRule(section, $event)"
			@toggle-section="toggleSection(section)" />

		<section v-if="hasCategory" class="filter-editor__category">
			<h4>
				<code>category</code>
				<span class="filter-editor__desc">{{ t('merlin', 'Fixed category for all articles of this website') }}</span>
			</h4>
			<p v-if="bundleCategory" class="filter-editor__bundleval">
				{{ t('merlin', 'Built-in value:') }} <code>{{ bundleCategory }}</code>
				<label class="filter-editor__section-off">
					<input type="checkbox" :checked="sectionDisabled('category')" @change="toggleSection('category')">
					<span>{{ t('merlin', 'ignore') }}</span>
				</label>
			</p>
			<input v-model="local.category" type="text" :placeholder="t('merlin', 'e.g. News')">
		</section>

		<FilterTestPanel :domain="detail.domain" :draft="local" />

		<details class="filter-editor__source">
			<summary>{{ t('merlin', 'Show XML source') }}</summary>
			<div class="filter-editor__sources">
				<div v-if="detail.bundle">
					<h5>{{ t('merlin', 'Built-in') }}</h5>
					<pre>{{ detail.bundle.xml }}</pre>
				</div>
				<div v-if="detail.custom">
					<h5>{{ t('merlin', 'Your own') }}</h5>
					<pre>{{ detail.custom.xml }}</pre>
				</div>
				<div v-if="detail.merged">
					<h5>{{ t('merlin', 'Merged (what the extractor sees)') }}</h5>
					<pre>{{ detail.merged.xml }}</pre>
				</div>
			</div>
		</details>
	</div>
</template>

<script>
import FilterTestPanel from './FilterTestPanel.vue'
import RuleSection from './RuleSection.vue'
import { exportUrl } from '../../api/contentFilters.js'
import {
	isListSection,
	isSectionDisabled,
	toggleDisabled,
	toggleSectionDisabled,
} from './draft.js'

export default {
	name: 'FilterEditor',

	components: { FilterTestPanel, RuleSection },

	props: {
		detail: { type: Object, required: true },
		schema: { type: Object, required: true },
		draft: { type: Object, required: true },
		saving: { type: Boolean, default: false },
		writable: { type: Boolean, default: false },
	},

	emits: ['save', 'discard', 'remove'],

	data() {
		// Eigene Kopie: der Entwurf der Elternkomponente darf erst beim Speichern
		// bzw. Neuladen ersetzt werden, sonst würde jeder Tastendruck den
		// Servertand überschreiben.
		return { local: JSON.parse(JSON.stringify(this.draft)) }
	},

	computed: {
		/** Alle Sektionen mit Regellisten, in der Reihenfolge der Pipeline. */
		listSections() {
			return this.schema.sectionOrder.filter(section => isListSection(this.schema, section))
		},

		hasCategory() {
			return !!this.schema.sections.category
		},

		bundleCategory() {
			const rules = this.detail.bundle ? this.detail.bundle.rules : null
			return rules && typeof rules.category === 'string' ? rules.category : ''
		},

		exportHref() {
			return exportUrl(this.detail.domain)
		},
	},

	watch: {
		// Nach dem Speichern liefert der Server den kanonischen Stand zurück.
		draft(next) {
			this.local = JSON.parse(JSON.stringify(next))
		},
	},

	methods: {
		bundleRulesFor(section) {
			const rules = this.detail.bundle ? this.detail.bundle.rules : null
			const value = rules ? rules[section] : null
			return Array.isArray(value) ? value : []
		},

		setSection(section, rules) {
			this.local = { ...this.local, [section]: rules }
		},

		sectionDisabled(section) {
			return isSectionDisabled(this.local, section)
		},

		toggleRule(section, rule) {
			toggleDisabled(this.local, section, rule)
			// Neue Objektidentität, damit Vue die abhängigen Zeilen neu bewertet.
			this.local = { ...this.local }
		},

		toggleSection(section) {
			toggleSectionDisabled(this.local, section)
			this.local = { ...this.local }
		},

		confirmRemove() {
			// eslint-disable-next-line no-alert
			if (window.confirm(this.t('merlin', 'Delete your own rules for {domain}? The built-in filter stays in place.', { domain: this.detail.domain }))) {
				this.$emit('remove')
			}
		},
	},
}
</script>

<style scoped>
.filter-editor__header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: flex-start;
	gap: 10px;
	margin-bottom: 12px;
}

.filter-editor__header h3 {
	margin: 0;
}

.filter-editor__origin {
	margin: 2px 0 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.filter-editor__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.filter-editor__link {
	font-size: 0.85em;
	text-decoration: underline;
}

.filter-editor__danger {
	color: var(--color-error-text, #c0392b);
}

.filter-editor__mergeerror {
	background-color: var(--color-error, #e9322d);
	color: #fff;
	padding: 6px 10px;
	border-radius: var(--border-radius, 4px);
}

.filter-editor__note {
	display: block;
	margin-bottom: 12px;
}

.filter-editor__note span {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.filter-editor__note textarea {
	width: 100%;
}

.filter-editor__category {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 10px 12px;
	margin-bottom: 12px;
}

.filter-editor__category h4 {
	margin: 0 0 4px;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
}

.filter-editor__desc {
	font-weight: normal;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.filter-editor__bundleval {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	display: flex;
	gap: 10px;
	align-items: center;
	margin: 0 0 4px;
}

.filter-editor__section-off {
	display: flex;
	align-items: center;
	gap: 4px;
}

.filter-editor__source {
	margin-top: 16px;
}

.filter-editor__source summary {
	cursor: pointer;
	font-size: 0.9em;
}

.filter-editor__sources {
	display: grid;
	gap: 12px;
	margin-top: 8px;
}

.filter-editor__sources h5 {
	margin: 0 0 2px;
}

.filter-editor__sources pre {
	max-height: 320px;
	overflow: auto;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius, 4px);
	padding: 8px;
	font-size: 0.75em;
	font-family: monospace;
	white-space: pre;
}
</style>
