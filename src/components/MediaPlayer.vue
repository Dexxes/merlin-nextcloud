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

	Rendert NICHTS, solange/falls sich keine Quelle auflösen lässt oder die
	Wiedergabe fehlschlägt: der Artikeltext bleibt in jedem Fall sichtbar,
	inklusive des Fallback-Links im Marker, nie ein kaputter/leerer Player.
-->
<template>
	<div v-if="playable" class="media-player" :class="'media-player--' + kind">
		<iframe
			v-if="delivery === 'embed'"
			:src="currentVariant.url"
			frameborder="0"
			allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
			allowfullscreen
			referrerpolicy="strict-origin-when-cross-origin" />

		<audio
			v-else-if="kind === 'audio'"
			ref="mediaEl"
			controls
			preload="metadata"
			@error="handlePlaybackError" />

		<video
			v-else
			ref="mediaEl"
			:poster="posterUrl"
			controls
			playsinline
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
import { resolveMedia } from '../api/articles.js'

// Kategorien reiner Medienseiten (siehe ContentFilterSchema::MEDIA_CATEGORIES).
// Für sie wird auch ohne Marker beim Backend nachgefragt - Artikel, die vor
// Einführung der Marker gespeichert wurden, haben keinen.
const MEDIA_CATEGORIES = ['Video', 'Audio']
const KINDS = ['video', 'audio']
const DELIVERIES = ['hls', 'file', 'embed']

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
	},

	emits: ['state-change'],

	data() {
		return {
			playable: false,
			kind: 'video',
			delivery: null,
			variants: [],
			selectedIndex: 0,
		}
	},

	computed: {
		currentVariant() {
			return this.variants[this.selectedIndex] || { url: '' }
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

	beforeUnmount() {
		this._teardown()
	},

	methods: {
		_reset() {
			this._teardown()
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

			this.playable = true
			await this.$nextTick()
			if (this.delivery !== 'embed') {
				this._attach(this.currentVariant)
			}
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

		handlePlaybackError() {
			this.playable = false
			this._teardown()
		},

		_teardown() {
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
