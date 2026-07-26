<template>
	<div class="merlin-admin">
		<h2>{{ t('merlin', 'Content filters') }}</h2>
		<p class="merlin-admin__intro">
			{{ t('merlin', 'Content filters clean up saved articles per website: they remove ads and navigation before the article is extracted, and can correct title, author or date. Filters shipped with the app can be extended here; your own rules are merged with them.') }}
		</p>

		<div v-if="error" class="merlin-admin__error">
			<AlertCircleOutline :size="20" />
			<span>{{ error }}</span>
			<button class="merlin-admin__dismiss" @click="error = ''">
				<Close :size="16" />
			</button>
		</div>

		<div v-if="notice" class="merlin-admin__notice">
			<Check :size="20" />
			<span>{{ notice }}</span>
		</div>

		<div class="merlin-admin__layout">
			<FilterList
				:filters="filters"
				:selected="selected"
				:busy="loadingDetail"
				@select="select"
				@create="createFilter"
				@import="importXml" />

			<div class="merlin-admin__detail">
				<p v-if="!selected" class="merlin-admin__empty">
					{{ t('merlin', 'Select a website on the left, or add a new one.') }}
				</p>

				<p v-else-if="loadingDetail" class="merlin-admin__empty">
					{{ t('merlin', 'Loading…') }}
				</p>

				<FilterEditor
					v-else-if="detail"
					:key="detail.domain"
					:detail="detail"
					:schema="schema"
					:draft="draft"
					:saving="saving"
					:writable="true"
					@save="save"
					@discard="reload"
					@remove="remove" />
			</div>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FilterEditor from './FilterEditor.vue'
import FilterList from './FilterList.vue'
import {
	deleteFilter,
	getFilter,
	importFilter,
	listFilters,
	saveFilter,
} from '../../api/contentFilters.js'
import { emptyDraft, normalizeDraft } from './draft.js'

export default {
	name: 'ContentFilterAdmin',

	components: {
		AlertCircleOutline,
		Check,
		Close,
		FilterEditor,
		FilterList,
	},

	data() {
		// Erststand kommt aus AdminSettings::getForm(); ohne ihn wüsste die
		// Oberfläche erst nach einem Roundtrip, ob überhaupt gespeichert werden kann.
		const initial = loadState('merlin', 'contentFilters', null) || {}
		return {
			filters: initial.filters || [],
			schema: initial.schema || null,
			selected: null,
			detail: null,
			draft: null,
			loadingDetail: false,
			saving: false,
			error: '',
			notice: '',
			// Zwischengespeicherter Import-Inhalt für die Überschreiben-Rückfrage.
			pendingImport: null,
		}
	},

	mounted() {
		if (!this.schema) {
			this.refreshList()
		}
	},

	methods: {
		async refreshList() {
			try {
				const data = await listFilters()
				this.filters = data.filters
				this.schema = data.schema
			} catch (e) {
				this.fail(e)
			}
		},

		async select(domain) {
			this.selected = domain
			this.notice = ''
			this.loadingDetail = true
			try {
				this.detail = await getFilter(domain)
				this.draft = normalizeDraft(this.detail.custom, this.schema)
			} catch (e) {
				// Eine Domain, für die es noch keine Datei gibt, ist kein Fehler:
				// der Nutzer hat sie über "Website hinzufügen" gerade erfunden.
				if (e.response && e.response.status === 404) {
					this.detail = { domain, bundle: null, custom: null, merged: null }
					this.draft = emptyDraft(this.schema)
				} else {
					this.fail(e)
					this.detail = null
				}
			} finally {
				this.loadingDetail = false
			}
		},

		reload() {
			if (this.selected) {
				this.select(this.selected)
			}
		},

		createFilter(domain) {
			const clean = String(domain || '').trim().toLowerCase()
			if (!clean) {
				return
			}
			if (!this.filters.some(f => f.domain === clean)) {
				this.filters = [...this.filters, { domain: clean, hasBundle: false, hasCustom: false }]
					.sort((a, b) => a.domain.localeCompare(b.domain))
			}
			this.select(clean)
		},

		async save(draft) {
			this.saving = true
			this.error = ''
			this.notice = ''
			try {
				this.detail = await saveFilter(this.selected, draft)
				this.draft = normalizeDraft(this.detail.custom, this.schema)
				this.markHasCustom(this.selected, true)
				this.notice = this.t('merlin', 'Filter saved.')
			} catch (e) {
				this.fail(e)
			} finally {
				this.saving = false
			}
		},

		async remove() {
			this.saving = true
			try {
				await deleteFilter(this.selected)
				this.markHasCustom(this.selected, false)
				// Domains ohne Bundle-Filter verschwinden mit ihrer Custom-Datei.
				const entry = this.filters.find(f => f.domain === this.selected)
				if (entry && !entry.hasBundle) {
					this.filters = this.filters.filter(f => f.domain !== this.selected)
					this.selected = null
					this.detail = null
					this.draft = null
				} else {
					await this.select(this.selected)
				}
				this.notice = this.t('merlin', 'Your own rules were deleted.')
			} catch (e) {
				this.fail(e)
			} finally {
				this.saving = false
			}
		},

		async importXml({ xml, domain, overwrite = false }) {
			this.error = ''
			this.notice = ''
			// Für den Fall, dass der Server mit 409 nachfragt: Inhalt merken.
			this.pendingImport = overwrite ? null : xml
			try {
				const data = await importFilter(xml, domain, overwrite)
				this.detail = data
				this.selected = data.domain
				this.draft = normalizeDraft(data.custom, this.schema)
				this.markHasCustom(data.domain, true)
				await this.refreshList()
				this.pendingImport = null
				this.notice = this.t('merlin', 'Filter imported.')
			} catch (e) {
				this.fail(e)
			}
		},

		markHasCustom(domain, value) {
			this.filters = this.filters.map(f =>
				f.domain === domain ? { ...f, hasCustom: value } : f,
			)
		},

		/**
		 * Fehlermeldungen des Servers durchreichen statt "Etwas ist
		 * schiefgelaufen": bei einem ungültigen Filter steht in errors[] die
		 * Zeilennummer, die der Admin braucht.
		 */
		fail(e) {
			const data = e.response ? e.response.data : null
			// 409: Der Domainname stammt aus der Datei und würde vorhandene Regeln
			// ersetzen. Nachfragen statt kommentarlos überschreiben.
			if (data && data.code === 'custom_filter_exists' && this.pendingImport) {
				const xml = this.pendingImport
				this.pendingImport = null
				// eslint-disable-next-line no-alert
				if (window.confirm(data.message)) {
					this.importXml({ xml, domain: data.domain, overwrite: true })
					return
				}
				this.error = this.t('merlin', 'Import cancelled.')
				return
			}
			if (data && Array.isArray(data.errors) && data.errors.length) {
				const details = data.errors
					.map(err => (err.line ? `Zeile ${err.line}: ${err.message}` : err.message))
					.join(' · ')
				this.error = `${data.message || ''} ${details}`.trim()
				return
			}
			this.error = (data && data.message) || e.message || String(e)
		},
	},
}
</script>

<style scoped>
.merlin-admin {
	max-width: 1100px;
}

.merlin-admin__intro {
	max-width: 70ch;
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.merlin-admin__error,
.merlin-admin__notice {
	display: flex;
	gap: 10px;
	align-items: flex-start;
	padding: 10px 12px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
	margin-bottom: 12px;
}

.merlin-admin__error {
	background-color: var(--color-error, #e9322d);
	color: #fff;
}

.merlin-admin__notice {
	background-color: var(--color-success, #46ba61);
	color: #fff;
}

.merlin-admin__dismiss {
	margin-inline-start: auto;
	background: transparent;
	border: none;
	color: inherit;
	cursor: pointer;
	padding: 0 4px;
}

.merlin-admin__layout {
	display: grid;
	grid-template-columns: minmax(220px, 300px) 1fr;
	gap: 20px;
	align-items: start;
}

@media (max-width: 900px) {
	.merlin-admin__layout {
		grid-template-columns: 1fr;
	}
}

.merlin-admin__detail {
	min-width: 0;
}

.merlin-admin__empty {
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
}
</style>
