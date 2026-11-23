import { appUrl, http } from '../platform/index.js'

export async function getHighlights(articleId) {
	const url = appUrl(`/api/articles/${articleId}/highlights`)
	const response = await http.get(url)
	return response.data
}

export async function createHighlight(articleId, data) {
	const url = appUrl(`/api/articles/${articleId}/highlights`)
	const response = await http.post(url, data)
	return response.data
}

export async function deleteHighlight(id) {
	const url = appUrl(`/api/highlights/${id}`)
	await http.delete(url)
}

/** Stelle (länger/kürzer gezogen) und optional Farbe einer Markierung ändern. */
export async function updateHighlight(id, data) {
	const url = appUrl(`/api/highlights/${id}`)
	const response = await http.put(url, data)
	return response.data
}
