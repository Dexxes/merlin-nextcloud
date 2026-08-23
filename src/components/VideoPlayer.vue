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

	data() {
		return {
			playable: false,
		}
	},

	watch: {
		articleId: {
			immediate: true,
			handler() {
				this._teardown()
				this.playable = false
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
			if (!data?.available || data.type !== 'hls' || typeof data.url !== 'string') {
				return
			}

			this.playable = true
			await this.$nextTick()
			this._attach(data.url)
		},

		_attach(streamUrl) {
			const video = this.$refs.videoEl
			if (!video) return

			// Safari unterstützt HLS nativ über <video src>, alle anderen
			// gängigen Browser brauchen hls.js (MediaSource-basiert).
			if (video.canPlayType('application/vnd.apple.mpegurl')) {
				video.src = streamUrl
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
.video-player {
	margin: 0 0 2em;
}

.video-player video {
	display: block;
	width: 100%;
	max-width: 100%;
	aspect-ratio: 16 / 9;
	border-radius: 4px;
	background: #000;
}
</style>
