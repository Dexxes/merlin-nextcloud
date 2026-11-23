import { appUrl, http } from '../platform/index.js'

export async function getSettings() {
	const url = appUrl('/api/settings')
	const response = await http.get(url)
	return response.data
}

export async function updateSettings(settings) {
	const url = appUrl('/api/settings')
	// Flach senden (wie iOS/Android): SettingsController::update() liest die
	// Top-Level-Params und ignoriert unbekannte Keys — ein verschachteltes
	// { settings: {...} } würde komplett verworfen und nie gespeichert.
	const response = await http.put(url, settings)
	return response.data
}
