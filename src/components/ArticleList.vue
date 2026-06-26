<template>
	<div class="article-list">
		<NcEmptyContent
			v-if="!loading && articles.length === 0"
			:title="emptyTitle"
			:description="emptyDescription">
			<template #icon>
				<PlayCircleOutline v-if="filterCategory === 'video'" :size="64" />
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
import { mapActions } from 'vuex'
import {
    NcEmptyContent,
    NcLoadingIcon
} from '@nextcloud/vue'
import BookOpen from 'vue-material-design-icons/BookOpen.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import ArticleCard from './ArticleCard.vue'

export default {
	name: 'ArticleList',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		BookOpen,
		PlayCircleOutline,
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

	computed: {
		emptyTitle() {
			if (this.filterCategory === 'video') {
				return t('merlin', 'No videos yet')
			}
			return t('merlin', 'No articles yet')
		},
		emptyDescription() {
			if (this.filterCategory === 'video') {
				return t('merlin', 'Save video links to watch them here')
			}
			return t('merlin', 'Add your first article to get started')
		},
	},

	methods: {
		...mapActions(['deleteArticle', 'toggleArchive', 'toggleFavorite']),
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
