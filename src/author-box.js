import './author-box.css'

/**
 * Autorenboxen (Foto, Name, Kurzbiografie) am Artikelende, zur Lesezeit
 * angehängt - wie die Support-Infobox (support-box.js) bewusst nicht im
 * gespeicherten Content, damit Export, TTS und Highlight-XPaths unberührt
 * bleiben. Daten: article.authors[].image/.bio aus den Content-Filter-Feldern
 * <metadata><author-image>/<author-bio> (ContentExtractorService).
 * Die Box trägt data-hl-exclude, damit highlight-engine.js sie übergeht.
 */

const DEFAULT_ACCENT = '#FF3B30'

/** Nur absolute http(s)-URLs; landet in src/href. */
function safeHttpUrl(value) {
	if (typeof value !== 'string') return null
	try {
		const url = new URL(value.trim())
		return (url.protocol === 'http:' || url.protocol === 'https:') ? url.href : null
	} catch {
		return null
	}
}

/**
 * Autoren, für die eine Box entsteht: mit Name und mindestens Foto oder Bio.
 *
 * @param {Array|null} authors article.authors
 * @returns {Array<{name: string, url: ?string, image: ?string, bio: ?string}>}
 */
export function authorBoxEntries(authors) {
	if (!Array.isArray(authors)) return []
	return authors
		.filter(a => a && typeof a.name === 'string' && a.name.trim() !== '')
		.map(a => ({
			name: a.name.trim(),
			url: safeHttpUrl(a.url),
			image: safeHttpUrl(a.image),
			bio: typeof a.bio === 'string' && a.bio.trim() !== '' ? a.bio.trim() : null,
		}))
		.filter(a => a.image || a.bio)
}

// http(s)://… oder www.… bis zum nächsten Leerzeichen/Klammer/Anführungszeichen.
const URL_PATTERN = /\b(?:https?:\/\/|www\.)[^\s<>"'()]+/gi
// Satzzeichen am Ende gehören meist zum Satz, nicht zur URL.
const TRAILING_PUNCTUATION = /[.,;:!?…]+$/

/**
 * Hängt $text an $parent an und macht darin enthaltene URLs zu Links. Alles
 * als Text-/Elementknoten, kein innerHTML: die Bio stammt von der Quellseite.
 */
function appendLinkified(doc, parent, text) {
	let last = 0
	for (const match of text.matchAll(URL_PATTERN)) {
		const raw = match[0].replace(TRAILING_PUNCTUATION, '')
		const href = safeHttpUrl(/^www\./i.test(raw) ? 'https://' + raw : raw)
		if (!href) continue
		if (match.index > last) parent.appendChild(doc.createTextNode(text.slice(last, match.index)))
		const a = doc.createElement('a')
		a.href = href
		a.target = '_blank'
		a.rel = 'noopener noreferrer'
		a.textContent = raw
		parent.appendChild(a)
		last = match.index + raw.length
	}
	if (last < text.length) parent.appendChild(doc.createTextNode(text.slice(last)))
}

function buildBox(doc, author, accent) {
	const box = doc.createElement('div')
	box.className = 'merlin-author-box'
	box.setAttribute('data-hl-exclude', '')
	box.setAttribute('role', 'note')
	box.style.setProperty('--merlin-author-accent', accent)

	if (author.image) {
		const img = doc.createElement('img')
		img.className = 'merlin-author-box__photo'
		// setAttribute statt IDL-Properties: das Attribut muss die Serialisierung überleben.
		img.setAttribute('alt', '')
		img.setAttribute('loading', 'lazy')
		img.setAttribute('referrerpolicy', 'no-referrer')
		img.setAttribute('src', author.image)
		box.appendChild(img)
	}

	const body = doc.createElement('div')
	body.className = 'merlin-author-box__body'

	const name = doc.createElement('p')
	name.className = 'merlin-author-box__name'
	if (author.url) {
		const a = doc.createElement('a')
		a.href = author.url
		a.target = '_blank'
		a.rel = 'noopener noreferrer'
		a.textContent = author.name
		name.appendChild(a)
	} else {
		name.textContent = author.name
	}
	body.appendChild(name)

	if (author.bio) {
		const bio = doc.createElement('p')
		bio.className = 'merlin-author-box__bio'
		appendLinkified(doc, bio, author.bio)
		body.appendChild(bio)
	}

	box.appendChild(body)
	return box
}

/**
 * Hängt je Autor mit Foto oder Bio eine Box ans Ende des Artikel-HTML.
 *
 * @param {string} html  Artikel-HTML
 * @param {Array|null} authors  article.authors
 * @param {string} accentColor  Akzentfarbe des Lesers (#RRGGBB)
 * @param {Function} t  Übersetzungsfunktion (app, text) => string
 * @returns {string}
 */
export function appendAuthorBoxes(html, authors, accentColor, t) {
	const entries = authorBoxEntries(authors)
	if (!html || entries.length === 0) return html

	const doc = new DOMParser().parseFromString(html, 'text/html')
	const accent = /^#[0-9a-fA-F]{6}$/.test(accentColor || '') ? accentColor : DEFAULT_ACCENT

	const section = doc.createElement('section')
	section.className = 'merlin-author-boxes'
	section.setAttribute('data-hl-exclude', '')
	section.setAttribute('aria-label', entries.length === 1
		? t('merlin', 'About the author')
		: t('merlin', 'About the authors'))
	for (const author of entries) {
		section.appendChild(buildBox(doc, author, accent))
	}
	doc.body.appendChild(section)
	return doc.body.innerHTML
}

/**
 * Entfernt Autorenfotos, die nicht laden (Hotlink-Schutz, 404) - die Box
 * bleibt mit Name und Bio vollständig. Nach jedem Rendern aufrufen.
 *
 * @param {ParentNode} root Container mit dem gerenderten Artikel-HTML
 */
export function hideBrokenAuthorPhotos(root) {
	if (!root) return
	root.querySelectorAll('.merlin-author-box__photo').forEach(img => {
		if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) {
			img.remove()
		} else {
			img.addEventListener('error', () => img.remove(), { once: true })
		}
	})
}
