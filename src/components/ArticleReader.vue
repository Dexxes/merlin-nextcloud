<template>
	<div class="article-reader" :class="readerClasses">

		<!-- Bottom-center floating dock (Desktop only) — replaces the former left/right panels.
		     Colored with the user's accent color; only the highest-priority actions live
		     here (back, archive, favorite, share). Everything else sits behind "More". -->
		<div v-if="!isMobile" ref="dockRef" class="reader-dock" :style="dockStyle">
			<NcButton
				class="dock-btn"
				:title="t('merlin', 'Back to list')"
				@click="$emit('close')">
				<template #icon>
					<ArrowLeft :size="18" />
				</template>
			</NcButton>

			<div class="dock-divider" />

			<NcButton
				class="dock-btn"
				:title="t('merlin', 'Archive and return to list')"
				@click="archiveAndClose">
				<template #icon>
					<ArchiveArrowDown :size="18" />
				</template>
			</NcButton>

			<NcButton
				class="dock-btn"
				:title="isFavoriteFromStore ? t('merlin', 'Remove from favorites') : t('merlin', 'Add to favorites')"
				@click="toggleFavoriteStatus">
				<template #icon>
					<Star v-if="isFavoriteFromStore" :size="18" class="fav-btn--active" />
					<StarOutline v-else :size="18" />
				</template>
			</NcButton>

			<!-- Share dropdown -->
			<div ref="shareWrapperRef" class="share-wrapper">
				<NcButton
					class="dock-btn"
					:title="t('merlin', 'Share article')"
					:class="{ 'share-btn--active': shareMenuOpen }"
					@click.stop="toggleShareMenu">
					<template #icon>
						<ShareVariant :size="18" />
					</template>
				</NcButton>

				<Teleport to="body">
					<div
						v-if="shareMenuOpen"
						class="export-backdrop"
						@click="shareMenuOpen = false" />
					<ul
						v-if="shareMenuOpen"
						role="menu"
						class="export-menu"
						:class="{ 'share-menu--mobile': isMobile, 'dark-mode': isDarkMode }"
						:style="shareMenuStyle">
						<li v-if="hasNativeShare" role="menuitem" @click="nativeShare(); shareMenuOpen = false">
							<ShareVariant :size="16" />
							<span>{{ t('merlin', 'Share…') }}</span>
						</li>
						<li role="menuitem" @click="copyLink(); shareMenuOpen = false">
							<ContentCopy :size="16" />
							<span>{{ t('merlin', 'Copy link') }}</span>
						</li>
						<li role="menuitem" @click="shareByEmail(); shareMenuOpen = false">
							<Email :size="16" />
							<span>{{ t('merlin', 'Send by email') }}</span>
						</li>
						<li role="menuitem" @click="shareToBluesky(); shareMenuOpen = false">
							<Butterfly :size="16" />
							<span>{{ t('merlin', 'Share to Bluesky') }}</span>
						</li>
						<li role="menuitem" @click="shareToMastodon(); shareMenuOpen = false">
							<Mastodon :size="16" />
							<span>{{ t('merlin', 'Share to Mastodon') }}</span>
						</li>
						<li role="menuitem" @click="shareMenuOpen = false; shareLinkDialogOpen = true">
							<LinkVariant :size="16" />
							<span>{{ t('merlin', 'Public link…') }}</span>
						</li>
					</ul>
				</Teleport>
			</div>

			<div class="dock-divider" />

			<!-- More: everything else (appearance, tags, export, delete) -->
			<NcButton
				class="dock-btn"
				:title="t('merlin', 'More options')"
				:class="{ 'more-btn--active': moreMenuOpen }"
				@click.stop="toggleMoreMenu">
				<template #icon>
					<DotsHorizontal :size="18" />
				</template>
			</NcButton>

			<Teleport to="body">
				<div v-if="moreMenuOpen" class="export-backdrop" @click="moreMenuOpen = false" />
				<ul
					v-if="moreMenuOpen"
					role="menu"
					class="export-menu dock-anchored-menu"
					:class="{ 'dark-mode': isDarkMode }"
					:style="dockAnchoredMenuStyle">
					<li role="menuitem" @click="toggleDarkMode(); moreMenuOpen = false">
						<WeatherNight v-if="!isDarkMode" :size="16" />
						<WhiteBalanceSunny v-else :size="16" />
						<span>{{ isDarkMode ? t('merlin', 'Switch to light mode') : t('merlin', 'Switch to dark mode') }}</span>
					</li>
					<li role="menuitem" @click="cycleFontSize(); moreMenuOpen = false">
						<FormatFontSizeIncrease :size="16" />
						<span>{{ t('merlin', 'Adjust font size') }}</span>
					</li>
					<li role="menuitem" @click="moreMenuOpen = false; openTagMenu()">
						<Tag :size="16" />
						<span>{{ t('merlin', 'Manage tags') }}</span>
					</li>
					<li role="menuitem" @click="exportHtml(); moreMenuOpen = false">
						<Download :size="16" />
						<span>{{ t('merlin', 'Export as HTML') }}</span>
					</li>
					<li role="menuitem" class="more-menu-danger" @click="confirmDelete(); moreMenuOpen = false">
						<Delete :size="16" /><span>{{ t('merlin', 'Delete article') }}</span>
					</li>
				</ul>
			</Teleport>

			<!-- Tag picker (opened from the More menu; shares the desktop dock anchor) -->
			<Teleport to="body">
				<div v-if="tagMenuOpen" class="export-backdrop" @click="tagMenuOpen = false" />
				<ul
					v-if="tagMenuOpen"
					role="menu"
					class="export-menu"
					:class="{ 'tag-menu--mobile': isMobile, 'dock-anchored-menu': !isMobile, 'dark-mode': isDarkMode }"
					:style="!isMobile ? dockAnchoredMenuStyle : null">
					<li v-if="allTags.length === 0" role="none" class="tag-menu-empty">
						<span>{{ t('merlin', 'No tags defined yet') }}</span>
					</li>
					<li
						v-for="tag in allTags"
						:key="tag.id"
						role="menuitemcheckbox"
						:aria-checked="articleHasTag(tag)"
						@click="handleTagToggle(tag)">
						<span class="tag-color-dot" :style="{ backgroundColor: tag.color }" />
						<span class="tag-name">{{ tag.name }}</span>
						<Check v-if="articleHasTag(tag)" :size="16" class="tag-check" />
					</li>

					<!-- New tag form -->
					<li role="none" class="tag-new-form" @click.stop>
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
			<ul v-if="mobileMoreOpen" role="menu" class="export-menu mobile-more-menu" :class="{ 'dark-mode': isDarkMode }">
				<li role="menuitem" @click="toggleFavoriteStatus(); mobileMoreOpen = false">
					<Star v-if="isFavoriteFromStore" :size="16" class="fav-btn--active" />
					<StarOutline v-else :size="16" />
					<span>{{ isFavoriteFromStore ? t('merlin', 'Remove from favorites') : t('merlin', 'Add to favorites') }}</span>
				</li>

				<li role="menuitem" @click="mobileMoreOpen = false; openTagMenu()">
					<Tag :size="16" />
					<span>{{ t('merlin', 'Manage tags') }}</span>
				</li>

				<li role="menuitem" @click.stop="mobileMoreOpen = false; toggleShareMenu()">
					<ShareVariant :size="16" />
					<span>{{ t('merlin', 'Share') }}</span>
				</li>

				<li role="menuitem" @click="cycleFontSize(); mobileMoreOpen = false">
					<FormatFontSizeIncrease :size="16" />
					<span>{{ t('merlin', 'Adjust appearance') }}</span>
				</li>

				<li role="menuitem" @click="toggleDarkMode(); mobileMoreOpen = false">
					<WeatherNight v-if="!isDarkMode" :size="16" />
					<WhiteBalanceSunny v-else :size="16" />
					<span>{{ isDarkMode ? t('merlin', 'Switch to light mode') : t('merlin', 'Switch to dark mode') }}</span>
				</li>
				<li role="menuitem" class="more-menu-danger" @click="confirmDelete(); mobileMoreOpen = false">
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
							:href="safeArticleUrl"
							target="_blank"
							rel="noopener noreferrer">
							<Web :size="16" />
							{{ article.siteName }}
						</a>
						<span v-if="article.publishedAt" class="meta-date">
							<Calendar :size="16" />
							{{ formatDate(article.publishedAt) }}
						</span>
						<span class="meta-added" :title="t('merlin', 'The day the article was added to your list')">
							<CalendarPlus :size="16" />
							{{ formatDate(article.createdAt) }}
						</span>
						<span v-if="article.readingTime" class="meta-time">
							<Clock :size="16" />
							{{ t('merlin', '{minutes} min', { minutes: article.readingTime }) }}
						</span>
					</div>

					<!-- Article tags -->
					<div v-if="articleTagsFromStore.length > 0" class="article-tags-row">
						<span
							v-for="tag in articleTagsFromStore"
							:key="tag.id"
							class="article-tag-chip"
							:style="{ backgroundColor: tag.color || '#6b7280', color: contrastColor(tag.color || '#6b7280') }">{{ tag.name }}</span>
					</div>
				</header>

				<div class="article-body" :class="{ 'has-native-video': videoPlayable }">
					<!-- Bei abspielbarem Video dient das Hero-Bild als Poster im
						Player (siehe :poster-url unten) statt zusätzlich separat
						darüber angezeigt zu werden.

						data-hl-flatten: der Hero/Rest-Split (siehe heroAndRestContent) und der
						dazwischen eingefügte VideoPlayer sind rein präsentationell - sie
						existieren nicht im rohen article-content-HTML, gegen das Highlight-XPaths
						auf anderen Plattformen berechnet werden. highlight-engine.js sieht durch
						data-hl-flatten-Wrapper hindurch (ihre Kinder zählen als direkte Kinder von
						.article-body) und überspringt data-hl-exclude-Teilbäume komplett, sodass
						der XPath eines Highlights weiterhin so auflöst, als hätte dieser Split nie
						stattgefunden. -->
					<!-- eslint-disable-next-line vue/no-v-html -->
					<div v-if="heroAndRestContent.heroHtml && !videoPlayable" data-hl-flatten v-html="heroAndRestContent.heroHtml" />

					<VideoPlayer
						v-if="article.url"
						data-hl-exclude
						:article-id="article.id"
						:article-url="article.url"
						:poster-url="heroAndRestContent.heroImageUrl"
						@playable-change="videoPlayable = $event" />

					<!-- eslint-disable-next-line vue/no-v-html -->
					<div data-hl-flatten v-html="heroAndRestContent.restHtml" />
				</div>

				<!-- Article footer -->
				<footer class="article-footer">
					<div class="next-article-divider" />
				</footer>
			</article>
		</div>

		<ShareLinkDialog
			v-if="shareLinkDialogOpen"
			:article-id="article.id"
			@close="shareLinkDialogOpen = false" />
	</div>
</template>

<script>
import { mapState, mapActions } from 'vuex'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import WeatherNight from 'vue-material-design-icons/WeatherNight.vue'
import WhiteBalanceSunny from 'vue-material-design-icons/WhiteBalanceSunny.vue'
import FormatFontSizeIncrease from 'vue-material-design-icons/FormatFontSizeIncrease.vue'
import Download from 'vue-material-design-icons/Download.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Email from 'vue-material-design-icons/Email.vue'
import Butterfly from 'vue-material-design-icons/Butterfly.vue'
import Mastodon from 'vue-material-design-icons/Mastodon.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import ArchiveArrowDown from 'vue-material-design-icons/ArchiveArrowDown.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Account from 'vue-material-design-icons/Account.vue'
import Web from 'vue-material-design-icons/Web.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarPlus from 'vue-material-design-icons/CalendarPlus.vue'
import Clock from 'vue-material-design-icons/Clock.vue'
import axios from '@nextcloud/axios'
import * as articlesAPI from '../api/articles'
import * as highlightsAPI from '../api/highlights'
import { HighlightEngine } from '../highlight-engine'
import ShareLinkDialog from './ShareLinkDialog.vue'
import VideoPlayer from './VideoPlayer.vue'

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
		FormatFontSizeIncrease,
		Download,
		ShareVariant,
		LinkVariant,
		ShareLinkDialog,
		VideoPlayer,
		ContentCopy,
		Email,
		Butterfly,
		Mastodon,
		Delete,
		DotsHorizontal,
		ArchiveArrowDown,
		Star,
		StarOutline,
		Tag,
		Check,
		Account,
		Web,
		Calendar,
		CalendarPlus,
		Clock,
	},

	emits: ['close', 'delete-article'],

	props: {
		article: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			// Einzige Quelle der Wahrheit für das Erscheinungsbild dieser Reader-Instanz;
			// 'dark'/'sepia' sind explizite Nutzerwahlen, 'auto' wird in mounted() einmalig
			// anhand des OS-Farbschemas zu 'light'/'dark' aufgelöst (Sepia hängt nicht am OS).
			themeMode: 'light',
			fontSize: FONT_SIZE_DEFAULT,
			shareMenuOpen: false,
			shareMenuStyle: {},
			shareLinkDialogOpen: false,
			hasNativeShare: typeof navigator !== 'undefined' && !!navigator.share,
			// true, sobald VideoPlayer erfolgreich einen nativen Stream lädt -
			// blendet dann den redundanten "Zum Video"-Fallback-Link aus (siehe
			// .merlin-video-fallback-link weiter unten).
			videoPlayable: false,
			isMobile: false,
			showBottomBar: true,
			_lastScrollTop: 0,
			mobileMoreOpen: false,
			moreMenuOpen: false,
			tagMenuOpen: false,
			dockAnchoredMenuStyle: {},
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

		isDarkMode() {
			return this.themeMode === 'dark'
		},

		isSepia() {
			return this.themeMode === 'sepia'
		},

		readerClasses() {
			return {
				'dark-mode': this.isDarkMode,
				'sepia-mode': this.isSepia,
			}
		},

		// Sichere Variante der Artikel-URL für das href-Attribut: nur http(s)
		// zulassen, damit ein bösartiges javascript:-Schema (z. B. bei fehl-
		// geschlagener Extraktion in article.url verblieben) nicht per Klick
		// ausgeführt werden kann. Vue sanitisiert v-bind:href NICHT.
		safeArticleUrl() {
			return this.sanitizeHref(this.article?.url)
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
		// Dock background follows the user's chosen accent color (same source as the progress bar).
		// Icon-/Overlay-Farben werden je nach Helligkeit der Akzentfarbe umgeschaltet (siehe
		// contrastColor), sonst verschwinden z. B. weiße Icons auf hellem Gelb komplett.
		dockStyle() {
			const accent = this.settings.accentColor || '#FF3B30'
			const fg = this.contrastColor(accent)
			const isLightFg = fg === '#1d1d1f'
			return {
				background: accent,
				'--dock-fg': fg,
				'--dock-overlay': isLightFg ? 'rgba(0, 0, 0, 0.12)' : 'rgba(255, 255, 255, 0.18)',
				'--dock-overlay-active': isLightFg ? 'rgba(0, 0, 0, 0.20)' : 'rgba(255, 255, 255, 0.28)',
				'--dock-divider': isLightFg ? 'rgba(0, 0, 0, 0.20)' : 'rgba(255, 255, 255, 0.25)',
			}
		},

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

		// Trennt eine führende <figure class="merlin-hero-image"> (siehe
		// ContentExtractorService Step 12) vom restlichen Content ab, damit der
		// VideoPlayer zwischen Hero-Bild und Rest platziert werden kann statt
		// immer ganz oben. Nur ein Split, wenn die Figure wirklich das erste
		// Element ist - sonst bleibt alles wie zuvor in restHtml.
		heroAndRestContent() {
			const html = this.processedContent
			if (!html) return { heroHtml: '', heroImageUrl: '', restHtml: html }

			const doc = new DOMParser().parseFromString(html, 'text/html')
			const hero = doc.body.firstElementChild
			if (!hero || hero.tagName !== 'FIGURE' || !hero.classList.contains('merlin-hero-image')) {
				return { heroHtml: '', heroImageUrl: '', restHtml: html }
			}

			const heroHtml = hero.outerHTML
			// Dient bei einem abspielbaren Video als Poster-Bild statt separat
			// über der Figure angezeigt zu werden (siehe VideoPlayer-Bindung
			// unten) - deshalb schon hier mit heraustrennen.
			const heroImageUrl = hero.querySelector('img')?.src ?? ''
			hero.remove()
			return { heroHtml, heroImageUrl, restHtml: doc.body.innerHTML }
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
				this._executeEmbedScripts()
			})
		},
	},

	mounted() {
		this.themeMode = this.resolveThemeMode(this.settings.theme)
		this.fontSize = parseFontSize(this.settings.fontSize)
		this._checkMobile = () => {
			this.isMobile = window.innerWidth <= 768
			// Re-anchor any open dropdown on resize — they're positioned in JS via
			// getBoundingClientRect() at open time, so without this a resize with a
			// menu open leaves it hanging at its old (now wrong) coordinates.
			if (this.shareMenuOpen && !this.isMobile) {
				this.shareMenuStyle = this._computeShareMenuStyle()
			}
			if (!this.isMobile && (this.moreMenuOpen || this.tagMenuOpen)) {
				this.dockAnchoredMenuStyle = this._computeDockAnchoredMenuStyle()
			}
		}
		this._checkMobile()
		window.addEventListener('resize', this._checkMobile)
		this._onKeydown = (event) => {
			if (event.key !== 'Escape') return
			this.shareMenuOpen = false
			this.moreMenuOpen = false
			this.tagMenuOpen = false
			this.mobileMoreOpen = false
		}
		window.addEventListener('keydown', this._onKeydown)
		this.$nextTick(() => {
			this._restoreScrollPosition()
			this._initHighlights()
			this._addImageErrorHandlers()
			this._executeEmbedScripts()
			if (this.$refs.readerContent) {
				this._onScroll = this._handleScroll.bind(this)
				this.$refs.readerContent.addEventListener('scroll', this._onScroll, { passive: true })
			}
		})
	},

	beforeUnmount() {
		window.removeEventListener('resize', this._checkMobile)
		window.removeEventListener('keydown', this._onKeydown)
		if (this._onScroll && this.$refs.readerContent) {
			this.$refs.readerContent.removeEventListener('scroll', this._onScroll)
		}
		clearTimeout(this._scrollTimer)

		// Endgültigen Fortschritt sofort schreiben (statt auf den Debounce-Timer zu warten)
		// und die Karten-Ansicht benachrichtigen, damit der Balken ohne Reload erscheint
		// (entspricht .articleProgressDidUpdate in den Mobile-Apps). Schreibt lokal UND
		// pusht zum Server für die geräteübergreifende Sync.
		if (this.settings.saveProgress) {
			this._persistProgress(this.scrollPct / 100)
		}
		window.dispatchEvent(new CustomEvent('merlin-progress-updated', { detail: { articleId: this.article.id } }))
		if (this._highlightEngine) {
			this._highlightEngine.destroy()
			this._highlightEngine = null
		}
	},

	methods: {
		...mapActions(['toggleArchive', 'toggleFavorite', 'addTag', 'addTagToArticle', 'removeTagFromArticle', 'updateSettings']),

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

		// Opens the tag picker; on desktop it shares the dock anchor with the "More"
		// menu, so its position is computed the same way (see _computeDockAnchoredMenuStyle).
		openTagMenu() {
			if (!this.isMobile) {
				this.dockAnchoredMenuStyle = this._computeDockAnchoredMenuStyle()
			}
			this.tagMenuOpen = true
		},

		toggleMoreMenu() {
			if (!this.moreMenuOpen) {
				this.dockAnchoredMenuStyle = this._computeDockAnchoredMenuStyle()
			}
			this.moreMenuOpen = !this.moreMenuOpen
		},

		// Anchors the "More"/"Manage tags" dropdowns above the actual dock element
		// (via its measured rect) instead of relying on `left: 50%` in CSS, which
		// centers on the viewport rather than the article column the dock sits over.
		_computeDockAnchoredMenuStyle() {
			const dock = this.$refs.dockRef
			if (!dock) return {}
			const rect = dock.getBoundingClientRect()
			const menuWidth = 190
			const left = Math.min(Math.max(8, rect.left + rect.width / 2 - menuWidth / 2), window.innerWidth - menuWidth - 8)
			return {
				bottom: (window.innerHeight - rect.top + 8) + 'px',
				top: 'auto',
				left: left + 'px',
			}
		},

		// 'auto' (System) zu 'light'/'dark' auflösen; 'dark'/'sepia' bleiben explizit,
		// da Sepia keine OS-Gegenentsprechung hat.
		resolveThemeMode(theme) {
			if (theme === 'dark' || theme === 'sepia') return theme
			if (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark'
			return 'light'
		},

		toggleDarkMode() {
			// Schneller Umschalter im Dock: pendelt nur zwischen hell/dunkel (auch aus Sepia
			// heraus) — für Sepia gibt es die Auswahl über die Settings-Seite.
			this.themeMode = this.isDarkMode ? 'light' : 'dark'
			// Persistieren, sonst gilt der Toggle nur für diese Komponenten-Instanz:
			// mounted() liest beim nächsten Artikel wieder settings.theme und der
			// Hintergrund-Poll würde den lokalen Store mit dem Server-Wert überschreiben.
			// Explizit 'dark'/'light' statt 'auto', damit die Wahl eindeutig ist.
			this.updateSettings({
				...this.settings,
				theme: this.themeMode,
			}).catch(() => {}) // Fehler wird bereits in der Store-Action geloggt
		},

		// Mobile: rotiert durch alle Schriftgrößen in einer Schaltfläche
		cycleFontSize() {
			const idx = FONT_SIZE_STEPS.findIndex(s => s >= this.fontSize)
			this.fontSize = FONT_SIZE_STEPS[(idx + 1) % FONT_SIZE_STEPS.length]
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
				this.shareMenuStyle = this._computeShareMenuStyle()
			}
			this.shareMenuOpen = !this.shareMenuOpen
		},

		// Desktop: anchor the menu above the dock, centered on the share button
		// (the dock sits at the bottom of the screen, so menus open upward).
		// Also re-run on resize (see _checkMobile) so an open menu doesn't drift
		// away from its anchor when the window is resized.
		_computeShareMenuStyle() {
			const btn = this.isMobile ? null : this.$refs.shareWrapperRef
			if (!btn) return {}
			const rect = btn.getBoundingClientRect()
			const menuWidth = 190
			const left = Math.min(Math.max(8, rect.left + rect.width / 2 - menuWidth / 2), window.innerWidth - menuWidth - 8)
			return {
				bottom: (window.innerHeight - rect.top + 8) + 'px',
				top: 'auto',
				left: left + 'px',
			}
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
					this._persistProgress(this.scrollPct / 100)
				}, 500)
			}
		},

		// Speichert den Fortschritt als Fraktion (0–1) lokal UND synchronisiert ihn
		// geräteübergreifend zum Server. Gespeichert wird bewusst die Fraktion, nicht
		// der Pixel-Offset (Pixel variieren mit Erscheinungsbild/Gerät). Der lokale
		// Zeitstempel treibt die Last-Write-Wins-Auflösung in _restoreScrollPosition.
		_persistProgress(fraction) {
			const clamped = Math.min(Math.max(fraction, 0), 1)
			const now = Date.now()
			localStorage.setItem(`merlin_pct_${this.article.id}`, String(clamped))
			localStorage.setItem(`merlin_pcts_${this.article.id}`, String(now))
			// Fire-and-forget: bei Server-Fehler bleibt der lokale Wert erhalten.
			articlesAPI.updateProgress(this.article.id, clamped, now).catch(() => {})
		},

		_restoreScrollPosition() {
			const el = this.$refs.readerContent
			if (!el) return

			if (this.settings.resumeOnOpen) {
				// Last-Write-Wins: lokaler vs. Server-Wert, der neuere Zeitstempel gewinnt.
				const localPct = parseFloat(localStorage.getItem(`merlin_pct_${this.article.id}`)) || 0
				const localTs = parseInt(localStorage.getItem(`merlin_pcts_${this.article.id}`), 10) || 0
				const serverPct = this.article.scrollProgress || 0
				const serverTs = this.article.scrollUpdatedAt || 0
				const fraction = serverTs > localTs ? serverPct : localPct
				// Aus der Fraktion gegen die *aktuelle* Inhaltshöhe einen Pixel-Offset
				// berechnen – so passt sich die Position dem aktuellen Erscheinungsbild an.
				const max = el.scrollHeight - el.clientHeight
				el.scrollTop = max > 0 ? fraction * max : 0
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

		// ── Embed-Widget-Skripte (Instagram/X) ──────────────────────────────

		// v-html setzt den Inhalt über .innerHTML – <script>-Tags, die dabei ins
		// DOM gelangen, werden vom Browser NIE ausgeführt (Standardverhalten,
		// nicht Vue-spezifisch). Der Sanitizer lässt aber genau zwei <script>-Tags
		// durch (isAllowedWidgetScriptSrc() im Backend: Instagrams embed.js,
		// X' widgets.js) – ohne diesen Schritt blieben deren <blockquote>s für
		// immer als reiner Link/Zitat-Fallback stehen, statt zum Post/Reel zu
		// werden. Jedes gefundene <script> wird deshalb durch eine neu erzeugte
		// Kopie ersetzt; nur DAS bringt den Browser dazu, es auszuführen.
		_executeEmbedScripts() {
			const bodyEl = this.$el?.querySelector('.article-body')
			if (!bodyEl) return
			bodyEl.querySelectorAll('script').forEach(oldScript => {
				const newScript = document.createElement('script')
				for (const attr of oldScript.attributes) {
					newScript.setAttribute(attr.name, attr.value)
				}
				oldScript.replaceWith(newScript)
			})
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

			const icon = document.createElement('span')
			icon.className = 'img-placeholder__icon'
			icon.setAttribute('aria-hidden', 'true')
			ph.appendChild(icon)

			// img.alt stammt aus dem importierten Artikel (angreiferkontrolliert)
			// und ist bereits entity-dekodiert. Über textContent statt innerHTML
			// eingesetzt, damit ein alt-Wert wie "<img onerror=…>" als reiner Text
			// behandelt wird und kein DOM/HTML mehr erzeugt (DOM-XSS-Schutz).
			if (img.alt) {
				const label = document.createElement('span')
				label.className = 'img-placeholder__label'
				label.textContent = img.alt
				ph.appendChild(label)
			}

			img.parentNode.replaceChild(ph, img)
		},

		// Gibt die URL nur zurück, wenn sie ein sicheres Schema trägt (http/https)
		// oder relativ/Anker ist; andernfalls null, damit kein javascript:- oder
		// data:-Schema in ein href/src gelangt.
		sanitizeHref(url) {
			if (typeof url !== 'string') return null
			// Führende Steuerzeichen/Whitespace entfernen (Browser ignorieren sie
			// beim Scheme-Parsing, z. B. "java\tscript:").
			const normalized = url.replace(/[\u0000-\u0020]+/g, '').toLowerCase()
			if (normalized.startsWith('javascript:')
				|| normalized.startsWith('vbscript:')
				|| normalized.startsWith('data:')) {
				return null
			}
			return url
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

		// Wählt schwarzen oder weißen Vordergrund je nach wahrgenommener Helligkeit
		// von `hex` (ITU-R BT.601 Luma). Gebraucht für Dock-Icons und Tag-Chips, deren
		// Hintergrund frei wählbar ist (Akzentfarbe bzw. Tag-Farbe) — ein hart codiertes
		// Weiß wird sonst z. B. bei Gelb (#FFCC00) unsichtbar.
		contrastColor(hex) {
			if (typeof hex !== 'string') return '#fff'
			const normalized = hex.replace('#', '')
			const full = normalized.length === 3
				? normalized.split('').map(c => c + c).join('')
				: normalized
			if (!/^[0-9a-f]{6}$/i.test(full)) return '#fff'
			const r = parseInt(full.substring(0, 2), 16)
			const g = parseInt(full.substring(2, 4), 16)
			const b = parseInt(full.substring(4, 6), 16)
			const luma = (r * 299 + g * 587 + b * 114) / 1000
			return luma > 170 ? '#1d1d1f' : '#fff'
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

/* Sepia: warmes Papier-Look, an die Sepia-Swatch-Vorschau in Settings.vue angelehnt. */
.article-reader.sepia-mode {
	background: #f4ecd8;
	color: #5b4636;
}

/* Bottom-center floating dock (replaces the former left/right panels). Background
   is the user's accent color (see dockStyle); icon/overlay colors switch between
   black and white via the --dock-* custom properties set in dockStyle so buttons
   stay visible against light accents (e.g. yellow) too — no separate dark-mode
   override needed like the old panels required. */
.reader-dock {
	position: absolute;
	left: 50%;
	bottom: 24px;
	transform: translateX(-50%);
	z-index: 10;
	display: flex;
	align-items: center;
	gap: 2px;
	border-radius: 999px;
	padding: 6px;
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
}

.reader-dock :deep(.button-vue) {
	color: var(--dock-fg, #fff) !important;
	background: transparent !important;
	border-color: transparent !important;
}

.reader-dock :deep(.button-vue:hover) {
	background: var(--dock-overlay, rgba(255, 255, 255, 0.18)) !important;
}

.reader-dock :deep(.button-vue:active) {
	background: var(--dock-overlay-active, rgba(255, 255, 255, 0.28)) !important;
}

.dock-divider {
	width: 1px;
	height: 20px;
	background: var(--dock-divider, rgba(255, 255, 255, 0.25));
	margin: 0 4px;
}

/* Scrollable content — bottom padding leaves room for the floating dock (24px
   offset + ~56px pill height) plus breathing room, so the last lines of an
   article never end up hidden behind it. */
.reader-content {
	height: 100%;
	overflow-y: auto;
	padding: 40px 80px 120px;
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

.sepia-mode .article-metadata {
	color: #8a7357;
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

/* Der "Zum Video"-Fallback-Link (siehe ContentExtractorService, Video-Zweig)
   wird redundant, sobald VideoPlayer erfolgreich einen nativen Stream
   gefunden hat - videoPlayable steuert diese Klasse. */
.article-body.has-native-video :deep(.merlin-video-fallback-link) {
	display: none;
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

/* Self-hosted <video> (GIF-Ersatz mancher Blogs, siehe sanitizeHtml()) bringt
   im Gegensatz zu iframe-Embeds keine sinnvolle Default-Breite mit – ohne
   diese Regel rendert es in seiner nativen Pixelbreite und sprengt die
   Artikelspalte. */
.article-body :deep(video) {
	max-width: 100%;
	height: auto;
	display: block;
	margin: 2em auto;
	border-radius: 4px;
}

/* Video-Embeds (YouTube/Vimeo/Twitch/TikTok/Facebook/Arte), siehe
   isAllowedVideoEmbedSrc() im Backend. 16:9 als bester Kompromiss über alle
   Hosts hinweg – einzelne Embeds bringen zwar eigene width/height mit, die
   überschreiben sich per Inline-Attribut aber nicht gegen dieses CSS. */
.article-body :deep(iframe) {
	display: block;
	width: 100%;
	max-width: 100%;
	aspect-ratio: 16 / 9;
	border: 0;
	margin: 2em auto;
}

/* Instagram-/X-/Bluesky-Embeds (siehe isAllowedWidgetScriptSrc()) rendern sich
   nach dem Laden des Widget-Skripts selbst komplett neu und bringen ihr
   eigenes Kartendesign mit – die generische Zitat-Optik für <blockquote>
   unten würde nur bis zum Laden sichtbar sein und dann falsch wirken,
   deshalb hier zurückgesetzt. */
.article-body :deep(blockquote.instagram-media),
.article-body :deep(blockquote.twitter-tweet),
.article-body :deep(blockquote.bluesky-embed) {
	border-left: none;
	padding-left: 0;
	font-style: normal;
	color: inherit;
	max-width: 100%;
	overflow: hidden;
	margin: 2em auto;
}

/* Mehrere aufeinanderfolgende Bluesky-Embeds (Self-Thread, siehe
   BlueskyThreadResolverService) sollen sichtbar zusammengehören statt wie
   unabhängige Zitate mit vollem Absatzabstand zu wirken. */
.article-body :deep(blockquote.bluesky-embed + blockquote.bluesky-embed) {
	margin-top: 0.5em;
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

@media (max-width: 768px) {
	.reader-content {
		padding: 24px 64px;
	}

	.article-header h1 {
		font-size: 1.8em;
	}
}

/* Favorit-Button — goldenes Icon wenn aktiv */
:deep(.fav-btn--active) {
	color: #f59e0b !important;
	opacity: 1 !important;
}

.more-btn--active :deep(.button-vue) {
	background: var(--dock-overlay, rgba(255, 255, 255, 0.18)) !important;
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

/* Desktop "More" and "Manage tags" menus: anchored above the dock via the
   dockAnchoredMenuStyle computed in JS (see _computeDockAnchoredMenuStyle),
   which measures the dock's actual position instead of assuming it's
   centered in the viewport. */
.dock-anchored-menu {
	transform-origin: bottom center;
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
	/* Desktop-Dock ausblenden */
	.reader-dock {
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

	/* Gleicher Grund wie beim Desktop-Panel oben: NcButton (secondary) folgt
	   sonst mit Text-, Hintergrund- und Rahmenfarbe dem globalen Nextcloud-
	   Theme statt dem lokal erzwungenen Reader-Dark-Mode. In der flachen
	   Toolbar bewusst transparent statt Pill-Optik. */
	.dark-mode .mobile-toolbar :deep(.button-vue) {
		color: #e0e0e0 !important;
		background-color: transparent !important;
		border-color: transparent !important;
	}

	.dark-mode .mobile-toolbar :deep(.button-vue:active) {
		background-color: rgba(255, 255, 255, 0.08) !important;
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

	/* prefers-color-scheme spiegelt nur die OS-Einstellung, nicht das in
	   Nextcloud tatsächlich aktive Theme (z. B. wenn Nutzer "Dunkel" explizit
	   wählen, ihr OS aber hell steht). Nextcloud setzt bei aktivem Dark Theme
	   zuverlässig das data-theme-dark-Attribut auf <body> — das ist das
	   korrekte Signal (gleicher Fix wie in ArticleCard.vue). */
	body[data-theme-dark] .more-menu-danger,
	body[data-theme-dark-highcontrast] .more-menu-danger {
		color: #fca5a5;
	}

	body[data-theme-dark] .more-menu-danger .material-design-icon,
	body[data-theme-dark-highcontrast] .more-menu-danger .material-design-icon {
		color: #fca5a5 !important;
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
