<!--
	Generischer Audio-/Video-Player für Artikel mit Medium (Kategorien Video,
	Audio und Mixed). Welche Quelle abgespielt wird, entscheidet das Backend
	(content-filters/<domain>.xml, Abschnitt <media>, siehe
	Service/Media/MediaResolverService). Diese Komponente kennt keine Hosts:

	  1. Der beim Speichern in den Content geschriebene Marker
	     (<div class="merlin-media" data-media-kind data-media-delivery
	     data-media-src>) wird direkt abgespielt, wenn er eine Quelle trägt
	     (stabile Dateien, Embeds). Das funktioniert auch in der öffentlichen
	     Share-Ansicht ohne Login.
	  2. Sonst (Mediathek-Streams, deren URLs kurzlebig sind, oder Artikel aus
	     der Zeit vor den Markern) fragt sie GET /api/articles/{id}/media.

	Auslieferungsarten:
	  hls   - hls.js (bzw. natives HLS in Safari), mit Varianten-Auswahl
	  file  - natives <audio>/<video src>
	  embed - offizieller iframe-Player (z. B. YouTube über youtube-nocookie)

	Abspielposition (hls/file über das Medien-Element, YouTube-Embeds über die
	postMessage-Schnittstelle der IFrame-API; andere Embeds nicht): wird wie
	der Lesefortschritt
	lokal und per PUT /api/articles/{id}/media-position geräteübergreifend
	gespeichert und beim erneuten Öffnen wiederhergestellt (Last-Write-Wins
	über den Zeitstempel). Gesteuert über dieselben Einstellungen wie die
	Leseposition (saveProgress/resumeOnOpen); in der Share-Ansicht nie.

	Rendert NICHTS, solange/falls sich keine Quelle auflösen lässt oder die
	Wiedergabe fehlschlägt: der Artikeltext bleibt in jedem Fall sichtbar,
	inklusive des Fallback-Links im Marker, nie ein kaputter/leerer Player.
-->
<template>
	<div v-if="playable" class="media-player" :class="'media-player--' + kind">
		<iframe
			v-if="delivery === 'embed'"
			ref="embedEl"
			:src="embedSrc"
			frameborder="0"
			allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
			allowfullscreen
			referrerpolicy="strict-origin-when-cross-origin"
			@load="onEmbedLoad" />

		<audio
			v-else-if="kind === 'audio'"
			ref="mediaEl"
			controls
			preload="metadata"
			@play="onPlay"
			@timeupdate="onTimeUpdate"
			@pause="savePosition"
			@ended="savePosition"
			@error="handlePlaybackError" />

		<video
			v-else
			ref="mediaEl"
			:poster="posterUrl"
			controls
			playsinline
			@play="onPlay"
			@timeupdate="onTimeUpdate"
			@pause="savePosition"
			@ended="savePosition"
			@error="handlePlaybackError" />

		<!-- Nur bei mehr als einer Variante zeigen (z. B. Standard vs.
			Gebärdensprache/Audiodeskription bei ARD/ZDF) - sonst wäre die
			Auswahl bedeutungslos. -->
		<select
			v-if="variants.length > 1"
			class="media-player-variant"
			:value="selectedIndex"
			@change="selectVariant(Number($event.target.value))">
			<option v-for="(variant, index) in variants" :key="variant.url" :value="index">
				{{ variant.label }}
			</option>
		</select>
	</div>
</template>

<script>
import { resolveMedia, updateMediaPosition } from '../api/articles.js'

// Kategorien reiner Medienseiten (siehe ContentFilterSchema::MEDIA_CATEGORIES).
// Für sie wird auch ohne Marker beim Backend nachgefragt - Artikel, die vor
// Einführung der Marker gespeichert wurden, haben keinen.
const MEDIA_CATEGORIES = ['Video', 'Audio']
const KINDS = ['video', 'audio']
const DELIVERIES = ['hls', 'file', 'embed']

// Hosts, deren Embed die YouTube-IFrame-API (postMessage-Protokoll) spricht.
const YOUTUBE_EMBED_HOSTS = ['www.youtube-nocookie.com', 'www.youtube.com']

/**
 * @param {string} url Embed-URL
 * @return {?URL} geparste URL, wenn es ein YouTube-Embed ist
 */
function youtubeEmbedUrl(url) {
	try {
		const parsed = new URL(url)
		return parsed.protocol === 'https:' && YOUTUBE_EMBED_HOSTS.includes(parsed.host) ? parsed : null
	} catch {
		return null
	}
}

// Während der Wiedergabe höchstens so oft speichern (ms) - timeupdate feuert
// mehrmals pro Sekunde.
const POSITION_SAVE_INTERVAL = 5000

/**
 * Abspielposition, die für das Medium gespeichert werden soll, oder null,
 * wenn sich keine sinnvolle Position angeben lässt (Livestream ohne feste
 * Dauer, Metadaten noch nicht geladen). Kurz vor dem Ende zählt das Medium
 * als fertig abgespielt: dann 0, damit es beim nächsten Öffnen von vorn
 * beginnt statt in den letzten Sekunden.
 *
 * @param {{currentTime: number, duration: number, ended: boolean}} media
 *   Audio-/Video-Element oder zuletzt gemeldeter Zustand des YouTube-Players
 * @return {?number} Sekunden
 */
function playbackPosition(media) {
	const duration = media.duration
	if (!Number.isFinite(duration) || duration <= 0) return null
	const position = media.currentTime
	if (media.ended || duration - position <= Math.min(10, duration * 0.02)) return 0
	return Math.max(0, position)
}

/**
 * Liest den ersten Medien-Marker aus dem Artikel-HTML.
 *
 * @param {string} html Artikel-Content
 * @return {{kind: string, delivery: ?string, src: ?string}|null}
 */
export function parseMediaMarker(html) {
	if (!html || !html.includes('merlin-media')) return null
	const doc = new DOMParser().parseFromString(html, 'text/html')
	const el = doc.querySelector('div.merlin-media[data-media-kind]')
	if (!el) return null
	const kind = el.getAttribute('data-media-kind')
	if (!KINDS.includes(kind)) return null
	const delivery = el.getAttribute('data-media-delivery')
	const src = el.getAttribute('data-media-src')
	const hasSource = DELIVERIES.includes(delivery) && typeof src === 'string' && src.startsWith('https://')
	return { kind, delivery: hasSource ? delivery : null, src: hasSource ? src : null }
}

export default {
	name: 'MediaPlayer',

	props: {
		// null in der öffentlichen Share-Ansicht: dort gibt es keinen
		// authentifizierten /media-Endpunkt, nur Marker mit Quelle.
		articleId: {
			type: Number,
			default: null,
		},
		content: {
			type: String,
			default: '',
		},
		category: {
			type: String,
			default: null,
		},
		// Hero-Bild des Artikels (siehe ArticleReader.vue), dient bei Video
		// als Poster statt zusätzlich separat über dem Player angezeigt zu
		// werden - leer, wenn der Artikel keins hat.
		posterUrl: {
			type: String,
			default: '',
		},
		// Serverseitig gespeicherte Abspielposition (Sekunden) samt
		// Zeitstempel, siehe Article::jsonSerialize() (mediaPosition,
		// mediaPositionUpdatedAt).
		savedPosition: {
			type: Number,
			default: 0,
		},
		savedPositionUpdatedAt: {
			type: Number,
			default: 0,
		},
		// Abspielposition speichern (Einstellung saveProgress) bzw. beim
		// Öffnen wiederherstellen (Einstellung resumeOnOpen).
		rememberPosition: {
			type: Boolean,
			default: false,
		},
		resumePosition: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['state-change'],

	data() {
		return {
			playable: false,
			kind: 'video',
			delivery: null,
			variants: [],
			selectedIndex: 0,
			// Startsekunde für YouTube-Embeds (wiederhergestellte Position).
			embedStart: 0,
		}
	},

	computed: {
		currentVariant() {
			return this.variants[this.selectedIndex] || { url: '' }
		},

		// YouTube-Embeds bekommen die IFrame-API freigeschaltet (enablejsapi +
		// origin, sonst schickt der Player keine Nachrichten) und ggf. die
		// wiederhergestellte Position als Startzeit. Andere Embeds unverändert.
		embedSrc() {
			const url = youtubeEmbedUrl(this.currentVariant.url)
			if (!url) return this.currentVariant.url
			url.searchParams.set('enablejsapi', '1')
			url.searchParams.set('origin', window.location.origin)
			if (this.embedStart > 0) url.searchParams.set('start', String(this.embedStart))
			return url.toString()
		},
	},

	watch: {
		playable() {
			this._emitState()
		},

		articleId: {
			immediate: true,
			handler() {
				this._reset()
				this._load()
			},
		},
	},

	mounted() {
		// Tab/App in den Hintergrund (auf Mobilgeräten oft das letzte
		// zuverlässige Ereignis vor dem Beenden): aktuelle Position sichern.
		this._onVisibilityChange = () => {
			if (document.visibilityState === 'hidden') this.savePosition()
		}
		document.addEventListener('visibilitychange', this._onVisibilityChange)
		this._onWindowMessage = (event) => this.onEmbedMessage(event)
		window.addEventListener('message', this._onWindowMessage)
	},

	beforeUnmount() {
		document.removeEventListener('visibilitychange', this._onVisibilityChange)
		window.removeEventListener('message', this._onWindowMessage)
		this.savePosition()
		this._teardown()
	},

	methods: {
		_reset() {
			// Läuft beim Artikelwechsel noch vor dem Neu-Rendern: $refs.mediaEl
			// ist dann noch das Element des bisherigen Artikels.
			this.savePosition()
			this._positionArticleId = null
			this._hasPlayed = false
			this._lastSavedAt = 0
			this._lastSavedPosition = null
			this._teardown()
			this._embedState = null
			this.embedStart = 0
			this.playable = false
			this.kind = 'video'
			this.delivery = null
			this.variants = []
			this.selectedIndex = 0
		},

		_emitState() {
			this.$emit('state-change', { playable: this.playable, kind: this.playable ? this.kind : null })
		},

		async _load() {
			const loadFor = this.articleId
			const marker = parseMediaMarker(this.content)

			let data = null
			if (marker?.src) {
				data = {
					available: true,
					kind: marker.kind,
					delivery: marker.delivery,
					variants: [{ label: 'Standard', url: marker.src }],
					defaultIndex: 0,
				}
			} else if (this.articleId !== null && (marker || MEDIA_CATEGORIES.includes(this.category))) {
				try {
					data = await resolveMedia(this.articleId)
				} catch {
					return
				}
				// Inzwischen einen anderen Artikel geöffnet? Dann ist die Antwort veraltet.
				if (loadFor !== this.articleId) return
			}

			if (!data?.available
				|| !KINDS.includes(data.kind)
				|| !DELIVERIES.includes(data.delivery)
				|| !Array.isArray(data.variants)
				|| data.variants.length === 0) {
				return
			}

			this.kind = data.kind
			this.delivery = data.delivery
			this.variants = data.variants
			this.selectedIndex = Number.isInteger(data.defaultIndex) && data.variants[data.defaultIndex]
				? data.defaultIndex
				: 0

			if (this.delivery === 'embed') {
				if (youtubeEmbedUrl(this.currentVariant.url)) {
					this._positionArticleId = this.articleId
					// YouTube nimmt als Startzeit nur ganze Sekunden.
					this.embedStart = Math.floor(this._storedPosition())
				}
				this.playable = true
				return
			}

			this.playable = true
			await this.$nextTick()
			this._positionArticleId = this.articleId
			this._attach(this.currentVariant, { resumeAt: this._storedPosition() })
		},

		selectVariant(index) {
			if (!this.variants[index] || index === this.selectedIndex) return
			this.selectedIndex = index
			if (this.delivery === 'embed') return

			// Abspielposition beim Varianten-Wechsel beibehalten (z. B. von
			// Gebärdensprache auf Normal mitten im Video umschalten), statt
			// wieder bei 0 zu beginnen.
			const media = this.$refs.mediaEl
			const resumeAt = media?.currentTime ?? 0
			const wasPlaying = media && !media.paused

			this._teardown()
			this._attach(this.variants[index], { resumeAt, autoplay: wasPlaying })
		},

		async _attach(variant, { resumeAt = 0, autoplay = false } = {}) {
			const media = this.$refs.mediaEl
			if (!media) return

			const seekAndPlay = () => {
				if (resumeAt > 0) media.currentTime = resumeAt
				if (autoplay) media.play().catch(() => {})
			}

			// Direkte Datei oder natives HLS (Safari): einfach als src setzen.
			const nativeHls = this.delivery === 'hls' && media.canPlayType('application/vnd.apple.mpegurl')
			if (this.delivery === 'file' || nativeHls) {
				media.src = variant.url
				if (resumeAt > 0 || autoplay) {
					media.addEventListener('loadedmetadata', seekAndPlay, { once: true })
				}
				// Kein hls.js hier - die Untertitelspur direkt am Element
				// erzwingen, siehe Kommentar unten.
				this._enforceNativeSubtitleLanguage(media, variant.subtitleLanguage)
				return
			}

			// hls.js erst bei Bedarf laden: Dateien und Embeds (und damit die
			// öffentliche Share-Ansicht, die nur solche abspielt) brauchen es nie.
			const { default: Hls } = await import('hls.js')
			// Während des Nachladens Artikel/Variante gewechselt? Dann nicht mehr anhängen.
			if (this.$refs.mediaEl !== media || this.currentVariant !== variant) return

			if (!Hls.isSupported()) {
				this.playable = false
				return
			}

			const hls = new Hls()
			this._hls = hls
			hls.on(Hls.Events.ERROR, (event, data) => {
				if (data.fatal) {
					this.playable = false
					this._teardown()
				}
			})
			if (resumeAt > 0 || autoplay) {
				hls.on(Hls.Events.MANIFEST_PARSED, seekAndPlay)
			}
			// Jedes Arte-Versions-Manifest bettet trotzdem mehrere Untertitel-
			// Spuren ein statt nur die zur gewählten Version passende - hls.js
			// wählt sonst selbstständig eine davon (u. a. nach Systemsprache).
			// Über hls.js' eigene subtitleTrack-API statt direkt am Element
			// setzen, da hls.js' SubtitleTrackController eine direkte DOM-
			// Manipulation sonst wieder überschreiben würde. "und"/kein Wert
			// (siehe ArteProvider) bedeutet "keine Untertitel für diese
			// Version" - bei anderen Quellen fehlt das Feld (undefined) und
			// hier passiert bewusst nichts.
			if (variant.subtitleLanguage !== undefined) {
				hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, () => {
					const match = hls.subtitleTracks.findIndex(track => track.lang === variant.subtitleLanguage)
					hls.subtitleTrack = match
				})
			}
			hls.loadSource(variant.url)
			hls.attachMedia(media)
		},

		// Pendant zur hls.subtitleTrack-Steuerung oben, für den Zweig ohne
		// hls.js: hier gibt es keinen SubtitleTrackController, der direkte
		// Änderungen an textTracks überschreiben könnte, also reicht das
		// Setzen von .mode direkt.
		_enforceNativeSubtitleLanguage(media, subtitleLanguage) {
			if (subtitleLanguage === undefined || !media.textTracks) return
			const apply = () => {
				for (let i = 0; i < media.textTracks.length; i++) {
					const track = media.textTracks[i]
					if (track.kind !== 'subtitles' && track.kind !== 'captions') continue
					track.mode = subtitleLanguage && track.language === subtitleLanguage ? 'showing' : 'disabled'
				}
			}
			apply()
			media.textTracks.addEventListener('addtrack', apply)
		},

		// ── Abspielposition ─────────────────────────────────────────────

		// Last-Write-Wins zwischen lokal (localStorage) und Server gespeicherter
		// Position, wie ArticleReader._restoreScrollPosition().
		_storedPosition() {
			if (!this.resumePosition || this.articleId === null) return 0
			let localPos = 0
			let localTs = 0
			try {
				localPos = parseFloat(localStorage.getItem(`merlin_mpos_${this.articleId}`)) || 0
				localTs = parseInt(localStorage.getItem(`merlin_mposts_${this.articleId}`), 10) || 0
			} catch {}
			const position = this.savedPositionUpdatedAt > localTs ? this.savedPosition : localPos
			return Number.isFinite(position) && position > 0 ? position : 0
		},

		onPlay() {
			// Erst ab dem ersten Abspielen speichern: sonst überschriebe schon
			// das bloße Öffnen (currentTime 0 vor dem Laden der Metadaten) die
			// gespeicherte Position.
			this._hasPlayed = true
		},

		onTimeUpdate() {
			if (Date.now() - (this._lastSavedAt || 0) >= POSITION_SAVE_INTERVAL) {
				this.savePosition()
			}
		},

		// YouTube-IFrame-API ohne das iframe_api-Skript (CSP): nach dem Laden
		// "listening" senden, bis der Player antwortet - ab dann meldet er
		// Zustandsänderungen per infoDelivery (nur geänderte Felder).
		onEmbedLoad() {
			clearInterval(this._embedListenTimer)
			if (!youtubeEmbedUrl(this.currentVariant.url)) return
			this._embedState = { currentTime: this.embedStart, duration: NaN, ended: false }
			let attempts = 0
			const listen = () => {
				const frame = this.$refs.embedEl
				if (!frame?.contentWindow || this._embedConnected || ++attempts > 40) {
					clearInterval(this._embedListenTimer)
					return
				}
				frame.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), new URL(frame.src).origin)
			}
			this._embedConnected = false
			listen()
			this._embedListenTimer = setInterval(listen, 250)
		},

		onEmbedMessage(event) {
			const frame = this.$refs.embedEl
			if (!frame || event.source !== frame.contentWindow) return
			let data
			try {
				if (!YOUTUBE_EMBED_HOSTS.includes(new URL(event.origin).host)) return
				data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data
			} catch {
				return
			}
			if (!data || typeof data !== 'object' || !this._embedState) return
			this._embedConnected = true

			const info = data.info && typeof data.info === 'object' ? data.info : null
			let playerState = null
			if (info) {
				if (Number.isFinite(info.currentTime)) this._embedState.currentTime = info.currentTime
				if (Number.isFinite(info.duration)) this._embedState.duration = info.duration
				if (Number.isInteger(info.playerState)) playerState = info.playerState
			} else if (data.event === 'onStateChange' && Number.isInteger(data.info)) {
				playerState = data.info
			}

			// playerState: 0 beendet, 1 spielt, 2 pausiert (YT.PlayerState).
			if (playerState === null) {
				if (this._embedState.playing) this.onTimeUpdate()
				return
			}
			this._embedState.ended = playerState === 0
			this._embedState.playing = playerState === 1
			if (playerState === 1) {
				this.onPlay()
				this.onTimeUpdate()
			} else if (playerState === 0 || playerState === 2) {
				this.savePosition()
			}
		},

		savePosition() {
			const media = this.delivery === 'embed' ? this._embedState : this.$refs.mediaEl
			const articleId = this._positionArticleId
			if (!this.rememberPosition || !this._hasPlayed || !media || articleId == null) return

			const position = playbackPosition(media)
			if (position === null) return
			this._lastSavedAt = Date.now()
			if (this._lastSavedPosition !== null && Math.abs(position - this._lastSavedPosition) < 1) return
			this._lastSavedPosition = position

			const now = Date.now()
			try {
				localStorage.setItem(`merlin_mpos_${articleId}`, String(position))
				localStorage.setItem(`merlin_mposts_${articleId}`, String(now))
			} catch {}
			// Fire-and-forget: bei Server-Fehler bleibt der lokale Wert erhalten.
			updateMediaPosition(articleId, position, now).catch(() => {})
		},

		handlePlaybackError() {
			this.playable = false
			this._teardown()
		},

		_teardown() {
			clearInterval(this._embedListenTimer)
			if (this._hls) {
				this._hls.destroy()
				this._hls = null
			}
		},
	},
}
</script>

<style scoped>
/* Feste Obergrenze statt volle Spaltenbreite: ein 16:9-Video, das die ganze
   (teils sehr breite) Reader-Spalte ausfüllt, wirkt beim Abspielen unruhig
   groß. Echtes "groß ansehen" gibt es über den nativen Vollbildmodus bzw.
   den des Embeds. margin: 0 auto zentriert unabhängig vom umgebenden Layout. */
.media-player {
	max-width: 720px;
	margin: 0 auto 2em;
}

.media-player video,
.media-player iframe {
	display: block;
	width: 100%;
	max-width: 100%;
	aspect-ratio: 16 / 9;
	object-fit: contain;
	border: 0;
	border-radius: 4px;
	background: #000;
}

.media-player audio {
	display: block;
	width: 100%;
}

.media-player-variant {
	display: block;
	margin: 0.5em auto 0;
	padding: 4px 8px;
	font-size: 0.85em;
	border-radius: 4px;
	border: 1px solid var(--color-border, #ccc);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
}
</style>
