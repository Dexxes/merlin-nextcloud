<template>
	<div class="comment-item" :class="{ 'comment-item--deleted': comment.deleted }">
		<p v-if="comment.deleted" class="ci-deleted">
			{{ t('merlin', 'Comment deleted') }}
		</p>
		<template v-else>
			<div class="ci-meta">
				<span class="ci-author">{{ comment.authorName }}</span>
				<span v-if="comment.authorType === 'owner'" class="ci-badge">{{ ownerLabel }}</span>
				<span v-if="replyTo" class="ci-reply-to">→ {{ replyTo }}</span>
				<time class="ci-time" :datetime="comment.createdAt" :title="absolute(comment.createdAt)">{{ relative(comment.createdAt) }}</time>
				<span v-if="comment.edited" class="ci-edited">{{ t('merlin', '(edited)') }}</span>
			</div>

			<form v-if="editing" class="ci-edit" @submit.prevent="save">
				<textarea ref="input"
					v-model="draft"
					class="ci-input"
					rows="3"
					maxlength="5000"
					@keydown.esc.prevent="editing = false" />
				<div class="ci-actions">
					<button type="button" class="ci-btn" @click="editing = false">
						{{ t('merlin', 'Cancel') }}
					</button>
					<button type="submit" class="ci-btn ci-btn--primary" :disabled="busy || !draft.trim()">
						{{ t('merlin', 'Save') }}
					</button>
				</div>
			</form>
			<!-- Klartext: nur Text-Interpolation, nie v-html. -->
			<p v-else class="ci-body">{{ comment.body }}</p>

			<div v-if="!editing" class="ci-links">
				<button v-if="canReply" type="button" class="ci-link" @click="$emit('reply')">
					{{ t('merlin', 'Reply') }}
				</button>
				<button v-if="canEdit" type="button" class="ci-link" @click="startEdit">
					{{ t('merlin', 'Edit') }}
				</button>
				<button v-if="canDelete" type="button" class="ci-link ci-link--danger" :disabled="busy" @click="$emit('delete')">
					{{ t('merlin', 'Delete') }}
				</button>
			</div>
		</template>
	</div>
</template>

<script>
export default {
	name: 'CommentItem',

	props: {
		comment: { type: Object, required: true },
		replyTo: { type: String, default: '' },
		canEdit: { type: Boolean, default: false },
		canDelete: { type: Boolean, default: false },
		canReply: { type: Boolean, default: false },
		ownerLabel: { type: String, default: '' },
		busy: { type: Boolean, default: false },
	},

	emits: ['edit', 'delete', 'reply'],

	data() {
		return { editing: false, draft: '' }
	},

	methods: {
		startEdit() {
			this.draft = this.comment.body
			this.editing = true
			this.$nextTick(() => this.$refs.input?.focus())
		},

		save() {
			const body = this.draft.trim()
			if (!body) return
			this.$emit('edit', body)
			this.editing = false
		},

		absolute(iso) {
			try {
				return new Date(iso).toLocaleString()
			} catch {
				return iso
			}
		},

		relative(iso) {
			const date = new Date(iso)
			const seconds = Math.round((Date.now() - date.getTime()) / 1000)
			if (Number.isNaN(seconds)) return ''
			if (seconds < 60) return this.t('merlin', 'just now')
			const minutes = Math.round(seconds / 60)
			if (minutes < 60) return this.n('merlin', '%n minute ago', '%n minutes ago', minutes)
			const hours = Math.round(minutes / 60)
			if (hours < 24) return this.n('merlin', '%n hour ago', '%n hours ago', hours)
			return date.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.comment-item {
	padding: 4px 0;
}

.ci-meta {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 6px;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #666);
}

.ci-author {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-main-text, #222);
}

.ci-badge {
	padding: 0 6px;
	border-radius: 8px;
	background: var(--color-primary-element-light, #e6f3fa);
	color: var(--color-primary-element-light-text, #00669e);
	font-size: 11px;
}

.ci-body {
	margin: 2px 0 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	line-height: 1.45;
}

.ci-deleted {
	margin: 0;
	font-style: italic;
	color: var(--color-text-maxcontrast, #666);
}

.ci-links {
	display: flex;
	gap: 12px;
	margin-top: 2px;
}

.ci-link {
	border: none;
	background: none;
	padding: 0;
	color: var(--color-text-maxcontrast, #666);
	cursor: pointer;
	font: inherit;
	font-size: 12px;
}

.ci-link:hover {
	color: var(--color-main-text, #222);
}

.ci-link--danger:hover {
	color: var(--color-error, #c00);
}

.ci-edit {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 4px;
}

.ci-input {
	width: 100%;
	box-sizing: border-box;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: 8px;
	background: var(--color-main-background, #fff);
	color: inherit;
	font: inherit;
}

.ci-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.ci-btn {
	padding: 4px 12px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: 14px;
	background: var(--color-background-hover, #f5f5f5);
	color: inherit;
	cursor: pointer;
	font: inherit;
	font-size: 13px;
}

.ci-btn--primary {
	background: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}
</style>
