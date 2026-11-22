import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/merlin/api/user/site-credentials'

// Domainnamen enthalten Punkte, siehe api/userContentFilters.js für die
// gleiche Begründung (Nextclouds Router schneidet sonst am Punkt ab).
function domainUrl(domain) {
	return generateUrl(`${base}/${encodeURIComponent(domain)}`)
}

/**
 * @returns {Promise<{credentials: Array<{domain: string, status: string, lastLoginAt: ?string}>, availableDomains: string[]}>}
 */
export async function listSiteCredentials() {
	const { data } = await axios.get(generateUrl(base))
	return data
}

/**
 * Speichert Zugangsdaten für eine Domain und führt sofort einen Login-Versuch
 * aus. Bei falschem Passwort antwortet der Server mit 401 und
 * { message, reason } – die Zugangsdaten sind trotzdem gespeichert, damit ein
 * späterer automatischer Retry ohne erneute Eingabe funktioniert.
 */
export async function saveSiteCredential(domain, username, password) {
	const { data } = await axios.put(domainUrl(domain), { username, password })
	return data
}

export async function deleteSiteCredential(domain) {
	const { data } = await axios.delete(domainUrl(domain))
	return data
}
