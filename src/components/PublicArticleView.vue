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
				<button type="button" class="pav-btn" :class="{ 'pav-btn--active': commentsOpen }" @click="toggleComments">
					{{ commentCount > 0 ? t('merlin', 'Comments ({count})', { count: commentCount }) : t('merlin', 'Comments') }}
				</button>
			</div>
			<p v-if="allowComments" class="pav-comment-hint">
				{{ t('merlin', 'Select text to highlight it or comment on it.') }}
			</p>

			<audio v-if="audioVisible" ref="audioEl" class="pav-audio" controls :src="ttsUrl" />

			<header class="pav-header">
				<h1>{{ article.title }}</h1>
				<p v-if="article.excerpt" class="pav-excerpt">{{ article.excerpt }}</p>
				<div class="pav-meta">
					<span v-if="authorLinks">
						<template v-for="(a, i) in authorLinks" :key="i">
							<a v-if="a.url" :href="a.url" target="_blank" rel="noopener noreferrer">{{ a.name }}</a>
							<template v-else>{{ a.name }}</template>
							<template v-if="i < authorLinks.length - 1">, </template>
						</template>
					</span>
					<a v-else-if="article.author && safeAuthorUrl" :href="safeAuthorUrl" target="_blank" rel="noopener noreferrer">{{ article.author }}</a>
					<span v-else-if="article.author">{{ article.author }}</span>
					<a v-if="article.siteName && safeArticleUrl" :href="safeArticleUrl" target="_blank" rel="noopener noreferrer">{{ article.siteName }}</a>
					<span v-else-if="article.siteName">{{ article.siteName }}</span>
					<span v-if="article.readingTime">{{ t('merlin', '{minutes} min', { minutes: article.readingTime }) }}</span>
				</div>
			</header>

			<!-- Nur Medien mit stabiler Quelle im Marker (Datei/Embed) - ohne
				Login gibt es keinen /media-Endpunkt für Mediathek-Streams. -->
			<MediaPlayer
				:content="article.content || ''"
				:category="article.category || null"
				@state-change="mediaPlayable = $event.playable" />

			<!-- PDF-Artikel: Vorschau über den Durchreich-Endpunkt des Shares; die PDF liegt beim Quellserver. -->
			<PdfViewer v-if="article.category === 'PDF' && article.url"
				:src="pdfProxyUrl"
				:source-url="article.url" />

			<!-- eslint-disable-next-line vue/no-v-html -->
			<div ref="bodyEl" class="pav-body" :class="{ 'has-native-media': mediaPlayable }" v-html="bodyHtml" />

			<p v-if="footerByline" class="pav-footer-byline">– {{ footerByline }}</p>
		</article>

		<CommentPanel
			v-if="state === 'ready' && commentsOpen && commentSession"
			class="pav-comment-panel"
			:session="commentSession"
			:client="commentClient"
			mode="guest"
			:current-name="guestName"
			:can-write="allowComments"
			:sheet="isNarrow"
			:focused-highlight-id="commentFocus"
			:highlight-order="highlightOrder"
			:ensure-name="ensureName"
			@close="commentsOpen = false; commentFocus = null"
			@unfocus="commentFocus = null"
			@focus-highlight="focusHighlight"
			@change-name="askName()" />

		<!-- Namenswahl: Gäste weisen sich nur über ihren Namen aus. -->
		<div v-if="nameDialog" class="pav-name-backdrop" @click.self="closeNameDialog(false)">
			<form class="pav-name-dialog" role="dialog" aria-modal="true" @submit.prevent="closeNameDialog(true)">
				<h2>{{ t('merlin', 'What is your name?') }}</h2>
				<p>{{ t('merlin', 'Your name is shown with your highlights and comments and is visible to everyone with this link.') }}</p>
				<p class="pav-name-note">
					{{ t('merlin', 'Anyone who enters the same name can edit and delete what was written under it.') }}
				</p>
				<input ref="nameInput"
					v-model="nameDraft"
					class="pav-input pav-name-input"
					type="text"
					maxlength="50"
					autocomplete="nickname"
					:placeholder="t('merlin', 'Name')">
				<p v-if="nameError" class="pav-error">{{ nameError }}</p>
				<div class="pav-name-actions">
					<button type="button" class="pav-btn" @click="closeNameDialog(false)">
						{{ t('merlin', 'Cancel') }}
					</button>
					<button type="submit" class="pav-btn pav-btn--primary" :disabled="nameDraft.trim().length < 2">
						{{ t('merlin', 'Continue') }}
					</button>
				</div>
			</form>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { markRaw } from 'vue'
import { HighlightEngine } from '../highlight-engine'
import { guestCommentsClient } from '../api/comments'
import { createCommentSession, guestNameKey, loadGuestName, saveGuestName } from '../comment-session'
import CommentPanel from './CommentPanel.vue'
import MediaPlayer from './MediaPlayer.vue'
import { hideBrokenSupportBoxIcons, insertSupportBox } from '../support-box'
import { mountInlineMedia, unmountInlineMedia } from '../inline-media'
import PdfViewer from './PdfViewer.vue'

export default {
	name: 'PublicArticleView',

	components: { CommentPanel, MediaPlayer, PdfViewer },

	data() {
		return {
			token: loadState('merlin', 'shareToken', ''),
			state: 'loading',
			article: null,
			password: '',
			unlocking: false,
			unlockError: '',
			audioVisible: false,
			mediaPlayable: false,
			// Markieren und Kommentieren als Gast
			guestName: loadGuestName(),
			commentSession: null,
			commentClient: null,
			commentsOpen: false,
			commentFocus: null,
			highlightOrder: [],
			nameDialog: false,
			nameDraft: '',
			nameError: '',
			isNarrow: typeof window !== 'undefined' && window.innerWidth <= 768,
		}
	},

	computed: {
		allowComments() {
			return this.article?.allowComments !== false
		},

		commentCount() {
			const threads = this.commentSession?.state.threads || []
			return threads.reduce((sum, thread) => sum + (thread.deleted ? 0 : 1)
				+ thread.replies.filter(r => !r.deleted).length, 0)
		},

		// "Autor, Medium" am Artikelende (z.B. "Max Muster, taz.de").
		footerByline() {
			let host = ''
			try {
				host = new URL(this.article.url).hostname.replace(/^www\./, '')
			} catch {
				// keine gültige URL – kein Host-Fallback
			}
			return [this.article.author, this.article.siteName || host]
				.map((v) => (v || '').trim())
				.filter(Boolean)
				.join(', ')
		},

		// Artikeltext inkl. Support-Infobox (Abo-/Spendenlink der Quelle). Seed ist
		// der Titel, weil die Share-Antwort keine Artikel-ID enthält.
		bodyHtml() {
			const content = this.article?.content || ''
			return insertSupportBox(content, this.article?.supportBox, this.article?.title || '', this.t)
		},

		/** Durchreich-Endpunkt für die PDF eines PDF-Artikels (siehe PdfProxyService). */
		pdfProxyUrl() {
			return generateUrl(`/apps/merlin/s/${this.token}/pdf`)
		},

		ttsUrl() {
			return generateUrl(`/apps/merlin/s/${this.token}/tts`)
		},

		// Nur http(s)/relative/Anker-URLs im href zulassen. article.url wird vom
		// Share-Owner kontrolliert; ein javascript:-Schema wuerde sonst beim Klick
		// eines Share-Besuchers ausgefuehrt (Vue sanitisiert v-bind:href NICHT).
		// Autoren mit eigenem Profil-Link (Co-Autoren einzeln), nur absolute
		// http(s)-URLs. null, wenn keiner einen Link hat - dann greift die
		// einfache Darstellung über author/authorUrl.
		authorLinks() {
			const list = Array.isArray(this.article?.authors) ? this.article.authors : []
			const entries = list
				.filter(a => a && typeof a.name === 'string' && a.name !== '')
				.map(a => ({
					name: a.name,
					url: typeof a.url === 'string' && /^https?:\/\//i.test(a.url.trim()) ? a.url.trim() : null,
				}))
			return entries.length > 1 && entries.some(a => a.url) ? entries : null
		},

		// Link zum Autorenprofil der Quelle, nur absolute http(s)-URLs.
		safeAuthorUrl() {
			const url = this.article?.authorUrl
			return typeof url === 'string' && /^https?:\/\//i.test(url.trim()) ? url.trim() : null
		},

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
		this._onResize = () => { this.isNarrow = window.innerWidth <= 768 }
		window.addEventListener('resize', this._onResize)
	},

	updated() {
		this._mountInlineMedia()
	},

	beforeUnmount() {
		unmountInlineMedia(this._inlinePlayers ??= new Set())
		window.removeEventListener('resize', this._onResize)
		this._engine?.destroy()
		this.commentSession?.stop()
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
						this._initDiscussion()
						this._executeEmbedScripts()
						hideBrokenSupportBoxIcons(this.$refs.bodyEl)
						this._mountInlineMedia()
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

		// Player auf die Vorschaubilder von Videos mitten im Text legen, siehe
		// inline-media.js.
		_mountInlineMedia() {
			mountInlineMedia(this.$refs.bodyEl, this.$.appContext, this._inlinePlayers ??= new Set())
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

		// ── Markieren und Kommentieren ───────────────────────────────────────

		_initDiscussion() {
			this._engine?.destroy()
			this.commentSession?.stop()

			const bodyEl = this.$refs.bodyEl
			const client = guestCommentsClient(this.token, () => this.guestName)
			const engine = new HighlightEngine(bodyEl, {
				canCreate: () => this.allowComments,
				onCreate: async ({ highlightedText, startXpath, startOffset, endXpath, endOffset, color, tempId, comment }) => {
					if (!(await this.ensureName())) {
						engine.removeHighlight(tempId)
						return
					}
					try {
						const saved = await client.createHighlight({
							highlightedText, startXpath, startOffset, endXpath, endOffset, color,
						})
						engine.updateTempId(tempId, saved.id, saved)
						this._updateHighlightOrder()
						if (comment) this.openComments(saved.id)
					} catch (error) {
						engine.removeHighlight(tempId)
						console.error('Failed to save highlight:', error)
					}
				},
				onDelete: async (highlightId) => {
					try {
						await client.removeHighlight(highlightId)
						await this.commentSession?.refresh()
					} catch (error) {
						console.error('Failed to delete highlight:', error)
						await this.commentSession?.refresh()
					}
				},
				onOpenComments: (highlightId) => this.openComments(highlightId),
				canDelete: (highlightId) => {
					const h = this._highlightById(highlightId)
					return this.allowComments && !!h && h.authorType === 'guest'
						&& this.guestName !== '' && guestNameKey(h.authorName) === guestNameKey(this.guestName)
				},
				describe: (highlightId) => {
					const h = this._highlightById(highlightId)
					if (!h) return null
					const name = h.authorType === 'guest' ? h.authorName : this.article?.ownerName
					return name ? this.t('merlin', 'Highlighted by {name}', { name }, undefined, { escape: false }) : null
				},
				t: (text) => this.t('merlin', text),
			})
			this._engine = engine

			const session = createCommentSession(client, {
				onUpdate: (state) => {
					if (this._engine !== engine) return
					engine.setHighlights(state.highlights)
					engine.setCommentCounts(session.countsByHighlight())
					this._updateHighlightOrder()
				},
			})
			this.commentClient = markRaw(client)
			this.commentSession = markRaw(session)
			// Erster Stand kommt mit data(), danach nur noch Push-Ereignisse.
			session.apply({
				comments: this.article.comments || [],
				highlights: this.article.highlights || [],
				signature: this.article.signature || '',
			})
			session.connect()
		},

		_highlightById(id) {
			return this.commentSession?.state.highlights.find(h => h.id === id) || null
		},

		_updateHighlightOrder() {
			const ids = []
			this.$refs.bodyEl?.querySelectorAll('mark.merlin-highlight').forEach(el => {
				const id = parseInt(el.dataset.highlightId, 10)
				if (!ids.includes(id)) ids.push(id)
			})
			this.highlightOrder = ids
		},

		openComments(highlightId = null) {
			this.commentFocus = highlightId
			this.commentsOpen = true
		},

		toggleComments() {
			if (this.commentsOpen) {
				this.commentsOpen = false
				this.commentFocus = null
			} else {
				this.openComments(null)
			}
		},

		focusHighlight(highlightId) {
			this._engine?.focusHighlight(highlightId)
			this.commentFocus = highlightId
		},

		/** Sorgt für einen Gast-Namen; fragt beim ersten Schreiben danach. */
		ensureName() {
			if (this.guestName) return Promise.resolve(true)
			return this.askName()
		},

		askName() {
			this.nameDraft = this.guestName
			this.nameError = ''
			this.nameDialog = true
			this.$nextTick(() => this.$refs.nameInput?.focus())
			return new Promise(resolve => { this._nameResolve = resolve })
		},

		closeNameDialog(confirmed) {
			if (confirmed) {
				const name = this.nameDraft.replace(/\s+/g, ' ').trim()
				if (name.length < 2) {
					this.nameError = this.t('merlin', 'Please enter a name with at least 2 characters.')
					return
				}
				if (this.article?.ownerName && guestNameKey(name) === guestNameKey(this.article.ownerName)) {
					this.nameError = this.t('merlin', 'This name is reserved. Please choose another one.')
					return
				}
				this.guestName = name
				saveGuestName(name)
			}
			this.nameDialog = false
			this._nameResolve?.(confirmed && !!this.guestName)
			this._nameResolve = null
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

.pav-btn--active {
	border-color: var(--color-primary-element, #0082c9);
}

.pav-comment-hint {
	margin: -12px 0 20px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #666);
}

/* Die öffentliche Seite hat keine Nextcloud-Kopfleiste. */
.pav-comment-panel:not(.comment-panel--sheet) {
	top: 0;
}

.pav-name-backdrop {
	position: fixed;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	background: rgba(0, 0, 0, 0.4);
	z-index: 3000;
}

.pav-name-dialog {
	width: min(420px, 100%);
	padding: 20px 22px;
	border-radius: 12px;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
}

.pav-name-dialog h2 {
	margin: 0 0 8px;
	font-size: 1.2em;
}

.pav-name-dialog p {
	margin: 0 0 10px;
	font-size: 0.9em;
}

.pav-name-note {
	color: var(--color-text-maxcontrast, #666);
}

.pav-name-input {
	width: 100%;
	box-sizing: border-box;
}

.pav-name-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 14px;
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

.pav-footer-byline {
	margin: 40px 0 0;
	font-size: 0.9em;
	font-style: italic;
	color: var(--color-text-maxcontrast);
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

/* Tabellen aus dem Artikel-HTML bringen oft feste Spaltenbreiten mit und
   sprengen sonst die Artikelspalte statt zu umbrechen; block+auto-Scroll
   hält sie innerhalb der Spaltenbreite. */
.pav-body :deep(table) {
	display: block;
	max-width: 100%;
	width: max-content;
	overflow-x: auto;
	margin: 2em 0;
	border-collapse: collapse;
}

.pav-body :deep(th),
.pav-body :deep(td) {
	border: 1px solid var(--color-border);
	padding: 0.5em 0.75em;
}

/* Medien-Marker samt Fallback-Link ausblenden, sobald der MediaPlayer
   darüber die Quelle abspielt (siehe ArticleReader.vue). */
.pav-body.has-native-media :deep(.merlin-media),
.pav-body.has-native-media :deep(.merlin-video-fallback-link) {
	display: none;
}

/* PDF-Marker + Fallback-Link: die PdfCard übernimmt die Darstellung. */
.pav-body :deep(.merlin-pdf) {
	display: none;
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

/* Instagram-/X-/Bluesky-/TikTok-Embeds (siehe isAllowedWidgetScriptSrc())
   rendern sich nach dem Laden des Widget-Skripts selbst neu und bringen ihr
   eigenes Kartendesign mit. */
.pav-body :deep(blockquote.instagram-media),
.pav-body :deep(blockquote.twitter-tweet),
.pav-body :deep(blockquote.bluesky-embed),
.pav-body :deep(blockquote.tiktok-embed) {
	max-width: 100%;
	overflow: hidden;
	margin: 2em auto;
}

/* Mastodon-Post-Karte (siehe MastodonPostResolverService/
   buildMastodonThreadHtml()): kein Drittanbieter-Widget wie Instagram/X/
   Bluesky/TikTok (föderiert, kein zentraler Embed-Host), sondern eigenes,
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
