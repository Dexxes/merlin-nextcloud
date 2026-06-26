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

	const url = generateUrl(`/apps/merlin/api/articles?${params.toString()}`)
	const response = await axios.get(url)
	return response.data
}

export async function getArticle(id) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}`)
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

export async function searchArticles(query) {
	const url = generateUrl('/apps/merlin/api/articles/search')
	const response = await axios.get(url, { params: { query } })
	return response.data
}

export async function exportHtml(id) {
	const url = generateUrl(`/apps/merlin/api/articles/${id}/export/html`)
	window.location.href = url
}
