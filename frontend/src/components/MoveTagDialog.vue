<template>
	<NcDialog
		:name="t('merlin', 'Move tag')"
		:open="true"
		@close="$emit('close', undefined)">
		<p class="move-tag__intro">
			{{ hasSubTags
				? t('merlin', 'Move "{name}" with its sub-tags below:', { name: tag.name })
				: t('merlin', 'Move "{name}" below:', { name: tag.name }) }}
		</p>
		<ul class="move-tag__list" role="listbox" :aria-label="t('merlin', 'New parent tag')">
			<li role="option"
				:aria-selected="selected === null ? 'true' : 'false'"
				:class="['move-tag__option', { 'is-selected': selected === null }]"
				tabindex="0"
				@click="selected = null"
				@keydown.enter.space.prevent="selected = null">
				<span class="move-tag__name move-tag__name--root">{{ t('merlin', 'Top level') }}</span>
			</li>
			<li v-for="row in targets"
				:key="row.tag.id"
				role="option"
				:aria-selected="selected === row.tag.id ? 'true' : 'false'"
				:class="['move-tag__option', { 'is-selected': selected === row.tag.id }]"
				:style="{ paddingInlineStart: (12 + row.depth * 16) + 'px' }"
				tabindex="0"
				@click="selected = row.tag.id"
				@keydown.enter.space.prevent="selected = row.tag.id">
				<span class="move-tag__dot" :style="{ backgroundColor: row.tag.color }" />
				<span class="move-tag__name">{{ row.tag.name }}</span>
			</li>
		</ul>

		<template #actions>
			<NcButton @click="$emit('close', undefined)">
				{{ t('merlin', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="selected === currentParent"
				@click="$emit('close', selected)">
				{{ t('merlin', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { descendantIds, flattenTree } from '../tag-tree'

/**
 * Wählt den neuen Eltern-Tag für einen Tag. Der Tag selbst und seine
 * Unter-Tags stehen nicht zur Wahl (sonst entstünde ein Kreis, siehe
 * TagTree::canMove). close(parentId) mit null = oberste Ebene,
 * close(undefined) = abgebrochen.
 */
export default {
	name: 'MoveTagDialog',

	components: { NcButton, NcDialog },

	props: {
		tag: { type: Object, required: true },
		tags: { type: Array, required: true },
	},

	emits: ['close'],

	data() {
		return {
			selected: this.tag.parentId ?? null,
		}
	},

	computed: {
		currentParent() {
			return this.tag.parentId ?? null
		},
		subTagIds() {
			return descendantIds(this.tags, this.tag.id)
		},
		hasSubTags() {
			return this.subTagIds.length > 0
		},
		targets() {
			const excluded = new Set([this.tag.id, ...this.subTagIds])
			return flattenTree(this.tags).filter(({ tag }) => !excluded.has(tag.id))
		},
	},
}
</script>

<style scoped>
.move-tag__intro {
	margin-bottom: 8px;
}

.move-tag__list {
	margin: 0;
	padding: 0;
	list-style: none;
	max-height: 50vh;
	overflow-y: auto;
}

.move-tag__option {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: var(--default-clickable-area, 34px);
	padding: 0 12px;
	border-radius: var(--border-radius-element, 8px);
	cursor: pointer;
}

.move-tag__option:hover {
	background: var(--color-background-hover);
}

.move-tag__option.is-selected {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: bold;
}

.move-tag__dot {
	flex: none;
	width: 10px;
	height: 10px;
	border-radius: 50%;
}

.move-tag__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.move-tag__name--root {
	font-style: italic;
}
</style>
