<template>
	<section class="test-panel">
		<h4>{{ t('merlin', 'Test run') }}</h4>
		<p class="test-panel__hint">
			{{ t('merlin', 'Fetches an article of this website with the rules currently shown — including your unsaved changes — and reports how many elements each rule matched. Nothing is saved.') }}
		</p>

		<form class="test-panel__form" @submit.prevent="run">
			<input
				v-model="url"
				type="url"
				:placeholder="'https://' + domain + '/…'"
				required>
			<button type="submit" :disabled="running || !url.trim()">
				{{ running ? t('merlin', 'Testing…') : t('merlin', 'Test') }}
			</button>
		</form>

		<div v-if="error" class="test-panel__error">{{ error }}</div>

		<template v-if="report">
			<div class="test-panel__summary">
				<span>{{ n('merlin', '%n rule', '%n rules', report.summary.rules) }}</span>
				<span :class="{ 'test-panel__warn': report.summary.misses > 0 }">
					{{ n('merlin', '%n without a match', '%n without a match', report.summary.misses) }}
				</span>
				<span v-if="report.summary.errors > 0" class="test-panel__bad">
					{{ n('merlin', '%n faulty', '%n faulty', report.summary.errors) }}
				</span>
				<span v-if="report.draft" class="test-panel__draft">{{ t('merlin', 'unsaved state') }}</span>
			</div>

			<table v-if="report.trace.length" class="test-panel__trace">
				<thead>
					<tr>
						<th>{{ t('merlin', 'Section') }}</th>
						<th>{{ t('merlin', 'Rule') }}</th>
						<th>{{ t('merlin', 'Origin') }}</th>
						<th class="test-panel__num">{{ t('merlin', 'Matches') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(entry, index) in report.trace"
						:key="index"
						:class="{
							'test-panel__row--miss': entry.matches === 0 && !entry.error,
							'test-panel__row--error': !!entry.error,
						}">
						<td><code>{{ entry.section }}</code></td>
						<td>
							<code>&lt;{{ entry.element }}{{ formatAttributes(entry.attributes) }}&gt;</code>
							<div v-if="entry.error" class="test-panel__rowerror">{{ entry.error }}</div>
						</td>
						<td>{{ originLabel(entry.origin) }}</td>
						<td class="test-panel__num">{{ entry.matches }}</td>
					</tr>
				</tbody>
			</table>

			<dl v-if="report.result" class="test-panel__meta">
				<template v-for="field in metaFields" :key="field.key">
					<dt>{{ field.label }}</dt>
					<dd :class="{ 'test-panel__missing': !field.value }">
						{{ field.value || t('merlin', '— empty —') }}
					</dd>
				</template>
			</dl>

			<details v-if="report.result && report.result.content" class="test-panel__content">
				<summary>{{ t('merlin', 'Show extracted article') }}</summary>
				<!-- eslint-disable-next-line vue/no-v-html -->
				<div class="test-panel__article" v-html="report.result.content" />
			</details>
		</template>
	</section>
</template>

<script>
import { testFilter } from '../../api/userContentFilters.js'

export default {
	name: 'PersonalFilterTestPanel',

	props: {
		domain: { type: String, required: true },
		draft: { type: Object, required: true },
	},

	data() {
		return { url: '', running: false, error: '', report: null }
	},

	computed: {
		metaFields() {
			if (!this.report || !this.report.result) {
				return []
			}
			const r = this.report.result
			return [
				{ key: 'title', label: this.t('merlin', 'Title'), value: r.title },
				{ key: 'author', label: this.t('merlin', 'Author'), value: r.author },
				{ key: 'publishedAt', label: this.t('merlin', 'Date'), value: r.publishedAt },
				{ key: 'excerpt', label: this.t('merlin', 'Teaser'), value: r.excerpt },
				{ key: 'category', label: this.t('merlin', 'Category'), value: r.category },
				{ key: 'imageUrl', label: this.t('merlin', 'Image'), value: r.imageUrl },
			]
		},
	},

	methods: {
		/** Drei mögliche Herkünfte (statt der zwei in der Admin-Oberfläche). */
		originLabel(origin) {
			if (origin === 'user') {
				return this.t('merlin', 'yours')
			}
			if (origin === 'admin') {
				return this.t('merlin', 'admin default')
			}
			return this.t('merlin', 'built-in')
		},

		formatAttributes(attributes) {
			return Object.keys(attributes || {})
				.map(name => ` ${name}="${attributes[name]}"`)
				.join('')
		},

		async run() {
			this.running = true
			this.error = ''
			this.report = null
			try {
				// Entwurf mitschicken: der Nutzer soll einen XPath prüfen können,
				// ohne ihn vorher zu speichern.
				this.report = await testFilter(this.domain, this.url.trim(), this.draft)
			} catch (e) {
				const data = e.response ? e.response.data : null
				if (data && Array.isArray(data.errors) && data.errors.length) {
					this.error = `${data.message || ''} ${data.errors.map(x => x.message).join(' · ')}`.trim()
				} else {
					this.error = (data && data.message) || e.message || String(e)
				}
				if (data && Array.isArray(data.trace) && data.trace.length) {
					this.report = {
						trace: data.trace,
						draft: !!data.draft,
						result: null,
						summary: {
							rules: data.trace.length,
							misses: data.trace.filter(x => x.matches === 0 && !x.error).length,
							errors: data.trace.filter(x => !!x.error).length,
						},
					}
				}
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.test-panel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 14px 16px;
	margin-top: 20px;
	background-color: var(--color-background-hover);
}

.test-panel h4 {
	margin: 0 0 4px;
}

.test-panel__hint {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	max-width: 70ch;
}

.test-panel__form {
	display: flex;
	gap: 8px;
	margin: 10px 0;
}

.test-panel__form input {
	flex: 1;
	min-width: 0;
	border-radius: var(--border-radius-large, 8px);
}

.test-panel__form button {
	border-radius: var(--border-radius-pill, 20px);
	padding: 7px 18px;
	border: 1px solid var(--color-primary-element, #0082c9);
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	cursor: pointer;
	font-weight: 600;
	font-size: 0.9em;
}

.test-panel__form button:hover:not(:disabled) {
	background-color: var(--color-primary-element-hover, #006ba3);
}

.test-panel__form button:disabled {
	opacity: 0.6;
	cursor: default;
}

.test-panel__error {
	background-color: var(--color-error, #e9322d);
	color: #fff;
	padding: 6px 10px;
	border-radius: var(--border-radius, 4px);
	margin-bottom: 8px;
}

.test-panel__summary {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	font-size: 0.85em;
	margin-bottom: 8px;
}

.test-panel__warn {
	color: var(--color-warning-text, #c17d11);
	font-weight: bold;
}

.test-panel__bad {
	color: var(--color-error-text, #c0392b);
	font-weight: bold;
}

.test-panel__draft {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.test-panel__trace {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.8em;
	margin-bottom: 10px;
}

.test-panel__trace th,
.test-panel__trace td {
	border-bottom: 1px solid var(--color-border);
	padding: 3px 6px;
	text-align: start;
	vertical-align: top;
}

.test-panel__trace code {
	font-family: monospace;
	word-break: break-all;
}

.test-panel__num {
	text-align: end;
	white-space: nowrap;
}

.test-panel__row--miss {
	background-color: var(--color-background-hover);
}

.test-panel__row--miss .test-panel__num {
	color: var(--color-warning-text, #c17d11);
	font-weight: bold;
}

.test-panel__row--error {
	background-color: var(--color-error, #e9322d);
	color: #fff;
}

.test-panel__rowerror {
	font-size: 0.9em;
}

.test-panel__meta {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px 12px;
	font-size: 0.85em;
	margin: 0 0 8px;
}

.test-panel__meta dt {
	color: var(--color-text-maxcontrast);
}

.test-panel__meta dd {
	margin: 0;
	word-break: break-word;
}

.test-panel__missing {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.test-panel__content summary {
	cursor: pointer;
	font-size: 0.9em;
}

.test-panel__article {
	max-height: 420px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	padding: 10px;
	margin-top: 6px;
	background-color: var(--color-main-background);
}

.test-panel__article :deep(img) {
	max-width: 100%;
	height: auto;
}
</style>
