<template>
	<div class="comment-item"
		:class="{ 'comment-item--deleted': comment.deleted, 'comment-item--deletable': canDelete && !comment.deleted }"
		:style="{ '--ci-color': authorColor }">
		<p v-if="comment.deleted" class="ci-deleted">
			{{ t('merlin', 'Comment deleted') }}
		</p>
		<template v-else>
			<div class="ci-meta">
				<span class="ci-author"><span class="ci-dot" aria-hidden="true" />{{ comment.authorName }}</span>
				<span v-if="comment.authorType === 'owner'" class="ci-badge">{{ ownerLabel }}</span>
				<span v-if="replyTo" class="ci-reply-to">→ {{ replyTo }}</span>
				<time class="ci-time" :datetime="comment.createdAt" :title="absolute(comment.createdAt)">{{ relative(comment.createdAt) }}</time>
				<span v-if="comment.edited" class="ci-edited">{{ t('merlin', '(edited)') }}</span>
			</div>
			<button v-if="canDelete"
				type="button"
				class="ci-delete"
				:title="t('merlin', 'Delete')"
				:aria-label="t('merlin', 'Delete')"
				:disabled="busy"
				@click="$emit('delete')">
				<DeleteOutline :size="18" />
			</button>

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
			<!-- Klartext: nur Text-Interpolation, nie v-html. Links als echte <a>. -->
			<p v-else class="ci-body">
				<template v-for="(part, i) in bodyParts"><a v-if="part.href"
					:key="i"
					class="ci-url"
					:href="part.href"
					target="_blank"
					rel="noopener noreferrer nofollow ugc">{{ part.text }}</a><template v-else>{{ part.text }}</template></template>
			</p>

			<div v-if="!editing" class="ci-links">
				<button v-if="canReply" type="button" class="ci-link" @click="$emit('reply')">
					{{ t('merlin', 'Reply') }}
				</button>
				<button v-if="canEdit" type="button" class="ci-link" @click="startEdit">
					{{ t('merlin', 'Edit') }}
				</button>
			</div>
		</template>
	</div>
</template>

<script>
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import { splitLinks } from '../linkify.js'

/** Wie CommentRules::FALLBACK_COLOR – für Stände ohne `authorColor`. */
const FALLBACK_COLOR = '#57534e'

export default {
	name: 'CommentItem',

	components: { DeleteOutline },

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

	computed: {
		bodyParts() {
			return splitLinks(this.comment.body || '')
		},

		/** Verfasser-Farbe vom Server (Besitzer orange, Gäste je eigene). */
		authorColor() {
			const c = this.comment.authorColor
			return typeof c === 'string' && /^#[0-9a-f]{6}$/i.test(c) ? c : FALLBACK_COLOR
		},
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
	position: relative;
	padding: 4px 0 4px 10px;
	border-left: 3px solid var(--ci-color);
}

.comment-item--deleted {
	border-left-color: var(--color-border, #ddd);
}

/* Platz für den Papierkorb oben rechts. */
.comment-item--deletable .ci-meta {
	padding-right: 28px;
}

.ci-dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	margin-right: 5px;
	border-radius: 50%;
	background: var(--ci-color);
	vertical-align: 1px;
}

.ci-delete {
	position: absolute;
	top: 0;
	right: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: none;
	color: var(--color-text-maxcontrast, #666);
	cursor: pointer;
}

.ci-delete:hover,
.ci-delete:focus-visible {
	background: var(--color-background-hover, #f0f0f0);
	color: var(--color-error, #c00);
}

.ci-delete:disabled {
	opacity: 0.5;
	cursor: default;
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

.ci-url {
	color: var(--color-primary-element, #00679e);
	text-decoration: underline;
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
