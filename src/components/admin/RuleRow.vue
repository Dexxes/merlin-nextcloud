<template>
	<div class="rule-row" :class="{ 'rule-row--readonly': readonly }">
		<!-- Regelart: nur zeigen, wenn die Sektion mehrere kennt -->
		<select
			v-if="elementNames.length > 1"
			class="rule-row__element"
			:value="rule.element"
			:disabled="readonly"
			@change="changeElement($event.target.value)">
			<option v-for="name in elementNames" :key="name" :value="name">
				{{ name }}
			</option>
		</select>
		<span v-else class="rule-row__element rule-row__element--fixed">{{ rule.element }}</span>

		<!-- Genau eines aus einer Auswahl (remove/infobox: id | class | xpath) -->
		<template v-if="fields.oneOf.length">
			<select
				class="rule-row__kind"
				:value="activeOneOf"
				:disabled="readonly"
				@change="switchOneOf($event.target.value)">
				<option v-for="name in fields.oneOf" :key="name" :value="name">
					{{ name }}
				</option>
			</select>
			<input
				class="rule-row__value"
				type="text"
				:value="rule.attributes[activeOneOf] || ''"
				:readonly="readonly"
				:placeholder="placeholder(activeOneOf)"
				@input="setAttribute(activeOneOf, $event.target.value)">
		</template>

		<!-- Feste Attribute (z. B. saveElements: xpath + class) -->
		<label v-for="name in fixedFields" :key="name" class="rule-row__field">
			<span class="rule-row__field-name">{{ name }}</span>
			<input
				type="text"
				:value="rule.attributes[name] || ''"
				:readonly="readonly"
				:placeholder="placeholder(name)"
				@input="setAttribute(name, $event.target.value)">
		</label>

		<span v-if="readonly" class="rule-row__origin">
			{{ t('merlin', 'built-in') }}
		</span>

		<!-- Abschalten einer Bundle-Regel bzw. Löschen einer eigenen Regel -->
		<label v-if="readonly" class="rule-row__disable" :title="t('merlin', 'Ignore this built-in rule')">
			<input type="checkbox" :checked="disabled" @change="$emit('toggle-disabled')">
			<span>{{ t('merlin', 'off') }}</span>
		</label>
		<button v-else class="rule-row__remove" :title="t('merlin', 'Delete rule')" @click="$emit('remove')">
			<Close :size="16" />
		</button>
	</div>
</template>

<script>
import Close from 'vue-material-design-icons/Close.vue'
import { attributeNames } from './draft.js'

export default {
	name: 'RuleRow',

	components: { Close },

	props: {
		rule: { type: Object, required: true },
		section: { type: String, required: true },
		schema: { type: Object, required: true },
		readonly: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
	},

	emits: ['remove', 'toggle-disabled', 'update'],

	computed: {
		elementNames() {
			const def = this.schema.sections[this.section]
			return def && def.children ? Object.keys(def.children) : []
		},

		fields() {
			return attributeNames(this.schema, this.section, this.rule.element)
		},

		/** Attribute mit eigenem Eingabefeld (alles außer der oneOf-Auswahl). */
		fixedFields() {
			return [...this.fields.required, ...this.fields.optional]
		},

		/**
		 * Das aktuell benutzte Attribut der oneOf-Gruppe. Ist noch keines gesetzt,
		 * wird das erste angeboten – sonst stände die Auswahl auf einem Wert, den
		 * die Regel nicht trägt.
		 */
		activeOneOf() {
			const set = this.fields.oneOf.find(name => (this.rule.attributes[name] || '') !== '')
			return set || this.fields.oneOf[0] || ''
		},
	},

	methods: {
		placeholder(name) {
			switch (name) {
			case 'id': return 'cookie-banner'
			case 'class': return 'ad-container'
			case 'xpath': return "//div[@data-ad]"
			case 'container-xpath': return "//figure[contains(@class,'img')]"
			case 'caption-xpath': return './/figcaption'
			case 'text-xpath': return './/p'
			case 'author-xpath': return './/cite'
			case 'json': return '$.author.name'
			case 'name': return 'Cookie'
			case 'value': return 'consent=1'
			case 'index': return '0'
			default: return ''
			}
		},

		setAttribute(name, value) {
			this.$emit('update', { ...this.rule, attributes: { ...this.rule.attributes, [name]: value } })
		},

		/**
		 * Wechselt innerhalb der oneOf-Gruppe und nimmt den bisherigen Wert mit.
		 * Der alte Schlüssel wird entfernt, weil zwei gesetzte oneOf-Attribute vom
		 * Validator abgelehnt werden (der Extractor würde nur das erste beachten).
		 */
		switchOneOf(next) {
			const attributes = { ...this.rule.attributes }
			const previous = this.activeOneOf
			const carried = attributes[previous] || ''
			this.fields.oneOf.forEach(name => {
				delete attributes[name]
			})
			attributes[next] = carried
			this.$emit('update', { ...this.rule, attributes })
		},

		/** Beim Wechsel der Regelart nur noch erlaubte Attribute behalten. */
		changeElement(next) {
			const allowed = attributeNames(this.schema, this.section, next)
			const keep = [...allowed.required, ...allowed.optional, ...allowed.oneOf]
			const attributes = {}
			keep.forEach(name => {
				if (this.rule.attributes[name] !== undefined) {
					attributes[name] = this.rule.attributes[name]
				}
			})
			this.$emit('update', { element: next, attributes })
		},
	},
}
</script>

<style scoped>
.rule-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
	padding: 4px 0;
}

.rule-row--readonly {
	color: var(--color-text-maxcontrast);
}

.rule-row__element,
.rule-row__kind {
	flex-shrink: 0;
}

.rule-row__element--fixed {
	font-family: monospace;
	font-size: 0.85em;
	min-width: 90px;
}

.rule-row__value {
	flex: 1;
	min-width: 180px;
	font-family: monospace;
	font-size: 0.85em;
}

.rule-row__field {
	display: flex;
	align-items: center;
	gap: 4px;
	flex: 1;
	min-width: 200px;
}

.rule-row__field-name {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.rule-row__field input {
	flex: 1;
	min-width: 0;
	font-family: monospace;
	font-size: 0.85em;
}

.rule-row__origin {
	font-size: 0.7rem;
	padding: 0 5px;
	border-radius: 8px;
	background-color: var(--color-background-dark);
}

.rule-row__disable {
	display: flex;
	align-items: center;
	gap: 3px;
	font-size: 0.8em;
	flex-shrink: 0;
}

.rule-row__remove {
	background: transparent;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.rule-row__remove:hover {
	color: var(--color-error, #e9322d);
}
</style>
