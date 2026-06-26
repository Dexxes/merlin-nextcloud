<template>
	<NcContent app-name="merlin" :class="{ 'reader-mode': view === 'reader' }">
		<MerlinSidebar
	         :tags="tags"
	         :counts="counts"
	         :current-filter="currentFilter"
	         :current-tag-id="currentTagId"
			 :enable-pinning="false"
	         @add-article="showAddArticleDialog = true"
	         @filter="setFilter"
	         @filter-tag="filterByTag"
	         @filter-category="filterByCategory"
	         @delete-tag="handleDeleteTag"
	         @toggle-pin-tag="onTogglePinTag"
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
				:next-article="nextArticle"
				@close="closeReader"
				@delete-article="onDeleteArticle"
				@open-next-article="openArticle" />

			<Settings v-else-if="view === 'settings'" />
		</NcAppContent>

		<AddArticleDialog
			v-if="showAddArticleDialog"
			:initial-url="addArticleUrl"
			@close="showAddArticleDialog = false; addArticleUrl = ''"
			@added="onArticleAdded" />
	</NcContent>
</template>

<script>
import { mapState, mapGetters, mapActions, mapMutations } from 'vuex'
import { showSuccess } from '@nextcloud/dialogs'
import {
	NcContent,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppNavigationCaption,
	NcAppNavigationSpacer,
	NcAppContent,
	NcButton,
	NcCounterBubble,
	NcActions,
	NcActionButton,
} from '@nextcloud/vue'

import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import InboxOutline from 'vue-material-design-icons/InboxOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import Rss from 'vue-material-design-icons/Rss.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'

import ArticleList from './components/ArticleList.vue'
import ArticleReader from './components/ArticleReader.vue'
import AddArticleDialog from './components/AddArticleDialog.vue'
import Settings from './components/Settings.vue'
import Sidebar from './components/Sidebar.vue'

export default {
	name: 'MerlinApp',

	components: {
		NcContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationCaption,
		NcAppNavigationSpacer,
		NcAppContent,
		NcButton,
		NcCounterBubble,
		FormatListBulleted,
		InboxOutline,
		Star,
		Archive,
		Rss,
		Plus,
		TrashCanOutline,
		PlayCircleOutline,
		Cog,
		NcActions,
		NcActionButton,
		ArticleList,
		ArticleReader,
		AddArticleDialog,
		Settings,
		MerlinSidebar: Sidebar,
	},

	data() {
		return {
			showAddArticleDialog: false,
			addArticleUrl: '',
			currentFilter: 'unread',
			// Tracks whether we pushed a history entry when opening the reader.
			// Needed so manual close can pop it, preventing a dangling back-entry.
			_readerHistoryPushed: false,
			_pollInterval: null,
			currentTagId: null,
		}
	},

	computed: {
		...mapState(['articles', 'counts', 'tags', 'currentArticle', 'loading', 'view', 'settings']),
		...mapGetters(['unreadCount', 'favoriteCount', 'filteredArticles']),

		nextArticle() {
			if (!this.currentArticle) return null
			// filteredArticles statt articles, damit "nächster Artikel" nicht in
			// einen per excludedTagIds ausgeblendeten Artikel navigiert.
			const idx = this.filteredArticles.findIndex(a => a.id === this.currentArticle.id)
			return idx !== -1 && idx < this.filteredArticles.length - 1
				? this.filteredArticles[idx + 1]
				: null
		},
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
			// 'unread'-Start-Zustand, falls der Nutzer eine andere Default-Ansicht gewählt hat.
			const defaultView = this.settings && this.settings.defaultView
			if (defaultView && defaultView !== this.currentFilter) {
				this.setFilter(defaultView)
			}
			this._pollInterval = setInterval(() => this.pollForUpdates(), 15_000)
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
		...mapActions(['fetchArticles', 'fetchCounts', 'fetchTags', 'fetchSettings', 'deleteArticle', 'deleteTag', 'pollForUpdates']),
		...mapMutations(['SET_FILTER', 'RESET_FILTER', 'SET_VIEW', 'SET_CURRENT_ARTICLE']),

		async loadData() {
			await Promise.all([
				this.fetchArticles(),
				this.fetchCounts(),
				this.fetchTags(),
				this.fetchSettings(),
			])
		},

		setFilter(type) {
			this.currentFilter = type
			this.currentTagId = null
			this.RESET_FILTER()
			this.SET_VIEW('list')
			switch (type) {
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
			// Always show both archived and non-archived articles for tag filters
			this.SET_FILTER({ key: 'isArchived', value: null })
			this.fetchArticles()
		},

		filterByCategory(category) {
			this.currentFilter = 'video'
			this.RESET_FILTER()
			this.SET_VIEW('list')
			this.SET_FILTER({ key: 'category', value: category })
			this.SET_FILTER({ key: 'isArchived', value: null })
			this.fetchArticles()
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

		async handleDeleteTag(tagId) {
			await this.deleteTag(tagId)
			if (this.currentTagId === tagId) {
				this.currentTagId = null
				this.setFilter('all')
			}
		},

		openSettings() {
			this.currentFilter = null
			this.SET_VIEW('settings')
		},

		onTogglePinTag(tagId) {
			// Tag-Pinning not yet implemented server-side
			console.warn('[Merlin] onTogglePinTag not implemented', tagId)
		},
	},
}
</script>

<style scoped>
.sidebar-new-article {
	padding: 8px 12px 4px;
}

.new-article-btn {
	width: 100%;
	justify-content: flex-start;
}

.tags-caption-row {
	display: flex;
	align-items: center;
	padding: 4px 12px;
	min-height: 34px;
	list-style: none;
}

.tags-caption-label {
	flex: 1;
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}


.tag-dot {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	flex-shrink: 0;
}

/* Move the sidebar collapse/toggle button to the bottom */
:deep(.app-navigation-toggle-wrapper) {
	top: auto !important;
	bottom: 0 !important;
}

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
</style>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 