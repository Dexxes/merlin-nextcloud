import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = '/apps/merlin/api/admin/content-filters'

/**
 * Domainnamen enthalten Punkte. generateUrl() kodiert sie nicht, die Route in
 * appinfo/routes.php erlaubt sie über eine eigene requirements-Angabe – ohne die
 * würde Nextclouds Router "golem.de" am Punkt abschneiden.
 */
function domainUrl(domain, suffix = '') {
	return generateUrl(`${base}/${encodeURIComponent(domain)}${suffix}`)
}

export async function listFilters() {
	const { data } = await axios.get(generateUrl(base))
	return data
}

export async function getFilter(domain) {
	const { data } = await axios.get(domainUrl(domain))
	return data
}

/**
 * @param {string} domain Zieldomain
 * @param {object} rules Builder-Struktur; der Server serialisiert sie zu XML
 */
export async function saveFilter(domain, rules) {
	const { data } = await axios.put(domainUrl(domain), { rules })
	return data
}

export async function deleteFilter(domain) {
	const { data } = await axios.delete(domainUrl(domain))
	return data
}

/**
 * Testlauf. Wird `rules` übergeben, testet der Server den ungespeicherten
 * Entwurf statt des abgelegten Filters.
 */
export async function testFilter(domain, url, rules = null) {
	const payload = rules === null ? { url } : { url, rules }
	const { data } = await axios.post(domainUrl(domain, '/test'), payload)
	return data
}

/**
 * Wird die Domain nicht mitgegeben, leitet der Server sie aus dem name-Attribut
 * der Datei ab und antwortet mit 409, falls dort schon eigene Regeln liegen.
 * Erst `overwrite` bestätigt das Ersetzen.
 */
export async function importFilter(xml, domain = null, overwrite = false) {
	const payload = { xml }
	if (domain !== null) {
		payload.domain = domain
	}
	if (overwrite) {
		payload.overwrite = true
	}
	const { data } = await axios.post(generateUrl(`${base}/import`), payload)
	return data
}

export function exportUrl(domain) {
	return domainUrl(domain, '/export')
}
