<template>
	<NcDialog
		:name="t('merlin', 'Public link')"
		:open="true"
		@close="$emit('close')">
		<div class="share-link-dialog">
			<NcLoadingIcon v-if="loading" :size="32" class="share-loading" />

			<template v-else>
				<!-- Kein Link vorhanden: Anlegen mit optionalem Passwort/Ablauf -->
				<div v-if="!share.enabled" class="share-create">
					<p>{{ t('merlin', 'Anyone with this link can read the article — including your highlights.') }}</p>

					<label class="share-checkbox">
						<input v-model="createPasswordEnabled" type="checkbox">
						{{ t('merlin', 'Protect with a password') }}
					</label>
					<input
						v-if="createPasswordEnabled"
						v-model="createPassword"
						type="password"
						class="share-input"
						:placeholder="t('merlin', 'Password')">

					<label class="share-checkbox">
						<input v-model="createExpiryEnabled" type="checkbox">
						{{ t('merlin', 'Link expires on a specific date') }}
					</label>
					<input
						v-if="createExpiryEnabled"
						v-model="createExpiry"
						type="date"
						class="share-input">

					<div class="dialog-actions">
						<NcButton variant="primary" :disabled="busy" @click="handleCreate">
							<template v-if="busy" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							{{ t('merlin', 'Create public link') }}
						</NcButton>
					</div>
				</div>

				<!-- Link vorhanden: verwalten -->
				<div v-else class="share-manage">
					<div class="share-link-row">
						<input :value="share.url" class="share-input share-link-input" readonly @focus="$event.target.select()">
						<NcButton :title="t('merlin', 'Copy link')" @click="copyLink">
							<template #icon>
								<ContentCopy :size="18" />
							</template>
						</NcButton>
					</div>

					<section class="share-section">
						<label class="share-checkbox">
							<input :checked="share.hasPassword" type="checkbox" @change="onTogglePassword">
							{{ t('merlin', 'Protect with a password') }}
						</label>
						<div v-if="passwordEditing" class="share-inline-form">
							<input
								v-model="newPassword"
								type="password"
								class="share-input"
								:placeholder="t('merlin', 'New password')">
							<NcButton :disabled="busy || !newPassword" @click="handleSetPassword">
								{{ t('merlin', 'Save') }}
							</NcButton>
						</div>
					</section>

					<section class="share-section">
						<label class="share-checkbox">
							<input :checked="!!share.expiresAt" type="checkbox" @change="onToggleExpiry">
							{{ t('merlin', 'Link expires on a specific date') }}
						</label>
						<div v-if="expiryEditing" class="share-inline-form">
							<input v-model="newExpiry" type="date" class="share-input">
							<NcButton :disabled="busy || !newExpiry" @click="handleSetExpiry">
								{{ t('merlin', 'Save') }}
							</NcButton>
						</div>
						<p v-else-if="share.expiresAt" class="share-hint">
							{{ t('merlin', 'Expires on {date}', { date: formatDate(share.expiresAt) }) }}
						</p>
					</section>

					<div class="dialog-actions dialog-actions--split">
						<NcButton :disabled="busy" @click="handleRegenerate">
							{{ t('merlin', 'Regenerate link') }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="handleRevoke">
							{{ t('merlin', 'Revoke link') }}
						</NcButton>
					</div>
				</div>
			</template>
		</div>
	</NcDialog>
</template>

<script>
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcDialog, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import * as sharesAPI from '../api/shares'

export default {
	name: 'ShareLinkDialog',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		ContentCopy,
	},

	props: {
		articleId: {
			type: Number,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			busy: false,
			share: { enabled: false },
			createPasswordEnabled: false,
			createPassword: '',
			createExpiryEnabled: false,
			createExpiry: '',
			passwordEditing: false,
			newPassword: '',
			expiryEditing: false,
			newExpiry: '',
		}
	},

	async mounted() {
		await this.refresh()
	},

	methods: {
		async refresh() {
			this.loading = true
			try {
				this.share = await sharesAPI.getShare(this.articleId)
			} catch (error) {
				showError(this.t('merlin', 'Failed to load share status'))
				this.share = { enabled: false }
			} finally {
				this.loading = false
			}
		},

		async handleCreate() {
			this.busy = true
			try {
				this.share = await sharesAPI.createShare(this.articleId, {
					password: this.createPasswordEnabled ? this.createPassword : null,
					expiresAt: this.createExpiryEnabled && this.createExpiry ? this.createExpiry : null,
				})
				showSuccess(this.t('merlin', 'Public link created'))
			} catch (error) {
				showError(this.t('merlin', 'Failed to create public link'))
			} finally {
				this.busy = false
			}
		},

		onTogglePassword(event) {
			if (event.target.checked) {
				this.passwordEditing = true
				this.newPassword = ''
			} else {
				this.passwordEditing = false
				this.confirmAndUpdate({ password: null }, this.t('merlin', 'Password protection removed'))
			}
		},

		async handleSetPassword() {
			if (!this.newPassword) return
			await this.confirmAndUpdate({ password: this.newPassword }, this.t('merlin', 'Password updated'))
			this.passwordEditing = false
			this.newPassword = ''
		},

		onToggleExpiry(event) {
			if (event.target.checked) {
				this.expiryEditing = true
				this.newExpiry = ''
			} else {
				this.expiryEditing = false
				this.confirmAndUpdate({ expiresAt: null }, this.t('merlin', 'Expiry date removed'))
			}
		},

		async handleSetExpiry() {
			if (!this.newExpiry) return
			await this.confirmAndUpdate({ expiresAt: this.newExpiry }, this.t('merlin', 'Expiry date updated'))
			this.expiryEditing = false
			this.newExpiry = ''
		},

		async confirmAndUpdate(fields, successMessage) {
			this.busy = true
			try {
				this.share = await sharesAPI.updateShare(this.articleId, fields)
				showSuccess(successMessage)
			} catch (error) {
				showError(this.t('merlin', 'Failed to update public link'))
				await this.refresh()
			} finally {
				this.busy = false
			}
		},

		async handleRegenerate() {
			if (!confirm(this.t('merlin', 'The old link will stop working immediately. Continue?'))) return
			this.busy = true
			try {
				this.share = await sharesAPI.regenerateShare(this.articleId)
				showSuccess(this.t('merlin', 'Link regenerated'))
			} catch (error) {
				showError(this.t('merlin', 'Failed to regenerate link'))
			} finally {
				this.busy = false
			}
		},

		async handleRevoke() {
			if (!confirm(this.t('merlin', 'Anyone with the old link will lose access. Continue?'))) return
			this.busy = true
			try {
				await sharesAPI.deleteShare(this.articleId)
				this.share = { enabled: false }
				showSuccess(this.t('merlin', 'Public link revoked'))
			} catch (error) {
				showError(this.t('merlin', 'Failed to revoke public link'))
			} finally {
				this.busy = false
			}
		},

		async copyLink() {
			try {
				await navigator.clipboard.writeText(this.share.url)
				showSuccess(this.t('merlin', 'Link copied to clipboard'))
			} catch {
				showError(this.t('merlin', 'Could not copy link'))
			}
		},

		formatDate(dateString) {
			return new Intl.DateTimeFormat('default', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(dateString))
		},
	},
}
</script>

<style scoped>
.share-link-dialog {
	padding: 20px;
	min-width: 340px;
}

.share-loading {
	display: flex;
	justify-content: center;
	padding: 30px 0;
}

.share-create p,
.share-create .share-hint {
	color: var(--color-text-lighter);
	margin: 0 0 12px 0;
}

.share-checkbox {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 10px 0;
	cursor: pointer;
}

.share-input {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-sizing: border-box;
	margin-bottom: 8px;
}

.share-link-row {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.share-link-input {
	flex: 1;
	margin-bottom: 0;
	font-family: monospace;
	font-size: 0.85em;
}

.share-section {
	margin: 16px 0;
	padding-top: 12px;
	border-top: 1px solid var(--color-border, #eee);
}

.share-inline-form {
	display: flex;
	gap: 8px;
	align-items: center;
}

.share-inline-form .share-input {
	margin-bottom: 0;
}

.share-hint {
	font-size: 0.9em;
	margin: 4px 0 0 0;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
}

.dialog-actions--split {
	justify-content: space-between;
}
</style>
