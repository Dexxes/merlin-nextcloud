<template>
	<div
		class="article-card"
		:class="{ 'article-card--processing': article.isProcessing }"
		@contextmenu.prevent="openContextMenu">

		<!-- Processing overlay -->
		<div v-if="article.isProcessing" class="article-processing-badge">
			<span class="article-processing-spinner" />
			{{ t('merlin', 'Loading…') }}
		</div>

		<div class="article-image">
			<img :src="article.imageUrl || noImgPath" :alt="article.title" @error="onImageError">
			<!-- Lese-Fortschrittsbalken, analog zur Karten-Anzeige in den Mobile-Apps;
			     Farbe kommt aus den Settings (accentColor), wie bei iOS/Android -->
			<div
				v-if="showReadProgress"
				class="article-read-progress"
				:style="{ width: (readProgress * 100) + '%', background: accentColor }" />
		</div>

		<div class="article-content">
			<h3 class="article-title">{{ article.title }}</h3>

			<p class="article-excerpt">{{ article.excerpt }}</p>

			<div class="article-meta">
				<span v-if="article.author" class="meta-author">
					<Account :size="16" />
					{{ article.author }}
				</span>
				<span v-if="article.siteName" class="meta-site">
					<Web :size="16" />
					{{ article.siteName }}
				</span>
				<span class="meta-time">
					<Clock :size="16" />
					{{ article.readingTime }} Min.
				</span>
				<span v-if="article.isArchived && article.archivedAt" class="meta-archived">
					<ArchiveArrowDown :size="16" />
					{{ t('merlin', 'Archived') }}: {{ formatDate(article.archivedAt) }}
				</span>
			</div>

			<div class="article-tags">
				<span
					v-for="tag in article.tags"
					:key="tag.id"
					class="tag"
					:style="{ backgroundColor: tag.color }">
					{{ tag.name }}
				</span>
			</div>
		</div>

		<!-- Kontext-Menü via Teleport in body, damit overflow/z-index kein Problem macht -->
		<Teleport to="body">
			<template v-if="menu.visible">
				<!-- Transparenter Backdrop schließt das Menü bei Klick daneben -->
				<div
					class="context-menu-backdrop"
					@click="closeMenu"
					@contextmenu.prevent="closeMenu" />

				<ul
					class="article-context-menu"
					:style="{ top: menu.y + 'px', left: menu.x + 'px' }"
					@contextmenu.prevent>

					<!-- Hauptmenü -->
					<template v-if="subMenu === null">
						<li @click="handleOpen">
							<BookOpen :size="16" />
							<span>{{ t('merlin', 'Open') }}</span>
						</li>

						<li @click="subMenu = 'share'">
							<ShareVariant :size="16" />
							<span>{{ t('merlin', 'Share') }}</span>
							<ChevronRight :size="14" class="submenu-chevron" />
						</li>

						<li @click="handleArchive">
							<ArchiveArrowDown v-if="!article.isArchived" :size="16" />
							<ArchiveArrowUp v-else :size="16" />
							<span>{{ article.isArchived ? t('merlin', 'Restore') : t('merlin', 'Archive') }}</span>
						</li>

						<li @click="handleFavorite">
							<Star v-if="article.isFavorite" :size="16" class="ctx-fav-active" />
							<StarOutline v-else :size="16" />
							<span>{{ article.isFavorite ? t('merlin', 'Remove from favorites') : t('merlin', 'Add to favorites') }}</span>
						</li>

						<li @click="subMenu = 'tags'">
							<Tag :size="16" />
							<span>{{ t('merlin', 'Tags') }}</span>
							<ChevronRight :size="14" class="submenu-chevron" />
						</li>

						<li @click="handleReport">
							<AlertCircleOutline :size="16" />
							<span>{{ t('merlin', 'Report faulty rendered article') }}</span>
						</li>

						<li class="context-menu-item--danger" @click="handleDelete">
							<Delete :size="16" />
							<span>{{ t('merlin', 'Delete') }}</span>
						</li>
					</template>

					<!-- Share-Untermenü -->
					<template v-else-if="subMenu === 'share'">
						<li class="context-menu-item--back" @click="subMenu = null">
							<ArrowLeft :size="16" />
							<span>{{ t('merlin', 'Share') }}</span>
						</li>

						<li v-if="hasNativeShare" @click="handleNativeShare">
							<ShareVariant :size="16" />
							<span>{{ t('merlin', 'Share…') }}</span>
						</li>

						<li @click="handleCopyLink">
							<ContentCopy :size="16" />
							<span>{{ t('merlin', 'Copy link') }}</span>
						</li>

						<li @click="handleShareByEmail">
							<Email :size="16" />
							<span>{{ t('merlin', 'Send by email') }}</span>
						</li>

						<li @click="shareToBluesky">
							<Butterfly :size="16" />
							<span>{{ t('merlin', 'Share to Bluesky') }}</span>
						</li>

						<li @click="shareToMastodon">
							<Mastodon :size="16" />
							<span>{{ t('merlin', 'Share to Mastodon') }}</span>
						</li>
					</template>

					<!-- Tags-Untermenü -->
					<template v-else-if="subMenu === 'tags'">
						<li class="context-menu-item--back" @click="subMenu = null">
							<ArrowLeft :size="16" />
							<span>{{ t('merlin', 'Tags') }}</span>
						</li>

						<li v-if="allTags.length === 0" class="context-menu-item--empty">
							<span>{{ t('merlin', 'No tags defined yet') }}</span>
						</li>

						<li
							v-for="tag in allTags"
							:key="tag.id"
							@click="handleTagToggle(tag)">
							<span class="ctx-tag-dot" :style="{ backgroundColor: tag.color }" />
							<span class="ctx-tag-name">{{ tag.name }}</span>
							<Check v-if="articleHasTag(tag)" :size="16" class="ctx-tag-check" />
						</li>

						<!-- New tag form -->
						<li class="ctx-tag-new-form" @click.stop>
							<input
								v-model="newTagName"
								class="ctx-tag-new-input"
								type="text"
								:placeholder="t('merlin', 'New tag…')"
								@keyup.enter="createAndAssignTag" />
							<div class="ctx-tag-new-row">
								<div class="ctx-tag-new-swatches">
									<span
										v-for="color in tagColors"
										:key="color"
										class="ctx-tag-new-swatch"
										:class="{ 'ctx-tag-new-swatch--active': newTagColor === color }"
										:style="{ backgroundColor: color }"
										@click.stop="newTagColor = color" />
								</div>
								<button
									class="ctx-tag-new-btn"
									:disabled="!newTagName.trim()"
									@click.stop="createAndAssignTag">+</button>
							</div>
						</li>
					</template>
				</ul>
			</template>
		</Teleport>

		<!-- Report-Dialog via Teleport — zentriertes Modal, kein window.prompt() -->
		<Teleport to="body">
			<template v-if="reportDialog.visible">
				<div class="report-dialog-backdrop" @click="cancelReport" />
				<div class="report-dialog" role="dialog" aria-modal="true" @click.stop>
					<h3 class="report-dialog-title">
						<AlertCircleOutline :size="18" />
						{{ t('merlin', 'Report faulty rendered article') }}
					</h3>
					<p class="report-dialog-url">{{ article.url }}</p>
					<textarea
						v-model="reportDialog.comment"
						class="report-dialog-textarea"
						:placeholder="t('merlin', 'What is wrong? (optional)')"
						rows="3"
						:disabled="reportDialog.sending" />
					<div class="report-dialog-actions">
						<button
							class="report-dialog-btn report-dialog-btn--cancel"
							:disabled="reportDialog.sending"
							@click="cancelReport">
							{{ t('merlin', 'Cancel') }}
						</button>
						<button
							class="report-dialog-btn report-dialog-btn--submit"
							:disabled="reportDialog.sending"
							@click="submitReport">
							{{ reportDialog.sending ? '…' : t('merlin', 'Report') }}
						</button>
					</div>
				</div>
			</template>
		</Teleport>
	</div>
</template>

<script>
import { showSuccess, showError } from '@nextcloud/dialogs'
import { imagePath } from '@nextcloud/router'
import Account from 'vue-material-design-icons/Account.vue'
import Web from 'vue-material-design-icons/Web.vue'
import Clock from 'vue-material-design-icons/Clock.vue'
import BookOpen from 'vue-material-design-icons/BookOpen.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Email from 'vue-material-design-icons/Email.vue'
import Butterfly from 'vue-material-design-icons/Butterfly.vue'
import Mastodon from 'vue-material-design-icons/Mastodon.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ArchiveArrowDown from 'vue-material-design-icons/ArchiveArrowDown.vue'
import ArchiveArrowUp from 'vue-material-design-icons/ArchiveArrowUp.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import Check from 'vue-material-design-icons/Check.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'


const TAG_COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899']

const MENU_WIDTH = 190  // Approximate width of context menu in px
const MENU_HEIGHT = 240 // Approximate height (share submenu can have 4 items)

export default {
	name: 'ArticleCard',

	components: {
		Account,
		Web,
		Clock,
		BookOpen,
		ShareVariant,
		ChevronRight,
		ArrowLeft,
		ContentCopy,
		Email,
		Butterfly,
		Mastodon,
		Delete,
		ArchiveArrowDown,
		ArchiveArrowUp,
		Star,
		StarOutline,
		Tag,
		Check,
		AlertCircleOutline,
	},

	props: {
		article: {
			type: Object,
			required: true,
		},
	},

	emits: ['open', 'delete', 'archive', 'favorite'],

	data() {
		return {
			noImgPath: imagePath('merlin', 'no-img.png'),
			menu: {
				visible: false,
				x: 0,
				y: 0,
			},
			subMenu: null, // null | 'share' | 'tags'
			newTagName: '',
			newTagColor: TAG_COLORS[5], // blue default
			tagColors: TAG_COLORS,
			hasNativeShare: typeof navigator !== 'undefined' && !!navigator.share,
			reportDialog: {
				visible: false,
				comment: '',
				sending: false,
			},
			readProgress: 0,
		}
	},

	mounted() {
		window.addEventListener('keydown', this._onKeyDown = (e) => {
			if (e.key === 'Escape') { this.closeMenu(); this.cancelReport() }
		})
		window.addEventListener('merlin-progress-updated', this._onProgressUpdated = (e) => {
			if (e.detail?.articleId === this.article.id) this.reloadReadProgress()
		})
		this.reloadReadProgress()
	},

	beforeUnmount() {
		window.removeEventListener('keydown', this._onKeyDown)
		window.removeEventListener('merlin-progress-updated', this._onProgressUpdated)
	},

	computed: {
		allTags() {
			return this.$store.state.tags || []
		},

		// URL aus den Merlin-Settings, gespeichert per SettingsController
		reportBackendUrl() {
			return (this.$store.state.settings?.reportBackendUrl || '').trim()
		},

		// Akzentfarbe für den Fortschrittsbalken, wie in PreferencesStore.accentProgressColorHex (iOS)
		accentColor() {
			return this.$store.state.settings?.accentColor || '#FF3B30'
		},

		// Balken nur bei begonnenen, aber nicht abgeschlossenen Artikeln zeigen
		// (gleiche Schwellenwerte wie ArticleCardView.swift in der iOS-App)
		showReadProgress() {
			return this.readProgress > 0.01 && this.readProgress < 0.99
		},
	},

	methods: {
		formatDate(dateString) {
			const date = new Date(dateString)
			return new Intl.DateTimeFormat('default', {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			}).format(date)
		},

		openContextMenu(event) {
			const x = Math.min(event.clientX, window.innerWidth - MENU_WIDTH - 8)
			const y = Math.min(event.clientY, window.innerHeight - MENU_HEIGHT - 8)
			this.menu = { visible: true, x, y }
		},

		closeMenu() {
			this.menu.visible = false
			this.subMenu = null
			this.newTagName = ''
		},

		handleOpen() {
			this.closeMenu()
			this.$emit('open')
		},

		async handleNativeShare() {
			this.closeMenu()
			try {
				await navigator.share({ title: this.article.title, url: this.article.url })
			} catch {
				// User cancelled or API unavailable — no error shown
			}
		},

		async handleCopyLink() {
			this.closeMenu()
			try {
				await navigator.clipboard.writeText(this.article.url)
				showSuccess(this.t('merlin', 'Link copied to clipboard'))
			} catch {
				const el = document.createElement('textarea')
				el.value = this.article.url
				document.body.appendChild(el)
				el.select()
				document.execCommand('copy')
				document.body.removeChild(el)
				showSuccess(this.t('merlin', 'Link copied to clipboard'))
			}
		},

		handleShareByEmail() {
			this.closeMenu()
			const subject = encodeURIComponent(this.article.title || '')
			const body = encodeURIComponent(this.article.url)
			window.open(`mailto:?subject=${subject}&body=${body}`)
		},

		shareToBluesky() {
			this.closeMenu()
			const text = encodeURIComponent(`${this.article.title}\n${this.article.url}`)
			window.open(`https://bsky.app/intent/compose?text=${text}`, '_blank')
		},

		shareToMastodon() {
			this.closeMenu()
			let instance = localStorage.getItem('merlin_mastodon_instance')
			if (!instance) {
				instance = window.prompt(
					this.t('merlin', 'Your Mastodon instance (e.g. mastodon.social):'),
					'mastodon.social',
				)
				if (!instance) return
				instance = instance.replace(/^https?:\/\//, '').replace(/\/$/, '')
				localStorage.setItem('merlin_mastodon_instance', instance)
			}
			const text = encodeURIComponent(`${this.article.title}\n${this.article.url}`)
			window.open(`https://${instance}/share?text=${text}`, '_blank')
		},

		handleArchive() {
			this.closeMenu()
			this.$emit('archive')
		},

		handleFavorite() {
			this.closeMenu()
			this.$emit('favorite')
		},

		articleHasTag(tag) {
			return (this.article.tags || []).some(t => t.id === tag.id)
		},

		async handleTagToggle(tag) {
			try {
				if (this.articleHasTag(tag)) {
					await this.$store.dispatch('removeTagFromArticle', { articleId: this.article.id, tagId: tag.id })
				} else {
					await this.$store.dispatch('addTagToArticle', { articleId: this.article.id, tagId: tag.id })
				}
			} catch (error) {
				console.error('Failed to toggle tag:', error)
			}
		},

		async createAndAssignTag() {
			const name = this.newTagName.trim()
			if (!name) return
			try {
				const tag = await this.$store.dispatch('addTag', { name, color: this.newTagColor })
				await this.$store.dispatch('addTagToArticle', { articleId: this.article.id, tagId: tag.id })
				this.newTagName = ''
			} catch (error) {
				console.error('Failed to create tag:', error)
			}
		},

		// Liest den vom ArticleReader unter merlin_pct_{id} gespeicherten Fortschritt (0–1).
		// Lokal pro Gerät, wie der entsprechende Wert in den Mobile-Apps.
		reloadReadProgress() {
			const raw = localStorage.getItem(`merlin_pct_${this.article.id}`)
			this.readProgress = raw ? parseFloat(raw) || 0 : 0
		},

		onImageError(event) {
			// Replace broken image with the local no-img placeholder; prevent retry loop.
			event.target.onerror = null
			event.target.src = imagePath('merlin', 'no-img.png')
		},

		handleReport() {
			this.closeMenu()
			this.reportDialog.comment = ''
			this.reportDialog.sending = false
			this.reportDialog.visible = true
		},

		cancelReport() {
			this.reportDialog.visible = false
			this.reportDialog.comment = ''
		},

		async submitReport() {
			if (!this.reportBackendUrl) {
				showError(this.t('merlin', 'No report backend configured. Set the URL in Settings.'))
				return
			}
			this.reportDialog.sending = true
			try {
				const res = await fetch(this.reportBackendUrl + '?action=report', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						url: this.article.url,
						comment: this.reportDialog.comment.trim(),
					}),
				})
				if (!res.ok) throw new Error(`HTTP ${res.status}`)
				showSuccess(this.t('merlin', 'Article reported'))
				this.reportDialog.visible = false
			} catch (err) {
				console.error('Failed to report article:', err)
				showError(this.t('merlin', 'Could not send report — please try again.'))
			} finally {
				this.reportDialog.sending = false
			}
		},

		handleDelete() {
			this.closeMenu()
			if (confirm(this.t('merlin', 'Are you sure you want to delete this article?'))) {
				this.$emit('delete')
			}
		},
	},
}
</script>

<style scoped>
.article-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	cursor: pointer;
	transition: box-shadow 0.2s ease, transform 0.2s ease;
	position: relative;
	user-select: none;
}

.article-card:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	transform: translateY(-2px);
}

.article-card--processing {
	opacity: 0.75;
}

/* Small badge shown at the top of a card while content is being extracted */
.article-processing-badge {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	font-size: 11px;
	color: var(--color-primary-text, #fff);
	background: var(--color-primary, #0082c9);
	border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
}

@keyframes merlin-spin {
	to { transform: rotate(360deg); }
}

.article-processing-spinner {
	display: inline-block;
	width: 10px;
	height: 10px;
	border: 2px solid rgba(255, 255, 255, 0.4);
	border-top-color: #fff;
	border-radius: 50%;
	animation: merlin-spin 0.7s linear infinite;
	flex-shrink: 0;
}


.article-image {
	width: 100%;
	height: 200px;
	overflow: hidden;
	position: relative;
}

.article-image img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

/* Lese-Fortschrittsbalken am unteren Rand des Vorschaubilds, analog zur
   Karten-Anzeige in den Mobile-Apps (siehe ArticleCardView.swift) */
.article-read-progress {
	position: absolute;
	bottom: 0;
	left: 0;
	height: 3px;
	background: var(--color-primary, #0082c9);
	transition: width 0.2s ease;
}

.article-content {
	padding: 16px;
}

.article-title {
	margin: 0 0 8px 0;
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
	line-height: 1.4;
}

.article-excerpt {
	margin: 0 0 12px 0;
	color: var(--color-text-lighter);
	font-size: 14px;
	line-height: 1.5;
	display: -webkit-box;
	-webkit-line-clamp: 3;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.article-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	font-size: 13px;
	color: var(--color-text-lighter);
	margin-bottom: 12px;
}

.article-meta span {
	display: flex;
	align-items: center;
	gap: 4px;
}

.article-tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.tag {
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 12px;
	color: white;
	font-weight: 500;
}
</style>

<!-- Context menu styles are unscoped because the element is teleported to <body> -->
<style>
.context-menu-backdrop {
	position: fixed;
	inset: 0;
	z-index: 9998;
}

.article-context-menu {
	position: fixed;
	z-index: 9999;
	min-width: 190px;
	margin: 0;
	padding: 6px;
	list-style: none;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 12px;
	box-shadow:
		0 4px 6px -1px rgba(0, 0, 0, 0.10),
		0 10px 20px -3px rgba(0, 0, 0, 0.08);
	transform-origin: top left;
	animation: ctx-menu-appear 0.12s ease;
}

@keyframes ctx-menu-appear {
	from { opacity: 0; transform: scale(0.93); }
	to   { opacity: 1; transform: scale(1); }
}

.article-context-menu li {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 9px 12px;
	border-radius: 8px;
	cursor: pointer;
	font-size: 0.9em;
	color: var(--color-main-text);
	transition: background 0.1s ease;
	user-select: none;
}

/* Cursor auf allen Kind-Elementen einheitlich halten */
.article-context-menu li *,
.article-context-menu li span {
	cursor: pointer;
}

.article-context-menu li:hover {
	background: var(--color-background-hover);
}

.article-context-menu li .material-design-icon {
	color: var(--color-primary, #0082c9);
	opacity: 0.8;
	flex-shrink: 0;
}

/* Chevron im Share-Eintrag nach rechts schieben */
.article-context-menu .submenu-chevron {
	margin-left: auto;
	opacity: 0.5;
}

/* Zurück-Eintrag im Share-Untermenü */
.article-context-menu .context-menu-item--back {
	font-weight: 600;
}

.article-context-menu .context-menu-item--back .material-design-icon {
	color: var(--color-main-text);
	opacity: 0.7;
}

/* Löschen-Eintrag — eigene Farbe, unabhängig von --color-error des Themes */
.article-context-menu li.context-menu-item--danger {
	color: #9b1c1c !important;
}

.article-context-menu li.context-menu-item--danger .material-design-icon {
	color: #9b1c1c !important;
	opacity: 1;
}

.article-context-menu li.context-menu-item--danger:hover {
	background: rgba(155, 28, 28, 0.10);
}

@media (prefers-color-scheme: dark) {
	.article-context-menu li.context-menu-item--danger {
		color: #fca5a5 !important;
	}
	.article-context-menu li.context-menu-item--danger .material-design-icon {
		color: #fca5a5 !important;
	}
	.article-context-menu li.context-menu-item--danger:hover {
		background: rgba(252, 165, 165, 0.12);
	}
}

/* Favorit — goldenes Stern-Icon */
.article-context-menu .ctx-fav-active {
	color: #f59e0b !important;
	opacity: 1 !important;
}

/* Leerer Zustand im Tags-Untermenü */
.article-context-menu .context-menu-item--empty {
	cursor: default;
	color: var(--color-text-lighter);
	font-style: italic;
}

.article-context-menu .context-menu-item--empty:hover {
	background: transparent;
}

/* Tags-Untermenü: farbiger Punkt + Name + Häkchen */
.article-context-menu .ctx-tag-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
}

.article-context-menu .ctx-tag-name {
	flex: 1;
}

.article-context-menu .ctx-tag-check {
	margin-left: auto;
	color: var(--color-primary, #0082c9) !important;
	opacity: 1 !important;
}

/* New-tag form inside tags submenu */
.article-context-menu .ctx-tag-new-form {
	flex-direction: column !important;
	gap: 6px;
	padding: 8px 10px;
	align-items: stretch;
	cursor: default;
}

.article-context-menu .ctx-tag-new-form:hover {
	background: transparent;
}

.article-context-menu .ctx-tag-new-input {
	width: 100%;
	padding: 4px 8px;
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 6px;
	font-size: 0.85em;
	background: var(--color-main-background);
	color: var(--color-main-text);
	outline: none;
	box-sizing: border-box;
}

.article-context-menu .ctx-tag-new-input:focus {
	border-color: var(--color-primary, #0082c9);
}

.article-context-menu .ctx-tag-new-row {
	display: flex;
	align-items: center;
	gap: 6px;
}

.article-context-menu .ctx-tag-new-swatches {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
	flex: 1;
}

.article-context-menu .ctx-tag-new-swatch {
	width: 14px;
	height: 14px;
	border-radius: 50%;
	cursor: pointer;
	border: 2px solid transparent;
	flex-shrink: 0;
	box-sizing: border-box;
	transition: transform 0.1s;
}

.article-context-menu .ctx-tag-new-swatch:hover {
	transform: scale(1.25);
}

.article-context-menu .ctx-tag-new-swatch--active {
	border-color: var(--color-main-text, #222);
}

.article-context-menu .ctx-tag-new-btn {
	padding: 2px 8px;
	border: none;
	border-radius: 6px;
	background: var(--color-primary, #0082c9);
	color: #fff;
	cursor: pointer;
	font-size: 1em;
	font-weight: bold;
	line-height: 1.6;
	flex-shrink: 0;
}

.article-context-menu .ctx-tag-new-btn:disabled {
	opacity: 0.35;
	cursor: not-allowed;
}

.article-context-menu .ctx-tag-new-btn:not(:disabled):hover {
	filter: brightness(1.12);
}

/* ── Report-Dialog ──────────────────────────────────────────────────────── */
/* Zentriertes Modal — kein window.prompt(), passt zum bestehenden UI-Stil  */

.report-dialog-backdrop {
	position: fixed;
	inset: 0;
	z-index: 10000;
	background: rgba(0, 0, 0, 0.45);
}

.report-dialog {
	position: fixed;
	z-index: 10001;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	width: min(420px, calc(100vw - 32px));
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 14px;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.22);
	padding: 20px 22px 18px;
	animation: ctx-menu-appear 0.14s ease;
}

.report-dialog-title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 15px;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 8px;
}

.report-dialog-title .material-design-icon {
	color: var(--color-warning, #e6a817);
	opacity: 1;
}

.report-dialog-url {
	font-size: 12px;
	color: var(--color-text-lighter);
	word-break: break-all;
	margin-bottom: 14px;
	max-height: 36px;
	overflow: hidden;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
}

.report-dialog-textarea {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: 8px;
	font-size: 13px;
	line-height: 1.5;
	resize: vertical;
	background: var(--color-background-dark, #f5f5f7);
	color: var(--color-main-text);
	outline: none;
	box-sizing: border-box;
	font-family: inherit;
	margin-bottom: 14px;
}

.report-dialog-textarea:focus {
	border-color: var(--color-primary, #0082c9);
}

.report-dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.report-dialog-btn {
	padding: 7px 16px;
	border-radius: 8px;
	border: none;
	font-size: 13px;
	font-weight: 500;
	cursor: pointer;
	transition: filter 0.12s;
}

.report-dialog-btn:disabled {
	opacity: 0.45;
	cursor: not-allowed;
}

.report-dialog-btn--cancel {
	background: var(--color-background-hover, #f0f0f0);
	color: var(--color-main-text);
}

.report-dialog-btn--cancel:not(:disabled):hover {
	filter: brightness(0.94);
}

.report-dialog-btn--submit {
	background: var(--color-primary, #0082c9);
	color: #fff;
}

.report-dialog-btn--submit:not(:disabled):hover {
	filter: brightness(1.12);
}
</style>
