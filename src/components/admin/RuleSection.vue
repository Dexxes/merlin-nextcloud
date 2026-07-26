<template>
	<section class="rule-section">
		<header class="rule-section__header">
			<h4>
				<code>{{ section }}</code>
				<span class="rule-section__desc">{{ description }}</span>
			</h4>
			<label v-if="bundleRules.length" class="rule-section__section-off">
				<input
					type="checkbox"
					:checked="sectionDisabled"
					@change="$emit('toggle-section')">
				<span>{{ t('merlin', 'Ignore all built-in rules of this section') }}</span>
			</label>
		</header>

		<!-- Mitgelieferte Regeln: read-only, einzeln abschaltbar -->
		<div v-if="bundleRules.length" class="rule-section__bundle" :class="{ 'rule-section__bundle--off': sectionDisabled }">
			<RuleRow
				v-for="(rule, index) in bundleRules"
				:key="'bundle-' + index"
				:rule="rule"
				:section="section"
				:schema="schema"
				readonly
				:disabled="sectionDisabled || isRuleDisabled(rule)"
				@toggle-disabled="$emit('toggle-rule', rule)" />
			<p v-if="sectionDisabled" class="rule-section__note">
				{{ t('merlin', 'This whole section is ignored. Rules you add below replace it.') }}
			</p>
		</div>

		<!-- Eigene Regeln -->
		<div class="rule-section__own">
			<RuleRow
				v-for="(rule, index) in rules"
				:key="'own-' + index"
				:rule="rule"
				:section="section"
				:schema="schema"
				@update="update(index, $event)"
				@remove="remove(index)" />

			<button class="rule-section__add" @click="add">
				<Plus :size="16" />
				<span>{{ t('merlin', 'Add rule') }}</span>
			</button>
		</div>
	</section>
</template>

<script>
import Plus from 'vue-material-design-icons/Plus.vue'
import RuleRow from './RuleRow.vue'
import { defaultElement, isDisabled } from './draft.js'

export default {
	name: 'RuleSection',

	components: { Plus, RuleRow },

	props: {
		section: { type: String, required: true },
		schema: { type: Object, required: true },
		rules: { type: Array, required: true },
		bundleRules: { type: Array, default: () => [] },
		draft: { type: Object, required: true },
		sectionDisabled: { type: Boolean, default: false },
	},

	emits: ['change', 'toggle-rule', 'toggle-section'],

	computed: {
		description() {
			switch (this.section) {
			case 'fetch':
				return this.t('merlin', 'HTTP headers for fetching the page (e.g. consent cookie)')
			case 'pre-filter':
				return this.t('merlin', 'Applied to the raw page before the article is extracted')
			case 'post-filter':
				return this.t('merlin', 'Applied to the already extracted article text')
			case 'images':
				return this.t('merlin', 'Rewraps image and caption into figure/figcaption')
			case 'quotes':
				return this.t('merlin', 'Converts site-specific quote markup into blockquotes')
			case 'json':
				return this.t('merlin', 'Named JSON sources that metadata fields can reference')
			case 'metadata':
				return this.t('merlin', 'Overrides title, author, date etc.; several rules per field act as a fallback chain')
			default:
				return ''
			}
		},
	},

	methods: {
		isRuleDisabled(rule) {
			return isDisabled(this.draft, this.section, rule)
		},

		add() {
			const element = defaultElement(this.schema, this.section)
			this.$emit('change', [...this.rules, { element, attributes: {} }])
		},

		update(index, rule) {
			const next = [...this.rules]
			next[index] = rule
			this.$emit('change', next)
		},

		remove(index) {
			this.$emit('change', this.rules.filter((_, i) => i !== index))
		},
	},
}
</script>

<style scoped>
.rule-section {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 14px 16px;
	margin-bottom: 14px;
	background-color: var(--color-main-background);
}

.rule-section__header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: baseline;
	gap: 8px;
	margin-bottom: 10px;
	padding-bottom: 10px;
	border-bottom: 1px solid var(--color-border);
}

.rule-section__header h4 {
	margin: 0;
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 8px;
}

.rule-section__header code {
	font-family: monospace;
	font-size: 0.95em;
	background-color: var(--color-background-dark);
	padding: 1px 6px;
	border-radius: var(--border-radius, 4px);
}

.rule-section__desc {
	font-weight: normal;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.rule-section__section-off {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.rule-section__bundle {
	border-inline-start: 3px solid var(--color-border-dark, var(--color-border));
	padding-inline-start: 10px;
	margin-bottom: 10px;
}

.rule-section__bundle--off {
	opacity: 0.5;
	text-decoration: line-through;
}

.rule-section__note {
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.rule-section__add {
	display: flex;
	align-items: center;
	gap: 6px;
	background-color: var(--color-background-hover);
	border: 1px dashed var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large, 8px);
	padding: 6px 12px;
	margin-top: 6px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-weight: 600;
}

.rule-section__add:hover {
	color: var(--color-primary-element-text, #fff);
	background-color: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	border-style: solid;
}
</style>
