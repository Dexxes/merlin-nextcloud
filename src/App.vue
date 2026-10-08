<template>
	<NcContent app-name="merlin" :class="{ 'reader-mode': view === 'reader' }">
		<MerlinSidebar
	         :tags="tags"
	         :counts="counts"
	         :current-filter="currentFilter"
	         :current-tag-id="currentTagId"
	         :view="view"
	         @add-article="showAddArticleDialog = true"
	         @filter="setFilter"
	         @filter-tag="filterByTag"
	         :hidden-tag-ids="excludedTagIdSet"
	         @delete-tag="tagToDelete = tags.find(tag => tag.id === $event) || null"
	         @toggle-tag-hidden="toggleTagExcluded"
	         @move-tag="handleMoveTag"
	         @open-settings="openSettings"
		/>

		<NcAppContent>
			<ArticleList
				v-if="view === 'list'"
				:articles="filteredArticles"
				:loading="loading"
				:filter-category="currentFilter"
				@open-article="openArticle" />

			<ArticleReader
				v-else-if="view === 'reader' && currentArticle"
				:article="currentArticle"
				@close="closeReader"
				@delete-article="onDeleteArticle"
				@open-settings="openSettings" />

			<Settings v-else-if="view === 'settings'" />
		</NcAppContent>

		<RetentionNoticeDialog
			v-if="showRetentionNotice"
			:days="settings.retentionEffectiveDays || 0"
			:favorites-days="settings.retentionFavoritesEffectiveDays || 0"
			@close="onRetentionNoticeClose" />

		<DeleteTagDialog
			v-if="tagToDelete"
			:tag="tagToDelete"
			:tags="tags"
			@close="onDeleteTagClose" />

		<AddArticleDialog
			v-if="showAddArticleDialog"
			:initial-url="addArticleUrl"
			@close="showAddArticleDialog = false; addArticleUrl = ''"
			@added="onArticleAdded" />
	</NcContent>
</template>

<script>
import { mapState, mapGetters, mapActions, mapMutations } from 'vuex'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { acknowledgeRetentionNotice } from './api/retention.js'
import { getArticle } from './api/articles.js'
import {
	NcContent,
	NcAppContent,
} from '@nextcloud/vue'

import ArticleList from './components/ArticleList.vue'
import ArticleReader from './components/ArticleReader.vue'
import AddArticleDialog from './components/AddArticleDialog.vue'
import DeleteTagDialog from './components/DeleteTagDialog.vue'
import Settings from './components/Settings.vue'
import RetentionNoticeDialog from './components/RetentionNoticeDialog.vue'
import Sidebar from './components/Sidebar.vue'

export default {
	name: 'MerlinApp',

	components: {
		NcContent,
		NcAppContent,
		ArticleList,
		ArticleReader,
		AddArticleDialog,
		DeleteTagDialog,
		Settings,
		RetentionNoticeDialog,
		MerlinSidebar: Sidebar,
	},

	data() {
		return {
			showAddArticleDialog: false,
			// Tag awaiting the delete confirmation (DeleteTagDialog).
			tagToDelete: null,
			addArticleUrl: '',
			// Einmaliger Löschfrist-Hinweis, siehe RetentionNoticeDialog.
			showRetentionNotice: false,
			currentFilter: 'pages-unread',
			// Tracks whether we pushed a history entry when opening the reader.
			// Needed so manual close can pop it, preventing a dangling back-entry.
			_readerHistoryPushed: false,
			_pollInterval: null,
			currentTagId: null,
		}
	},

	computed: {
		...mapState(['articles', 'counts', 'tags', 'currentArticle', 'loading', 'view', 'settings']),
		...mapGetters(['filteredArticles', 'excludedTagIdSet']),
	},

	mounted() {
		// Check for ?add=<url> parameter (e.g. from iOS Shortcut share sheet)
		const params = new URLSearchParams(window.location.search)
		const addUrl = params.get('add')
		if (addUrl) {
			this.addArticleUrl = addUrl
			this.showAddArticleDialog = true
			// Remove the parameter from the browser URL without reloading
			history.replaceState(null, '', window.location.pathname)
		}

		this.loadData().then(() => {
			// defaultView aus den Settings auswerten: erst nach loadData() bekannt, da
			// fetchSettings() asynchron ist. setFilter() ersetzt damit den hartcodierten
			// 'pages-unread'-Start-Zustand, falls der Nutzer eine andere Default-Ansicht
			// gewählt hat. Legacy-Werte aus der Zeit vor der Pages/Videos-Aufteilung
			// ('unread', 'favorites', 'archived', 'video') werden auf ihr Pages/Videos-
			// Äquivalent gemappt statt eine ungültige Ansicht zu setzen.
			const legacyDefaultViewMap = {
				unread: 'pages-unread',
				favorites: 'pages-favorites',
				archived: 'pages-archived',
				video: 'videos-unread',
			}
			const rawDefaultView = this.settings && this.settings.defaultView
			const defaultView = legacyDefaultViewMap[rawDefaultView] || rawDefaultView
			if (defaultView && defaultView !== this.currentFilter) {
				this.setFilter(defaultView)
			}
			this.openArticleFromHash()
			this._pollInterval = setInterval(() => this.pollForUpdates(), 15_000)
			this.showRetentionNotice = Boolean(this.settings && this.settings.retentionNoticeRequired)
		})
		// Intercept the browser back button so it closes the reader instead of
		// navigating away to the Files app (or the previous Nextcloud page).
		this._onPopState = () => {
			if (this.view === 'reader') {
				// The browser already moved back in history — just update Vue state.
				this._readerHistoryPushed = false
				this.SET_VIEW('list')
				this.SET_CURRENT_ARTICLE(null)
			}
		}
		window.addEventListener('popstate', this._onPopState)
	},

	beforeUnmount() {
		clearInterval(this._pollInterval)
		window.removeEventListener('popstate', this._onPopState)
	},

	methods: {
		...mapActions(['fetchArticles', 'fetchCounts', 'fetchTags', 'fetchSettings', 'fetchLoginCapableDomains', 'deleteArticle', 'deleteTag', 'moveTag', 'toggleTagExcluded', 'pollForUpdates']),
		...mapMutations(['SET_FILTER', 'RESET_FILTER', 'SET_VIEW', 'SET_CURRENT_ARTICLE', 'SET_SETTINGS']),

		async loadData() {
			await Promise.all([
				this.fetchArticles(),
				this.fetchCounts(),
				this.fetchTags(),
				this.fetchSettings(),
				this.fetchLoginCapableDomains(),
			])
		},

		// type ist eine der neun Pages/Videos/Audio x Unread/Favorites/Archived-
		// Ansichten, z.B. 'pages-unread' oder 'audio-favorites' - siehe Sidebar.vue.
		// "Mixed"-Artikel (Text mit Medium) gehören zu den Pages.
		setFilter(type) {
			this.currentFilter = type
			this.currentTagId = null
			this.RESET_FILTER()
			this.SET_VIEW('list')
			const [contentType, status] = type.split('-')
			const contentTypeFilter = { videos: 'video', audio: 'audio' }
			this.SET_FILTER({ key: 'contentType', value: contentTypeFilter[contentType] || 'page' })
			switch (status) {
			case 'unread':
				this.SET_FILTER({ key: 'isRead', value: false })
				break
			case 'favorites':
				this.SET_FILTER({ key: 'isFavorite', value: true })
				this.SET_FILTER({ key: 'isArchived', value: null })
				break
			case 'archived':
				this.SET_FILTER({ key: 'isArchived', value: true })
				break
			}
			this.fetchArticles()
		},

		filterByTag(tagId) {
			this.currentTagId = tagId
			this.currentFilter = null
			this.RESET_FILTER()
			this.SET_VIEW('list')
			this.SET_FILTER({ key: 'tagId', value: tagId })
			// Always show both archived and non-archived articles, and all of
			// pages, videos and audio, for tag filters
			this.SET_FILTER({ key: 'isArchived', value: null })
			this.fetchArticles()
		},

		// Treffer der Nextcloud-Suche (ArticleSearchProvider) verlinken auf
		// #article-<id>: den Artikel direkt im Reader öffnen.
		async openArticleFromHash() {
			const match = window.location.hash.match(/^#article-(\d+)$/)
			if (!match) return
			history.replaceState(null, '', window.location.pathname + window.location.search)
			try {
				this.openArticle(await getArticle(Number(match[1])))
			} catch (error) {
				console.error('Failed to open article from search result:', error)
			}
		},

		openArticle(article) {
			this.SET_CURRENT_ARTICLE(article)
			this.SET_VIEW('reader')
			// Push a history entry so the browser back button closes the reader
			// instead of leaving the Nextcloud app entirely.
			history.pushState({ readerView: true, articleId: article.id }, '')
			this._readerHistoryPushed = true
		},

		closeReader() {
			this.SET_VIEW('list')
			this.SET_CURRENT_ARTICLE(null)
			// If we pushed a history entry when opening, pop it now so the browser
			// history stays clean after a manual close (Back button / footer button).
			if (this._readerHistoryPushed) {
				this._readerHistoryPushed = false
				history.back()
			}
		},

		async onDeleteArticle(articleId) {
			await this.deleteArticle(articleId)
			this.closeReader()
			showSuccess(this.t('merlin', 'Article deleted'))
		},

		onArticleAdded() {
			this.showAddArticleDialog = false
			this.fetchArticles()
		},

		// A tag is deleted together with its sub-tags, so only after the
		// confirmation in DeleteTagDialog.
		async onDeleteTagClose(confirmed) {
			const tag = this.tagToDelete
			this.tagToDelete = null
			if (confirmed && tag) {
				await this.handleDeleteTag(tag.id)
			}
		},

		async handleMoveTag({ tagId, parentId }) {
			try {
				await this.moveTag({ tagId, parentId })
			} catch {
				showError(this.t('merlin', 'Could not move the tag'))
				return
			}
			// The article list of a filtered parent tag includes its sub-tags.
			if (this.currentTagId != null) this.fetchArticles()
		},

		async handleDeleteTag(tagId) {
			const deletedIds = await this.deleteTag(tagId)
			if (deletedIds.includes(this.currentTagId)) {
				this.currentTagId = null
				// 'all' view was removed — fall back to the app's default (Pages/Unread)
				// instead of a filter that no longer exists in the sidebar.
				this.setFilter('pages-unread')
			}
		},

		// Jedes Schließen bestätigt den Hinweis, damit er nicht bei jedem Aufruf
		// wiederkommt; er erscheint erst wieder, wenn eine Frist kürzer wird.
		async onRetentionNoticeClose(openSettings) {
			this.showRetentionNotice = false
			if (openSettings) {
				this.openSettings()
			}
			try {
				const info = await acknowledgeRetentionNotice()
				this.SET_SETTINGS({ ...this.settings, ...info })
			} catch (error) {
				console.error('Failed to acknowledge retention notice:', error)
			}
		},

		openSettings() {
			this.currentFilter = null
			this.SET_VIEW('settings')
		},
	},
}
</script>

<style scoped>
/* Sidebar toggle: no custom positioning needed anymore. @nextcloud/vue 9's
   NcAppNavigationToggle already anchors itself at the top of the sidebar
   (top: var(--app-navigation-padding)) and already uses
   var(--color-main-background) for its own background — correct in light
   and dark out of the box. The old override here fought that positioning
   (it didn't account for the component's own margin-inline-end offset),
   which pushed the button off its intended spot. Removed; only the
   reader-mode/mobile hiding rules below are still ours to own. */

/* ── Mobile PWA optimizations ───────────────────────────────────────── */
@media (max-width: 768px) {
	/* 1+2: Im Reader-View Nextcloud-Sidebar und Sidebar-Toggle ausblenden */
	.reader-mode :deep(.app-navigation),
	.reader-mode :deep(.app-navigation-toggle-wrapper) {
		display: none !important;
	}

	/* Reader-View: App-Content nimmt volle Breite ein */
	.reader-mode :deep(.app-content) {
		margin-inline-start: 0 !important;
	}

	/* 3: Kein Extra-Abstand unten — Toolbar sitzt bündig am Bildschirmrand */
	:deep(.app-content) {
		padding-bottom: 0 !important;
	}
}
</style>
