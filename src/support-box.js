import './support-box.css'

/**
 * Support-Infobox ("Dir gefällt der Artikel von …? Überlege ein Abo
 * abzuschließen oder zu spenden"), zur Lesezeit zwischen zwei Absätze gesetzt.
 *
 * Bewusst nicht im gespeicherten Content: Export, TTS und Highlight-XPaths
 * bleiben unberührt (siehe SupportBoxService.php). Die Box trägt
 * data-hl-exclude, damit highlight-engine.js sie beim XPath-Indexieren
 * übergeht und Highlights plattformübergreifend gleich auflösen.
 */

const MIN_PARAGRAPHS = 4

/** Nur absolute http(s)-URLs; landet in einem href. */
function safeHttpUrl(value) {
	if (typeof value !== 'string') return null
	try {
		const url = new URL(value)
		return (url.protocol === 'http:' || url.protocol === 'https:') ? url.href : null
	} catch {
		return null
	}
}

/** FNV-1a: stabiler 32-Bit-Hash eines beliebigen Seeds (Artikel-ID/Titel). */
function hashSeed(seed) {
	let h = 0x811c9dc5
	const str = String(seed)
	for (let i = 0; i < str.length; i++) {
		h ^= str.charCodeAt(i)
		h = Math.imul(h, 0x01000193)
	}
	return h >>> 0
}

function buildSentence(t, box, doc) {
	const subscribeUrl = safeHttpUrl(box.subscribeUrl)
	const donationsUrl = safeHttpUrl(box.donationsUrl)
	if (!subscribeUrl && !donationsUrl) return null

	let template
	if (subscribeUrl && donationsUrl) {
		template = t('merlin', 'Consider taking out a {subscribe} or making a {donate}.')
	} else if (subscribeUrl) {
		template = t('merlin', 'Consider taking out a {subscribe}.')
	} else {
		template = t('merlin', 'Consider making a {donate}.')
	}

	const links = {
		subscribe: subscribeUrl && { href: subscribeUrl, label: t('merlin', 'subscription') },
		donate: donationsUrl && { href: donationsUrl, label: t('merlin', 'donation') },
	}

	// Platzhalter selbst auflösen statt über t()-Variablen: die würden HTML-
	// escaped bzw. wir müssten Markup einschleusen. So bleibt alles Textknoten.
	const p = doc.createElement('p')
	p.className = 'merlin-support-box__text'
	for (const part of template.split(/(\{subscribe\}|\{donate\})/)) {
		const match = /^\{(subscribe|donate)\}$/.exec(part)
		const link = match && links[match[1]]
		if (link) {
			const a = doc.createElement('a')
			a.href = link.href
			a.target = '_blank'
			a.rel = 'noopener noreferrer'
			a.textContent = link.label
			p.appendChild(a)
		} else if (part) {
			p.appendChild(doc.createTextNode(part))
		}
	}
	return p
}

/**
 * Setzt die Box nach einem pseudo-zufälligen Top-Level-Absatz ein.
 *
 * @param {string} html    Artikel-HTML
 * @param {object|null} box  {siteName, subscribeUrl, donationsUrl, accentColor}
 * @param {string|number} seed  stabiler Wert (Artikel-ID), damit die Position beim erneuten Rendern gleich bleibt
 * @param {Function} t  Übersetzungsfunktion (app, text) => string
 * @returns {string}
 */
export function insertSupportBox(html, box, seed, t) {
	if (!html || !box) return html

	const doc = new DOMParser().parseFromString(html, 'text/html')
	const sentence = buildSentence(t, box, doc)
	if (!sentence) return html

	// Nur Absätze direkt unter <body>: nicht in Zitaten, Listen, Figures, Infoboxen.
	const paragraphs = [...doc.body.children]
		.filter(el => el.tagName === 'P' && el.textContent.trim() !== '')
	if (paragraphs.length < MIN_PARAGRAPHS) return html

	// Nach dem 2. bis (n-1). Absatz, nie ganz vorn oder am Ende.
	const index = 1 + (hashSeed(seed) % (paragraphs.length - 2))

	const accent = /^#[0-9a-fA-F]{6}$/.test(box.accentColor || '') ? box.accentColor : '#FF3B30'
	const wrapper = doc.createElement('div')
	wrapper.className = 'merlin-support-box'
	wrapper.setAttribute('data-hl-exclude', '')
	wrapper.setAttribute('role', 'note')
	wrapper.style.setProperty('--merlin-support-accent', accent)

	const title = doc.createElement('p')
	title.className = 'merlin-support-box__title'
	title.textContent = t('merlin', 'Enjoying this article from {site}?')
		.replace('{site}', box.siteName || '')
	wrapper.appendChild(title)
	wrapper.appendChild(sentence)

	paragraphs[index].after(wrapper)
	return doc.body.innerHTML
}
