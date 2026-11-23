import { appUrl, http } from '../platform/index.js'

/**
 * Zwei gleich gebaute Clients für Kommentare und Markierungen: einer für den
 * Besitzer (eingeloggt), einer für Gäste hinter dem Share-Link. CommentPanel
 * und createCommentSession() sprechen nur diese Schnittstelle an.
 *
 * Antwort von list() und vom Push-Kanal: { signature, generatedAt, comments, highlights },
 * comments = Thread-Wurzeln mit `replies`.
 */

export function ownerCommentsClient(articleId) {
	const base = `/api/articles/${articleId}`
	return {
		async list() {
			return (await http.get(appUrl(`${base}/comments`))).data
		},
		// anchor: neue Textstelle ({ highlightedText, startXpath, … }), die mit
		// diesem Kommentar entsteht (unterstrichen, siehe COMMENT_COLOR)
		async create({ body, highlightId = null, parentId = null, anchor = null }) {
			return (await http.post(appUrl(`${base}/comments`), { body, highlightId, parentId, anchor })).data
		},
		async update(id, body) {
			return (await http.put(appUrl(`/api/comments/${id}`), { body })).data
		},
		async remove(id) {
			await http.delete(appUrl(`/api/comments/${id}`))
		},
		async removeHighlight(id) {
			await http.delete(appUrl(`/api/highlights/${id}`))
		},
		streamUrl(since) {
			return appUrl(`${base}/comments/stream`) + '?since=' + encodeURIComponent(since || '')
		},
	}
}

/**
 * Gast-Client: der Name ist die einzige Berechtigung. Beim Anlegen geht er als
 * `authorName` mit, beim Bearbeiten/Löschen im Header X-Merlin-Guest-Name
 * (URL-kodiert, Header vertragen kein UTF-8).
 *
 * @param {string} token Share-Token
 * @param {Function} getName liefert den aktuell gewählten Gast-Namen
 */
export function guestCommentsClient(token, getName) {
	const base = `/s/${token}`
	const headers = () => ({ 'X-Merlin-Guest-Name': encodeURIComponent(getName() || '') })
	return {
		async list() {
			return (await http.get(appUrl(`${base}/comments`))).data
		},
		/** Namen wählen und Farbe festlegen; Antwort { name, color }. */
		async claimGuest(name, color) {
			return (await http.post(appUrl(`${base}/guest`), { authorName: name, color: color || '' })).data
		},
		async create({ body, highlightId = null, parentId = null, anchor = null, website = '' }) {
			return (await http.post(appUrl(`${base}/comments`), {
				authorName: getName(), body, highlightId, parentId, anchor, website,
			})).data
		},
		async update(id, body) {
			return (await http.put(appUrl(`${base}/comments/${id}`), { body }, { headers: headers() })).data
		},
		async remove(id) {
			await http.delete(appUrl(`${base}/comments/${id}`), { headers: headers() })
		},
		async createHighlight(data) {
			return (await http.post(appUrl(`${base}/highlights`), { ...data, authorName: getName() })).data
		},
		async removeHighlight(id) {
			await http.delete(appUrl(`${base}/highlights/${id}`), { headers: headers() })
		},
		streamUrl(since) {
			return appUrl(`${base}/events`) + '?since=' + encodeURIComponent(since || '')
		},
	}
}
