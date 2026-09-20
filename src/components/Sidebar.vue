<template>
	<NcAppNavigation>
		<template #list>
			<!-- Add article -->
			<div class="sidebar-new-article">
				<NcButton
					variant="primary"
					class="new-article-btn"
					@click="$emit('add-article')">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('merlin', 'Add Article') }}
				</NcButton>
			</div>

			<!-- Top-level filters: Pages and Videos, each with their own
			     Unread(/Unwatched)/Favorites/Archived sub-views. -->
			<li class="filter-caption-row app-navigation-caption">
				<span class="filter-caption-label">{{ t('merlin', 'Pages') }}</span>
			</li>
			<NcAppNavigationItem
				:name="t('merlin', 'Unread')"
				:active="currentFilter === 'pages-unread'"
				@click="$emit('filter', 'pages-unread')">
				<template #icon>
					<InboxOutline :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble :count="counts.pages.unread" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('merlin', 'Favorites')"
				:active="currentFilter === 'pages-favorites'"
				@click="$emit('filter', 'pages-favorites')">
				<template #icon>
					<Star :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble :count="counts.pages.favorites" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('merlin', 'Archived')"
				:active="currentFilter === 'pages-archived'"
				@click="$emit('filter', 'pages-archived')">
				<template #icon>
					<Archive :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble :count="counts.pages.archived" />
				</template>
			</NcAppNavigationItem>

			<li class="filter-caption-row app-navigation-caption">
				<span class="filter-caption-label">{{ t('merlin', 'Videos') }}</span>
			</li>
			<NcAppNavigationItem
				:name="t('merlin', 'Unwatched')"
				:active="currentFilter === 'videos-unread'"
				@click="$emit('filter', 'videos-unread')">
				<template #icon>
					<PlayCircleOutline :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble :count="counts.videos.unread" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('merlin', 'Favorites')"
				:active="currentFilter === 'videos-favorites'"
				@click="$emit('filter', 'videos-favorites')">
				<template #icon>
					<Star :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble :count="counts.videos.favorites" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('merlin', 'Archived')"
				:active="currentFilter === 'videos-archived'"
				@click="$emit('filter', 'videos-archived')">
				<template #icon>
					<Archive :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble :count="counts.videos.archived" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationSpacer />

			<template v-if="tags.length">
				<!-- Tags caption with count -->
				<li class="tags-caption-row app-navigation-caption">
					<span class="tags-caption-label">{{ t('merlin', 'Tags') }}</span>
					<span class="tags-caption-count">{{ tags.length }}</span>
				</li>

				<!-- Tag search -->
				<li class="tags-search-row">
					<label class="tags-search">
						<Magnify :size="16" class="tags-search-icon" />
						<input
							v-model="tagQuery"
							type="text"
							:placeholder="t('merlin', 'Filter tags…')"
							class="tags-search-input"
							@keydown.escape="tagQuery = ''">
						<button
							v-if="tagQuery"
							type="button"
							class="tags-search-clear"
							:aria-label="t('merlin', 'Clear search')"
							@click="tagQuery = ''">
							<Close :size="14" />
						</button>
					</label>
				</li>

				<!-- Tags -->
				<li v-if="visibleTags.length" class="tag-chips">
					<span
						v-for="tag in visibleTags"
						:key="tag.id"
						class="tag-chip"
						:class="{ 'is-active': currentTagId === tag.id }">
						<button
							type="button"
							class="tag-chip-main"
							:title="tag.name"
							@click="$emit('filter-tag', tag.id)">
							<span class="tag-chip-dot" :style="{ backgroundColor: tag.color }" />
							<span class="tag-chip-label">{{ tag.name }}</span>
							<span v-if="tag.count != null" class="tag-chip-count">{{ tag.count }}</span>
						</button>
						<NcActions
							class="tag-chip-actions"
							:force-menu="true">
							<NcActionButton @click="$emit('delete-tag', tag.id)">
								<template #icon>
									<TrashCanOutline :size="20" />
								</template>
								{{ t('merlin', 'Delete tag') }}
							</NcActionButton>
						</NcActions>
					</span>
				</li>

				<!-- Show all / less toggle -->
				<li v-if="!tagQuery && filteredTags.length > collapsedRestLimit" class="tags-show-all-row">
					<button
						type="button"
						class="tags-show-all-btn"
						@click="showAllTags = !showAllTags">
						<ChevronDown v-if="showAllTags" :size="12" />
						<ChevronRight v-else :size="12" />
						<span v-if="showAllTags">{{ t('merlin', 'Show less') }}</span>
						<span v-else>{{ n('merlin', 'Show all {count} tag', 'Show all {count} tags', filteredTags.length, { count: filteredTags.length }) }}</span>
					</button>
				</li>

				<!-- Empty state -->
				<li v-if="tagQuery && filteredTags.length === 0" class="tags-empty">
					{{ t('merlin', 'No tags matching "{query}"', { query: tagQuery }) }}
				</li>
			</template>

			<NcAppNavigationSpacer />

			<!-- Settings -->
			<NcAppNavigationItem
				:name="t('merlin', 'Settings')"
				:active="view === 'settings'"
				@click="$emit('open-settings')">
				<template #icon>
					<Cog :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import {
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppNavigationSpacer,
	NcButton,
	NcCounterBubble,
	NcActions,
	NcActionButton,
} from '@nextcloud/vue'

import InboxOutline from 'vue-material-design-icons/InboxOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

export default {
	name: 'Sidebar',

	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSpacer,
		NcButton,
		NcCounterBubble,
		NcActions,
		NcActionButton,
		InboxOutline,
		Star,
		Archive,
		Plus,
		TrashCanOutline,
		PlayCircleOutline,
		Cog,
		Magnify,
		Close,
		ChevronDown,
		ChevronRight,
	},

	props: {
		tags: {
			type: Array,
			required: true,
		},
		counts: {
			type: Object,
			required: true,
		},
		currentFilter: {
			type: String,
			default: null,
		},
		currentTagId: {
			type: [Number, String],
			default: null,
		},
		// Which main view is active ('list' | 'reader' | 'settings'). Used to
		// highlight the Settings entry, since opening Settings clears
		// currentFilter rather than setting it to 'settings'.
		view: {
			type: String,
			default: 'list',
		},
		// Number of tags to render before the "Show all" toggle.
		collapsedRestLimit: {
			type: Number,
			default: 8,
		},
	},

	emits: [
		'add-article',
		'filter',
		'filter-tag',
		'delete-tag',
		'open-settings',
	],

	data() {
		return {
			tagQuery: '',
			showAllTags: false,
		}
	},

	computed: {
		filteredTags() {
			const q = this.tagQuery.trim().toLowerCase()
			if (!q) return this.tags
			return this.tags.filter(t => (t.name || '').toLowerCase().includes(q))
		},
		visibleTags() {
			if (this.tagQuery || this.showAllTags) return this.filteredTags
			return this.filteredTags.slice(0, this.collapsedRestLimit)
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
	justify-content: center;
}

/* ── Pages/Videos filter group captions ──────────────────────────────── */
.filter-caption-row {
	display: flex;
	align-items: center;
	padding: 4px 12px;
	min-height: 34px;
	list-style: none;
}

.filter-caption-row:not(:first-child) {
	margin-top: 8px;
}

.filter-caption-label {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

/* ── Tags caption ─────────────────────────────────────────────────── */
.tags-caption-row {
	display: flex;
	align-items: center;
	padding: 4px 12px;
	min-height: 34px;
	list-style: none;
	gap: 6px;
}

.tags-caption-label {
	flex: 1;
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.tags-caption-count {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
}

/* ── Tag search ───────────────────────────────────────────────────── */
.tags-search-row {
	list-style: none;
	padding: 0 8px 8px;
}

.tags-search {
	display: flex;
	align-items: center;
	gap: 6px;
	height: 28px;
	padding: 0 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-background-hover);
	border: none;
	color: var(--color-text-maxcontrast);
	transition: color 120ms;
}

.tags-search:focus-within {
	color: var(--color-main-text);
}

.tags-search-icon {
	flex-shrink: 0;
}

.tags-search-input {
	flex: 1;
	min-width: 0;
	border: none;
	outline: none;
	background: transparent;
	font-size: 13px;
	font-family: inherit;
	color: inherit;
	padding: 0;
}

.tags-search-clear {
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
}

.tags-search-clear:hover {
	color: var(--color-main-text);
}

/* ── Tag chips ────────────────────────────────────────────────────── */
.tag-chips {
	list-style: none;
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 4px 12px 8px;
}

/* Wrapper is a non-interactive element: the clickable filter surface is the
   nested .tag-chip-main <button>, and NcActions renders its own <button> as
   a sibling. Nesting a real <button> (NcActions) inside another <button>
   used to happen here — invalid HTML that breaks keyboard/screen-reader
   semantics. */
.tag-chip {
	display: inline-flex;
	align-items: center;
	height: 28px;
	max-width: 100%;
	padding: 0 4px 0 0;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-background-hover);
	border: 1px solid transparent;
	white-space: nowrap;
	transition: background 120ms, border-color 120ms;
}

.tag-chip:hover {
	background: var(--color-background-dark);
}

.tag-chip.is-active {
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
	font-weight: 600;
}

.tag-chip-main {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 100%;
	min-width: 0;
	max-width: 100%;
	padding: 0 4px 0 10px;
	border: none;
	background: transparent;
	font: inherit;
	font-size: 12px;
	color: inherit;
	cursor: pointer;
}

.tag-chip-dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
}

.tag-chip-label {
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 160px;
}

.tag-chip-count {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.tag-chip-actions {
	margin-inline-start: 2px;
	opacity: 0;
	transition: opacity 100ms;
}

.tag-chip:hover .tag-chip-actions,
.tag-chip:focus-within .tag-chip-actions {
	opacity: 1;
}

/* ── Show all toggle ──────────────────────────────────────────────── */
.tags-show-all-row {
	list-style: none;
	padding: 2px 12px 8px;
}

.tags-show-all-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	background: none;
	border: none;
	padding: 6px 4px;
	font-family: inherit;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	font-weight: 600;
}

.tags-show-all-btn:hover {
	color: var(--color-main-text);
}

/* ── Empty state ──────────────────────────────────────────────────── */
.tags-empty {
	list-style: none;
	padding: 12px 14px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-style: italic;
}
</style>
