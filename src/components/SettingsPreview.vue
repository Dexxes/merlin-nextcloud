<template>
	<aside class="settings-preview">
		<div class="settings-preview__chrome">
			<div class="settings-preview__dots">
				<span /><span /><span />
			</div>
			<span class="settings-preview__title">{{ t('merlin', 'Live preview') }}</span>
			<span class="settings-preview__hint">
				<Eye :size="13" />
				{{ t('merlin', 'Reader') }}
			</span>
		</div>

		<div :class="['settings-preview__body', { 'is-dark': resolvedTheme === 'dark' }]">
			<article class="settings-preview__article" :style="articleStyle">
				<h1 class="settings-preview__article-title">
					{{ t('merlin', 'The quiet craft of reading well') }}
				</h1>
				<div class="settings-preview__article-meta">
					<span><Clock :size="12" /> {{ t('merlin', '6 min read') }}</span>
					<span>{{ t('merlin', 'by Anya Mehra') }}</span>
					<span>longform.dev</span>
				</div>

				<p>{{ t('merlin', 'In an age of infinite feeds, the act of finishing an article has quietly become a small rebellion. Saving something to read later is a wager — that this version of you, the calmer one, will return to it.') }}</p>
				<p>{{ t('merlin', 'Most never do. The list grows; guilt accrues. The trick, it turns out, isn\'t discipline. It\'s design: a place that doesn\'t shout, type that breathes, a margin wide enough to think in.') }}</p>
				<p>{{ t('merlin', 'Set your column to a comfortable measure — somewhere between sixty and seventy-five characters. Choose a face you trust. The rest is just sitting still.') }}</p>

				<template v-if="settings.progressEdge !== 'off'">
					<div class="settings-preview__progress">
						<div class="settings-preview__progress-fill" />
					</div>
					<div class="settings-preview__legend">
						{{ t('merlin', '38% · about 4 min left') }}
					</div>
				</template>
			</article>
		</div>
	</aside>
</template>

<script>
import Eye from 'vue-material-design-icons/Eye.vue'
import Clock from 'vue-material-design-icons/Clock.vue'

const FONT_FAMILIES = {
	default: "'Lora', Georgia, serif",
	serif: "Georgia, 'Times New Roman', 'Palatino Linotype', serif",
	'sans-serif': "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif",
	monospace: "'Courier New', Courier, 'Lucida Console', monospace",
}

const FONT_SIZES = {
	small: 16,
	medium: 18,
	large: 20,
	'x-large': 22,
}

export default {
	name: 'SettingsPreview',

	components: { Eye, Clock },

	props: {
		settings: {
			type: Object,
			required: true,
		},
	},

	computed: {
		resolvedTheme() {
			if (this.settings.theme === 'dark') return 'dark'
			if (this.settings.theme === 'light') return 'light'
			// 'auto' — follow the document's NC theme
			if (typeof document !== 'undefined'
				&& document.documentElement.dataset.themes?.includes('dark')) {
				return 'dark'
			}
			return 'light'
		},

		articleStyle() {
			return {
				fontFamily: FONT_FAMILIES[this.settings.fontFamily] || FONT_FAMILIES.default,
				fontSize: (FONT_SIZES[this.settings.fontSize] || 18) + 'px',
				lineHeight: this.settings.lineHeight,
			}
		},
	},
}
</script>

<style scoped>
.settings-preview {
	position: sticky;
	top: 24px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	overflow: hidden;
	background: var(--color-main-background);
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.settings-preview__chrome {
	background: var(--color-background-hover);
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
	color: var(--color-text-lighter);
}

.settings-preview__dots {
	display: flex;
	gap: 6px;
}
.settings-preview__dots > span {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-border-dark, #dbdbdb);
}

.settings-preview__title {
	font-weight: 500;
	letter-spacing: 0.2px;
	margin-left: 8px;
}

.settings-preview__hint {
	margin-left: auto;
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.settings-preview__body {
	padding: 20px;
	height: 460px;
	overflow-y: auto;
	transition: background-color 0.25s ease, color 0.25s ease;
}

.settings-preview__body.is-dark {
	background: #1e1e1e;
	color: #e0e0e0;
}

.settings-preview__article {
	max-width: 800px;
	margin: 0 auto;
}

.settings-preview__article-title {
	font-size: 1.6em;
	font-weight: 700;
	line-height: 1.2;
	margin: 0 0 12px;
}

.settings-preview__article-meta {
	display: flex;
	gap: 12px;
	font-size: 0.78em;
	color: var(--color-text-lighter);
	margin-bottom: 20px;
	flex-wrap: wrap;
}
.settings-preview__body.is-dark .settings-preview__article-meta {
	color: #999;
}

.settings-preview__article-meta span {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.settings-preview__article p {
	margin: 1em 0;
	line-height: inherit;
}
.settings-preview__article p:first-of-type { margin-top: 0; }

.settings-preview__progress {
	position: relative;
	height: 4px;
	background: var(--color-border);
	border-radius: 2px;
	margin-top: 16px;
	overflow: hidden;
}
.settings-preview__body.is-dark .settings-preview__progress {
	background: #333;
}

.settings-preview__progress-fill {
	position: absolute;
	inset: 0 auto 0 0;
	background: var(--color-primary, #0082c9);
	width: 38%;
	border-radius: 2px;
}

.settings-preview__legend {
	font-size: 11px;
	color: var(--color-text-lighter);
	text-align: right;
	margin-top: 4px;
	font-variant-numeric: tabular-nums;
}
</style>
