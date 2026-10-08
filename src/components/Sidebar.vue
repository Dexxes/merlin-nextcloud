<template>
	<NcAppNavigation>
		<template #list>
			<!-- Add article -->
			<div class="sidebar-new-article">
				<NcButton
					variant="primary"
					wide
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

			<template v-if="tags.length">
				<NcAppNavigationCaption :name="t('merlin', 'Tags')" />

				<!-- Tag search: only worth it once the list is long enough to collapse -->
				<li v-if="tags.length > collapsedRestLimit" class="tags-search-row">
					<NcTextField
						v-model="tagQuery"
						:label="t('merlin', 'Filter tags…')"
						:show-trailing-button="tagQuery !== ''"
						:trailing-button-label="t('merlin', 'Clear search')"
						@trailing-button-click="tagQuery = ''"
						@keydown.escape="tagQuery = ''">
						<template #icon>
							<Magnify :size="20" />
						</template>
					</NcTextField>
				</li>

				<!-- Tags: same rows as the views above (colored dot instead of an
				     icon, counter bubble). Eye and trash sit inline next to each tag;
				     hidden tags (excludedTagIds, also editable in Settings) are dimmed. -->
				<NcAppNavigationItem
					v-for="tag in visibleTags"
					:key="tag.id"
					:class="{ 'is-hidden-tag': isTagHidden(tag.id) }"
					:name="tag.name"
					:active="currentTagId === tag.id"
					:inline-actions="2"
					@click="$emit('filter-tag', tag.id)">
					<template #icon>
						<span class="tag-dot" :style="{ backgroundColor: tag.color }" />
					</template>
					<template v-if="tag.count != null" #counter>
						<NcCounterBubble :count="tag.count" />
					</template>
					<template #actions>
						<NcActionButton
							:aria-label="isTagHidden(tag.id) ? t('merlin', 'Show tag') : t('merlin', 'Hide tag')"
							:title="isTagHidden(tag.id) ? t('merlin', 'Show tag') : t('merlin', 'Hide tag')"
							@click="$emit('toggle-tag-hidden', tag.id)">
							<template #icon>
								<EyeOffOutline v-if="isTagHidden(tag.id)" :size="20" />
								<EyeOutline v-else :size="20" />
							</template>
							{{ isTagHidden(tag.id) ? t('merlin', 'Show tag') : t('merlin', 'Hide tag') }}
						</NcActionButton>
						<NcActionButton
							:aria-label="t('merlin', 'Delete tag')"
							:title="t('merlin', 'Delete tag')"
							@click="$emit('delete-tag', tag.id)">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							{{ t('merlin', 'Delete tag') }}
						</NcActionButton>
					</template>
				</NcAppNavigationItem>

				<!-- Show all / less toggle -->
				<NcAppNavigationItem
					v-if="!tagQuery && filteredTags.length > collapsedRestLimit"
					class="tags-show-all"
					:name="showAllTags
						? t('merlin', 'Show less')
						: n('merlin', 'Show all {count} tag', 'Show all {count} tags', filteredTags.length, { count: filteredTags.length })"
					@click="showAllTags = !showAllTags">
					<template #icon>
						<ChevronUp v-if="showAllTags" :size="20" />
						<ChevronDown v-else :size="20" />
					</template>
				</NcAppNavigationItem>

				<!-- Empty state -->
				<li v-if="tagQuery && filteredTags.length === 0" class="tags-empty">
					{{ t('merlin', 'No tags matching "{query}"', { query: tagQuery }) }}
				</li>
			</template>
		</template>

		<!-- Settings pinned to the bottom like in other Nextcloud apps -->
		<template #footer>
			<ul class="sidebar-footer">
				<NcAppNavigationItem
					:name="t('merlin', 'Settings')"
					:active="view === 'settings'"
					@click="$emit('open-settings')">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</ul>
		</template>
	</NcAppNavigation>
</template>

<script>
import {
	NcAppNavigation,
	NcAppNavigationCaption,
	NcAppNavigationItem,
	NcButton,
	NcCounterBubble,
	NcActionButton,
	NcTextField,
} from '@nextcloud/vue'

import InboxOutline from 'vue-material-design-icons/InboxOutline.vue'
import Star from 'vue-material-design-icons/Star.vue'
import Archive from 'vue-material-design-icons/Archive.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PlayCircleOutline from 'vue-material-design-icons/PlayCircleOutline.vue'
import Headphones from 'vue-material-design-icons/Headphones.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'

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
		NcAppNavigationCaption,
		NcAppNavigationItem,
		NcButton,
		NcCounterBubble,
		NcActionButton,
		NcTextField,
		InboxOutline,
		Star,
		Archive,
		Plus,
		TrashCanOutline,
		PlayCircleOutline,
		Headphones,
		Cog,
		EyeOutline,
		EyeOffOutline,
		Magnify,
		ChevronDown,
		ChevronUp,
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
		// Ids of tags hidden from the article list (setting excludedTagIds).
		hiddenTagIds: {
			type: Set,
			default: () => new Set(),
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
		'toggle-tag-hidden',
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

	methods: {
		isTagHidden(tagId) {
			return this.hiddenTagIds.has(tagId)
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
/* All custom rows share the inset of NcAppNavigationItem (the list already
   pads by --app-navigation-padding), its height and its corner radius, so
   button, tabs, views and tags line up on the same edges. */

.sidebar-new-article {
	box-sizing: border-box;
	width: 100%;
	padding-bottom: calc(var(--default-grid-baseline, 4px) * 2);
	list-style: none;
}

.new-article-btn {
	width: 100%;
}

/* ── Media type tabs (Text/Video/Audio) ───────────────────────────── */
.media-tabs-row {
	list-style: none;
	padding-bottom: var(--default-grid-baseline, 4px);
}

.media-tabs {
	display: flex;
	gap: 2px;
	padding: 2px;
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-primary-element-light);
}

.media-tab {
	flex: 1;
	min-width: 0;
	height: calc(var(--default-clickable-area, 34px) - 4px);
	min-height: 0;
	margin: 0;
	padding: 0 8px;
	border: none;
	border-radius: calc(var(--border-radius-element, 8px) - 2px);
	background: transparent;
	font: inherit;
	color: var(--color-primary-element-light-text);
	cursor: pointer;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	transition: background var(--animation-quick, 100ms), color var(--animation-quick, 100ms);
}

.media-tab:hover {
	background: var(--color-primary-element-light-hover);
}

.media-tab:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

/* Selected tab in the Nextcloud theme color, like the primary button above */
.media-tab.is-active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: bold;
}

/* ── Tags ─────────────────────────────────────────────────────────── */
.tags-search-row {
	list-style: none;
	padding-bottom: var(--default-grid-baseline, 4px);
}

/* Colored dot in the 20px icon box, same size as NcAppNavigationIconBullet */
.tag-dot {
	display: block;
	width: 14px;
	height: 14px;
	margin: 3px;
	border-radius: 50%;
}

/* Hidden tags stay listed (so they can be shown again) but are dimmed */
.is-hidden-tag :deep(.app-navigation-entry__name),
.is-hidden-tag .tag-dot {
	opacity: 0.5;
}

.tags-show-all :deep(.app-navigation-entry__name) {
	color: var(--color-text-maxcontrast);
}

.tags-empty {
	list-style: none;
	padding: var(--default-grid-baseline, 4px) calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-text-maxcontrast);
}

/* ── Footer ───────────────────────────────────────────────────────── */
.sidebar-footer {
	padding: var(--app-navigation-padding, 8px);
}
</style>
