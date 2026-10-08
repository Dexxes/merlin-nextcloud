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
				     icon, counter bubble), as a tree: sub-tags are indented below
				     their parent, which gets a chevron to fold them. While searching,
				     matches are listed flat with their path. Eye and trash sit inline
				     next to each tag, "Move to…" in the menu; hidden tags
				     (excludedTagIds, also editable in Settings) and their sub-tags
				     are dimmed. -->
				<NcAppNavigationItem
					v-for="row in visibleTags"
					:key="row.tag.id"
					:class="['tag-row', { 'is-hidden-tag': isTagDimmed(row.tag.id) }]"
					:style="{ '--tag-depth': row.depth }"
					:name="row.label"
					:active="currentTagId === row.tag.id"
					:inline-actions="2"
					@click="$emit('filter-tag', row.tag.id)">
					<template #icon>
						<span class="tag-icon">
							<span v-if="row.hasChildren"
								class="tag-fold tag-fold--toggle"
								role="button"
								tabindex="0"
								:aria-expanded="isExpanded(row.tag.id) ? 'true' : 'false'"
								:aria-label="isExpanded(row.tag.id) ? t('merlin', 'Collapse sub-tags') : t('merlin', 'Expand sub-tags')"
								:title="isExpanded(row.tag.id) ? t('merlin', 'Collapse sub-tags') : t('merlin', 'Expand sub-tags')"
								@click.stop.prevent="toggleExpanded(row.tag.id)"
								@keydown.enter.space.stop.prevent="toggleExpanded(row.tag.id)">
								<ChevronDown v-if="isExpanded(row.tag.id)" :size="14" />
								<ChevronRight v-else :size="14" />
							</span>
							<span v-else class="tag-fold" />
							<span class="tag-dot" :style="{ backgroundColor: row.tag.color }" />
						</span>
					</template>
					<template v-if="row.tag.count != null" #counter>
						<NcCounterBubble :count="row.tag.count" />
					</template>
					<template #actions>
						<NcActionButton
							:aria-label="isTagHidden(row.tag.id) ? t('merlin', 'Show tag') : t('merlin', 'Hide tag')"
							:title="isTagHidden(row.tag.id) ? t('merlin', 'Show tag') : t('merlin', 'Hide tag')"
							@click="$emit('toggle-tag-hidden', row.tag.id)">
							<template #icon>
								<EyeOffOutline v-if="isTagHidden(row.tag.id)" :size="20" />
								<EyeOutline v-else :size="20" />
							</template>
							{{ isTagHidden(row.tag.id) ? t('merlin', 'Show tag') : t('merlin', 'Hide tag') }}
						</NcActionButton>
						<NcActionButton
							:aria-label="t('merlin', 'Delete tag')"
							:title="t('merlin', 'Delete tag')"
							@click="$emit('delete-tag', row.tag.id)">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							{{ t('merlin', 'Delete tag') }}
						</NcActionButton>
						<NcActionButton
							:aria-label="t('merlin', 'Move to…')"
							@click="tagToMove = row.tag">
							<template #icon>
								<FolderMoveOutline :size="20" />
							</template>
							{{ t('merlin', 'Move to…') }}
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

		<MoveTagDialog
			v-if="tagToMove"
			:tag="tagToMove"
			:tags="tags"
			@close="onMoveTagClose" />
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
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FolderMoveOutline from 'vue-material-design-icons/FolderMoveOutline.vue'
import MoveTagDialog from './MoveTagDialog.vue'
import { childrenMap, descendantIds, tagPath } from '../tag-tree'

// Unfolded parent tags, remembered per browser.
const EXPANDED_KEY = 'merlin_expanded_tags'

/**
 * @return {Set<number>}
 */
function loadExpanded() {
	try {
		const parsed = JSON.parse(localStorage.getItem(EXPANDED_KEY) || '[]')
		return new Set(Array.isArray(parsed) ? parsed : [])
	} catch {
		return new Set()
	}
}

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
		ChevronRight,
		FolderMoveOutline,
		MoveTagDialog,
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
		'move-tag',
		'open-settings',
	],

	data() {
		return {
			tagQuery: '',
			showAllTags: false,
			expandedTagIds: loadExpanded(),
			// Tag whose "Move to…" dialog is open.
			tagToMove: null,
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
		// The active tag stays visible: unfold its parents.
		currentTagId: {
			immediate: true,
			handler(tagId) {
				const byId = new Map(this.tags.map(t => [t.id, t]))
				let parent = byId.get(byId.get(tagId)?.parentId)
				let changed = false
				while (parent && !this.expandedTagIds.has(parent.id)) {
					this.expandedTagIds.add(parent.id)
					changed = true
					parent = byId.get(parent.parentId)
				}
				if (changed) this.saveExpanded()
			},
		},
	},

	methods: {
		isTagHidden(tagId) {
			return this.hiddenTagIds.has(tagId)
		},
		// Dimmed: hidden itself or below a hidden tag (hiding includes sub-tags).
		isTagDimmed(tagId) {
			return this.inheritedHiddenTagIds.has(tagId)
		},
		isExpanded(tagId) {
			return this.expandedTagIds.has(tagId)
		},
		toggleExpanded(tagId) {
			if (this.expandedTagIds.has(tagId)) this.expandedTagIds.delete(tagId)
			else this.expandedTagIds.add(tagId)
			this.saveExpanded()
		},
		saveExpanded() {
			try {
				localStorage.setItem(EXPANDED_KEY, JSON.stringify([...this.expandedTagIds]))
			} catch {
				// storage unavailable (private mode): folding still works for this visit
			}
		},
		onMoveTagClose(parentId) {
			const tag = this.tagToMove
			this.tagToMove = null
			if (tag && parentId !== undefined) {
				// Unfold the new parent so the moved tag stays in sight.
				if (parentId !== null && !this.expandedTagIds.has(parentId)) {
					this.expandedTagIds.add(parentId)
					this.saveExpanded()
				}
				this.$emit('move-tag', { tagId: tag.id, parentId })
			}
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
		tagChildren() {
			return childrenMap(this.tags)
		},
		inheritedHiddenTagIds() {
			const hidden = new Set()
			for (const id of this.hiddenTagIds) {
				hidden.add(id)
				for (const child of descendantIds(this.tags, id, this.tagChildren)) hidden.add(child)
			}
			return hidden
		},
		// Rows to render: the tree with folded branches left out, or while
		// searching every match flat, labelled with its path.
		filteredTags() {
			const q = this.tagQuery.trim().toLowerCase()
			if (q) {
				return this.tags
					.filter(t => (t.name || '').toLowerCase().includes(q))
					.map(tag => ({ tag, depth: 0, hasChildren: false, label: tagPath(this.tags, tag) }))
					.sort((a, b) => a.label.localeCompare(b.label))
			}
			const rows = []
			const walk = (parentId, depth) => {
				for (const tag of this.tagChildren.get(parentId) || []) {
					const hasChildren = this.tagChildren.has(tag.id)
					rows.push({ tag, depth, hasChildren, label: tag.name })
					if (hasChildren && this.expandedTagIds.has(tag.id)) walk(tag.id, depth + 1)
				}
			}
			walk(null, 0)
			return rows
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

/* Sub-tags are indented by their depth; the icon box holds the fold
   chevron (or its space) and the colored dot. */
.tag-row {
	padding-inline-start: calc(var(--tag-depth, 0) * 16px);
}

.tag-icon {
	display: flex;
	align-items: center;
}

/* Every tag row reserves the chevron slot left of the dot, so dots and
   names line up whether a tag has sub-tags or not. */
.tag-fold {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 14px;
	height: 20px;
	border-radius: 4px;
	color: var(--color-text-maxcontrast);
}

.tag-fold--toggle {
	cursor: pointer;
}

.tag-fold--toggle:hover,
.tag-fold--toggle:focus-visible {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.tag-icon .tag-dot {
	margin-inline: 1px 5px;
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
