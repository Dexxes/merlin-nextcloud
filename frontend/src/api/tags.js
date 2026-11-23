import { appUrl, http } from '../platform/index.js'

export async function getTags() {
	const url = appUrl('/api/tags')
	const response = await http.get(url)
	return response.data
}

export async function createTag(tagData) {
	const url = appUrl('/api/tags')
	const response = await http.post(url, tagData)
	return response.data
}

export async function updateTag(id, tagData) {
	const url = appUrl(`/api/tags/${id}`)
	const response = await http.put(url, tagData)
	return response.data
}

export async function deleteTag(id) {
	const url = appUrl(`/api/tags/${id}`)
	const response = await http.delete(url)
	return response.data
}

export async function addTagToArticle(articleId, tagId) {
	const url = appUrl(`/api/articles/${articleId}/tags/${tagId}`)
	const response = await http.post(url)
	return response.data
}

export async function removeTagFromArticle(articleId, tagId) {
	const url = appUrl(`/api/articles/${articleId}/tags/${tagId}`)
	const response = await http.delete(url)
	return response.data
}
