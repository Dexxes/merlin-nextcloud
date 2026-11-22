import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export async function getSettings() {
	const url = generateUrl('/apps/merlin/api/settings')
	const response = await axios.get(url)
	return response.data
}

export async function updateSettings(settings) {
	const url = generateUrl('/apps/merlin/api/settings')
	// Flach senden (wie iOS/Android): SettingsController::update() liest die
	// Top-Level-Params und ignoriert unbekannte Keys — ein verschachteltes
	// { settings: {...} } würde komplett verworfen und nie gespeichert.
	const response = await axios.put(url, settings)
	return response.data
}
