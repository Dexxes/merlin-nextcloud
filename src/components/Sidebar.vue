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

			<!-- Media type tabs (Text/Video/Audio) like the iOS app's side menu.
			     Below them the Unread(/Unwatched/Not listened)/Favorites/Archived
			     views of the selected type. Switching a tab only changes which
			     views are listed; a view loads once it is clicked. "Mixed"
			     articles (text with embedded media) count as text. -->
			<li class="media-tabs-row">
				<div class="media-tabs" role="tablist" :aria-label="t('merlin', 'Media type')">
					<button
						v-for="tab in mediaTabs"
						:key="tab.group"
						type="button"
						role="tab"
						class="media-tab"
						:class="{ 'is-active': menuGroup === tab.group }"
						:aria-selected="menuGroup === tab.group ? 'true' : 'false'"
						@click="menuGroup = tab.group">
						{{ tab.label }}
					</button>
				</div>
			</li>
			<NcAppNavigationItem
				v-for="item in groupFilters"
				:key="item.filter"
				:name="item.label"
				:active="currentFilter === item.filter"
				@click="$emit('filter', item.filter)">
				<template #icon>
					<component :is="item.icon" :size="20" />
				</template>
				<template #counter>
					<NcCounterBubble :count="item.count" />
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
import Headphones from 'vue-material-design-icons/Headphones.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

/**
 * Media type ('pages' | 'videos' | 'audio') of a filter such as 'audio-favorites'.
 *
 * @param {string|null} filter Active sidebar filter
 * @return {string|null}
 */
function groupOf(filter) {
	const group = (filter || '').split('-')[0]
	return ['pages', 'videos', 'audio'].includes(group) ? group : null
}

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
		Headphones,
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
			// Media type shown in the tabs ('pages' | 'videos' | 'audio').
			// Follows the active filter; a tag filter leaves it unchanged.
			menuGroup: groupOf(this.currentFilter) || 'pages',
		}
	},

	watch: {
		currentFilter(filter) {
			const group = groupOf(filter)
			if (group) this.menuGroup = group
		},
	},

	computed: {
		mediaTabs() {
			return [
				{ group: 'pages', label: this.t('merlin', 'Text') },
				{ group: 'videos', label: this.t('merlin', 'Video') },
				{ group: 'audio', label: this.t('merlin', 'Audio') },
			]
		},
		// Views of the selected media type, in the same order as on iOS.
		groupFilters() {
			const group = this.menuGroup
			const counts = this.counts[group] || {}
			const unreadLabel = {
				pages: this.t('merlin', 'Unread'),
				videos: this.t('merlin', 'Unwatched'),
				audio: this.t('merlin', 'Not listened'),
			}[group]
			const unreadIcon = {
				pages: 'InboxOutline',
				videos: 'PlayCircleOutline',
				audio: 'Headphones',
			}[group]
			return [
				{ filter: `${group}-unread`, label: unreadLabel, icon: unreadIcon, count: counts.unread },
				{ filter: `${group}-favorites`, label: this.t('merlin', 'Favorites'), icon: 'Star', count: counts.favorites },
				{ filter: `${group}-archived`, label: this.t('merlin', 'Archived'), icon: 'Archive', count: counts.archived },
			]
		},
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
	display: flex;
	justify-content: center;
	box-sizing: border-box;
	width: 100%;
	padding: 8px 12px 4px;
	list-style: none;
}

.new-article-btn {
	width: 100%;
	max-width: 100%;
	justify-content: center;
}

.new-article-btn :deep(.button-vue__wrapper) {
	justify-content: center;
}

/* ── Media type tabs (Text/Video/Audio) ───────────────────────────── */
.media-tabs-row {
	list-style: none;
	padding: 8px 12px 6px;
}

.media-tabs {
	display: flex;
	gap: 2px;
	padding: 2px;
	border-radius: var(--border-radius-large, 10px);
	background: var(--color-background-hover);
}

.media-tab {
	flex: 1;
	min-width: 0;
	min-height: 30px;
	margin: 0;
	padding: 0 8px;
	border: none;
	border-radius: calc(var(--border-radius-large, 10px) - 2px);
	background: transparent;
	font: inherit;
	font-size: 13px;
	color: var(--color-main-text);
	cursor: pointer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	transition: background 120ms;
}

.media-tab:hover {
	background: var(--color-background-dark);
}

.media-tab.is-active {
	background: var(--color-main-background);
	font-weight: 600;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
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
