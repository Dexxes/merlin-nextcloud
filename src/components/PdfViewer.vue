<!--
  SPDX-License-Identifier: AGPL-3.0-or-later

  Eingebettete PDF-Vorschau für PDF-Artikel (category "PDF") mit pdf.js.

  Die PDF wird nicht gespeichert: `src` zeigt auf den Durchreich-Endpunkt des
  eigenen Servers (PdfProxyService, `/api/articles/{id}/pdf` bzw.
  `/s/{token}/pdf`), der sie pro Request vom Quellserver holt und Range-Requests
  unterstützt - pdf.js lädt so Seiten nach, statt die ganze Datei abzuwarten.
  Direkt vom Quellserver ginge das meist nicht (CORS, X-Frame-Options).

  Gerendert wird seitenweise als Canvas; nur Seiten in der Nähe des
  Sichtbereichs (IntersectionObserver), damit lange Dokumente schnell öffnen.
  Über jedem Canvas liegt der Text-Layer von pdf.js (unsichtbarer Text zum
  Auswählen) und darunter eine Ebene für Markierungen
  (.merlin-pdf-hl-layer). Die zeichnet die HighlightEngine: sobald die Ebene
  einer Seite steht, meldet die Seite das mit dem Ereignis `merlin-pdf-page`.
  Beide Ebenen sind in der unrotierten Seitengröße angelegt und folgen der
  Breite über `--scale-factor` (wie im pdf.js-Viewer). Scheitert das Laden,
  erscheint statt der Vorschau die PdfCard mit "PDF öffnen".
-->
<template>
	<div class="pdf-viewer">
		<PdfCard v-if="state === 'error'" :url="sourceUrl" />

		<template v-else>
			<div class="pdf-viewer__bar">
				<span class="pdf-viewer__title">
					{{ t('merlin', 'PDF document') }}
					<span v-if="pages.length" class="pdf-viewer__count">
						· {{ n('merlin', '%n page', '%n pages', pages.length) }}
					</span>
				</span>
				<NcButton type="primary"
					:href="sourceUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('merlin', 'Open PDF') }}
				</NcButton>
			</div>

			<div ref="container" class="pdf-viewer__pages">
				<p v-if="state === 'loading'" class="pdf-viewer__status">
					{{ t('merlin', 'Loading PDF…') }}
				</p>
				<div v-for="page in pages"
					:key="page.number"
					:ref="setPageRef"
					class="pdf-viewer__page"
					:data-page="page.number"
					:style="{ height: page.height + 'px' }">
					<div class="pdf-viewer__sheet">
						<canvas />
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import PdfCard from './PdfCard.vue'

/** Höchste Pixeldichte, mit der Seiten gerendert werden (Speicher/Zeit gegen Schärfe). */
const MAX_PIXEL_RATIO = 2
/** Obergrenze für die Seitenbreite in CSS-Pixeln - breitere Seiten würden nur unnötig Pixel kosten. */
const MAX_PAGE_WIDTH = 900

export default {
	name: 'PdfViewer',

	components: { NcButton, PdfCard },

	props: {
		/** URL des Durchreich-Endpunkts auf dem eigenen Server. */
		src: { type: String, required: true },
		/** Quell-URL der PDF, für "PDF öffnen" und den Fehlerfall. */
		sourceUrl: { type: String, required: true },
	},

	data() {
		return {
			state: 'loading', // loading | ready | error
			pages: [], // [{ number, height }] - Höhe als Platzhalter, bis die Seite gerendert ist
		}
	},

	watch: {
		src() {
			this.load()
		},
	},

	mounted() {
		this.pageEls = new Map()
		this.rendered = new Map() // Seitennummer -> gerenderte CSS-Breite
		this.textLayers = new Map() // Seitennummer -> pdf.js TextLayer
		this.onSelectEnd = () => {
			this.$refs.container?.querySelectorAll('.textLayer.selecting')
				.forEach((el) => el.classList.remove('selecting'))
		}
		document.addEventListener('mouseup', this.onSelectEnd)
		this.load()
	},

	beforeUnmount() {
		document.removeEventListener('mouseup', this.onSelectEnd)
		this.teardown()
	},

	methods: {
		setPageRef(el) {
			// Vue ruft die Funktion mit null auf, wenn ein Element entfernt wird.
			if (el && this.observer) {
				this.observer.observe(el)
			}
			if (el) {
				this.pageEls.set(Number(el.dataset.page), el)
			}
		},

		teardown() {
			this.observer?.disconnect()
			this.resizeObserver?.disconnect()
			clearTimeout(this.resizeTimer)
			this.renderTasks?.forEach((task) => task.cancel())
			this.renderTasks = new Map()
			this.textLayers?.forEach((layer) => layer.cancel())
			this.textLayers = new Map()
			this.loadingTask?.destroy()
			this.loadingTask = null
			this.pdf = null
			this.pageEls?.clear()
			this.rendered?.clear()
		},

		async load() {
			this.teardown()
			this.state = 'loading'
			this.pages = []
			try {
				// pdf.js erst bei Bedarf laden (wie hls.js im MediaPlayer). Legacy-Build:
				// läuft auch auf älteren Browsern, die Nextcloud noch unterstützt.
				const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs')
				this.pdfjs = pdfjs
				if (!pdfjs.GlobalWorkerOptions.workerPort) {
					// `?worker` lässt Vite den Worker als eigene Datei vom App-Origin ausliefern
					// (passt zu `worker-src 'self'`); ein `?url`-Import würde hier als Modul gebündelt.
					const { default: PdfWorker } = await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?worker')
					pdfjs.GlobalWorkerOptions.workerPort = new PdfWorker()
				}

				// isEvalSupported aus: PDF-Funktionen werden nicht per eval() ausgewertet.
				this.loadingTask = pdfjs.getDocument({ url: this.src, isEvalSupported: false })
				const pdf = await this.loadingTask.promise
				this.pdf = pdf

				// Alle Seiten bekommen erst die Höhe der ersten als Platzhalter; die echte
				// Höhe setzt das Rendern (Dokumente mit gemischten Formaten springen leicht).
				const first = await pdf.getPage(1)
				const width = this.pageWidth()
				const viewport = first.getViewport({ scale: 1 })
				const height = Math.round((width / viewport.width) * viewport.height)
				this.pages = Array.from({ length: pdf.numPages }, (_, i) => ({ number: i + 1, height }))
				this.state = 'ready'

				await this.$nextTick()
				this.setupObservers()
			} catch (e) {
				// Zerstörte Ladeaufgabe (Komponente weg, src gewechselt) ist kein Fehler.
				if (this.loadingTask === null) {
					return
				}
				console.error('Merlin: PDF konnte nicht geladen werden', e)
				this.state = 'error'
			}
		},

		pageWidth() {
			const available = this.$refs.container?.clientWidth || MAX_PAGE_WIDTH
			return Math.max(200, Math.min(available, MAX_PAGE_WIDTH))
		},

		setupObservers() {
			this.observer = new IntersectionObserver((entries) => {
				for (const entry of entries) {
					if (entry.isIntersecting) {
						this.renderPage(Number(entry.target.dataset.page))
					}
				}
			}, { rootMargin: '800px 0px' })
			this.pageEls.forEach((el) => this.observer.observe(el))

			// Bei geänderter Breite (Fenster, Sidebar) alle Seiten neu rendern.
			this.resizeObserver = new ResizeObserver(() => {
				clearTimeout(this.resizeTimer)
				this.resizeTimer = setTimeout(() => this.onResize(), 200)
			})
			this.resizeObserver.observe(this.$refs.container)
		},

		onResize() {
			const width = this.pageWidth()
			const stale = [...this.rendered.entries()].filter(([, w]) => Math.abs(w - width) > 1)
			stale.forEach(([number]) => {
				this.rendered.delete(number)
				this.renderTasks.get(number)?.cancel()
			})
			// Sichtbare Seiten sofort neu; die übrigen kommen über den IntersectionObserver.
			this.pageEls.forEach((el, number) => {
				const rect = el.getBoundingClientRect()
				if (rect.bottom > -800 && rect.top < window.innerHeight + 800) {
					this.renderPage(number)
				}
			})
		},

		async renderPage(number) {
			const width = this.pageWidth()
			if (!this.pdf || this.rendered.get(number) === width || this.renderTasks.has(number)) {
				return
			}
			const el = this.pageEls.get(number)
			const canvas = el?.querySelector('canvas')
			if (!canvas) {
				return
			}

			try {
				const page = await this.pdf.getPage(number)
				const base = page.getViewport({ scale: 1 })
				const cssScale = width / base.width
				const ratio = Math.min(window.devicePixelRatio || 1, MAX_PIXEL_RATIO)
				const viewport = page.getViewport({ scale: cssScale * ratio })

				canvas.width = Math.floor(viewport.width)
				canvas.height = Math.floor(viewport.height)
				canvas.style.width = Math.floor(viewport.width / ratio) + 'px'
				canvas.style.height = Math.floor(viewport.height / ratio) + 'px'

				const task = page.render({ canvasContext: canvas.getContext('2d'), viewport })
				this.renderTasks.set(number, task)
				await task.promise

				this.rendered.set(number, width)
				// Echte Höhe übernehmen (Platzhalter stammte von Seite 1).
				const entry = this.pages[number - 1]
				if (entry) {
					entry.height = Math.round(viewport.height / ratio)
				}
				await this.renderLayers(number, page, canvas.parentElement, page.getViewport({ scale: cssScale }))
			} catch (e) {
				// Abgebrochenes Rendern (Resize/Unmount) ist normal.
				if (e?.name !== 'RenderingCancelledException') {
					console.error('Merlin: PDF-Seite ' + number + ' konnte nicht gerendert werden', e)
				}
			} finally {
				this.renderTasks?.delete(number)
			}
		},

		/**
		 * Text-Layer und Markierungs-Ebene einer Seite anlegen bzw. an eine neue
		 * Breite anpassen. Beim ersten Mal meldet die Seite `merlin-pdf-page`,
		 * damit die HighlightEngine ihre Markierungen hineinzeichnet.
		 */
		async renderLayers(number, page, sheet, viewport) {
			sheet.style.setProperty('--scale-factor', String(viewport.scale))
			const existing = this.textLayers.get(number)
			if (existing) {
				existing.update({ viewport })
				return
			}
			const hlLayer = document.createElement('div')
			hlLayer.className = 'merlin-pdf-hl-layer'
			this.pdfjs.setLayerDimensions(hlLayer, viewport)
			sheet.appendChild(hlLayer)

			const textDiv = document.createElement('div')
			textDiv.className = 'textLayer'
			sheet.appendChild(textDiv)
			const textLayer = new this.pdfjs.TextLayer({
				textContentSource: page.streamTextContent(),
				container: textDiv,
				viewport,
			})
			this.textLayers.set(number, textLayer)
			try {
				await textLayer.render()
			} catch (e) {
				if (e?.name !== 'AbortException') {
					console.error('Merlin: Text der PDF-Seite ' + number + ' nicht lesbar', e)
				}
			}
			// Ohne dieses Element springt die Auswahl beim Ziehen über leere
			// Stellen an das Seitenende (wie TextLayerBuilder im pdf.js-Viewer).
			const end = document.createElement('div')
			end.className = 'endOfContent'
			textDiv.appendChild(end)
			textDiv.addEventListener('mousedown', () => textDiv.classList.add('selecting'))

			sheet.dispatchEvent(new CustomEvent('merlin-pdf-page', { bubbles: true, detail: { page: number } }))
		},
	},
}
</script>

<style scoped>
.pdf-viewer {
	margin: 24px 0;
}

.pdf-viewer__bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 4px;
}

.pdf-viewer__title {
	font-weight: 600;
}

.pdf-viewer__count {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.pdf-viewer__pages {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-dark);
}

.pdf-viewer__status {
	margin: 24px 0;
	color: var(--color-text-maxcontrast);
}

.pdf-viewer__page {
	display: flex;
	align-items: flex-start;
	justify-content: center;
	width: 100%;
	max-width: 900px;
	background: #fff;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
}

.pdf-viewer__sheet {
	position: relative;
}

.pdf-viewer__page canvas {
	display: block;
}

/* Text-Layer von pdf.js (gekürzt aus pdfjs-dist/web/pdf_viewer.css): Text
   unsichtbar über dem Canvas, nur zum Auswählen. */
.pdf-viewer__sheet :deep(.textLayer) {
	position: absolute;
	inset: 0;
	overflow: clip;
	line-height: 1;
	text-align: initial;
	text-size-adjust: none;
	forced-color-adjust: none;
	transform-origin: 0 0;
	z-index: 2;
}

.pdf-viewer__sheet :deep(.textLayer span),
.pdf-viewer__sheet :deep(.textLayer br) {
	color: transparent;
	position: absolute;
	white-space: pre;
	cursor: text;
	transform-origin: 0% 0%;
}

/* Über .endOfContent, die beim Auswählen die ganze Ebene bedeckt. */
.pdf-viewer__sheet :deep(.textLayer > :not(.markedContent)),
.pdf-viewer__sheet :deep(.textLayer .markedContent span:not(.markedContent)) {
	z-index: 1;
}

.pdf-viewer__sheet :deep(.textLayer span.markedContent) {
	top: 0;
	height: 0;
}

.pdf-viewer__sheet :deep(.textLayer ::selection) {
	background: rgba(0, 100, 255, 0.25);
}

.pdf-viewer__sheet :deep(.textLayer br::selection) {
	background: transparent;
}

.pdf-viewer__sheet :deep(.textLayer .endOfContent) {
	display: block;
	position: absolute;
	inset: 100% 0 0;
	z-index: 0;
	cursor: default;
	user-select: none;
}

.pdf-viewer__sheet :deep(.textLayer.selecting .endOfContent) {
	top: 0;
}

/* Markierungen unter dem Text-Layer, damit Text auswählbar bleibt. */
.pdf-viewer__sheet :deep(.merlin-pdf-hl-layer) {
	position: absolute;
	top: 0;
	left: 0;
	transform-origin: 0 0;
	pointer-events: none;
	z-index: 1;
}

/* Gedrehte Seiten: beide Ebenen sind unrotiert angelegt (wie im pdf.js-Viewer). */
.pdf-viewer__sheet :deep([data-main-rotation="90"]) {
	transform: rotate(90deg) translateY(-100%);
}

.pdf-viewer__sheet :deep([data-main-rotation="180"]) {
	transform: rotate(180deg) translate(-100%, -100%);
}

.pdf-viewer__sheet :deep([data-main-rotation="270"]) {
	transform: rotate(270deg) translateX(-100%);
}
</style>
