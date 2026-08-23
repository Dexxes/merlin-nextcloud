<template>
	<div class="merlin-site-credentials">
		<h2>{{ t('merlin', 'Paywall subscriptions') }}</h2>
		<p class="merlin-site-credentials__intro">
			{{ t('merlin', 'For websites with a subscriber paywall (like Tagesspiegel Plus), you can store your own subscription login here so Merlin can save the full article instead of just the paywalled excerpt. Your password is stored encrypted and only used to log in on your behalf.') }}
		</p>

		<div v-if="error" class="merlin-site-credentials__error">
			<AlertCircleOutline :size="20" />
			<span>{{ error }}</span>
			<button class="merlin-site-credentials__dismiss" @click="error = ''">
				<Close :size="16" />
			</button>
		</div>

		<div v-if="notice" class="merlin-site-credentials__notice">
			<Check :size="20" />
			<span>{{ notice }}</span>
		</div>

		<p v-if="!domains.length" class="merlin-site-credentials__empty">
			{{ t('merlin', 'No supported paywall websites yet.') }}
		</p>

		<ul v-else class="merlin-site-credentials__list">
			<li v-for="entry in domains" :key="entry.domain" class="merlin-site-credentials__row">
				<div class="merlin-site-credentials__info">
					<span class="merlin-site-credentials__domain">{{ entry.domain }}</span>
					<span class="merlin-site-credentials__status" :class="statusClass(entry)">
						<LockOutline v-if="entry.status === 'ok'" :size="16" />
						<AlertCircleOutline v-else-if="entry.credential" :size="16" />
						<LockOffOutline v-else :size="16" />
						{{ statusLabel(entry) }}
					</span>
				</div>

				<div class="merlin-site-credentials__actions">
					<button
						class="merlin-site-credentials__link"
						:disabled="saving"
						@click="toggleForm(entry.domain)">
						{{ entry.credential ? t('merlin', 'Update login') : t('merlin', 'Connect') }}
					</button>
					<button
						v-if="entry.credential"
						class="merlin-site-credentials__link merlin-site-credentials__link--danger"
						:disabled="saving"
						@click="remove(entry.domain)">
						<TrashCanOutline :size="16" />
						{{ t('merlin', 'Remove') }}
					</button>
				</div>

				<form
					v-if="editingDomain === entry.domain"
					class="merlin-site-credentials__form"
					@submit.prevent="save(entry.domain)">
					<label :for="`merlin-cred-username-${entry.domain}`">
						{{ t('merlin', 'Email or username') }}
					</label>
					<input
						:id="`merlin-cred-username-${entry.domain}`"
						v-model="form.username"
						type="text"
						autocomplete="username"
						required>

					<label :for="`merlin-cred-password-${entry.domain}`">
						{{ t('merlin', 'Password') }}
					</label>
					<input
						:id="`merlin-cred-password-${entry.domain}`"
						v-model="form.password"
						type="password"
						autocomplete="current-password"
						required>

					<div class="merlin-site-credentials__form-actions">
						<button type="submit" :disabled="saving">
							{{ saving ? t('merlin', 'Checking…') : t('merlin', 'Save and log in') }}
						</button>
						<button type="button" :disabled="saving" @click="cancelForm">
							{{ t('merlin', 'Cancel') }}
						</button>
					</div>
				</form>
			</li>
		</ul>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import LockOffOutline from 'vue-material-design-icons/LockOffOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import {
	deleteSiteCredential,
	listSiteCredentials,
	saveSiteCredential,
} from '../../api/siteCredentials.js'

export default {
	name: 'PersonalSiteCredentials',

	components: {
		AlertCircleOutline,
		Check,
		Close,
		LockOffOutline,
		LockOutline,
		TrashCanOutline,
	},

	data() {
		// Erststand kommt aus PersonalSettings::getForm(); ohne ihn wüsste die
		// Oberfläche erst nach einem Roundtrip, welche Domains Paywall-Login
		// überhaupt unterstützen.
		const initial = loadState('merlin', 'siteCredentials', null) || {}
		return {
			credentials: initial.credentials || [],
			availableDomains: initial.availableDomains || [],
			editingDomain: null,
			form: { username: '', password: '' },
			saving: false,
			error: '',
			notice: '',
		}
	},

	computed: {
		/** Verbundene Domains zuerst, Rest alphabetisch. */
		domains() {
			const byDomain = new Map(this.credentials.map(c => [c.domain, c]))
			return [...this.availableDomains]
				.sort((a, b) => a.localeCompare(b))
				.map(domain => ({ domain, credential: byDomain.get(domain) || null, status: byDomain.get(domain)?.status || null }))
				.sort((a, b) => (b.credential ? 1 : 0) - (a.credential ? 1 : 0))
		},
	},

	mounted() {
		if (!this.availableDomains.length && !this.credentials.length) {
			this.refresh()
		}
	},

	methods: {
		async refresh() {
			try {
				const data = await listSiteCredentials()
				this.credentials = data.credentials
				this.availableDomains = data.availableDomains
			} catch (e) {
				this.fail(e)
			}
		},

		statusLabel(entry) {
			if (!entry.credential) {
				return this.t('merlin', 'Not connected')
			}
			switch (entry.status) {
			case 'ok':
				return this.t('merlin', 'Connected')
			case 'invalid_credentials':
				return this.t('merlin', 'Login failed – check your password')
			case 'login_flow_broken':
				return this.t('merlin', 'Login is temporarily unavailable')
			default:
				return this.t('merlin', 'Not checked yet')
			}
		},

		statusClass(entry) {
			if (!entry.credential) {
				return 'merlin-site-credentials__status--none'
			}
			if (entry.status === 'ok') {
				return 'merlin-site-credentials__status--ok'
			}
			return 'merlin-site-credentials__status--error'
		},

		toggleForm(domain) {
			if (this.editingDomain === domain) {
				this.cancelForm()
				return
			}
			this.editingDomain = domain
			this.form = { username: '', password: '' }
			this.notice = ''
		},

		cancelForm() {
			this.editingDomain = null
			this.form = { username: '', password: '' }
		},

		async save(domain) {
			this.saving = true
			this.error = ''
			this.notice = ''
			try {
				await saveSiteCredential(domain, this.form.username, this.form.password)
				this.notice = this.t('merlin', 'Connected. Merlin will use this login for {domain} from now on.', { domain })
				this.cancelForm()
				await this.refresh()
			} catch (e) {
				this.fail(e)
			} finally {
				this.saving = false
			}
		},

		async remove(domain) {
			this.saving = true
			this.error = ''
			this.notice = ''
			try {
				await deleteSiteCredential(domain)
				this.credentials = this.credentials.filter(c => c.domain !== domain)
				this.notice = this.t('merlin', 'Login removed.')
			} catch (e) {
				this.fail(e)
			} finally {
				this.saving = false
			}
		},

		fail(e) {
			const data = e.response ? e.response.data : null
			this.error = (data && data.message) || e.message || String(e)
		},
	},
}
</script>

<style scoped>
.merlin-site-credentials {
	max-width: 1200px;
	margin-bottom: 32px;
	padding-bottom: 24px;
	border-bottom: 1px solid var(--color-border);
}

.merlin-site-credentials h2 {
	margin-bottom: 4px;
	font-size: 1.3rem;
}

.merlin-site-credentials__intro {
	max-width: 70ch;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 20px;
	line-height: 1.5;
}

.merlin-site-credentials__error,
.merlin-site-credentials__notice {
	display: flex;
	gap: 10px;
	align-items: flex-start;
	padding: 10px 14px;
	border-radius: var(--border-radius-large, 8px);
	margin-bottom: 16px;
	font-size: 0.95em;
}

.merlin-site-credentials__error {
	background-color: var(--color-error, #e9322d);
	color: #fff;
}

.merlin-site-credentials__notice {
	background-color: var(--color-success, #46ba61);
	color: #fff;
}

.merlin-site-credentials__dismiss {
	margin-inline-start: auto;
	background: transparent;
	border: none;
	color: inherit;
	cursor: pointer;
	padding: 2px 4px;
	border-radius: var(--border-radius, 4px);
	display: flex;
}

.merlin-site-credentials__dismiss:hover {
	background-color: rgba(255, 255, 255, 0.2);
}

.merlin-site-credentials__empty {
	color: var(--color-text-maxcontrast);
	padding: 12px 0;
}

.merlin-site-credentials__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.merlin-site-credentials__row {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 10px 14px;
	background-color: var(--color-main-background);
}

.merlin-site-credentials__info {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.merlin-site-credentials__domain {
	font-weight: 600;
}

.merlin-site-credentials__status {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.merlin-site-credentials__status--ok {
	color: var(--color-success, #46ba61);
}

.merlin-site-credentials__status--error {
	color: var(--color-error, #e9322d);
}

.merlin-site-credentials__actions {
	display: flex;
	gap: 12px;
	margin-top: 6px;
}

.merlin-site-credentials__link {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: transparent;
	border: none;
	padding: 0;
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	font-size: 0.9em;
	text-decoration: underline;
}

.merlin-site-credentials__link:disabled {
	opacity: 0.5;
	cursor: default;
}

.merlin-site-credentials__link--danger {
	color: var(--color-error, #e9322d);
}

.merlin-site-credentials__form {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 360px;
}

.merlin-site-credentials__form label {
	font-size: 0.85em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-top: 6px;
}

.merlin-site-credentials__form input {
	border-radius: var(--border-radius-large, 8px);
}

.merlin-site-credentials__form-actions {
	display: flex;
	gap: 8px;
	margin-top: 10px;
}
</style>
