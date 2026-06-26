import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export async function getHighlights(articleId) {
	const url = generateUrl(`/apps/merlin/api/articles/${articleId}/highlights`)
	const response = await axios.get(url)
	return response.data
}

export async function createHighlight(articleId, data) {
	const url = generateUrl(`/apps/merlin/api/articles/${articleId}/highlights`)
	const response = await axios.post(url, data)
	return response.data
}

export async function deleteHighlight(id) {
	const url = generateUrl(`/apps/merlin/api/highlights/${id}`)
	await axios.delete(url)
}
