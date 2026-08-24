<!--
	Native ARD-/ZDF-/Arte-Wiedergabe über deren interne Stream-APIs, siehe
	VideoStreamResolverService-Docblock (Backend) für den Hintergrund - das
	ist bewusst kein offizieller Embed-Weg wie bei isAllowedVideoEmbedSrc().

	Rendert NICHTS, solange/falls sich kein Stream auflösen lässt oder die
	Wiedergabe fehlschlägt: der Artikeltext darunter bleibt in jedem Fall wie
	gewohnt sichtbar, nie ein kaputter/leerer Player.
-->
<template>
	<div v-if="playable" class="video-player">
		<video ref="videoEl" controls playsinline @error="handlePlaybackError" />

		<!-- Nur bei mehr als einer Variante zeigen (z. B. Standard vs.
			Gebärdensprache/Audiodeskription bei ARD/ZDF) - sonst wäre die
			Auswahl bedeutungslos. -->
		<select
			v-if="variants.length > 1"
			class="video-player-variant"
			:value="selectedIndex"
			@change="selectVariant(Number($event.target.value))">
			<option v-for="(variant, index) in variants" :key="variant.url" :value="index">
				{{ variant.label }}
			</option>
		</select>
	</div>
</template>

<script>
import Hls from 'hls.js'
import { resolveVideoStream } from '../api/articles.js'

// Nur diese drei Hosts (siehe Backend-Resolver) - alles andere läuft
// weiterhin über den normalen Artikel-Body/die offiziellen Embeds.
const NATIVE_VIDEO_HOSTS = ['ardmediathek.de', 'zdf.de', 'arte.tv']

function hasNativeVideoHost(articleUrl) {
	let host
	try {
		host = new URL(articleUrl).hostname.toLowerCase()
	} catch {
		return false
	}
	return NATIVE_VIDEO_HOSTS.some(domain => host === domain || host.endsWith('.' + domain))
}

export default {
	name: 'VideoPlayer',

	props: {
		articleId: {
			type: Number,
			required: true,
		},
		articleUrl: {
			type: String,
			required: true,
		},
	},

	emits: ['playable-change'],

	data() {
		return {
			playable: false,
			variants: [],
			selectedIndex: 0,
		}
	},

	watch: {
		playable(val) {
			this.$emit('playable-change', val)
		},

		articleId: {
			immediate: true,
			handler() {
				this._teardown()
				this.playable = false
				this.variants = []
				this.selectedIndex = 0
				if (hasNativeVideoHost(this.articleUrl)) {
					this._resolveAndLoad()
				}
			},
		},
	},

	beforeUnmount() {
		this._teardown()
	},

	methods: {
		async _resolveAndLoad() {
			let data
			try {
				data = await resolveVideoStream(this.articleId)
			} catch {
				return
			}
			if (!data?.available || data.type !== 'hls' || !Array.isArray(data.variants) || data.variants.length === 0) {
				return
			}

			this.variants = data.variants
			this.selectedIndex = Number.isInteger(data.defaultIndex) && data.variants[data.defaultIndex]
				? data.defaultIndex
				: 0

			this.playable = true
			await this.$nextTick()
			this._attach(this.variants[this.selectedIndex].url)
		},

		selectVariant(index) {
			if (!this.variants[index] || index === this.selectedIndex) return
			this.selectedIndex = index

			// Abspielposition beim Varianten-Wechsel beibehalten (z. B. von
			// Gebärdensprache auf Normal mitten im Video umschalten), statt
			// wieder bei 0 zu beginnen.
			const video = this.$refs.videoEl
			const resumeAt = video?.currentTime ?? 0
			const wasPlaying = video && !video.paused

			this._teardown()
			this._attach(this.variants[index].url, { resumeAt, autoplay: wasPlaying })
		},

		_attach(streamUrl, { resumeAt = 0, autoplay = false } = {}) {
			const video = this.$refs.videoEl
			if (!video) return

			const seekAndPlay = () => {
				if (resumeAt > 0) video.currentTime = resumeAt
				if (autoplay) video.play().catch(() => {})
			}

			// Safari unterstützt HLS nativ über <video src>, alle anderen
			// gängigen Browser brauchen hls.js (MediaSource-basiert).
			if (video.canPlayType('application/vnd.apple.mpegurl')) {
				video.src = streamUrl
				if (resumeAt > 0 || autoplay) {
					video.addEventListener('loadedmetadata', seekAndPlay, { once: true })
				}
				return
			}

			if (!Hls.isSupported()) {
				this.playable = false
				return
			}

			const hls = new Hls()
			this._hls = hls
			hls.on(Hls.Events.ERROR, (event, data2) => {
				if (data2.fatal) {
					this.playable = false
					this._teardown()
				}
			})
			if (resumeAt > 0 || autoplay) {
				hls.on(Hls.Events.MANIFEST_PARSED, seekAndPlay)
			}
			hls.loadSource(streamUrl)
			hls.attachMedia(video)
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
   groß und verliert seine Zentrierung, sobald die reale Videobreite ins
   Spiel kommt. Echtes "groß ansehen" gibt es ohnehin über den nativen
   Vollbildmodus der <video>-Controls - hier reicht ein angenehm lesbares
   Inline-Format. margin: 0 auto zentriert unabhängig vom umgebenden
   Layout (auch wenn .article-body z. B. mal Flex/Grid würde). */
.video-player {
	max-width: 720px;
	margin: 0 auto 2em;
}

.video-player video {
	display: block;
	width: 100%;
	max-width: 100%;
	aspect-ratio: 16 / 9;
	object-fit: contain;
	border-radius: 4px;
	background: #000;
}

.video-player-variant {
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
