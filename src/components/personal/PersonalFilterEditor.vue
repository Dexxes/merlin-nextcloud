<template>
	<div class="filter-editor">
		<header class="filter-editor__header">
			<div>
				<h3>{{ detail.domain }}</h3>
				<p class="filter-editor__origin">
					<span v-if="detail.reference">{{ t('merlin', 'Built-in/admin rules present') }}</span>
					<span v-else>{{ t('merlin', 'No built-in or admin rules') }}</span>
					·
					<span v-if="detail.own">{{ t('merlin', 'your own rules present') }}</span>
					<span v-else>{{ t('merlin', 'no own rules yet') }}</span>
				</p>
			</div>
			<div class="filter-editor__actions">
				<button :disabled="saving" @click="$emit('save', local)">
					{{ saving ? t('merlin', 'Saving…') : t('merlin', 'Save') }}
				</button>
				<button :disabled="saving" @click="$emit('discard')">
					{{ t('merlin', 'Discard') }}
				</button>
				<button
					v-if="detail.own"
					class="filter-editor__danger"
					:disabled="saving"
					@click="confirmRemove">
					{{ t('merlin', 'Delete my rules') }}
				</button>
			</div>
		</header>

		<p v-if="detail.mergeError" class="filter-editor__mergeerror">
			{{ t('merlin', 'The merged filter could not be built:') }} {{ detail.mergeError }}
		</p>

		<p class="filter-editor__hint">
			{{ t('merlin', 'Rules below marked as built-in come from the app or your administrator and are read-only here. Add your own rules to extend or override them — only you will see them.') }}
		</p>

		<label class="filter-editor__note">
			<span>{{ t('merlin', 'Note (stored for your own reference)') }}</span>
			<textarea v-model="local.note" rows="2" />
		</label>

		<RuleSection
			v-for="section in listSections"
			:key="section"
			:section="section"
			:schema="schema"
			:rules="local[section] || []"
			:bundle-rules="referenceRulesFor(section)"
			:draft="local"
			:section-disabled="sectionDisabled(section)"
			@change="setSection(section, $event)"
			@toggle-rule="toggleRule(section, $event)"
			@toggle-section="toggleSection(section)" />

		<section v-if="hasCategory" class="filter-editor__category">
			<h4>
				<code>category</code>
				<span class="filter-editor__desc">{{ t('merlin', 'Fixed category for all your articles of this website') }}</span>
			</h4>
			<p v-if="referenceCategory" class="filter-editor__bundleval">
				{{ t('merlin', 'Current value:') }} <code>{{ referenceCategory }}</code>
				<label class="filter-editor__section-off">
					<input type="checkbox" :checked="sectionDisabled('category')" @change="toggleSection('category')">
					<span>{{ t('merlin', 'ignore') }}</span>
				</label>
			</p>
			<input v-model="local.category" type="text" :placeholder="t('merlin', 'e.g. News')">
		</section>

		<PersonalFilterTestPanel :domain="detail.domain" :draft="local" />

		<details class="filter-editor__source">
			<summary>{{ t('merlin', 'Show XML source') }}</summary>
			<div class="filter-editor__sources">
				<div v-if="detail.reference">
					<h5>{{ t('merlin', 'Built-in + admin (reference)') }}</h5>
					<pre>{{ detail.reference.xml }}</pre>
				</div>
				<div v-if="detail.own">
					<h5>{{ t('merlin', 'Your own') }}</h5>
					<pre>{{ detail.own.xml }}</pre>
				</div>
				<div v-if="detail.merged">
					<h5>{{ t('merlin', 'Merged (what the extractor sees for you)') }}</h5>
					<pre>{{ detail.merged.xml }}</pre>
				</div>
			</div>
		</details>
	</div>
</template>

<script>
import PersonalFilterTestPanel from './PersonalFilterTestPanel.vue'
import RuleSection from '../admin/RuleSection.vue'
import {
	isListSection,
	isSectionDisabled,
	toggleDisabled,
	toggleSectionDisabled,
} from '../admin/draft.js'

export default {
	name: 'PersonalFilterEditor',

	components: { PersonalFilterTestPanel, RuleSection },

	props: {
		detail: { type: Object, required: true },
		schema: { type: Object, required: true },
		draft: { type: Object, required: true },
		saving: { type: Boolean, default: false },
	},

	emits: ['save', 'discard', 'remove'],

	data() {
		// Eigene Kopie: der Entwurf der Elternkomponente darf erst beim Speichern
		// bzw. Neuladen ersetzt werden, sonst würde jeder Tastendruck den
		// Servertand überschreiben.
		return { local: JSON.parse(JSON.stringify(this.draft)) }
	},

	computed: {
		listSections() {
			return this.schema.sectionOrder.filter(section => isListSection(this.schema, section))
		},

		hasCategory() {
			return !!this.schema.sections.category
		},

		referenceCategory() {
			const rules = this.detail.reference ? this.detail.reference.rules : null
			return rules && typeof rules.category === 'string' ? rules.category : ''
		},
	},

	watch: {
		draft(next) {
			this.local = JSON.parse(JSON.stringify(next))
		},
	},

	methods: {
		referenceRulesFor(section) {
			const rules = this.detail.reference ? this.detail.reference.rules : null
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
			this.local = { ...this.local }
		},

		toggleSection(section) {
			toggleSectionDisabled(this.local, section)
			this.local = { ...this.local }
		},

		confirmRemove() {
			// eslint-disable-next-line no-alert
			if (window.confirm(this.t('merlin', 'Delete your own rules for {domain}? Built-in and admin rules stay in place.', { domain: this.detail.domain }))) {
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
	gap: 12px;
	margin-bottom: 16px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.filter-editor__header h3 {
	margin: 0;
	font-size: 1.15rem;
}

.filter-editor__origin {
	margin: 4px 0 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.filter-editor__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.filter-editor__actions button {
	border-radius: var(--border-radius-pill, 20px);
	padding: 7px 18px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-weight: 600;
	font-size: 0.9em;
}

.filter-editor__actions button:hover:not(:disabled) {
	background-color: var(--color-background-hover);
}

.filter-editor__actions button:first-child {
	background-color: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.filter-editor__actions button:first-child:hover:not(:disabled) {
	background-color: var(--color-primary-element-hover, #006ba3);
}

.filter-editor__actions button:disabled {
	opacity: 0.6;
	cursor: default;
}

.filter-editor__danger {
	color: var(--color-error-text, #c0392b) !important;
	background-color: var(--color-main-background) !important;
	border-color: var(--color-border-dark, var(--color-border)) !important;
}

.filter-editor__danger:hover:not(:disabled) {
	background-color: var(--color-error, #e9322d) !important;
	border-color: var(--color-error, #e9322d) !important;
	color: #fff !important;
}

.filter-editor__mergeerror {
	background-color: var(--color-error, #e9322d);
	color: #fff;
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	margin-bottom: 12px;
}

.filter-editor__hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	max-width: 70ch;
	margin-bottom: 16px;
	line-height: 1.5;
}

.filter-editor__note {
	display: block;
	margin-bottom: 20px;
}

.filter-editor__note span {
	display: block;
	font-size: 0.85em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.filter-editor__note textarea {
	width: 100%;
	border-radius: var(--border-radius-large, 8px);
	resize: vertical;
}

.filter-editor__category {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 14px 16px;
	margin-bottom: 16px;
	background-color: var(--color-background-hover);
}

.filter-editor__category h4 {
	margin: 0 0 8px;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
}

.filter-editor__category input {
	width: 100%;
	max-width: 320px;
	border-radius: var(--border-radius-large, 8px);
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
	margin: 0 0 8px;
}

.filter-editor__section-off {
	display: flex;
	align-items: center;
	gap: 4px;
}

.filter-editor__source {
	margin-top: 20px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.filter-editor__source summary {
	cursor: pointer;
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.filter-editor__source summary:hover {
	color: var(--color-main-text);
}

.filter-editor__sources {
	display: grid;
	gap: 12px;
	margin-top: 10px;
}

.filter-editor__sources h5 {
	margin: 0 0 4px;
	font-size: 0.9em;
}

.filter-editor__sources pre {
	max-height: 320px;
	overflow: auto;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius-large, 8px);
	padding: 10px;
	font-size: 0.75em;
	font-family: monospace;
	white-space: pre;
}
</style>
