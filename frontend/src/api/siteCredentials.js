import { appUrl, http } from '../platform/index.js'

const base = '/api/user/site-credentials'

// Domainnamen enthalten Punkte, siehe api/userContentFilters.js für die
// gleiche Begründung (Nextclouds Router schneidet sonst am Punkt ab).
function domainUrl(domain) {
	return appUrl(`${base}/${encodeURIComponent(domain)}`)
}

/**
 * @returns {Promise<{credentials: Array<{domain: string, status: string, lastLoginAt: ?string}>, availableDomains: string[]}>}
 */
export async function listSiteCredentials() {
	const { data } = await http.get(appUrl(base))
	return data
}

/**
 * Speichert Zugangsdaten für eine Domain und führt sofort einen Login-Versuch
 * aus. Bei falschem Passwort antwortet der Server mit 401 und
 * { message, reason } – die Zugangsdaten sind trotzdem gespeichert, damit ein
 * späterer automatischer Retry ohne erneute Eingabe funktioniert.
 */
export async function saveSiteCredential(domain, username, password) {
	const { data } = await http.put(domainUrl(domain), { username, password })
	return data
}

export async function deleteSiteCredential(domain) {
	const { data } = await http.delete(domainUrl(domain))
	return data
}
