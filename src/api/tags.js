import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export async function getTags() {
	const url = generateUrl('/apps/merlin/api/tags')
	const response = await axios.get(url)
	return response.data
}

export async function createTag(tagData) {
	const url = generateUrl('/apps/merlin/api/tags')
	const response = await axios.post(url, tagData)
	return response.data
}

export async function updateTag(id, tagData) {
	const url = generateUrl(`/apps/merlin/api/tags/${id}`)
	const response = await axios.put(url, tagData)
	return response.data
}

export async function deleteTag(id) {
	const url = generateUrl(`/apps/merlin/api/tags/${id}`)
	const response = await axios.delete(url)
	return response.data
}

export async function addTagToArticle(articleId, tagId) {
	const url = generateUrl(`/apps/merlin/api/articles/${articleId}/tags/${tagId}`)
	const response = await axios.post(url)
	return response.data
}

export async function removeTagFromArticle(articleId, tagId) {
	const url = generateUrl(`/apps/merlin/api/articles/${articleId}/tags/${tagId}`)
	const response = await axios.delete(url)
	return response.data
}
