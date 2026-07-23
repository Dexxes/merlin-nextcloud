<template>
	<div class="public-article-view">
		<div v-if="state === 'loading'" class="pav-state">
			{{ t('merlin', 'Loading…') }}
		</div>

		<div v-else-if="state === 'locked'" class="pav-state pav-locked">
			<h1>{{ t('merlin', 'Password required') }}</h1>
			<p>{{ t('merlin', 'This article is protected with a password.') }}</p>
			<form class="pav-unlock-form" @submit.prevent="handleUnlock">
				<input
					v-model="password"
					type="password"
					class="pav-input"
					:placeholder="t('merlin', 'Password')"
					autofocus>
				<button type="submit" class="pav-btn pav-btn--primary" :disabled="unlocking || !password">
					{{ t('merlin', 'Unlock') }}
				</button>
			</form>
			<p v-if="unlockError" class="pav-error">{{ unlockError }}</p>
		</div>

		<div v-else-if="state === 'notfound'" class="pav-state">
			<h1>{{ t('merlin', 'Link not found') }}</h1>
			<p>{{ t('merlin', 'This share link does not exist or has been revoked.') }}</p>
		</div>

		<div v-else-if="state === 'expired'" class="pav-state">
			<h1>{{ t('merlin', 'Link expired') }}</h1>
			<p>{{ t('merlin', 'This share link is no longer valid.') }}</p>
		</div>

		<div v-else-if="state === 'error'" class="pav-state">
			<h1>{{ t('merlin', 'Something went wrong') }}</h1>
			<p>{{ t('merlin', 'Please try again later.') }}</p>
		</div>

		<article v-else-if="state === 'ready'" class="pav-article">
			<div class="pav-toolbar">
				<a :href="exportUrl" class="pav-btn" download>{{ t('merlin', 'Download HTML') }}</a>
				<button type="button" class="pav-btn" @click="toggleAudio">
					{{ audioVisible ? t('merlin', 'Hide audio player') : t('merlin', 'Listen') }}
				</button>
			</div>

			<audio v-if="audioVisible" ref="audioEl" class="pav-audio" controls :src="ttsUrl" />

			<header class="pav-header">
				<h1>{{ article.title }}</h1>
				<p v-if="article.excerpt" class="pav-excerpt">{{ article.excerpt }}</p>
				<div class="pav-meta">
					<span v-if="article.author">{{ article.author }}</span>
					<a v-if="article.siteName" :href="article.url" target="_blank" rel="noopener noreferrer">{{ article.siteName }}</a>
					<span v-if="article.readingTime">{{ t('merlin', '{minutes} min', { minutes: article.readingTime }) }}</span>
				</div>
			</header>

			<!-- eslint-disable-next-line vue/no-v-html -->
			<div ref="bodyEl" class="pav-body" v-html="article.content" />
		</article>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { renderHighlightsReadOnly } from '../highlight-engine'

export default {
	name: 'PublicArticleView',

	data() {
		return {
			token: loadState('merlin', 'shareToken', ''),
			state: 'loading',
			article: null,
			password: '',
			unlocking: false,
			unlockError: '',
			audioVisible: false,
		}
	},

	computed: {
		exportUrl() {
			return generateUrl(`/apps/merlin/s/${this.token}/export/html`)
		},
		ttsUrl() {
			return generateUrl(`/apps/merlin/s/${this.token}/tts`)
		},
	},

	mounted() {
		this.fetchData()
	},

	methods: {
		async fetchData() {
			this.state = 'loading'
			try {
				const url = generateUrl(`/apps/merlin/s/${this.token}/data`)
				const response = await axios.get(url)
				this.article = response.data
				this.state = 'ready'
				this.$nextTick(() => {
					if (this.$refs.bodyEl) {
						renderHighlightsReadOnly(this.$refs.bodyEl, this.article.highlights || [])
					}
				})
			} catch (error) {
				const status = error.response?.status
				if (status === 401) {
					this.state = 'locked'
				} else if (status === 404) {
					this.state = 'notfound'
				} else if (status === 410) {
					this.state = 'expired'
				} else {
					this.state = 'error'
				}
			}
		},

		async handleUnlock() {
			this.unlocking = true
			this.unlockError = ''
			try {
				const url = generateUrl(`/apps/merlin/s/${this.token}/unlock`)
				await axios.post(url, { password: this.password })
				await this.fetchData()
			} catch (error) {
				this.unlockError = this.t('merlin', 'Incorrect password')
			} finally {
				this.unlocking = false
			}
		},

		toggleAudio() {
			this.audioVisible = !this.audioVisible
		},
	},
}
</script>

<style scoped>
.public-article-view {
	min-height: 100vh;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
}

.pav-state {
	max-width: 480px;
	margin: 15vh auto;
	padding: 0 24px;
	text-align: center;
}

.pav-unlock-form {
	display: flex;
	gap: 8px;
	justify-content: center;
	margin-top: 16px;
}

.pav-input {
	padding: 8px 10px;
	border: 1px solid var(--color-border, #ccc);
	border-radius: 6px;
	font-size: 1em;
}

.pav-error {
	color: #c00;
	margin-top: 10px;
}

.pav-btn {
	display: inline-block;
	padding: 8px 16px;
	border: 1px solid var(--color-border, #ccc);
	border-radius: 6px;
	background: var(--color-background-hover, #f5f5f5);
	color: inherit;
	text-decoration: none;
	cursor: pointer;
	font-size: 0.95em;
}

.pav-btn--primary {
	background: var(--color-primary, #0082c9);
	border-color: var(--color-primary, #0082c9);
	color: #fff;
}

.pav-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.pav-article {
	max-width: 760px;
	margin: 0 auto;
	padding: 40px 24px 80px;
}

.pav-toolbar {
	display: flex;
	gap: 10px;
	margin-bottom: 24px;
}

.pav-audio {
	width: 100%;
	margin-bottom: 24px;
}

.pav-header h1 {
	font-size: 2.2em;
	line-height: 1.2;
	margin: 0 0 12px 0;
}

.pav-excerpt {
	font-style: italic;
	font-weight: 600;
	color: var(--color-text-lighter, #666);
	margin: 0 0 16px 0;
}

.pav-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 14px;
	color: var(--color-text-lighter, #666);
	font-size: 0.9em;
	margin-bottom: 30px;
}

.pav-meta a {
	color: inherit;
}

.pav-body {
	font-size: 1.1em;
	line-height: 1.65;
}

.pav-body :deep(img) {
	max-width: 100%;
	height: auto;
}

.pav-body :deep(p) {
	margin: 1.4em 0;
}

.pav-body :deep(mark.merlin-highlight) {
	border-radius: 2px;
	padding: 0 1px;
	box-decoration-break: clone;
	-webkit-box-decoration-break: clone;
}
</style>
