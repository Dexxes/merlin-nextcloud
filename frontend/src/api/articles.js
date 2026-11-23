import { appUrl, http } from '../platform/index.js'

export async function getCounts() {
	const url = appUrl('/api/articles/counts')
	const response = await http.get(url)
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

	const url = appUrl(`/api/articles?${params.toString()}`)
	const response = await http.get(url)
	return response.data
}

export async function getArticle(id) {
	const url = appUrl(`/api/articles/${id}`)
	const response = await http.get(url)
	return response.data
}

// Löst die Audio-/Video-Quelle eines Artikels auf, siehe
// MediaResolverService (Backend). Antwort ist { available: false } statt
// eines Fehlers, wenn sich nichts abspielen lässt, sonst
// { available: true, kind, delivery, variants, defaultIndex }.
export async function resolveMedia(id) {
	const url = appUrl(`/api/articles/${id}/media`)
	const response = await http.get(url)
	return response.data
}

export async function createArticle(articleUrl, tagIds = []) {
	const url = appUrl('/api/articles')
	const response = await http.post(url, {
		url: articleUrl,
		tagIds,
		// Domains aus content-filters/$unsupported.xml sofort mit HTTP 422
		// ablehnen (siehe ArticleController::create()), damit der Dialog eine
		// verständliche Meldung zeigen kann statt eines leeren Artikels.
		rejectUnsupported: true,
	})
	return response.data
}

export async function updateArticle(id, data) {
	const url = appUrl(`/api/articles/${id}`)
	const response = await http.put(url, data)
	return response.data
}

/**
 * Benennt die Datei eines Eintrags aus „Merlin Dateien“ um (Name ohne Endung,
 * die Endung bleibt). Liefert den neu aufgebauten Eintrag; 409 = Name vergeben.
 */
export async function renameFile(id, name) {
	const url = appUrl(`/api/articles/${id}/file-name`)
	const response = await http.put(url, { name })
	return response.data
}

export async function deleteArticle(id) {
	const url = appUrl(`/api/articles/${id}`)
	const response = await http.delete(url)
	return response.data
}

export async function toggleRead(id) {
	const url = appUrl(`/api/articles/${id}/read`)
	const response = await http.put(url)
	return response.data
}

export async function toggleFavorite(id) {
	const url = appUrl(`/api/articles/${id}/favorite`)
	const response = await http.put(url)
	return response.data
}

export async function toggleArchive(id) {
	const url = appUrl(`/api/articles/${id}/archive`)
	const response = await http.put(url)
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
	const url = appUrl(`/api/articles/${id}/progress`)
	const response = await http.put(url, { progress, updatedAt })
	return response.data
}

export async function searchArticles(query) {
	const url = appUrl('/api/articles/search')
	const response = await http.get(url, { params: { query } })
	return response.data
}

export async function exportHtml(id) {
	const url = appUrl(`/api/articles/${id}/export/html`)
	window.location.href = url
}
