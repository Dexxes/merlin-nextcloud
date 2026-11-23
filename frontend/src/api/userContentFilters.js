import { appUrl, http } from '../platform/index.js'

const base = '/api/user/content-filters'

/**
 * Domainnamen enthalten Punkte. appUrl() kodiert sie nicht, die Route in
 * appinfo/routes.php erlaubt sie über eine eigene requirements-Angabe – ohne die
 * würde Nextclouds Router "golem.de" am Punkt abschneiden.
 */
function domainUrl(domain, suffix = '') {
	return appUrl(`${base}/${encodeURIComponent(domain)}${suffix}`)
}

export async function listFilters() {
	const { data } = await http.get(appUrl(base))
	return data
}

export async function getFilter(domain) {
	const { data } = await http.get(domainUrl(domain))
	return data
}

/**
 * @param {string} domain Zieldomain
 * @param {object} rules Builder-Struktur; der Server serialisiert sie zu XML
 */
export async function saveFilter(domain, rules) {
	const { data } = await http.put(domainUrl(domain), { rules })
	return data
}

export async function deleteFilter(domain) {
	const { data } = await http.delete(domainUrl(domain))
	return data
}

/**
 * Testlauf gegen Bundle + Admin-Custom + den eigenen (ggf. ungespeicherten)
 * Entwurf. Der Server nutzt dafür denselben SSRF-geschützten Fetch-Pfad wie
 * der Admin-Testlauf, mit gleichem Rate-Limit.
 */
export async function testFilter(domain, url, rules = null) {
	const payload = rules === null ? { url } : { url, rules }
	const { data } = await http.post(domainUrl(domain, '/test'), payload)
	return data
}
