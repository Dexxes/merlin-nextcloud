import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Zwei gleich gebaute Clients für Kommentare und Markierungen: einer für den
 * Besitzer (eingeloggt), einer für Gäste hinter dem Share-Link. CommentPanel
 * und createCommentSession() sprechen nur diese Schnittstelle an.
 *
 * Antwort von list() und vom Push-Kanal: { signature, generatedAt, comments, highlights },
 * comments = Thread-Wurzeln mit `replies`.
 */

export function ownerCommentsClient(articleId) {
	const base = `/apps/merlin/api/articles/${articleId}`
	return {
		async list() {
			return (await axios.get(generateUrl(`${base}/comments`))).data
		},
		// anchor: neue Textstelle ({ highlightedText, startXpath, … }), die mit
		// diesem Kommentar entsteht (unterstrichen, siehe COMMENT_COLOR)
		async create({ body, highlightId = null, parentId = null, anchor = null }) {
			return (await axios.post(generateUrl(`${base}/comments`), { body, highlightId, parentId, anchor })).data
		},
		async update(id, body) {
			return (await axios.put(generateUrl(`/apps/merlin/api/comments/${id}`), { body })).data
		},
		async remove(id) {
			await axios.delete(generateUrl(`/apps/merlin/api/comments/${id}`))
		},
		async removeHighlight(id) {
			await axios.delete(generateUrl(`/apps/merlin/api/highlights/${id}`))
		},
		streamUrl(since) {
			return generateUrl(`${base}/comments/stream`) + '?since=' + encodeURIComponent(since || '')
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
	const base = `/apps/merlin/s/${token}`
	const headers = () => ({ 'X-Merlin-Guest-Name': encodeURIComponent(getName() || '') })
	return {
		async list() {
			return (await axios.get(generateUrl(`${base}/comments`))).data
		},
		async create({ body, highlightId = null, parentId = null, anchor = null, website = '' }) {
			return (await axios.post(generateUrl(`${base}/comments`), {
				authorName: getName(), body, highlightId, parentId, anchor, website,
			})).data
		},
		async update(id, body) {
			return (await axios.put(generateUrl(`${base}/comments/${id}`), { body }, { headers: headers() })).data
		},
		async remove(id) {
			await axios.delete(generateUrl(`${base}/comments/${id}`), { headers: headers() })
		},
		async createHighlight(data) {
			return (await axios.post(generateUrl(`${base}/highlights`), { ...data, authorName: getName() })).data
		},
		async removeHighlight(id) {
			await axios.delete(generateUrl(`${base}/highlights/${id}`), { headers: headers() })
		},
		streamUrl(since) {
			return generateUrl(`${base}/events`) + '?since=' + encodeURIComponent(since || '')
		},
	}
}
