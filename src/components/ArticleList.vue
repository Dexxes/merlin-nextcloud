<template>
	<div class="article-list">
		<div class="article-search">
			<label class="article-search-field">
				<Magnify :size="16" class="article-search-icon" />
				<input
					v-model="searchQuery"
					type="text"
					:placeholder="t('merlin', 'Search articles…')"
					class="article-search-input"
					@keydown.escape="searchQuery = ''">
				<button
					v-if="searchQuery"
					type="button"
					class="article-search-clear"
					:aria-label="t('merlin', 'Clear search')"
					@click="searchQuery = ''">
					<Close :size="14" />
				</button>
			</label>
		</div>

		<NcEmptyContent
			v-if="!loading && articles.length === 0"
			:name="emptyTitle"
			:description="emptyDescription">
			<template #icon>
				<Magnify v-if="searchQuery.trim()" :size="64" />
				<PlayCircleOutline v-else-if="filterCategory === 'video'" :size="64" />
				<BookOpen v-else :size="64" />
			</template>
		</NcEmptyContent>

		<NcLoadingIcon v-else-if="loading" :size="64" />

		<div v-else class="articles-grid">
			<ArticleCard
				v-for="article in articles"
				:key="article.id"
				:article="article"
				@click="$emit('open-article', article)"
				@open="$emit('open-article', article)"
				@archive="toggleArchive(article.id)"
				@favorite="toggleFavorite(article.id)"
				@delete="deleteArticle(article.id)" />
		</div>
	</div>
</template>

<script>
import { mapActions, mapMutations } from 'vuex'
import {
    NcEmptyContent,
    NcLoadingIcon
} from '@nextcloud/vue'
import BookOpen from 'vue-material-design-icons/BookOpen.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ArticleCard from './ArticleCard.vue'

export default {
	name: 'ArticleList',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		BookOpen,
		PlayCircleOutline,
		Magnify,
		Close,
		ArticleCard,
	},

	props: {
		articles: {
			type: Array,
			required: true,
		},
		loading: {
			type: Boolean,
			default: false,
		},
		filterCategory: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			searchQuery: '',
			_searchDebounceTimer: null,
		}
	},

	computed: {
		emptyTitle() {
			if (this.searchQuery.trim()) {
				return t('merlin', 'No results for "{query}"', { query: this.searchQuery.trim() })
			}
			if (this.filterCategory === 'video') {
				return t('merlin', 'No videos yet')
			}
			return t('merlin', 'No articles yet')
		},
		emptyDescription() {
			if (this.searchQuery.trim()) {
				return t('merlin', 'Try a different search term')
			}
			if (this.filterCategory === 'video') {
				return t('merlin', 'Save video links to watch them here')
			}
			return t('merlin', 'Add your first article to get started')
		},
	},

	watch: {
		// Debounced live search: `searchArticles` hits a dedicated backend
		// endpoint, so we avoid firing it on every keystroke.
		searchQuery(query) {
			clearTimeout(this._searchDebounceTimer)
			this._searchDebounceTimer = setTimeout(() => {
				const trimmed = query.trim()
				this.SET_FILTER({ key: 'search', value: trimmed })
				if (trimmed) {
					this.searchArticles(trimmed)
				} else {
					// Query cleared – restore the list for the active sidebar filter.
					this.fetchArticles()
				}
			}, 300)
		},
		// Switching sidebar filter/category while a search is active would
		// otherwise leave a stale query in the box that no longer matches
		// what's on screen (fetchArticles() in App.vue already replaced the
		// list using the new filter, bypassing the search box entirely).
		filterCategory() {
			this.searchQuery = ''
		},
	},

	beforeUnmount() {
		clearTimeout(this._searchDebounceTimer)
	},

	methods: {
		...mapActions(['deleteArticle', 'toggleArchive', 'toggleFavorite', 'searchArticles', 'fetchArticles']),
		...mapMutations(['SET_FILTER']),
	},
}
</script>

<style scoped>
.article-list {
	padding: 20px;
	/* Safe area für Landscape-Modus (Notch/Dynamic Island seitlich) */
	padding-left: max(20px, env(safe-area-inset-left, 20px));
	padding-right: max(20px, env(safe-area-inset-right, 20px));
	min-height: 100%;
}

.article-search {
	margin-bottom: 16px;
}

.article-search-field {
	display: flex;
	align-items: center;
	gap: 8px;
	height: 36px;
	max-width: 400px;
	padding: 0 12px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-background-hover);
	border: 1px solid transparent;
	color: var(--color-text-maxcontrast);
	transition: color 120ms, border-color 120ms;
}

.article-search-field:focus-within {
	color: var(--color-main-text);
	border-color: var(--color-primary-element);
}

.article-search-icon {
	flex-shrink: 0;
}

.article-search-input {
	flex: 1;
	min-width: 0;
	border: none;
	outline: none;
	background: transparent;
	font-size: 14px;
	font-family: inherit;
	color: inherit;
	padding: 0;
}

.article-search-clear {
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
}

.article-search-clear:hover {
	color: var(--color-main-text);
}

@media (max-width: 768px) {
	.article-search-field {
		max-width: none;
	}
}

.articles-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 20px;
}

@media (max-width: 768px) {
	.article-list {
		padding: 12px;
		padding-left: max(12px, env(safe-area-inset-left, 12px));
		padding-right: max(12px, env(safe-area-inset-right, 12px));
	}

	.articles-grid {
		grid-template-columns: 1fr;
		gap: 12px;
	}
}
</style>
