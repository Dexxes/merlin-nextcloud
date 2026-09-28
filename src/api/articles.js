import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export async function getCounts() {
	const url = generateUrl('/apps/merlin/api/articles/counts')
	const response = await axios.get(url)
	return response.data
}

export async function getArticles(filters = {}) {
	const params = new URLSearchParams()

	if (filters.isRead !== null && filters.isRead !== undefined) {
		params.append('isRead', filters.isRead)
	}
	if (filters.isFavorite !== null && filters.isFavorite !== undefined) {
		params.append('isFavorite', filters.isFavorite)
	}
	if (filters.isArchived !== null && filters.isArchived !== undefined) {
		params.append('isArchived', filters.isArchived)
	}
	if (filters.tagId) {
		params.append('tagId', filters.tagId)
	}
	if (filters.category) {
		params.append('category', filters.category)
	}
	if (filters.contentType) {
		params.append('contentType', filters.contentType)
	}

	const url = generateUrl(`/apps/merlin/api/articles?${params.toString()}`)
	const response = await axios.get(url)
	return response.data
}

export async function getArticle(id) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}`)
	const response = await axios.get(url)
	return response.data
}

// Löst die Audio-/Video-Quelle eines Artikels auf, siehe
// MediaResolverService (Backend). Antwort ist { available: false } statt
// eines Fehlers, wenn sich nichts abspielen lässt, sonst
// { available: true, kind, delivery, variants, defaultIndex }.
export async function resolveMedia(id) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}/media`)
	const response = await axios.get(url)
	return response.data
}

export async function createArticle(articleUrl, tagIds = []) {
	const url = generateUrl('/apps/merlin/api/articles')
	const response = await axios.post(url, {
		url: articleUrl,
		tagIds,
	})
	return response.data
}

export async function updateArticle(id, data) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}`)
	const response = await axios.put(url, data)
	return response.data
}

export async function deleteArticle(id) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}`)
	const response = await axios.delete(url)
	return response.data
}

export async function toggleRead(id) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}/read`)
	const response = await axios.put(url)
	return response.data
}

export async function toggleFavorite(id) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}/favorite`)
	const response = await axios.put(url)
	return response.data
}

export async function toggleArchive(id) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}/archive`)
	const response = await axios.put(url)
	return response.data
}

/**
 * Speichert die geräteübergreifende Leseposition (Fraktion 0..1) plus einen
 * Client-Zeitstempel (Epoch-Millis) für die Last-Write-Wins-Auflösung.
 *
 * @param {number} id Artikel-ID
 * @param {number} progress Lesefortschritt 0..1
 * @param {number} updatedAt Epoch-Millis dieses Schreibvorgangs
 * @return {Promise<object>} { scrollProgress, scrollUpdatedAt }
 */
export async function updateProgress(id, progress, updatedAt) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}/progress`)
	const response = await axios.put(url, { progress, updatedAt })
	return response.data
}

/**
 * Speichert die geräteübergreifende Abspielposition des Audios/Videos im
 * Artikel (Sekunden, 0 = von vorn) plus Client-Zeitstempel für Last-Write-Wins.
 *
 * @param {number} id Artikel-ID
 * @param {number} position Abspielposition in Sekunden
 * @param {number} updatedAt Epoch-Millis dieses Schreibvorgangs
 * @return {Promise<object>} { mediaPosition, mediaPositionUpdatedAt }
 */
export async function updateMediaPosition(id, position, updatedAt) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}/media-position`)
	const response = await axios.put(url, { position, updatedAt })
	return response.data
}

export async function searchArticles(query) {
	const url = generateUrl('/apps/merlin/api/articles/search')
	const response = await axios.get(url, { params: { query } })
	return response.data
}

export async function exportHtml(id) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}/export/html`)
	window.location.href = url
}
