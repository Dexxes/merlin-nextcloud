<template>
	<NcDialog
		:name="t('merlin', 'Delete tag')"
		:open="true"
		@close="$emit('close', false)">
		<div class="delete-tag">
			<p v-if="subTags.length === 0">
				{{ t('merlin', 'Delete the tag "{name}"?', { name: tag.name }) }}
			</p>
			<template v-else>
				<p class="delete-tag__warning">
					{{ n('merlin', 'Delete the tag "{name}" and its {count} sub-tag?', 'Delete the tag "{name}" and its {count} sub-tags?', subTags.length, { name: tag.name, count: subTags.length }) }}
				</p>
				<ul class="delete-tag__list">
					<li v-for="sub in subTags" :key="sub.id">
						<span class="delete-tag__dot" :style="{ backgroundColor: sub.color }" />
						{{ sub.path }}
					</li>
				</ul>
			</template>
			<p>{{ t('merlin', 'The articles are kept, only the tags are removed from them.') }}</p>
		</div>

		<template #actions>
			<NcButton @click="$emit('close', false)">
				{{ t('merlin', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" @click="$emit('close', true)">
				{{ subTags.length ? t('merlin', 'Delete all') : t('merlin', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { descendantIds, flattenTree, tagPath } from '../tag-tree'

/**
 * Rückfrage vor dem Löschen eines Tags. Ein Tag wird samt allen Unter-Tags
 * gelöscht (TagController::destroy); die Unter-Tags werden deshalb einzeln
 * mit Pfad aufgezählt. close(true) = löschen.
 */
export default {
	name: 'DeleteTagDialog',

	components: { NcButton, NcDialog },

	props: {
		tag: { type: Object, required: true },
		tags: { type: Array, required: true },
	},

	emits: ['close'],

	computed: {
		subTags() {
			const ids = new Set(descendantIds(this.tags, this.tag.id))
			return flattenTree(this.tags)
				.filter(({ tag }) => ids.has(tag.id))
				.map(({ tag }) => ({ id: tag.id, color: tag.color, path: tagPath(this.tags, tag) }))
		},
	},
}
</script>

<style scoped>
.delete-tag p + p,
.delete-tag__list + p {
	margin-top: 8px;
}

.delete-tag__warning {
	font-weight: bold;
}

.delete-tag__list {
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
	max-height: 40vh;
	overflow-y: auto;
}

.delete-tag__list li {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 2px 0;
}

.delete-tag__dot {
	flex: none;
	width: 10px;
	height: 10px;
	border-radius: 50%;
}
</style>
