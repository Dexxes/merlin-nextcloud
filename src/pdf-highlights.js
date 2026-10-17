/**
 * Markierungen in PDFs (siehe PdfViewer.vue und HighlightEngine).
 *
 * Eine Stelle in einer PDF wird nicht per XPath, sondern über Seite und
 * Rechtecke gespeichert: `pdfPage` (ab 1) und `pdfRects` = [[x, y, w, h], …],
 * normiert auf 0..1 bezogen auf die unrotierte Seite (CropBox), Ursprung oben
 * links. So passt sie in Web und iOS, obwohl pdf.js und PDFKit den Seitentext
 * unterschiedlich zerlegen. Gezeichnet wird in die Ebene
 * `.merlin-pdf-hl-layer` der Seite, die selbst unrotiert ist; Prozentwerte
 * folgen damit jeder Breite.
 */

export const PDF_PAGE_SELECTOR = '.pdf-viewer__page'
const MAX_RECTS = 200

/** Seiten-Element (data-page) um einen Knoten, sonst null. */
export function pdfPageOf(node) {
	const el = node?.nodeType === Node.ELEMENT_NODE ? node : node?.parentElement
	return el?.closest?.(PDF_PAGE_SELECTOR) ?? null
}

/** Drehung der Seite (0/90/180/270) aus der Markierungs-Ebene. */
function rotationOf(pageEl) {
	const layer = pageEl.querySelector('.merlin-pdf-hl-layer')
	return Number(layer?.getAttribute('data-main-rotation') || 0)
}

/**
 * Bildschirmpunkt (anteilig in der angezeigten, ggf. gedrehten Seite) in die
 * unrotierte Seite umrechnen. Gegenstück zu den Transformationen von
 * `[data-main-rotation]` in PdfViewer.vue.
 */
function unrotate(u, v, rotation) {
	switch (rotation) {
	case 90: return [v, 1 - u]
	case 180: return [1 - u, 1 - v]
	case 270: return [1 - v, u]
	default: return [u, v]
	}
}

/** Die Auswahl auf die Seite begrenzen, auf der sie beginnt. */
export function clipRangeToPage(range, pageEl) {
	const textLayer = pageEl.querySelector('.textLayer')
	if (!textLayer) return null
	const clipped = range.cloneRange()
	if (!textLayer.contains(clipped.startContainer)) return null
	if (!textLayer.contains(clipped.endContainer)) {
		const end = textLayer.querySelector('.endOfContent')
		if (end) clipped.setEndBefore(end)
		else clipped.setEnd(textLayer, textLayer.childNodes.length)
	}
	return clipped
}

/**
 * Normierte Rechtecke einer Auswahl auf einer Seite. Die einzelnen Zeilen-
 * Stücke (je <span> eins) werden pro Zeile zu einem Rechteck vereinigt.
 */
export function rectsForRange(range, pageEl) {
	const sheet = pageEl.querySelector('.pdf-viewer__sheet') ?? pageEl
	const box = sheet.getBoundingClientRect()
	if (!box.width || !box.height) return []
	const rotation = rotationOf(pageEl)
	const raw = []
	for (const r of range.getClientRects()) {
		if (r.width < 1 || r.height < 1) continue
		const corners = [
			unrotate((r.left - box.left) / box.width, (r.top - box.top) / box.height, rotation),
			unrotate((r.right - box.left) / box.width, (r.bottom - box.top) / box.height, rotation),
		]
		const x1 = clamp(Math.min(corners[0][0], corners[1][0]))
		const x2 = clamp(Math.max(corners[0][0], corners[1][0]))
		const y1 = clamp(Math.min(corners[0][1], corners[1][1]))
		const y2 = clamp(Math.max(corners[0][1], corners[1][1]))
		if (x2 > x1 && y2 > y1) raw.push({ x1, x2, y1, y2 })
	}
	// Zeilen laufen nach dem Zurückdrehen immer waagerecht.
	const lines = []
	for (const r of raw) {
		const line = lines.find(l => sameLine(l, r))
		if (line) {
			line.x1 = Math.min(line.x1, r.x1)
			line.x2 = Math.max(line.x2, r.x2)
			line.y1 = Math.min(line.y1, r.y1)
			line.y2 = Math.max(line.y2, r.y2)
		} else {
			lines.push({ ...r })
		}
	}
	return lines.slice(0, MAX_RECTS).map(l => [l.x1, l.y1, l.x2 - l.x1, l.y2 - l.y1].map(round))
}

/** Zwei Stücke liegen in derselben Zeile, wenn sie sich zu mehr als der Hälfte überlappen. */
function sameLine(a, b) {
	const overlap = Math.min(a.y2, b.y2) - Math.max(a.y1, b.y1)
	return overlap > 0.5 * Math.min(a.y2 - a.y1, b.y2 - b.y1)
}

/**
 * Text einer Auswahl im Text-Layer. Range.toString() klebt Zeilen aneinander,
 * weil pdf.js Zeilenenden als <br> setzt; die werden hier zu Leerzeichen.
 */
function rangeText(range) {
	const root = range.commonAncestorContainer
	const parts = []
	if (root.nodeType === Node.TEXT_NODE) {
		parts.push(root.data.slice(range.startOffset, range.endOffset))
	} else {
		const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT)
		let node
		while ((node = walker.nextNode())) {
			if (!range.intersectsNode(node)) continue
			if (node.nodeName === 'BR') {
				parts.push(' ')
			} else if (node.nodeType === Node.TEXT_NODE) {
				const start = node === range.startContainer ? range.startOffset : 0
				const end = node === range.endContainer ? range.endOffset : node.length
				parts.push(node.data.slice(start, end))
			}
		}
	}
	return parts.join('').replace(/\s+/g, ' ').trim()
}

function clamp(value) {
	return Math.min(1, Math.max(0, value))
}

function round(value) {
	return Math.round(value * 100000) / 100000
}

/** Seite und Rechtecke einer Auswahl, oder null, wenn sie nicht in einer PDF liegt. */
export function pdfAnchorFor(range) {
	const pageEl = pdfPageOf(range.startContainer)
	if (!pageEl) return null
	const clipped = clipRangeToPage(range, pageEl)
	if (!clipped || clipped.collapsed) return null
	const pdfRects = rectsForRange(clipped, pageEl)
	const highlightedText = rangeText(clipped)
	if (!pdfRects.length || !highlightedText) return null
	return { pdfPage: Number(pageEl.dataset.page), pdfRects, highlightedText }
}

/**
 * Markierung in die Ebene ihrer Seite zeichnen. Gibt false zurück, solange die
 * Seite noch nicht gerendert ist (dann folgt das Ereignis `merlin-pdf-page`).
 */
export function paintPdfHighlight(container, highlight, createEl) {
	const layer = container.querySelector(`${PDF_PAGE_SELECTOR}[data-page="${Number(highlight.pdfPage)}"] .merlin-pdf-hl-layer`)
	if (!layer || !Array.isArray(highlight.pdfRects)) return false
	layer.querySelectorAll(`.merlin-highlight[data-highlight-id="${highlight.id}"]`).forEach(el => el.remove())
	for (const rect of highlight.pdfRects) {
		if (!Array.isArray(rect) || rect.length !== 4) continue
		const [x, y, w, h] = rect.map(Number)
		const el = createEl()
		el.style.left = `${x * 100}%`
		el.style.top = `${y * 100}%`
		el.style.width = `${w * 100}%`
		el.style.height = `${h * 100}%`
		layer.appendChild(el)
	}
	return true
}

/**
 * PDF-Markierung unter einem Klickpunkt. Die Ebene liegt unter dem Text-Layer
 * (pointer-events: none), daher per Koordinaten statt über das Klickziel.
 */
export function pdfHighlightAt(target, x, y) {
	const pageEl = pdfPageOf(target)
	if (!pageEl) return null
	for (const el of pageEl.querySelectorAll('.merlin-pdf-hl-layer .merlin-highlight')) {
		const r = el.getBoundingClientRect()
		if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) return el
	}
	return null
}
