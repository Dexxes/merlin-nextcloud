<template>
	<NcDialog
		:name="t('merlin', 'Retention period')"
		:open="true"
		@close="$emit('close', false)">
		<div class="retention-notice">
			<p v-for="line in lines" :key="line">
				{{ line }}
			</p>
			<p>{{ t('merlin', 'Articles that are not archived are kept. You can choose a shorter period in the settings.') }}</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('close', true)">
				{{ t('merlin', 'Open settings') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('close', false)">
				{{ t('merlin', 'Got it') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

/**
 * Einmaliger Hinweis auf die Löschfrist beim Öffnen der App. Erscheint, wenn
 * GET /api/settings retentionNoticeRequired meldet (eine Frist ist neu oder
 * kürzer geworden, siehe RetentionPolicy::noticeRequired()). App.vue bestätigt
 * ihn beim Schließen; close(true) öffnet zusätzlich die Einstellungen.
 */
export default {
	name: 'RetentionNoticeDialog',

	components: { NcButton, NcDialog },

	props: {
		days: { type: Number, default: 0 },
		favoritesDays: { type: Number, default: 0 },
	},

	emits: ['close'],

	computed: {
		lines() {
			return [
				this.days > 0
					? this.n('merlin', 'Archived articles are deleted %n day after archiving.', 'Archived articles are deleted %n days after archiving.', this.days)
					: this.t('merlin', 'Archived articles are kept without a time limit.'),
				this.favoritesDays > 0
					? this.n('merlin', 'Archived favorites are deleted %n day after archiving.', 'Archived favorites are deleted %n days after archiving.', this.favoritesDays)
					: this.t('merlin', 'Archived favorites are kept without a time limit.'),
			]
		},
	},
}
</script>

<style scoped>
.retention-notice p + p {
	margin-top: 8px;
}
</style>
