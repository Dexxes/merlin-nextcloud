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
					<a v-if="article.siteName && safeArticleUrl" :href="safeArticleUrl" target="_blank" rel="noopener noreferrer">{{ article.siteName }}</a>
					<span v-else-if="article.siteName">{{ article.siteName }}</span>
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
		ttsUrl() {
			return generateUrl(`/apps/merlin/s/${this.token}/tts`)
		},

		// Nur http(s)/relative/Anker-URLs im href zulassen. article.url wird vom
		// Share-Owner kontrolliert; ein javascript:-Schema wuerde sonst beim Klick
		// eines Share-Besuchers ausgefuehrt (Vue sanitisiert v-bind:href NICHT).
		safeArticleUrl() {
			const url = this.article?.url
			if (typeof url !== 'string') return null
			const normalized = url.replace(/[\u0000-\u0020]+/g, '').toLowerCase()
			if (normalized.startsWith('javascript:')
				|| normalized.startsWith('vbscript:')
				|| normalized.startsWith('data:')) {
				return null
			}
			return url
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
						this._executeEmbedScripts()
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

		// v-html setzt den Inhalt über .innerHTML – <script>-Tags, die dabei ins
		// DOM gelangen, werden vom Browser NIE ausgeführt (Standardverhalten,
		// nicht Vue-spezifisch). Der Sanitizer lässt aber genau zwei <script>-Tags
		// durch (isAllowedWidgetScriptSrc() im Backend: Instagrams embed.js, X'
		// widgets.js) – ohne diesen Schritt blieben deren <blockquote>s für immer
		// als reiner Link/Zitat-Fallback stehen, statt zum Post/Reel zu werden.
		// Jedes gefundene <script> wird deshalb durch eine neu erzeugte Kopie
		// ersetzt; nur DAS bringt den Browser dazu, es auszuführen.
		_executeEmbedScripts() {
			if (!this.$refs.bodyEl) return
			this.$refs.bodyEl.querySelectorAll('script').forEach(oldScript => {
				const newScript = document.createElement('script')
				for (const attr of oldScript.attributes) {
					newScript.setAttribute(attr.name, attr.value)
				}
				oldScript.replaceWith(newScript)
			})
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

<style>
/* Der eigentliche Übeltäter: Nextclouds layout.base.php wrappt unseren App-Root
   automatisch in <div id="content" class="app-public">. Core-CSS setzt darauf
   height: var(--body-height) + overflow: clip (server.css) – "clip" erlaubt im
   Gegensatz zu "hidden" nicht einmal programmatisches Scrollen. Bei längeren
   Artikeln wird der Text dadurch hart abgeschnitten statt scrollbar zu sein.
   body selbst hilft nicht, weil body position:fixed mit fester Höhe ist –
   der Fix muss also am #content-Wrapper selbst ansetzen. */
#content.app-public {
	/* Core setzt #content auf display:flex (row) – gedacht für Layouts mit
	   Sidebar. Als Flex-Item muss unser Root-Block sein width:100% erst über
	   flex-basis "erkämpfen", und ein Scrollbar (durch overflow-y:auto) frisst
	   dabei einseitig von der rechten Kante der Content-Box, wodurch die
	   Zentrierung von .pav-article sichtbar nach links verschoben wirkt.
	   display:block eliminiert das komplett: der Root-Block ist dann ganz
	   normal 100% breit, ohne Flex-Sizing-Eigenheiten.
	   Height bleibt wie von Core vorgegeben (var(--body-height)) – nur
	   display und overflow werden für diese Seite überschrieben. */
	display: block;
	overflow-y: auto;
	overflow-x: hidden;
}
</style>

<style scoped>
.public-article-view {
	/* #content.app-public ist jetzt display:block (siehe oben) – width:100%
	   ist dadurch eigentlich der Block-Default, wird hier aber explizit
	   gesetzt, damit .pav-article (max-width + margin:0 auto) zuverlässig
	   über die volle Breite zentrieren kann. */
	width: 100%;
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

/* Self-hosted <video> (GIF-Ersatz mancher Blogs, siehe sanitizeHtml()) bringt
   keine sinnvolle Default-Breite mit – ohne diese Regel rendert es in seiner
   nativen Pixelbreite und sprengt die Artikelspalte. */
.pav-body :deep(video) {
	max-width: 100%;
	height: auto;
	display: block;
	margin: 2em auto;
}

/* Video-Embeds (YouTube/Vimeo/Twitch/TikTok/Facebook/Arte), siehe
   isAllowedVideoEmbedSrc() im Backend. */
.pav-body :deep(iframe) {
	display: block;
	width: 100%;
	max-width: 100%;
	aspect-ratio: 16 / 9;
	border: 0;
	margin: 2em auto;
}

/* Instagram-/X-/Bluesky-Embeds (siehe isAllowedWidgetScriptSrc()) rendern sich
   nach dem Laden des Widget-Skripts selbst neu und bringen ihr eigenes
   Kartendesign mit. */
.pav-body :deep(blockquote.instagram-media),
.pav-body :deep(blockquote.twitter-tweet),
.pav-body :deep(blockquote.bluesky-embed) {
	max-width: 100%;
	overflow: hidden;
	margin: 2em auto;
}

/* Mastodon-Post-Karte (siehe MastodonPostResolverService/
   buildMastodonThreadHtml()): kein Drittanbieter-Widget wie Instagram/X/
   Bluesky (föderiert, kein zentraler Embed-Host), sondern eigenes,
   statisches Markup - braucht deshalb echtes Styling. */
.pav-body :deep(.merlin-mastodon-post) {
	display: block;
	border: 1px solid var(--color-border, #ccc);
	border-radius: 8px;
	padding: 1em 1.2em;
	margin: 1.2em 0;
	color: inherit;
	font-style: normal;
}

.pav-body :deep(.merlin-mastodon-post + .merlin-mastodon-post) {
	margin-top: 0.5em;
}

.pav-body :deep(.merlin-mastodon-post__header) {
	display: flex;
	align-items: center;
	gap: 0.6em;
	text-decoration: none;
	color: inherit;
	margin-bottom: 0.6em;
}

.pav-body :deep(.merlin-mastodon-post__avatar) {
	width: 40px;
	height: 40px;
	border-radius: 50%;
	object-fit: cover;
	flex-shrink: 0;
	margin: 0;
}

.pav-body :deep(.merlin-mastodon-post__author) {
	display: flex;
	flex-direction: column;
	line-height: 1.3;
	min-width: 0;
}

.pav-body :deep(.merlin-mastodon-post__name) {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pav-body :deep(.merlin-mastodon-post__handle) {
	color: var(--color-text-lighter, #888);
	font-size: 0.9em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pav-body :deep(.merlin-mastodon-post__content p) {
	margin: 0.5em 0;
}

.pav-body :deep(.merlin-mastodon-post__content p:first-child) {
	margin-top: 0;
}

.pav-body :deep(.merlin-mastodon-post__content p:last-child) {
	margin-bottom: 0;
}

.pav-body :deep(.merlin-mastodon-post__media) {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 0.5em;
	margin-top: 0.6em;
}

.pav-body :deep(.merlin-mastodon-post__media-item) {
	width: 100%;
	height: 160px;
	object-fit: cover;
	border-radius: 4px;
	margin: 0;
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
