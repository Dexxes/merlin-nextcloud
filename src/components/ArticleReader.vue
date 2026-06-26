<template>
	<div class="article-reader" :class="readerClasses">

		<!-- Left floating panel: navigation + read toggle (Desktop only) -->
		<div v-if="!isMobile" class="reader-panel reader-panel--left">
			<NcButton
				:title="t('merlin', 'Back to list')"
				@click="$emit('close')">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
			</NcButton>

			<NcButton
				:title="t('merlin', 'Archive and return to list')"
				type="secondary"
				@click="archiveAndClose">
				<template #icon>
					<ArchiveArrowDown :size="20" />
				</template>
			</NcButton>

			<NcButton
				:title="isFavoriteFromStore ? t('merlin', 'Remove from favorites') : t('merlin', 'Add to favorites')"
				@click="toggleFavoriteStatus">
				<template #icon>
					<Star v-if="isFavoriteFromStore" :size="20" class="fav-btn--active" />
					<StarOutline v-else :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Right floating panel: display controls + actions (Desktop only) -->
		<div v-if="!isMobile" class="reader-panel reader-panel--right">
			<NcButton
				:title="isDarkMode ? t('merlin', 'Switch to light mode') : t('merlin', 'Switch to dark mode')"
				@click="toggleDarkMode">
				<template #icon>
					<WeatherNight v-if="!isDarkMode" :size="20" />
					<WhiteBalanceSunny v-else :size="20" />
				</template>
			</NcButton>

			<NcButton
				:title="t('merlin', 'Decrease font size')"
				@click="decreaseFontSize">
				<template #icon>
					<FormatFontSizeDecrease :size="20" />
				</template>
			</NcButton>

			<NcButton
				:title="t('merlin', 'Increase font size')"
				@click="increaseFontSize">
				<template #icon>
					<FormatFontSizeIncrease :size="20" />
				</template>
			</NcButton>

			<!-- Share dropdown -->
			<div ref="shareWrapperRef" class="share-wrapper">
				<NcButton
					:title="t('merlin', 'Share article')"
					:class="{ 'share-btn--active': shareMenuOpen }"
					@click.stop="toggleShareMenu">
					<template #icon>
						<ShareVariant :size="20" />
					</template>
				</NcButton>

				<Teleport to="body">
					<div
						v-if="shareMenuOpen"
						class="export-backdrop"
						@click="shareMenuOpen = false" />
					<ul
						v-if="shareMenuOpen"
						class="export-menu"
						:class="{ 'share-menu--mobile': isMobile, 'dark-mode': isDarkMode }"
						:style="shareMenuStyle">
						<li v-if="hasNativeShare" @click="nativeShare(); shareMenuOpen = false">
							<ShareVariant :size="16" />
							<span>{{ t('merlin', 'Share…') }}</span>
						</li>
						<li @click="copyLink(); shareMenuOpen = false">
							<ContentCopy :size="16" />
							<span>{{ t('merlin', 'Copy link') }}</span>
						</li>
						<li @click="shareByEmail(); shareMenuOpen = false">
							<Email :size="16" />
							<span>{{ t('merlin', 'Send by email') }}</span>
						</li>
						<li @click="shareToBluesky(); shareMenuOpen = false">
							<Butterfly :size="16" />
							<span>{{ t('merlin', 'Share to Bluesky') }}</span>
						</li>
						<li @click="shareToMastodon(); shareMenuOpen = false">
							<Mastodon :size="16" />
							<span>{{ t('merlin', 'Share to Mastodon') }}</span>
						</li>
					</ul>
				</Teleport>
			</div>

			<!-- Tag picker -->
			<div ref="tagWrapperRef" class="tag-wrapper">
				<NcButton
					:title="t('merlin', 'Manage tags')"
					:class="{ 'tag-btn--active': tagMenuOpen }"
					@click.stop="toggleTagMenu">
					<template #icon>
						<Tag :size="20" />
					</template>
				</NcButton>
			</div>

			<!-- Export dropdown -->
			<div ref="exportWrapperRef" class="export-wrapper">
				<NcButton
					:title="t('merlin', 'Export article')"
					:class="{ 'export-btn--active': exportMenuOpen }"
					@click.stop="toggleExportMenu">
					<template #icon>
						<Download :size="20" />
					</template>
				</NcButton>

				<Teleport to="body">
					<div
						v-if="exportMenuOpen"
						class="export-backdrop"
						@click="exportMenuOpen = false" />
					<ul
						v-if="exportMenuOpen"
						class="export-menu"
						:class="{ 'dark-mode': isDarkMode }"
						:style="exportMenuStyle">
						<li @click="exportHtml(); exportMenuOpen = false">
							<Download :size="16" />
							<span>{{ t('merlin', 'Export as HTML') }}</span>
						</li>
					</ul>
				</Teleport>
			</div>

			<!-- Tag picker Teleport (shared desktop+mobile) -->
			<Teleport to="body">
				<div v-if="tagMenuOpen" class="export-backdrop" @click="tagMenuOpen = false" />
				<ul
					v-if="tagMenuOpen"
					class="export-menu"
					:class="{ 'tag-menu--mobile': isMobile, 'dark-mode': isDarkMode }"
					:style="tagMenuStyle">
					<li v-if="allTags.length === 0" class="tag-menu-empty">
						<span>{{ t('merlin', 'No tags defined yet') }}</span>
					</li>
					<li
						v-for="tag in allTags"
						:key="tag.id"
						@click="handleTagToggle(tag)">
						<span class="tag-color-dot" :style="{ backgroundColor: tag.color }" />
						<span class="tag-name">{{ tag.name }}</span>
						<Check v-if="articleHasTag(tag)" :size="16" class="tag-check" />
					</li>

					<!-- New tag form -->
					<li class="tag-new-form" @click.stop>
						<input
							v-model="newTagName"
							class="tag-new-input"
							type="text"
							:placeholder="t('merlin', 'New tag…')"
							@keyup.enter="createAndAssignTag" />
						<div class="tag-new-row">
							<div class="tag-new-swatches">
								<span
									v-for="color in tagColors"
									:key="color"
									class="tag-new-swatch"
									:class="{ 'tag-new-swatch--active': newTagColor === color }"
									:style="{ backgroundColor: color }"
									@click.stop="newTagColor = color" />
							</div>
							<button
								class="tag-new-btn"
								:disabled="!newTagName.trim()"
								@click.stop="createAndAssignTag">+</button>
						</div>
					</li>
				</ul>
			</Teleport>

			<!-- Delete button -->
			<NcButton
				:title="t('merlin', 'Delete article')"
				class="delete-btn"
				@click="confirmDelete">
				<template #icon>
					<Delete :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Mobile bottom toolbar (≤768px) — drei gleichbreite Buttons.
		     Blendet beim Runterscrollen aus, beim Hochscrollen / am Artikelende wieder ein
		     (gleiches Verhalten wie showBottomBar in ArticleReaderView.swift). -->
		<div v-if="isMobile" class="mobile-toolbar" :class="{ 'mobile-toolbar--hidden': !showBottomBar }">
			<NcButton
				:title="t('merlin', 'Back to list')"
				@click="$emit('close')">
				<template #icon><ArrowLeft :size="20" /></template>
			</NcButton>

			<NcButton
				:title="t('merlin', 'Archive and return to list')"
				@click="archiveAndClose">
				<template #icon><ArchiveArrowDown :size="20" /></template>
			</NcButton>

			<NcButton
				:title="t('merlin', 'More options')"
				@click.stop="mobileMoreOpen = !mobileMoreOpen">
				<template #icon><DotsHorizontal :size="20" /></template>
			</NcButton>
		</div>

		<!-- Mobile „Mehr"-Menü — enthält auch Teilen + Erscheinungsbild, die früher eigene Buttons in der Bottom-Bar waren -->
		<Teleport to="body">
			<div v-if="mobileMoreOpen" class="export-backdrop" @click="mobileMoreOpen = false" />
			<ul v-if="mobileMoreOpen" class="export-menu mobile-more-menu" :class="{ 'dark-mode': isDarkMode }">
				<li @click="toggleFavoriteStatus(); mobileMoreOpen = false">
					<Star v-if="isFavoriteFromStore" :size="16" class="fav-btn--active" />
					<StarOutline v-else :size="16" />
					<span>{{ isFavoriteFromStore ? t('merlin', 'Remove from favorites') : t('merlin', 'Add to favorites') }}</span>
				</li>

				<li @click="mobileMoreOpen = false; tagMenuOpen = true">
					<Tag :size="16" />
					<span>{{ t('merlin', 'Manage tags') }}</span>
				</li>

				<li @click.stop="mobileMoreOpen = false; toggleShareMenu()">
					<ShareVariant :size="16" />
					<span>{{ t('merlin', 'Share') }}</span>
				</li>

				<li @click="cycleFontSize(); mobileMoreOpen = false">
					<FormatFontSizeIncrease :size="16" />
					<span>{{ t('merlin', 'Adjust appearance') }}</span>
				</li>

				<li @click="toggleDarkMode(); mobileMoreOpen = false">
					<WeatherNight v-if="!isDarkMode" :size="16" />
					<WhiteBalanceSunny v-else :size="16" />
					<span>{{ isDarkMode ? t('merlin', 'Switch to light mode') : t('merlin', 'Switch to dark mode') }}</span>
				</li>
				<li class="more-menu-danger" @click="confirmDelete(); mobileMoreOpen = false">
					<Delete :size="16" /><span>{{ t('merlin', 'Delete article') }}</span>
				</li>
			</ul>
		</Teleport>

		<!-- Reading progress bar: Position (links/rechts/oben/unten) und Farbe kommen aus den Settings,
		     genau wie bei den Mobile-Apps (progressEdge / accentColor). -->
		<div
			v-if="showProgressBar"
			class="reader-progress-bar"
			:class="`reader-progress-bar--${settings.progressEdge}`"
			:style="progressBarStyle" />

		<!-- Scrollable article content -->
		<div ref="readerContent" class="reader-content">
			<article :style="articleStyles">
				<header class="article-header">
					<h1>{{ article.title }}</h1>

					<p v-if="article.excerpt" class="article-excerpt">{{ article.excerpt }}</p>

					<div class="article-metadata">
						<span v-if="article.author" class="meta-author">
							<Account :size="16" />
							{{ article.author }}
						</span>
						<a
							v-if="article.siteName"
							class="meta-site"
							:href="article.url"
							target="_blank"
							rel="noopener noreferrer">
							<Web :size="16" />
							{{ article.siteName }}
						</a>
						<span class="meta-date">
							<Calendar :size="16" />
							{{ article.publishedAt ? formatDate(article.publishedAt) : formatDate(article.createdAt) }}
						</span>
						<span class="meta-added" title="An diesem Tag wurde der Artikel in die Liste aufgenommen">
							<CalendarPlus :size="16" />
							{{ t('merlin', '') }}{{ formatDate(article.createdAt) }}
						</span>
						<span class="meta-time">
							<Clock :size="16" />
							{{ article.readingTime }} Min.
						</span>
					</div>

					<!-- Article tags -->
					<div v-if="articleTagsFromStore.length > 0" class="article-tags-row">
						<span
							v-for="tag in articleTagsFromStore"
							:key="tag.id"
							class="article-tag-chip"
							:style="{ backgroundColor: tag.color || '#6b7280' }">{{ tag.name }}</span>
					</div>
				</header>

				<!-- eslint-disable-next-line vue/no-v-html -->
				<div class="article-body" v-html="processedContent" />

				<!-- Article footer actions -->
				<footer class="article-footer">
					<div class="next-article-divider" />
					<div class="article-footer-actions">
						<!-- Archive and return to list -->
						<button class="footer-action-btn footer-action-btn--read" @click="archiveAndClose">
							<ArchiveArrowDown :size="18" />
							<span>{{ t('merlin', 'Archive and return') }}</span>
						</button>

						<!-- Next article (if available) -->
						<button v-if="nextArticle" class="footer-action-btn footer-action-btn--next" @click="archiveAndGoToNext">
							<span class="next-article-label">{{ t('merlin', 'Archive and go to next') }}</span>
							<span class="next-article-title">{{ nextArticle.title }}</span>
							<ArrowRight :size="18" class="next-article-arrow" />
						</button>
					</div>
				</footer>
			</article>
		</div>
	</div>
</template>

<script>
import { mapState, mapActions } from 'vuex'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import WeatherNight from 'vue-material-design-icons/WeatherNight.vue'
import WhiteBalanceSunny from 'vue-material-design-icons/WhiteBalanceSunny.vue'
import FormatFontSizeDecrease from 'vue-material-design-icons/FormatFontSizeDecrease.vue'
import FormatFontSizeIncrease from 'vue-material-design-icons/FormatFontSizeIncrease.vue'
import Download from 'vue-material-design-icons/Download.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Email from 'vue-material-design-icons/Email.vue'
import Butterfly from 'vue-material-design-icons/Butterfly.vue'
import Mastodon from 'vue-material-design-icons/Mastodon.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import ArchiveArrowDown from 'vue-material-design-icons/ArchiveArrowDown.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import Account from 'vue-material-design-icons/Account.vue'
import Web from 'vue-material-design-icons/Web.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import Clock from 'vue-material-design-icons/Clock.vue'
import axios from '@nextcloud/axios'
import * as articlesAPI from '../api/articles'
import * as highlightsAPI from '../api/highlights'
import { HighlightEngine } from '../highlight-engine'

const TAG_COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899']

// Numerische Schriftgrößen-Stufen (identisch mit iOS AppearanceSheet).
// Legacy-Strings ('small' etc.) werden beim Laden migriert.
const FONT_SIZE_STEPS = [13, 15, 17, 19, 21, 24]
const FONT_SIZE_DEFAULT = 17

// Konvertiert einen Einstellungswert (numerisch oder alter String-Key) in eine Zahl.
function parseFontSize(val) {
	const n = parseInt(val, 10)
	if (!isNaN(n)) return n
	// Rückwärtskompatibilität für alte String-Werte
	const legacyMap = { small: 15, medium: 17, large: 20, 'x-large': 24 }
	return legacyMap[val] || FONT_SIZE_DEFAULT
}

const FONT_FAMILIES = {
	default:    "'Lora', Georgia, serif",
	serif:      "Georgia, 'Times New Roman', 'Palatino Linotype', serif",
	'sans-serif': "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif",
	monospace:  "'Courier New', Courier, 'Lucida Console', monospace",
}

export default {
	name: 'ArticleReader',

	components: {
		NcButton,
		ArrowLeft,
		WeatherNight,
		WhiteBalanceSunny,
		FormatFontSizeDecrease,
		FormatFontSizeIncrease,
		Download,
		ShareVariant,
		ContentCopy,
		Email,
		Butterfly,
		Mastodon,
		Delete,
		DotsHorizontal,
		CheckAll,
		ArchiveArrowDown,
		Star,
		StarOutline,
		Tag,
		Check,
		ArrowRight,
		Account,
		Web,
		Calendar,
		CalendarPlus,
		Clock,
	},

	emits: ['close', 'delete-article', 'open-next-article'],

	props: {
		article: {
			type: Object,
			required: true,
		},
		nextArticle: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			isDarkMode: false,
			fontSize: FONT_SIZE_DEFAULT,
			exportMenuOpen: false,
			exportMenuStyle: {},
			shareMenuOpen: false,
			shareMenuStyle: {},
			hasNativeShare: typeof navigator !== 'undefined' && !!navigator.share,
			isMobile: false,
			showBottomBar: true,
			_lastScrollTop: 0,
			mobileMoreOpen: false,
			tagMenuOpen: false,
			tagMenuStyle: {},
			newTagName: '',
			newTagColor: TAG_COLORS[5], // blue default
			tagColors: TAG_COLORS,
			scrollPct: 0,
			_scrollTimer: null,
			_highlightEngine: null,
		}
	},

	computed: {
		...mapState(['settings', 'tags']),

		isArchivedFromStore() {
			const stored = this.$store.state.articles.find(a => a.id === this.article.id)
			return stored ? !!stored.isArchived : !!this.article.isArchived
		},

		isFavoriteFromStore() {
			const stored = this.$store.state.articles.find(a => a.id === this.article.id)
			return stored ? !!stored.isFavorite : !!this.article.isFavorite
		},

		articleTagsFromStore() {
			const stored = this.$store.state.articles.find(a => a.id === this.article.id)
			return stored ? (stored.tags || []) : (this.article.tags || [])
		},

		allTags() {
			return this.$store.state.tags || []
		},

		readerClasses() {
			return {
				'dark-mode': this.isDarkMode,
			}
		},

		articleStyles() {
			return {
				fontSize:   this.fontSize + 'px',
				fontFamily: FONT_FAMILIES[this.settings.fontFamily] || FONT_FAMILIES.default,
				maxWidth:   '800px',
				lineHeight: this.settings.lineHeight || '1.6',
				margin:     '0 auto',
			}
		},

		showProgressBar() {
			// progressEdge ersetzt showProgress: alles außer 'off' zeigt den Balken
			return this.settings.progressEdge !== 'off'
		},

		// Position + Farbe analog zu progressBar(in:) in ArticleReaderView.swift (iOS):
		// links/rechts wachsen vertikal von oben, oben/unten wachsen horizontal von links.
		progressBarStyle() {
			const color = this.settings.accentColor || '#FF3B30'
			const pct = this.scrollPct + '%'
			const THICKNESS = '4px'
			switch (this.settings.progressEdge) {
			case 'left':
				return { left: 0, top: 0, width: THICKNESS, height: pct, background: color }
			case 'right':
				return { right: 0, top: 0, width: THICKNESS, height: pct, background: color }
			case 'top':
				return { top: 0, left: 0, height: THICKNESS, width: pct, background: color }
			case 'bottom':
			default:
				return { bottom: 0, left: 0, height: THICKNESS, width: pct, background: color }
			}
		},

		processedContent() {
			return this.article.content || ''
		},
	},

	watch: {
		// Keep local fontSize in sync when the user changes it in the Settings panel
		'settings.fontSize'(val) {
			this.fontSize = parseFontSize(val)
		},

		'article.id'() {
			this.$nextTick(() => {
				this._restoreScrollPosition()
				this._initHighlights()
				this._addImageErrorHandlers()
			})
		},
	},

	mounted() {
		this.isDarkMode = this.settings.theme === 'dark'
			|| (this.settings.theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches)
		this.fontSize = parseFontSize(this.settings.fontSize)
		this._checkMobile = () => { this.isMobile = window.innerWidth <= 768 }
		this._checkMobile()
		window.addEventListener('resize', this._checkMobile)
		this.$nextTick(() => {
			this._restoreScrollPosition()
			this._initHighlights()
			this._addImageErrorHandlers()
			if (this.$refs.readerContent) {
				this._onScroll = this._handleScroll.bind(this)
				this.$refs.readerContent.addEventListener('scroll', this._onScroll, { passive: true })
			}
		})
	},

	beforeUnmount() {
		window.removeEventListener('resize', this._checkMobile)
		if (this._onScroll && this.$refs.readerContent) {
			this.$refs.readerContent.removeEventListener('scroll', this._onScroll)
		}
		clearTimeout(this._scrollTimer)

		// Endgültigen Fortschritt sofort schreiben (statt auf den Debounce-Timer zu warten)
		// und die Karten-Ansicht benachrichtigen, damit der Balken ohne Reload erscheint
		// (entspricht .articleProgressDidUpdate in den Mobile-Apps).
		if (this.settings.saveProgress) {
			localStorage.setItem(`merlin_pct_${this.article.id}`, String(this.scrollPct / 100))
		}
		window.dispatchEvent(new CustomEvent('merlin-progress-updated', { detail: { articleId: this.article.id } }))
		if (this._highlightEngine) {
			this._highlightEngine.destroy()
			this._highlightEngine = null
		}
	},

	methods: {
		...mapActions(['toggleRead', 'toggleArchive', 'toggleFavorite', 'addTag', 'addTagToArticle', 'removeTagFromArticle']),

		async toggleFavoriteStatus() {
			try {
				await this.toggleFavorite(this.article.id)
			} catch (error) {
				console.error('Failed to toggle favorite:', error)
			}
		},

		articleHasTag(tag) {
			return this.articleTagsFromStore.some(t => t.id === tag.id)
		},

		async handleTagToggle(tag) {
			try {
				if (this.articleHasTag(tag)) {
					await this.removeTagFromArticle({ articleId: this.article.id, tagId: tag.id })
				} else {
					await this.addTagToArticle({ articleId: this.article.id, tagId: tag.id })
				}
			} catch (error) {
				console.error('Failed to toggle tag:', error)
			}
		},

		async createAndAssignTag() {
			const name = this.newTagName.trim()
			if (!name) return
			try {
				const tag = await this.addTag({ name, color: this.newTagColor })
				await this.addTagToArticle({ articleId: this.article.id, tagId: tag.id })
				this.newTagName = ''
			} catch (error) {
				console.error('Failed to create tag:', error)
			}
		},

		toggleTagMenu() {
			if (!this.tagMenuOpen) {
				if (!this.isMobile) {
					const btn = this.$refs.tagWrapperRef
					if (btn) {
						const rect = btn.getBoundingClientRect()
						const menuWidth = 200
						const left = Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8)
						this.tagMenuStyle = {
							top: rect.bottom + 6 + 'px',
							left: Math.max(8, left) + 'px',
						}
					}
				}
			}
			this.tagMenuOpen = !this.tagMenuOpen
		},

		toggleExportMenu() {
			if (!this.exportMenuOpen) {
				const btn = this.$refs.exportWrapperRef
				if (btn) {
					const rect = btn.getBoundingClientRect()
					const menuWidth = 190
					const left = Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8)
					this.exportMenuStyle = {
						top: rect.bottom + 6 + 'px',
						left: Math.max(8, left) + 'px',
					}
				}
			}
			this.exportMenuOpen = !this.exportMenuOpen
		},

		toggleDarkMode() {
			this.isDarkMode = !this.isDarkMode
		},

		increaseFontSize() {
			const next = FONT_SIZE_STEPS.find(s => s > this.fontSize)
			if (next !== undefined) this.fontSize = next
		},

		decreaseFontSize() {
			const prev = [...FONT_SIZE_STEPS].reverse().find(s => s < this.fontSize)
			if (prev !== undefined) this.fontSize = prev
		},

		// Mobile: rotiert durch alle Schriftgrößen in einer Schaltfläche
		cycleFontSize() {
			const idx = FONT_SIZE_STEPS.findIndex(s => s >= this.fontSize)
			this.fontSize = FONT_SIZE_STEPS[(idx + 1) % FONT_SIZE_STEPS.length]
		},

		goToNext() {
			if (this.nextArticle) {
				this.$emit('open-next-article', this.nextArticle)
			}
		},

		async archiveAndGoToNext() {
			if (!this.nextArticle) return
			// Zielartikel VOR dem await sichern: durch das Archivieren wird der
			// aktuelle Artikel reaktiv aus state.articles entfernt, wodurch das
			// nextArticle-Computed in App.vue sofort auf null springt – die
			// Prop wäre im finally-Block dann null und würde openArticle(null)
			// auslösen, was eine leere Seite erzeugt.
			const target = this.nextArticle
			try {
				if (!this.isArchivedFromStore) {
					await this.toggleArchive(this.article.id)
				}
			} catch (error) {
				console.error('Failed to archive article:', error)
			} finally {
				this.$emit('open-next-article', target)
			}
		},

		async toggleReadStatus() {
			try {
				// Dispatch the Vuex action — this calls the API AND updates the store,
				// so the article list in the overview reflects the change immediately.
				await this.toggleRead(this.article.id)
				showSuccess(this.isReadFromStore
					? this.t('merlin', 'Marked as read')
					: this.t('merlin', 'Marked as unread'))
			} catch (error) {
				console.error('Failed to toggle read status:', error)
			}
		},

		async archiveAndClose() {
			try {
				if (!this.isArchivedFromStore) {
					await this.toggleArchive(this.article.id)
				}
				this.$emit('close')
			} catch (error) {
				console.error('Failed to archive article:', error)
				this.$emit('close')
			}
		},

		toggleShareMenu() {
			if (!this.shareMenuOpen) {
				// Auf Mobile wird der Teilen-Dialog per CSS unten rechts verankert
				// (wie das Tag- und Mehr-Menü) statt relativ zu einem Button positioniert,
				// da der frühere Toolbar-Button dafür entfernt wurde.
				const btn = this.isMobile ? null : this.$refs.shareWrapperRef
				if (btn) {
					const rect = btn.getBoundingClientRect()
					const menuWidth = 190
					const left = Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8)
					this.shareMenuStyle = {
						top: rect.bottom + 6 + 'px',
						left: Math.max(8, left) + 'px',
					}
				}
			}
			this.shareMenuOpen = !this.shareMenuOpen
		},

		async nativeShare() {
			try {
				await navigator.share({
					title: this.article.title,
					url: this.article.url,
				})
			} catch (error) {
				// User cancelled or share failed — no error shown
			}
		},

		async copyLink() {
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

		shareByEmail() {
			const subject = encodeURIComponent(this.article.title || '')
			const body = encodeURIComponent(this.article.url)
			window.open(`mailto:?subject=${subject}&body=${body}`)
		},

		shareToBluesky() {
			const text = encodeURIComponent(`${this.article.title}\n${this.article.url}`)
			window.open(`https://bsky.app/intent/compose?text=${text}`, '_blank')
		},

		shareToMastodon() {
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

		confirmDelete() {
			if (confirm(this.t('merlin', 'Are you sure you want to delete this article?'))) {
				this.$emit('delete-article', this.article.id)
			}
		},

		async exportHtml() {
			try {
				await articlesAPI.exportHtml(this.article.id)
				showSuccess(this.t('merlin', 'HTML exported successfully'))
			} catch (error) {
				showError(this.t('merlin', 'Failed to export HTML'))
			}
		},

		// ── Scroll progress ────────────────────────────────────────────────

		_handleScroll() {
			const el = this.$refs.readerContent
			if (!el) return
			const max = el.scrollHeight - el.clientHeight
			this.scrollPct = max > 0 ? (el.scrollTop / max) * 100 : 0
			this._updateBottomBarVisibility(el.scrollTop, max)

			if (this.settings.saveProgress) {
				clearTimeout(this._scrollTimer)
				this._scrollTimer = setTimeout(() => {
					localStorage.setItem(`merlin_scroll_${this.article.id}`, el.scrollTop)
					// Prozentwert (0–1) separat speichern, damit die Artikel-Karte den
					// Fortschritt anzeigen kann, ohne den vollen Scroll-Zustand kennen zu müssen
					// (analog zu PreferencesStore.saveScrollProgress in den Mobile-Apps).
					localStorage.setItem(`merlin_pct_${this.article.id}`, String(this.scrollPct / 100))
				}, 500)
			}
		},

		_restoreScrollPosition() {
			const el = this.$refs.readerContent
			if (!el) return

			if (this.settings.resumeOnOpen) {
				const saved = localStorage.getItem(`merlin_scroll_${this.article.id}`)
				el.scrollTop = saved ? parseInt(saved, 10) : 0
			} else {
				el.scrollTop = 0
			}

			const max = el.scrollHeight - el.clientHeight
			this.scrollPct = max > 0 ? (el.scrollTop / max) * 100 : 0
			// Bar beim Öffnen/Reset immer sichtbar starten und den Referenzpunkt
			// für die Scrollrichtungs-Erkennung neu setzen.
			this._lastScrollTop = el.scrollTop
			this.showBottomBar = true
		},

		// Blendet die mobile Bottom-Toolbar beim Runterscrollen aus und beim
		// Hochscrollen bzw. am Artikelende wieder ein — exakt das Verhalten aus
		// ArticleReaderView.swift (onScrollGeometryChange-Handler, iOS).
		_updateBottomBarVisibility(newOffset, scrollable) {
			const delta = newOffset - this._lastScrollTop
			// Innerhalb von 160px vom Ende gilt der Artikel als "fast fertig gelesen".
			const isNearBottom = scrollable > 0 ? (scrollable - newOffset < 160) : true

			if (Math.abs(delta) > 4) {
				const scrollingDown = delta > 0 && newOffset > 40
				const next = !(scrollingDown && !isNearBottom)
				// Menüs, die an der Toolbar verankert sind, beim Ausblenden mitschließen –
				// sonst hängen sie ohne ihren Anker in der Luft.
				if (this.showBottomBar && !next) {
					this.mobileMoreOpen = false
					this.shareMenuOpen = false
					this.tagMenuOpen = false
				}
				this.showBottomBar = next
			}
			this._lastScrollTop = newOffset
		},

		// ── Image error placeholders ────────────────────────────────────────

		_addImageErrorHandlers() {
			const bodyEl = this.$el?.querySelector('.article-body')
			if (!bodyEl) return
			bodyEl.querySelectorAll('img').forEach(img => {
				// Already broken (cached failure)
				if (img.complete && img.naturalWidth === 0) {
					this._replaceWithPlaceholder(img)
				} else {
					img.addEventListener('error', () => this._replaceWithPlaceholder(img), { once: true })
				}
			})
		},

		_replaceWithPlaceholder(img) {
			if (!img.parentNode) return
			const ph = document.createElement('div')
			ph.className = 'img-placeholder'
			const alt = img.alt ? `<span class="img-placeholder__label">${img.alt}</span>` : ''
			ph.innerHTML = `<span class="img-placeholder__icon" aria-hidden="true"></span>${alt}`
			img.parentNode.replaceChild(ph, img)
		},

		// ── Highlights ──────────────────────────────────────────────────────

		async _initHighlights() {
			// Tear down previous engine (article switch)
			if (this._highlightEngine) {
				this._highlightEngine.destroy()
				this._highlightEngine = null
			}

			const bodyEl = this.$el?.querySelector('.article-body')
			if (!bodyEl) return

			this._highlightEngine = new HighlightEngine(bodyEl, {
				onCreate: async ({ highlightedText, startXpath, startOffset, endXpath, endOffset, color, tempId }) => {
					try {
						const saved = await highlightsAPI.createHighlight(this.article.id, {
							highlightedText,
							startXpath,
							startOffset,
							endXpath,
							endOffset,
							color,
						})
						// Replace the temp DOM id with the real server id
						this._highlightEngine?.updateTempId(tempId, saved.id)
					} catch (error) {
						console.error('Failed to save highlight:', error)
					}
				},
				onDelete: async (highlightId) => {
					try {
						await highlightsAPI.deleteHighlight(highlightId)
					} catch (error) {
						console.error('Failed to delete highlight:', error)
					}
				},
			})

			// Load and restore existing highlights for this article
			try {
				const highlights = await highlightsAPI.getHighlights(this.article.id)
				this._highlightEngine.applyHighlights(highlights)
			} catch (error) {
				console.error('Failed to load highlights:', error)
			}
		},

		formatDate(dateString) {
			const date = new Date(dateString)
			// Use UTC to check if there is a meaningful time component — avoids
			// timezone-shifted midnight being shown as non-midnight local time.
			const hasTime = date.getUTCHours() !== 0 || date.getUTCMinutes() !== 0
			return new Intl.DateTimeFormat('default', {
				year: '2-digit',
				month: '2-digit',
				day: '2-digit',
				...(hasTime ? { hour: '2-digit', minute: '2-digit' } : {}),
			}).format(date)
		},
	},
}
</script>

<style scoped>
/* ── Lora: Variable-Font (Regular + Italic) ─────────────────────────── */
@font-face {
	font-family: 'Lora';
	src: url('../../fonts/Lora-VariableFont_wght.ttf') format('truetype');
	font-weight: 100 900;
	font-style: normal;
	font-display: swap;
}

@font-face {
	font-family: 'Lora';
	src: url('../../fonts/Lora-Italic-VariableFont_wght.ttf') format('truetype');
	font-weight: 100 900;
	font-style: italic;
	font-display: swap;
}

.article-reader {
	position: relative;
	height: 100%;
	overflow: hidden;
	background: var(--color-main-background);
	transition: background-color 0.3s ease, color 0.3s ease;
}

.article-reader.dark-mode {
	background: #000000;
	color: #e0e0e0;
}

/* Floating side panels */
.reader-panel {
	position: absolute;
	top: 16px;
	z-index: 10;
	display: flex;
	flex-direction: column;
	gap: 6px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 6px;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.dark-mode .reader-panel {
	background: #0d0d0d;
	border-color: #2a2a2a;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
}

/* NcButton zieht Icon-/Textfarbe normalerweise aus Nextclouds globalen
   Theme-Variablen (--color-main-text etc.) — die folgen dem Theme der
   Nextcloud-Instanz, nicht dem hier per Settings erzwungenen Reader-Theme.
   Ohne diese Overrides bleiben Icons auf dunklem Panel-/Toolbar-Hintergrund
   in der Farbe des hellen Nextcloud-Themes und sind kaum erkennbar. */
.dark-mode .reader-panel :deep(.button-vue) {
	color: #e0e0e0;
}

.dark-mode .reader-panel :deep(.button-vue:hover) {
	background: rgba(255, 255, 255, 0.08);
}

.reader-panel--left {
	left: 12px;
}

.reader-panel--right {
	right: 12px;
}

/* Scrollable content — side padding leaves room for the floating panels */
.reader-content {
	height: 100%;
	overflow-y: auto;
	padding: 40px 80px;
	box-sizing: border-box;
}

article {
	width: 100%;
	font-family: 'Lora', Georgia, serif;
}

.article-header {
	margin-bottom: 40px;
}

.article-header h1 {
	font-size: 2.5em;
	font-weight: 700;
	line-height: 1.2;
	margin: 0 0 20px 0;
	color: inherit;
}

.article-excerpt {
	font-weight: 700;
	font-style: italic;
	font-size: 1.15em;
	line-height: 1.5;
	margin: 0 0 20px 0;
	color: var(--color-text-lighter);
}

.article-metadata {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	color: #666;
	font-size: 0.9em;
	margin-bottom: 20px;
}

.dark-mode .article-metadata {
	color: #999;
}

.article-metadata span,
.article-metadata a {
	display: flex;
	align-items: center;
	gap: 6px;
}

.meta-site {
	color: inherit;
	text-decoration: none;
	cursor: pointer;
}

.meta-site:hover {
	text-decoration: underline;
	color: var(--color-primary);
}

.article-body {
	font-size: inherit;
	line-height: inherit;
}

.article-body :deep(p) {
	margin: 1.5em 0;
}

.article-body :deep(img) {
	max-width: 100%;
	height: auto;
	display: block;
	margin: 2em auto;
	border-radius: 4px;
}

.article-body :deep(.img-placeholder) {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 180px;
	background: var(--color-background-hover, #f0f0f0);
	border: 1px dashed var(--color-border, #ccc);
	border-radius: 4px;
	margin: 2em auto;
	gap: 8px;
	color: var(--color-text-lighter, #888);
	box-sizing: border-box;
}

.dark-mode .article-body :deep(.img-placeholder) {
	background: rgba(255, 255, 255, 0.05);
	border-color: #444;
}

.article-body :deep(.img-placeholder__icon)::before {
	content: '\1F5BC';
	font-size: 2em;
	opacity: 0.4;
}

.article-body :deep(.img-placeholder__label) {
	font-size: 0.82em;
	font-style: italic;
	max-width: 80%;
	text-align: center;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.article-body :deep(a) {
	color: #0082c9;
	text-decoration: none;
}

.article-body :deep(a:hover) {
	text-decoration: underline;
}

.dark-mode .article-body :deep(a) {
	color: #58a6ff;
}

.article-body :deep(h2),
.article-body :deep(h3),
.article-body :deep(h4) {
	margin: 1.5em 0 0.5em 0;
	font-weight: 600;
	color: inherit;
}

.article-body :deep(blockquote) {
	border-left: 4px solid #0082c9;
	padding-left: 20px;
	margin: 1.5em 0;
	font-style: italic;
	color: #666;
}

.dark-mode .article-body :deep(blockquote) {
	border-left-color: #58a6ff;
	color: #999;
}

/* -------------------------------------------------------
   reader-quote: Blockquotes, die von normalizeQuotes() vor
   Readability als Zitate erkannt und normalisiert wurden.
   Gilt für seitenspezifische Strukturen (via content-filter
   <quotes>-Regeln) sowie Standard-<blockquote>-Elemente.
   ------------------------------------------------------- */

.article-body :deep(blockquote.reader-quote) {
	border-left: 4px solid var(--color-primary, #0082c9);
	background: var(--color-background-hover);
	border-radius: 0 var(--border-radius-large) var(--border-radius-large) 0;
	padding: 1em 1.25em 0.75em;
	margin: 2em 0 0.5em;
	font-style: italic;
}

/* Zitat-Text: <p> ohne Block-Abstände */
.article-body :deep(blockquote.reader-quote p) {
	margin: 0;
	line-height: 1.65;
	font-size: 1.05em;
}

/* Autor als <cite> */
.article-body :deep(blockquote.reader-quote cite.reader-quote__author) {
	display: block;
	margin-top: 0.6em;
	font-size: 0.875em;
	font-style: normal;
	font-weight: 600;
	color: var(--color-text-lighter, #666);
}

.article-body :deep(blockquote.reader-quote cite.reader-quote__author::before) {
	content: '— ';
}

/* Dark mode */
.dark-mode .article-body :deep(blockquote.reader-quote) {
	background: rgba(255, 255, 255, 0.05);
	border-left-color: #58a6ff;
}

.dark-mode .article-body :deep(blockquote.reader-quote cite.reader-quote__author) {
	color: #aaa;
}

/* <q>-Elemente: inline-Zitate (reader-quote-inline) */
.article-body :deep(q.reader-quote-inline) {
	font-style: italic;
	color: var(--color-text-lighter, #555);
}

.article-body :deep(q.reader-quote-inline::before) { content: '\201E'; } /* „ */
.article-body :deep(q.reader-quote-inline::after)  { content: '\201C'; } /* " */

.dark-mode .article-body :deep(q.reader-quote-inline) {
	color: #bbb;
}

/* -------------------------------------------------------
   merlin-infobox: Infokästen, die per Content-Filter-Regel
   (<infobox> in pre-filter) mit dieser Klasse markiert wurden.
   Kann in eigenen Styles vollständig überschrieben werden.
   ------------------------------------------------------- */

.article-body :deep(ul) {
	list-style: disc;
	padding-left: 1.5em;
	margin: 0.75em 0;
}

.article-body :deep(ol) {
	list-style: decimal;
	padding-left: 1.5em;
	margin: 0.75em 0;
}

.article-body :deep(li) {
	margin: 0.25em 0;
}

.article-body :deep(.merlin-infobox) {
	border-left: 4px solid var(--color-warning, #e69d00);
	background: var(--color-background-hover);
	border-radius: 0 var(--border-radius-large) var(--border-radius-large) 0;
	padding: 0.75em 1em;
	margin: 1.2em 0;
}

.dark-mode .article-body :deep(.merlin-infobox) {
	background: rgba(255, 255, 255, 0.05);
	border-left-color: #e6b450;
}

.article-body :deep(code) {
	background: #f5f5f5;
	padding: 2px 6px;
	border-radius: 3px;
	font-family: monospace;
	font-size: 0.9em;
}

.dark-mode .article-body :deep(code) {
	background: #2d2d2d;
}

.article-body :deep(pre) {
	background: #f5f5f5;
	padding: 16px;
	border-radius: 4px;
	overflow-x: auto;
	margin: 1.5em 0;
}

.dark-mode .article-body :deep(pre) {
	background: #2d2d2d;
}

.article-body :deep(figure) {
	margin: 2em 0;
	text-align: center;
}

.article-body :deep(figure img) {
	margin: 0 auto;
}

.article-body :deep(figcaption) {
	margin-top: 0.5em;
	font-size: 0.85em;
	font-style: italic;
	color: var(--color-text-lighter, #888);
	line-height: 1.4;
}

.dark-mode .article-body :deep(figcaption) {
	color: #999;
}

/* Article footer */
.article-footer {
	margin-top: 60px;
	padding-bottom: 40px;
}

.next-article-divider {
	height: 1px;
	background: var(--color-border);
	margin-bottom: 16px;
}

.article-footer-actions {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

/* Shared styles for all footer action buttons */
.footer-action-btn {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 14px 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	cursor: pointer;
	text-align: left;
	font-size: 0.95em;
	color: var(--color-main-text);
	background: var(--color-background-hover);
	transition: background 0.15s ease, border-color 0.15s ease;
}

.footer-action-btn:hover {
	background: var(--color-primary-light);
	border-color: var(--color-primary);
}

/* "Mark as read and return" — subdued style */
.footer-action-btn--read {
	color: var(--color-text-lighter);
	font-weight: 500;
}

/* "Next article" — prominent style */
.footer-action-btn--next {
	font-weight: 500;
}

.next-article-label {
	flex-shrink: 0;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.next-article-title {
	flex: 1;
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.next-article-arrow {
	flex-shrink: 0;
	margin-left: auto;
	color: var(--color-text-lighter);
}

.dark-mode .footer-action-btn {
	background: #2a2a2a;
	border-color: #444;
}

.dark-mode .footer-action-btn:hover {
	background: #333;
	border-color: #666;
}

@media (max-width: 768px) {
	.reader-content {
		padding: 24px 64px;
	}

	.article-header h1 {
		font-size: 1.8em;
	}
}

/* Delete button — danger accent.
   NcButton merges the class onto its own root <button class="button-vue delete-btn">,
   so we must target it with :deep() from the component host. */
/* Delete button — eigene Farbe, unabhängig von --color-error des Themes */
:deep(.delete-btn) {
	color: #9b1c1c !important;
}

:deep(.delete-btn:hover) {
	background: rgba(155, 28, 28, 0.10) !important;
}

.dark-mode :deep(.delete-btn) {
	color: #fca5a5 !important;
}

.dark-mode :deep(.delete-btn:hover) {
	background: rgba(252, 165, 165, 0.12) !important;
}

/* Favorit-Button — goldenes Icon wenn aktiv */
:deep(.fav-btn--active) {
	color: #f59e0b !important;
	opacity: 1 !important;
}

/* Tag-Wrapper (ähnlich wie export-wrapper) */
.tag-wrapper {
	position: relative;
}

.tag-btn--active :deep(.button-vue) {
	background: var(--color-background-hover);
}

/* Tag-Picker Dropdown */
.tag-color-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
	display: inline-block;
}

.tag-name {
	flex: 1;
}

.tag-check {
	margin-left: auto;
	color: var(--color-primary, #0082c9) !important;
	opacity: 1 !important;
}

.tag-menu-empty {
	cursor: default;
	color: var(--color-text-lighter);
	font-style: italic;
}

.tag-menu-empty:hover {
	background: transparent !important;
}

/* Tag-Picker auf Mobile: von unten rechts (wie Mehr-Menü) */
.tag-menu--mobile {
	bottom: calc(56px + env(safe-area-inset-bottom, 0px) + 4px) !important;
	right: 8px !important;
	top: auto !important;
	transform-origin: bottom right;
}

/* Teilen-Menü auf Mobile: gleiche Verankerung wie Tag- und Mehr-Menü,
   da der eigene Toolbar-Button entfernt und durch den Mehr-Menü-Eintrag ersetzt wurde */
.share-menu--mobile {
	bottom: calc(56px + env(safe-area-inset-bottom, 0px) + 4px) !important;
	right: 8px !important;
	top: auto !important;
	transform-origin: bottom right;
}

/* Article tags row in reader header */
.article-tags-row {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 12px;
}

.article-tag-chip {
	display: inline-block;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 0.82em;
	font-weight: 500;
	color: #fff;
	font-family: var(--font-face, system-ui, sans-serif);
}

/* Export dropdown wrapper */
.export-wrapper {
	position: relative;
}

.export-btn--active :deep(.button-vue) {
	background: var(--color-background-hover);
}

/* Export dropdown backdrop (transparent, closes menu on outside click) */
.export-backdrop {
	position: fixed;
	inset: 0;
	z-index: 9998;
}

/* Export dropdown list */
.export-menu {
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
	transform-origin: top right;
	animation: exportMenuIn 0.12s ease;
}

@keyframes exportMenuIn {
	from { opacity: 0; transform: scale(0.93); }
	to   { opacity: 1; transform: scale(1); }
}

.export-menu li {
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
.export-menu li *,
.export-menu li span {
	cursor: pointer;
}

.export-menu li:hover {
	background: var(--color-background-hover);
}

.export-menu li .material-design-icon {
	color: var(--color-primary, #0082c9);
	opacity: 0.8;
	flex-shrink: 0;
}

/* Dropdowns (Teilen/Tags/Export/Mehr) werden via <Teleport to="body"> aus dem
   .article-reader-Baum gerendert — eine Vorfahren-Auswahl wie ".dark-mode .export-menu"
   kann sie deshalb nie erreichen. Stattdessen wird die dark-mode-Klasse direkt am
   <ul> gebunden (siehe Template) und hier ohne Vorfahren-Bezug gestylt. */
.export-menu.dark-mode {
	background: #1e1e1e;
	border-color: #444;
}

.export-menu.dark-mode li {
	color: #e0e0e0;
}

.export-menu.dark-mode li:hover {
	background: #2a2a2a;
}

.export-menu.dark-mode li .material-design-icon {
	color: #9ecbff;
}

/* ── Mobile Bottom Toolbar (≤768px) ─────────────────────────────────── */
.mobile-toolbar {
	display: none; /* standardmäßig versteckt — nur auf Mobile sichtbar */
}

@media (max-width: 768px) {
	/* Desktop-Panels ausblenden */
	.reader-panel {
		display: none !important;
	}

	/* Scrollbereich: Platz für Toolbar und iOS Home Indicator */
	.reader-content {
		padding: 24px max(16px, env(safe-area-inset-right, 16px))
		         calc(56px + env(safe-area-inset-bottom, 0px))
		         max(16px, env(safe-area-inset-left, 16px));
	}

	/* Artikel-Titel auf kleinen Bildschirmen verkleinern */
	.article-header h1 {
		font-size: 1.8em;
	}

	/* Bottom Toolbar */
	.mobile-toolbar {
		display: flex;
		position: fixed;
		bottom: 0;
		left: 0;
		right: 0;
		height: calc(56px + env(safe-area-inset-bottom, 0px));
		padding-bottom: env(safe-area-inset-bottom, 0px);
		background: var(--color-main-background);
		border-top: 1px solid var(--color-border);
		box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.06);
		z-index: 100;
		transform: translateY(0);
		transition: transform 0.2s ease-in-out;
	}

	/* Beim Runterscrollen ausgeblendet, beim Hochscrollen / am Artikelende wieder sichtbar */
	.mobile-toolbar--hidden {
		transform: translateY(100%);
	}

	.dark-mode .mobile-toolbar {
		background: #1e1e1e;
		border-color: #444;
	}

	/* Gleicher Grund wie beim Desktop-Panel oben: NcButton folgt sonst dem
	   globalen Nextcloud-Theme statt dem lokal erzwungenen Reader-Dark-Mode. */
	.dark-mode .mobile-toolbar :deep(.button-vue) {
		color: #e0e0e0;
	}

	.dark-mode .mobile-toolbar :deep(.button-vue:active) {
		background: rgba(255, 255, 255, 0.08);
	}

	/* Touch-freundliche Button-Größe (44×44pt Minimum) */
	.mobile-toolbar :deep(.button-vue) {
		flex: 1;
		height: 56px;
		min-width: 44px;
		border-radius: 0;
		justify-content: center;
	}

	/* Mehr-Menü: von unten positionieren */
	.mobile-more-menu {
		bottom: calc(56px + env(safe-area-inset-bottom, 0px) + 4px);
		right: 8px;
		top: auto !important;
		transform-origin: bottom right;
	}

	/* Löschen-Button im Mehr-Menü rot */
	.more-menu-danger {
		color: #9b1c1c;
	}

	.more-menu-danger .material-design-icon {
		color: #9b1c1c !important;
	}

	@media (prefers-color-scheme: dark) {
		.more-menu-danger {
			color: #fca5a5;
		}

		.more-menu-danger .material-design-icon {
			color: #fca5a5 !important;
		}
	}

	/* Lese-Fortschrittsbalken am unteren Rand: auf Mobile über die fixed
	   Bottom-Toolbar anheben, sonst wäre er dahinter verdeckt. Links/rechts/oben
	   sind von der Toolbar nicht betroffen und bleiben unverändert. */
	.reader-progress-bar--bottom {
		bottom: calc(56px + env(safe-area-inset-bottom, 0px)) !important;
	}
}

/* New-tag form inside tag picker */
.tag-new-form {
	flex-direction: column !important;
	gap: 6px;
	padding: 8px 10px;
	align-items: stretch;
	cursor: default;
}

.tag-new-form:hover {
	background: transparent;
}

.tag-new-row {
	display: flex;
	align-items: center;
	gap: 6px;
}

.tag-new-input {
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

.tag-new-input:focus {
	border-color: var(--color-primary, #0082c9);
}

.export-menu.dark-mode .tag-new-input {
	background: #2a2a2a;
	border-color: #444;
	color: #e0e0e0;
}

.tag-new-swatches {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
	flex: 1;
}

.tag-new-swatch {
	width: 14px;
	height: 14px;
	border-radius: 50%;
	cursor: pointer;
	border: 2px solid transparent;
	flex-shrink: 0;
	box-sizing: border-box;
	transition: transform 0.1s;
}

.tag-new-swatch:hover {
	transform: scale(1.25);
}

.tag-new-swatch--active {
	border-color: var(--color-main-text, #222);
}

.tag-new-btn {
	padding: 2px 8px;
	border: none;
	border-radius: 6px;
	background: var(--color-primary, #0082c9);
	color: #fff;
	cursor: pointer;
	font-size: 1em;
	font-weight: 700;
	line-height: 1.6;
	flex-shrink: 0;
}

.tag-new-btn:disabled {
	opacity: 0.35;
	cursor: not-allowed;
}
.reader-progress-bar {
	position: absolute;
	z-index: 5;
	transition: width 0.1s linear, height 0.1s linear;
	pointer-events: none;
}
</style>
