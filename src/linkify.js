/**
 * Zerlegt Klartext in Text- und Link-Stücke, damit Kommentare Links ohne
 * v-html anklickbar zeigen können. Nur http(s) und „www.“, alles andere
 * bleibt Text.
 */

const URL_PATTERN = /\b(?:https?:\/\/|www\.)[^\s<>"'`]+/gi
const TRAILING = /[.,;:!?'"»«“”‘’]+$/

/** Schließende Klammer am Ende nur behalten, wenn sie im Link geöffnet wurde. */
function trimTrailing(raw) {
	let url = raw.replace(TRAILING, '')
	while (/[)\]}]$/.test(url)) {
		const close = url.slice(-1)
		const open = { ')': '(', ']': '[', '}': '{' }[close]
		if (url.split(open).length >= url.split(close).length) break
		url = url.slice(0, -1).replace(TRAILING, '')
	}
	return url
}

function safeHref(text) {
	const candidate = /^www\./i.test(text) ? 'https://' + text : text
	try {
		const url = new URL(candidate)
		if (url.protocol !== 'http:' && url.protocol !== 'https:') return null
		if (!url.hostname || !url.hostname.includes('.')) return null
		return url.href
	} catch {
		return null
	}
}

/** @return {Array<{ text: string, href?: string }>} */
export function splitLinks(text) {
	const parts = []
	if (!text) return parts
	let last = 0
	for (const match of text.matchAll(URL_PATTERN)) {
		const url = trimTrailing(match[0])
		const href = safeHref(url)
		if (!href) continue
		if (match.index > last) parts.push({ text: text.slice(last, match.index) })
		parts.push({ text: url, href })
		last = match.index + url.length
	}
	if (last < text.length) parts.push({ text: text.slice(last) })
	return parts
}
