<template>
	<div class="merlin-admin merlin-retention">
		<h2>{{ t('merlin', 'Retention') }}</h2>
		<p class="merlin-admin__intro">
			{{ t('merlin', 'Archived articles are deleted automatically once they have been in the archive longer than the period set here. Articles that are not archived are never deleted. Users can choose a shorter period for themselves. 0 means no limit.') }}
		</p>

		<div v-if="error" class="merlin-admin__error">
			<span>{{ error }}</span>
		</div>
		<div v-if="notice" class="merlin-admin__notice">
			<span>{{ notice }}</span>
		</div>

		<div class="merlin-retention__fields">
			<label class="merlin-retention__field">
				<span>{{ t('merlin', 'Delete archived articles after (days)') }}</span>
				<input
					v-model.number="days"
					type="number"
					min="0"
					max="36500"
					step="1"
					:disabled="saving">
			</label>
			<label class="merlin-retention__field">
				<span>{{ t('merlin', 'Delete archived favorites after (days)') }}</span>
				<input
					v-model.number="favoritesDays"
					type="number"
					min="0"
					max="36500"
					step="1"
					:disabled="saving">
			</label>
		</div>

		<div v-if="pendingCount !== null" class="merlin-retention__confirm">
			<p>
				{{ n('merlin',
					'With these values, %n archived article will be deleted on the next daily run.',
					'With these values, %n archived articles will be deleted on the next daily run.',
					pendingCount) }}
			</p>
			<button class="primary" :disabled="saving" @click="save">
				{{ t('merlin', 'Save anyway') }}
			</button>
			<button :disabled="saving" @click="pendingCount = null">
				{{ t('merlin', 'Cancel') }}
			</button>
		</div>

		<button
			v-else
			class="primary"
			:disabled="saving || !dirty || !valid"
			@click="checkAndSave">
			{{ t('merlin', 'Save') }}
		</button>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import {
	previewAdminRetention,
	saveAdminRetention,
} from '../../api/retention.js'

const MAX_DAYS = 36500

export default {
	name: 'RetentionAdmin',

	data() {
		const initial = loadState('merlin', 'retentionAdmin', { days: 0, favoritesDays: 0 })
		return {
			saved: { ...initial },
			days: initial.days,
			favoritesDays: initial.favoritesDays,
			saving: false,
			error: '',
			notice: '',
			// Anzahl betroffener Artikel, solange die Rückfrage offen ist.
			pendingCount: null,
		}
	},

	computed: {
		dirty() {
			return this.days !== this.saved.days || this.favoritesDays !== this.saved.favoritesDays
		},
		valid() {
			return [this.days, this.favoritesDays].every(v => Number.isInteger(v) && v >= 0 && v <= MAX_DAYS)
		},
	},

	methods: {
		// Vor dem Speichern zählen, was der nächste Lauf löschen würde. Nur bei
		// betroffenen Artikeln nachfragen; sonst direkt speichern.
		async checkAndSave() {
			this.error = ''
			this.notice = ''
			this.saving = true
			try {
				const count = await previewAdminRetention(this.days, this.favoritesDays)
				if (count > 0) {
					this.pendingCount = count
					return
				}
			} catch (e) {
				this.error = this.t('merlin', 'Could not check how many articles are affected.')
				return
			} finally {
				this.saving = false
			}
			await this.save()
		},

		async save() {
			this.saving = true
			this.error = ''
			try {
				const result = await saveAdminRetention(this.days, this.favoritesDays)
				this.saved = { ...result }
				this.days = result.days
				this.favoritesDays = result.favoritesDays
				this.pendingCount = null
				this.notice = this.t('merlin', 'Retention saved')
			} catch (e) {
				this.error = this.t('merlin', 'Could not save the retention period.')
			} finally {
				this.saving = false
			}
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
	padding: 10px 12px;
	border-radius: var(--border-radius-large, 8px);
	margin-bottom: 12px;
	color: #fff;
}

.merlin-admin__error {
	background-color: var(--color-error, #e9322d);
}

.merlin-admin__notice {
	background-color: var(--color-success, #46ba61);
}

.merlin-retention__fields {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-bottom: 12px;
}

.merlin-retention__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.merlin-retention__field input {
	width: 12em;
}

.merlin-retention__confirm {
	max-width: 70ch;
	padding: 10px 12px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-warning, #eca700);
	color: var(--color-main-text);
}

.merlin-retention__confirm p {
	margin-bottom: 8px;
}
</style>
