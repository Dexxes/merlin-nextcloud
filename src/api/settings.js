import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export async function getSettings() {
	const url = generateUrl('/apps/merlin/api/settings')
	const response = await axios.get(url)
	return response.data
}

export async function updateSettings(settings) {
	const url = generateUrl('/apps/merlin/api/settings')
	const response = await axios.put(url, { settings })
	return response.data
}
