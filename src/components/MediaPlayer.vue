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
	<figure v-if="playable" class="media-player" :class="['media-player--' + kind, { 'media-player--hero': hero }]">
		<iframe
			v-if="delivery === 'embed'"
			:src="currentVariant.url"
			frameborder="0"
			allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
			allowfullscreen
			referrerpolicy="strict-origin-when-cross-origin" />

		<!-- Audio und Video (Datei/HLS): eigene Steuerung statt der Browser-
			Controls, für beide dieselbe. Audio mit Cover (Hero-Bild) zeigt das
			Bild, Video den Film; die Steuerung liegt als Overlay am unteren Rand
			(bei Audio ohne Cover als kompakte Leiste). -->
		<div
			v-else
			ref="stage"
			class="media-stage"
			:style="stageStyle"
			:class="{
				'media-stage--audio': kind === 'audio',
				'media-stage--cover': kind === 'audio' && !!posterUrl,
				'media-stage--paused': paused,
				'media-stage--fullscreen': fullscreen,
			}">
			<img v-if="kind === 'audio' && posterUrl" class="media-stage-cover" :src="posterUrl" alt="">
			<audio
				v-if="kind === 'audio'"
				ref="mediaEl"
				preload="metadata"
				@error="handlePlaybackError"
				@loadedmetadata="syncTime"
				@durationchange="syncTime"
				@timeupdate="syncTime"
				@volumechange="syncVolume"
				@play="paused = false"
				@pause="paused = true"
				@ended="paused = true" />
			<video
				v-else
				ref="mediaEl"
				class="media-stage-video"
				:poster="posterUrl"
				playsinline
				preload="metadata"
				@click="togglePlay"
				@error="handlePlaybackError"
				@loadedmetadata="syncTime"
				@durationchange="syncTime"
				@timeupdate="syncTime"
				@volumechange="syncVolume"
				@play="paused = false"
				@pause="paused = true"
				@ended="paused = true" />

			<!-- Großer Play-Knopf über dem Bild, solange pausiert. -->
			<button
				v-if="kind === 'video' && paused"
				type="button"
				class="media-stage-bigplay"
				:title="t('merlin', 'Play')"
				:aria-label="t('merlin', 'Play')"
				@click="togglePlay">
				<svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true">
					<path fill="currentColor" d="M8 5v14l11-7z" />
				</svg>
			</button>

			<div class="media-controls">
				<input
					class="media-controls-scrubber"
					type="range"
					min="0"
					:max="duration || 0"
					step="1"
					:value="currentTime"
					:disabled="!duration"
					:aria-label="t('merlin', 'Position')"
					@input="seekTo(Number($event.target.value))">
				<div class="media-controls-times">
					<span>{{ formatTime(currentTime) }}</span>
					<span v-if="duration">-{{ formatTime(duration - currentTime) }}</span>
				</div>
				<div class="media-controls-buttons">
					<button type="button" :title="t('merlin', 'Back 15 seconds')" :aria-label="t('merlin', 'Back 15 seconds')" @click="skip(-15)">−15</button>
					<button
						type="button"
						class="media-controls-play"
						:title="paused ? t('merlin', 'Play') : t('merlin', 'Pause')"
						:aria-label="paused ? t('merlin', 'Play') : t('merlin', 'Pause')"
						@click="togglePlay">
						<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true">
							<path v-if="paused" fill="currentColor" d="M8 5v14l11-7z" />
							<path v-else fill="currentColor" d="M6 5h4v14H6zm8 0h4v14h-4z" />
						</svg>
					</button>
					<button type="button" :title="t('merlin', 'Forward 30 seconds')" :aria-label="t('merlin', 'Forward 30 seconds')" @click="skip(30)">+30</button>
					<button type="button" :title="t('merlin', 'Playback speed')" :aria-label="t('merlin', 'Playback speed')" @click="cycleRate">{{ rate }}×</button>
					<template v-if="kind === 'video'">
						<button
							type="button"
							:title="muted ? t('merlin', 'Unmute') : t('merlin', 'Mute')"
							:aria-label="muted ? t('merlin', 'Unmute') : t('merlin', 'Mute')"
							@click="toggleMute">
							<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
								<path v-if="!muted" fill="currentColor" d="M3 9v6h4l5 5V4L7 9zm13.5 3A4.5 4.5 0 0 0 14 8v8a4.5 4.5 0 0 0 2.5-4z" />
								<path v-else fill="currentColor" d="M3 9v6h4l5 5V4L7 9zm13.6 3 2.7-2.7-1.4-1.4-2.7 2.7-2.7-2.7-1.4 1.4 2.7 2.7-2.7 2.7 1.4 1.4 2.7-2.7 2.7 2.7 1.4-1.4z" />
							</svg>
						</button>
						<button
							type="button"
							:title="fullscreen ? t('merlin', 'Exit fullscreen') : t('merlin', 'Fullscreen')"
							:aria-label="fullscreen ? t('merlin', 'Exit fullscreen') : t('merlin', 'Fullscreen')"
							@click="toggleFullscreen">
							<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
								<path v-if="!fullscreen" fill="currentColor" d="M5 5h5v2H7v3H5zm9 0h5v5h-2V7h-3zM5 14h2v3h3v2H5zm12 0h2v5h-5v-2h3z" />
								<path v-else fill="currentColor" d="M8 5h2v5H5V8h3zm6 0h2v3h3v2h-5zM5 14h5v5H8v-3H5zm9 0h5v2h-3v3h-2z" />
							</svg>
						</button>
					</template>
				</div>
			</div>
		</div>

		<!-- Nur bei mehr als einer Variante zeigen (z. B. Standard vs.
			Gebärdensprache/Audiodeskription bei ARD/ZDF) - sonst wäre die
			Auswahl bedeutungslos. -->
		<select
			v-if="variants.length > 1"
			class="media-player-variant"
			:aria-label="t('merlin', 'Version')"
			:value="selectedIndex"
			@change="selectVariant(Number($event.target.value))">
			<option v-for="(variant, index) in variants" :key="variant.url" :value="index">
				{{ variant.label }}
			</option>
		</select>

		<!-- Bildunterschrift des Hero-Bilds, das der Player ersetzt. -->
		<figcaption v-if="caption" class="media-player-caption">
			{{ caption }}
		</figcaption>
	</figure>
</template>

<script>
import { resolveMedia } from '../api/articles.js'

// Kategorien reiner Medienseiten (siehe ContentFilterSchema::MEDIA_CATEGORIES).
// Für sie wird auch ohne Marker beim Backend nachgefragt - Artikel, die vor
// Einführung der Marker gespeichert wurden, haben keinen.
const MEDIA_CATEGORIES = ['Video', 'Audio']
const KINDS = ['video', 'audio']
const DELIVERIES = ['hls', 'file', 'embed']
const RATES = [0.75, 1, 1.25, 1.5, 1.75, 2]

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
		// Player sitzt an der Stelle des Hero-Bilds (Reader): volle
		// Artikelbreite statt der 720px-Begrenzung.
		hero: {
			type: Boolean,
			default: false,
		},
		// Bildunterschrift des Hero-Bilds, das der Player ersetzt.
		caption: {
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
			// Zustand der eigenen Audio-Steuerung
			paused: true,
			currentTime: 0,
			duration: 0,
			rate: 1,
			muted: false,
			// Seitenverhältnis des Cover-Bilds (Breite / Höhe), 0 = unbekannt
			posterRatio: 0,
			fullscreen: false,
		}
	},

	computed: {
		currentVariant() {
			return this.variants[this.selectedIndex] || { url: '' }
		},

		// Video-Bühne hat das Seitenverhältnis des Cover-Bilds (sonst 16:9),
		// damit nie schwarze Balken entstehen; im Vollbild passt sie sich dem
		// Bildschirm an.
		stageStyle() {
			if (this.kind !== 'video' || this.fullscreen) return null
			return { aspectRatio: this.posterRatio > 0 ? String(this.posterRatio) : '16 / 9' }
		},
	},

	watch: {
		posterUrl: {
			immediate: true,
			handler(url) {
				this.posterRatio = 0
				if (!url) return
				const img = new Image()
				img.onload = () => {
					if (this.posterUrl === url && img.naturalWidth && img.naturalHeight) {
						this.posterRatio = img.naturalWidth / img.naturalHeight
					}
				}
				img.src = url
			},
		},

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
		document.addEventListener('fullscreenchange', this.syncFullscreen)
	},

	beforeUnmount() {
		document.removeEventListener('fullscreenchange', this.syncFullscreen)
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
			this.paused = true
			this.currentTime = 0
			this.duration = 0
			this.fullscreen = false
		},

		syncTime() {
			const media = this.$refs.mediaEl
			if (!media) return
			this.currentTime = media.currentTime || 0
			this.duration = Number.isFinite(media.duration) ? media.duration : 0
		},

		syncVolume() {
			const media = this.$refs.mediaEl
			if (media) this.muted = media.muted
		},

		toggleMute() {
			const media = this.$refs.mediaEl
			if (media) media.muted = !media.muted
		},

		syncFullscreen() {
			this.fullscreen = !!this.$refs.stage && document.fullscreenElement === this.$refs.stage
		},

		// Die Bühne (nicht das <video>) geht in den Vollbildmodus, damit die
		// eigene Steuerung erhalten bleibt. iOS-Safari kann das nur am Video.
		toggleFullscreen() {
			const stage = this.$refs.stage
			const media = this.$refs.mediaEl
			if (document.fullscreenElement) {
				document.exitFullscreen?.()
			} else if (stage?.requestFullscreen) {
				stage.requestFullscreen().catch(() => {})
			} else if (media?.webkitEnterFullscreen) {
				media.webkitEnterFullscreen()
			}
		},

		togglePlay() {
			const media = this.$refs.mediaEl
			if (!media) return
			if (media.paused) {
				media.play().catch(() => {})
			} else {
				media.pause()
			}
		},

		seekTo(seconds) {
			const media = this.$refs.mediaEl
			if (!media) return
			media.currentTime = seconds
			this.currentTime = seconds
		},

		skip(delta) {
			const media = this.$refs.mediaEl
			if (!media) return
			const max = this.duration || Infinity
			this.seekTo(Math.min(Math.max(media.currentTime + delta, 0), max))
		},

		cycleRate() {
			const media = this.$refs.mediaEl
			const index = RATES.indexOf(this.rate)
			this.rate = RATES[(index + 1) % RATES.length]
			if (media) {
				media.defaultPlaybackRate = this.rate
				media.playbackRate = this.rate
			}
		},

		formatTime(seconds) {
			const total = Math.max(0, Math.floor(seconds || 0))
			const h = Math.floor(total / 3600)
			const m = Math.floor((total % 3600) / 60)
			const sec = String(total % 60).padStart(2, '0')
			return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`
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

			// Gewähltes Tempo (Audio und Video) überlebt Varianten-Wechsel (src-Wechsel setzt es zurück).
			if (this.delivery !== 'embed') {
				media.defaultPlaybackRate = this.rate
				media.playbackRate = this.rate
			}

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
figure.media-player {
	max-width: 720px;
	margin: 0 auto 2em;
}

/* Hero-Position im Reader: volle Artikelbreite wie das Hero-Bild. */
figure.media-player.media-player--hero {
	max-width: none;
	margin: 0 0 2em;
}

.media-player iframe {
	display: block;
	width: 100%;
	max-width: 100%;
	aspect-ratio: 16 / 9;
	border: 0;
	border-radius: 4px;
	background: #000;
}

/* Bühne für Audio (Cover/Leiste) und Video. */
.media-stage {
	position: relative;
	border-radius: 4px;
	overflow: hidden;
	/* Nicht schwarz: bei Bruchteil-Pixeln (Seitenverhältnis des Covers) würde
	   sonst am Rand eine dunkle Linie durchscheinen. */
	background: var(--color-background-dark, #eee);
}

.media-stage--audio {
	background: var(--color-background-dark, #eee);
}

.media-stage-cover {
	display: block;
	width: 100%;
	height: auto;
}

/* Erhöhte Spezifität: der Reader stylt `.article-body video` (Außenabstand,
   height: auto, Radius) und würde sonst in die Bühne hineinwirken. */
.media-player .media-stage .media-stage-video {
	position: absolute;
	inset: 0;
	display: block;
	width: 100%;
	max-width: none;
	height: 100%;
	margin: 0;
	border-radius: 0;
	/* Füllt die Bühne (Seitenverhältnis des Covers) ohne Balken. */
	object-fit: cover;
	background: transparent;
	cursor: pointer;
}

.media-stage--fullscreen {
	background: #000;
	display: flex;
	align-items: center;
	border-radius: 0;
}

.media-stage--fullscreen .media-stage-video {
	object-fit: contain;
}

.media-stage-bigplay {
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	display: flex;
	align-items: center;
	justify-content: center;
	width: 72px;
	height: 72px;
	padding: 0;
	border: 0;
	border-radius: 50%;
	color: #fff;
	background: rgba(0, 0, 0, 0.55);
	backdrop-filter: blur(4px);
	cursor: pointer;
	transition: transform 0.15s, background 0.15s;
}

.media-stage-bigplay:hover,
.media-stage-bigplay:focus-visible {
	background: var(--color-primary-element, rgba(0, 0, 0, 0.75));
	transform: translate(-50%, -50%) scale(1.08);
}

.media-controls {
	color: var(--color-main-text, #222);
	padding: 10px 14px 12px;
}

/* Video und Audio mit Cover: Steuerung als Overlay am unteren Rand. Beim
   Video nur sichtbar, solange pausiert oder bei Hover/Fokus - sonst
   verdeckt sie den Film; auf Touch-Geräten (kein Hover) bleibt sie zu sehen. */
.media-stage--cover .media-controls,
.media-stage:not(.media-stage--audio) .media-controls {
	position: absolute;
	left: 0;
	right: 0;
	bottom: 0;
	color: #fff;
	background: linear-gradient(to top, rgba(0, 0, 0, 0.85), rgba(0, 0, 0, 0));
	padding-top: 40px;
}

@media (hover: hover) {
	.media-stage:not(.media-stage--audio):not(.media-stage--paused) .media-controls {
		opacity: 0;
		transition: opacity 0.2s;
	}

	.media-stage:not(.media-stage--audio):hover .media-controls,
	.media-stage:not(.media-stage--audio):focus-within .media-controls {
		opacity: 1;
	}
}

.media-controls-scrubber {
	display: block;
	width: 100%;
	margin: 0;
	accent-color: var(--color-primary-element, #0082c9);
}

.media-controls-times {
	display: flex;
	justify-content: space-between;
	font-size: 0.8em;
	font-variant-numeric: tabular-nums;
	opacity: 0.85;
}

.media-controls-buttons {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: center;
	gap: 8px;
	margin-top: 4px;
}

.media-controls-buttons button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 44px;
	height: 44px;
	padding: 0 8px;
	border: 0;
	border-radius: 22px;
	background: transparent;
	color: inherit;
	font: inherit;
	font-weight: 600;
	cursor: pointer;
}

.media-controls-buttons button:hover,
.media-controls-buttons button:focus-visible {
	background: rgba(127, 127, 127, 0.3);
}

.media-controls-buttons .media-controls-play {
	width: 52px;
	height: 52px;
	border-radius: 50%;
	background: rgba(127, 127, 127, 0.3);
}

.media-player-caption {
	margin: 0.5em 0 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #666);
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
