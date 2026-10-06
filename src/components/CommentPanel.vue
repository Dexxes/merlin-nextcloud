<template>
	<aside class="comment-panel" :class="{ 'comment-panel--sheet': sheet }" :aria-label="t('merlin', 'Comments')">
		<header class="cp-header">
			<!-- An einer Stelle keine Überschrift: das Zitat darunter sagt genug. -->
			<h2 v-if="!passageFocused" class="cp-title">
				{{ t('merlin', 'Comments') }}
			</h2>
			<button v-else
				type="button"
				class="cp-link cp-all"
				@click="$emit('unfocus')">
				{{ t('merlin', 'All comments') }}
			</button>
			<button type="button" class="cp-close" :title="t('merlin', 'Close')" @click="$emit('close')">
				×
			</button>
		</header>

		<div v-if="mode === 'guest' && currentName" class="cp-identity">
			{{ t('merlin', 'You are writing as {name}.', { name: currentName }, undefined, { escape: false }) }}
			<button type="button" class="cp-link" @click="$emit('change-name')">
				{{ t('merlin', 'Change name') }}
			</button>
		</div>

		<p v-if="!canWrite" class="cp-hint">
			{{ t('merlin', 'Comments are closed for this link.') }}
		</p>

		<div class="cp-threads">
			<p v-if="session.state.loaded && visibleThreads.length === 0 && !pendingAnchor" class="cp-empty">
				{{ focusedHighlightId !== null
					? t('merlin', 'No comments on this passage yet.')
					: t('merlin', 'No comments yet. Select text to highlight and comment on it.') }}
			</p>

			<section v-for="thread in visibleThreads"
				:key="thread.id"
				class="cp-thread"
				:class="{ 'cp-thread--active': thread.highlightId !== null && thread.highlightId === focusedHighlightId }">
				<blockquote v-if="thread.quotedText"
					class="cp-quote"
					:class="{ 'cp-quote--detached': thread.highlightId === null }"
					:title="thread.highlightId !== null ? t('merlin', 'Show in text') : t('merlin', 'The highlight was removed')"
					@click="thread.highlightId !== null && $emit('focus-highlight', thread.highlightId)">
					{{ shorten(thread.quotedText) }}
				</blockquote>
				<p v-else class="cp-article-label">
					{{ t('merlin', 'On the article') }}
				</p>

				<CommentItem
					:comment="thread"
					:can-edit="canEdit(thread)"
					:can-delete="canDelete(thread)"
					:owner-label="ownerLabel"
					:busy="busyId === thread.id"
					@edit="body => edit(thread, body)"
					@delete="remove(thread)" />

				<div v-if="thread.replies.length" class="cp-replies">
					<CommentItem
						v-for="reply in thread.replies"
						:key="reply.id"
						:comment="reply"
						:reply-to="replyToName(thread, reply)"
						:can-edit="canEdit(reply)"
						:can-delete="canDelete(reply)"
						:can-reply="canWrite"
						:owner-label="ownerLabel"
						:busy="busyId === reply.id"
						@edit="body => edit(reply, body)"
						@delete="remove(reply)"
						@reply="startReply(thread, reply)" />
				</div>

				<template v-if="canWrite">
					<form v-if="replyTarget && replyTarget.threadId === thread.id"
						class="cp-form cp-form--reply"
						@submit.prevent="submitReply(thread)">
						<p v-if="replyTarget.name" class="cp-reply-to">
							{{ t('merlin', 'Reply to {name}', { name: replyTarget.name }, undefined, { escape: false }) }}
						</p>
						<textarea ref="replyInput"
							v-model="replyBody"
							class="cp-input"
							rows="2"
							:maxlength="maxBody"
							:placeholder="t('merlin', 'Write a reply…')"
							@keydown.enter.exact.ctrl.prevent="submitReply(thread)"
							@keydown.enter.exact.meta.prevent="submitReply(thread)" />
						<div class="cp-form-actions">
							<button type="button" class="cp-btn" @click="replyTarget = null">
								{{ t('merlin', 'Cancel') }}
							</button>
							<button type="submit" class="cp-btn cp-btn--primary" :disabled="sending || !replyBody.trim()">
								{{ t('merlin', 'Reply') }}
							</button>
						</div>
					</form>
					<button v-else-if="!thread.deleted || thread.replies.length"
						type="button"
						class="cp-link cp-reply-btn"
						@click="startReply(thread, null)">
						{{ t('merlin', 'Reply') }}
					</button>
				</template>
			</section>
		</div>

		<form v-if="canWrite" class="cp-form cp-form--new" @submit.prevent="submitNew">
			<!-- Ausgewählte, noch nicht gespeicherte Stelle: erscheint im Text erst,
			     wenn der Kommentar abgeschickt ist. -->
			<blockquote v-if="pendingAnchor" class="cp-quote cp-quote--pending">
				{{ shorten(pendingAnchor.highlightedText) }}
			</blockquote>
			<label v-if="!passageFocused" class="cp-form-label" :for="inputId">
				{{ t('merlin', 'Comment on the article') }}
			</label>
			<textarea :id="inputId"
				ref="newInput"
				:aria-label="passageFocused ? t('merlin', 'Write a comment…') : null"
				v-model="newBody"
				class="cp-input"
				rows="3"
				:maxlength="maxBody"
				:placeholder="t('merlin', 'Write a comment…')"
				@keydown.enter.exact.ctrl.prevent="submitNew"
				@keydown.enter.exact.meta.prevent="submitNew" />
			<!-- Honeypot gegen einfache Bots: für Menschen unsichtbar, nie ausgefüllt. -->
			<input v-if="mode === 'guest'"
				v-model="website"
				class="cp-hp"
				type="text"
				name="website"
				tabindex="-1"
				autocomplete="off"
				aria-hidden="true">
			<div class="cp-form-actions">
				<button type="submit" class="cp-btn cp-btn--primary" :disabled="sending || !newBody.trim()">
					{{ t('merlin', 'Send') }}
				</button>
			</div>
		</form>

		<p v-if="error" class="cp-error" role="alert">
			{{ error }}
		</p>
	</aside>
</template>

<script>
import CommentItem from './CommentItem.vue'
import { guestNameKey } from '../comment-session'

const MAX_BODY = 5000

export default {
	name: 'CommentPanel',

	components: { CommentItem },

	props: {
		/** createCommentSession() */
		session: { type: Object, required: true },
		/** ownerCommentsClient() oder guestCommentsClient() */
		client: { type: Object, required: true },
		/** 'owner' (eingeloggter Reader) oder 'guest' (öffentlicher Link) */
		mode: { type: String, default: 'owner' },
		/** Name, unter dem gerade geschrieben wird (Gast) */
		currentName: { type: String, default: '' },
		canWrite: { type: Boolean, default: true },
		/** Thread-Ansicht einer Markierung, null = alle Kommentare */
		focusedHighlightId: { type: Number, default: null },
		/**
		 * Gerade ausgewählte Textstelle ohne Markierung („Kommentieren“ im
		 * Markier-Menü): der neue Kommentar legt sie mit an.
		 */
		pendingAnchor: { type: Object, default: null },
		/** Markierungs-IDs in Textreihenfolge, für die Sortierung der Threads */
		highlightOrder: { type: Array, default: () => [] },
		/** Vor dem Schreiben: sorgt für einen Gast-Namen, liefert true wenn vorhanden */
		ensureName: { type: Function, default: null },
		/** Als Bottom-Sheet (schmale Bildschirme) statt Seitenleiste */
		sheet: { type: Boolean, default: false },
	},

	emits: ['close', 'unfocus', 'focus-highlight', 'change-name', 'anchored'],

	data() {
		return {
			newBody: '',
			replyBody: '',
			replyTarget: null,
			website: '',
			sending: false,
			busyId: null,
			error: '',
			maxBody: MAX_BODY,
			inputId: 'cp-input-' + Math.random().toString(36).slice(2),
		}
	},

	computed: {
		passageFocused() {
			return this.focusedHighlightId !== null || !!this.pendingAnchor
		},

		ownerLabel() {
			return this.mode === 'guest' ? this.t('merlin', 'Shared this article') : this.t('merlin', 'You')
		},

		visibleThreads() {
			const order = new Map(this.highlightOrder.map((id, i) => [String(id), i]))
			if (this.pendingAnchor) return []
			const threads = this.session.state.threads.filter(thread => this.focusedHighlightId === null
				|| thread.highlightId === this.focusedHighlightId)
			const rank = thread => (thread.highlightId !== null && order.has(String(thread.highlightId))
				? order.get(String(thread.highlightId))
				: Number.MAX_SAFE_INTEGER)
			return [...threads].sort((a, b) => rank(a) - rank(b) || a.id - b.id)
		},
	},

	watch: {
		pendingAnchor(anchor) {
			if (!anchor) return
			this.error = ''
			this.$nextTick(() => this.$refs.newInput?.focus())
		},

		focusedHighlightId() {
			this.replyTarget = null
			this.error = ''
			this.$nextTick(() => {
				if (this.focusedHighlightId !== null && !this.visibleThreads.length) {
					this.$refs.newInput?.focus()
				}
			})
		},
	},

	mounted() {
		if (this.pendingAnchor || (this.focusedHighlightId !== null && !this.visibleThreads.length)) {
			this.$refs.newInput?.focus()
		}
	},

	methods: {
		shorten(text) {
			const clean = (text || '').replace(/\s+/g, ' ').trim()
			return clean.length > 220 ? clean.slice(0, 217) + '…' : clean
		},

		isOwnGuest(comment) {
			return comment.authorType === 'guest'
				&& this.currentName !== ''
				&& guestNameKey(comment.authorName) === guestNameKey(this.currentName)
		},

		canEdit(comment) {
			if (comment.deleted || !this.canWrite) return false
			return this.mode === 'owner' ? comment.authorType === 'owner' : this.isOwnGuest(comment)
		},

		canDelete(comment) {
			if (comment.deleted) return false
			// Der Besitzer moderiert alles, auch wenn Kommentare für Gäste zu sind.
			return this.mode === 'owner' ? true : (this.canWrite && this.isOwnGuest(comment))
		},

		replyToName(thread, reply) {
			if (reply.replyToId === null || reply.replyToId === thread.id) return ''
			const target = thread.replies.find(r => r.id === reply.replyToId)
			return target && !target.deleted ? target.authorName : ''
		},

		startReply(thread, reply) {
			this.replyTarget = {
				threadId: thread.id,
				parentId: reply ? reply.id : thread.id,
				name: reply && !reply.deleted ? reply.authorName : '',
			}
			this.replyBody = ''
			this.$nextTick(() => {
				const input = this.$refs.replyInput
				;(Array.isArray(input) ? input[0] : input)?.focus()
			})
		},

		messageFor(error) {
			const code = error?.response?.data?.error
			switch (code) {
			case 'name_too_short': return this.t('merlin', 'Please enter a name with at least 2 characters.')
			case 'name_too_long': return this.t('merlin', 'The name can be at most 50 characters long.')
			case 'name_reserved': return this.t('merlin', 'This name is reserved. Please choose another one.')
			case 'body_too_long': return this.t('merlin', 'The comment can be at most 5000 characters long.')
			case 'comments_closed': return this.t('merlin', 'Comments are closed for this link.')
			case 'daily_limit': return this.t('merlin', 'Too many comments today. Please try again tomorrow.')
			case 'forbidden': return this.t('merlin', 'You can only change your own comments.')
			default:
				return error?.response?.status === 429
					? this.t('merlin', 'Too many requests. Please wait a moment.')
					: this.t('merlin', 'The comment could not be saved.')
			}
		},

		async withName() {
			if (this.mode !== 'guest') return true
			return this.ensureName ? await this.ensureName() : !!this.currentName
		},

		async submitNew() {
			const body = this.newBody.trim()
			if (!body || this.sending) return
			if (!(await this.withName())) return
			this.sending = true
			this.error = ''
			try {
				const anchor = this.pendingAnchor
				const saved = await this.client.create({
					body,
					highlightId: anchor ? null : this.focusedHighlightId,
					anchor,
					website: this.website,
				})
				this.newBody = ''
				await this.session.refresh()
				// Stelle ist jetzt gespeichert (und unterstrichen): weiter in
				// ihrem Thread.
				if (anchor && saved?.highlightId) this.$emit('anchored', saved.highlightId)
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.sending = false
			}
		},

		async submitReply(thread) {
			const body = this.replyBody.trim()
			if (!body || this.sending || !this.replyTarget) return
			if (!(await this.withName())) return
			this.sending = true
			this.error = ''
			try {
				await this.client.create({ body, parentId: this.replyTarget.parentId, website: this.website })
				this.replyBody = ''
				this.replyTarget = null
				await this.session.refresh()
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.sending = false
			}
		},

		async edit(comment, body) {
			this.busyId = comment.id
			this.error = ''
			try {
				await this.client.update(comment.id, body)
				await this.session.refresh()
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.busyId = null
			}
		},

		async remove(comment) {
			if (!window.confirm(this.t('merlin', 'Delete this comment?'))) return
			this.busyId = comment.id
			this.error = ''
			try {
				await this.client.remove(comment.id)
				await this.session.refresh()
			} catch (e) {
				this.error = this.messageFor(e)
			} finally {
				this.busyId = null
			}
		},
	},
}
</script>

<style scoped>
.comment-panel {
	position: fixed;
	top: var(--header-height, 50px);
	right: 0;
	bottom: 0;
	width: min(380px, 100vw);
	display: flex;
	flex-direction: column;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	border-left: 1px solid var(--color-border, #e0e0e0);
	box-shadow: -4px 0 16px rgba(0, 0, 0, 0.08);
	z-index: 2000;
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
	font-size: 14px;
}

.comment-panel--sheet {
	top: auto;
	left: 0;
	width: 100vw;
	height: 70vh;
	border-left: none;
	border-top: 1px solid var(--color-border, #e0e0e0);
	border-radius: 14px 14px 0 0;
	box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.12);
}

.cp-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px 14px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
}

.cp-title {
	flex: 1;
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.cp-all {
	margin-right: auto;
}

.cp-close {
	border: none;
	background: none;
	font-size: 22px;
	line-height: 1;
	cursor: pointer;
	color: inherit;
	padding: 0 4px;
}

.cp-identity,
.cp-hint {
	margin: 0;
	padding: 8px 14px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
	border-bottom: 1px solid var(--color-border, #e0e0e0);
}

.cp-threads {
	flex: 1;
	overflow-y: auto;
	padding: 6px 14px 14px;
}

.cp-empty {
	color: var(--color-text-maxcontrast, #666);
	margin: 16px 0;
}

.cp-thread {
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border, #eee);
}

.cp-thread--active {
	background: var(--color-background-hover, #f7f7f7);
	margin: 0 -14px;
	padding: 12px 14px;
}

.cp-quote--pending {
	cursor: default;
	border-left-color: #f59e0b;
}

.cp-quote {
	margin: 0 0 8px;
	padding: 4px 10px;
	border-left: 3px solid #fde68a;
	color: var(--color-text-maxcontrast, #555);
	font-style: italic;
	cursor: pointer;
}

.cp-quote--detached {
	border-left-color: var(--color-border-dark, #bbb);
	cursor: default;
}

.cp-article-label {
	margin: 0 0 6px;
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast, #666);
}

.cp-replies {
	margin-left: 14px;
	padding-left: 10px;
	border-left: 2px solid var(--color-border, #eee);
}

.cp-reply-btn {
	margin-top: 4px;
}

.cp-reply-to {
	margin: 0 0 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #666);
}

.cp-form {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.cp-form--reply {
	margin-top: 8px;
}

.cp-form--new {
	padding: 10px 14px 14px;
	border-top: 1px solid var(--color-border, #e0e0e0);
}

.cp-form-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #666);
}

.cp-input {
	width: 100%;
	box-sizing: border-box;
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: 8px;
	background: var(--color-main-background, #fff);
	color: inherit;
	font: inherit;
	resize: vertical;
}

.cp-form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.cp-btn {
	padding: 6px 14px;
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: 16px;
	background: var(--color-background-hover, #f5f5f5);
	color: inherit;
	cursor: pointer;
	font: inherit;
}

.cp-btn--primary {
	background: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.cp-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.cp-link {
	border: none;
	background: none;
	padding: 0;
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	font: inherit;
	font-size: 13px;
}

.cp-hp {
	position: absolute;
	left: -9999px;
	width: 1px;
	height: 1px;
	opacity: 0;
}

.cp-error {
	margin: 0;
	padding: 8px 14px;
	color: var(--color-error, #c00);
	font-size: 13px;
}
</style>
