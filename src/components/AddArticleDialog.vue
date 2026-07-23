<template>
	<NcDialog
		:name="t('merlin', 'Add Article')"
		:open="true"
		@close="$emit('close')">
		<div class="add-article-dialog">
			<p>{{ t('merlin', 'Enter the URL of the article you want to save:') }}</p>

			<input
				v-model="url"
				type="url"
				:placeholder="t('merlin', 'https://example.com/article')"
				class="url-input"
				@keyup.enter="handleAddArticle">

			<div class="dialog-actions">
				<NcButton @click="$emit('close')">
					{{ t('merlin', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!isValidUrl || loading"
					@click="handleAddArticle">
					<template v-if="loading" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('merlin', 'Add Article') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { mapActions } from 'vuex'
import { showSuccess, showError } from '@nextcloud/dialogs'
import {
    NcDialog,
    NcButton,
    NcLoadingIcon
} from '@nextcloud/vue'

export default {
	name: 'AddArticleDialog',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
	},

	props: {
		initialUrl: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			url: this.initialUrl,
			loading: false,
		}
	},

	computed: {
		isValidUrl() {
			try {
				const urlObj = new URL(this.url)
				return urlObj.protocol === 'http:' || urlObj.protocol === 'https:'
			} catch {
				return false
			}
		},
	},

	methods: {
		...mapActions(['addArticle']),

		async handleAddArticle() {
			if (!this.isValidUrl) return

			this.loading = true
			try {
				await this.addArticle(this.url)
				showSuccess(this.t('merlin', 'Article added successfully'))
				this.$emit('added')
			} catch (error) {
				showError(this.t('merlin', 'Failed to add article: {error}', { error: error.message }))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.add-article-dialog {
	padding: 20px;
}

.add-article-dialog p {
	margin-bottom: 16px;
}

.url-input {
	width: 100%;
	padding: 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 16px; /* min. 16px verhindert automatischen iOS-Zoom beim Fokussieren */
	margin-bottom: 20px;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
