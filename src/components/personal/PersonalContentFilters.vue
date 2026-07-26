<template>
	<div class="merlin-personal">
		<h2>{{ t('merlin', 'My content filters') }}</h2>
		<p class="merlin-personal__intro">
			{{ t('merlin', 'Content filters clean up saved articles per website. Rules from the app and your administrator apply to everyone; here you can add your own private rules on top — only you will see them, and they take priority over everyone else\'s.') }}
		</p>

		<div v-if="error" class="merlin-personal__error">
			<AlertCircleOutline :size="20" />
			<span>{{ error }}</span>
			<button class="merlin-personal__dismiss" @click="error = ''">
				<Close :size="16" />
			</button>
		</div>

		<div v-if="notice" class="merlin-personal__notice">
			<Check :size="20" />
			<span>{{ notice }}</span>
		</div>

		<div class="merlin-personal__layout">
			<FilterList
				:filters="listFilterProps"
				:selected="selected"
				:busy="loadingDetail"
				:show-import="false"
				@select="select"
				@create="createFilter" />

			<div class="merlin-personal__detail">
				<p v-if="!selected" class="merlin-personal__empty">
					{{ t('merlin', 'Select a website on the left, or add a new one.') }}
				</p>

				<p v-else-if="loadingDetail" class="merlin-personal__empty">
					{{ t('merlin', 'Loading…') }}
				</p>

				<PersonalFilterEditor
					v-else-if="detail"
					:key="detail.domain"
					:detail="detail"
					:schema="schema"
					:draft="draft"
					:saving="saving"
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
import FilterList from '../admin/FilterList.vue'
import PersonalFilterEditor from './PersonalFilterEditor.vue'
import {
	deleteFilter,
	getFilter,
	listFilters,
	saveFilter,
} from '../../api/userContentFilters.js'
import { emptyDraft, normalizeDraft } from '../admin/draft.js'

export default {
	name: 'PersonalContentFilters',

	components: {
		AlertCircleOutline,
		Check,
		Close,
		FilterList,
		PersonalFilterEditor,
	},

	data() {
		// Erststand kommt aus PersonalSettings::getForm(); ohne ihn wüsste die
		// Oberfläche erst nach einem Roundtrip, welche Domains es gibt.
		const initial = loadState('merlin', 'userContentFilters', null) || {}
		return {
			domains: initial.domains || [],
			schema: initial.schema || null,
			selected: null,
			detail: null,
			draft: null,
			loadingDetail: false,
			saving: false,
			error: '',
			notice: '',
		}
	},

	computed: {
		/**
		 * FilterList.vue kennt nur hasBundle/hasCustom (für die Badges
		 * "built-in"/"own"). Hier bedeutet "own" bewusst "hasOwnOverride" –
		 * NICHT, ob der Admin einen Custom-Filter hat (das wäre für diesen
		 * Nutzer irrelevante, weil unveränderliche Information).
		 */
		listFilterProps() {
			return this.domains.map(d => ({
				domain: d.domain,
				hasBundle: d.hasBundle,
				hasCustom: d.hasOwnOverride,
			}))
		},
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
				this.domains = data.domains
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
				this.draft = normalizeDraft(this.detail.own, this.schema)
			} catch (e) {
				// Eine Domain ohne jeden Filter (Bundle/Admin/eigen) ist kein Fehler:
				// der Nutzer hat sie über "Website hinzufügen" gerade erfunden.
				if (e.response && e.response.status === 404) {
					this.detail = { domain, reference: null, own: null, merged: null }
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
			if (!this.domains.some(d => d.domain === clean)) {
				this.domains = [...this.domains, { domain: clean, hasBundle: false, hasAdminCustom: false, hasOwnOverride: false }]
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
				this.draft = normalizeDraft(this.detail.own, this.schema)
				this.markHasOwnOverride(this.selected, true)
				this.notice = this.t('merlin', 'Your filter was saved.')
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
				this.markHasOwnOverride(this.selected, false)
				const entry = this.domains.find(d => d.domain === this.selected)
				if (entry && !entry.hasBundle && !entry.hasAdminCustom) {
					this.domains = this.domains.filter(d => d.domain !== this.selected)
					this.selected = null
					this.detail = null
					this.draft = null
				} else {
					await this.select(this.selected)
				}
				this.notice = this.t('merlin', 'Your rules were deleted.')
			} catch (e) {
				this.fail(e)
			} finally {
				this.saving = false
			}
		},

		markHasOwnOverride(domain, value) {
			this.domains = this.domains.map(d =>
				d.domain === domain ? { ...d, hasOwnOverride: value } : d,
			)
		},

		fail(e) {
			const data = e.response ? e.response.data : null
			if (data && Array.isArray(data.errors) && data.errors.length) {
				const details = data.errors
					.map(err => (err.line ? `${this.t('merlin', 'Line')} ${err.line}: ${err.message}` : err.message))
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
.merlin-personal {
	max-width: 1100px;
}

.merlin-personal__intro {
	max-width: 70ch;
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.merlin-personal__error,
.merlin-personal__notice {
	display: flex;
	gap: 10px;
	align-items: flex-start;
	padding: 10px 12px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
	margin-bottom: 12px;
}

.merlin-personal__error {
	background-color: var(--color-error, #e9322d);
	color: #fff;
}

.merlin-personal__notice {
	background-color: var(--color-success, #46ba61);
	color: #fff;
}

.merlin-personal__dismiss {
	margin-inline-start: auto;
	background: transparent;
	border: none;
	color: inherit;
	cursor: pointer;
	padding: 0 4px;
}

.merlin-personal__layout {
	display: grid;
	grid-template-columns: minmax(220px, 300px) 1fr;
	gap: 20px;
	align-items: start;
}

@media (max-width: 900px) {
	.merlin-personal__layout {
		grid-template-columns: 1fr;
	}
}

.merlin-personal__detail {
	min-width: 0;
}

.merlin-personal__empty {
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
}
</style>
