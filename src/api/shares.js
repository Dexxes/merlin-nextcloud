import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Aktuellen Share-Status eines Artikels abfragen ({ enabled: false } falls keiner existiert). */
export async function getShare(articleId) {
	const url = generateUrl(`/apps/merlin/api/articles/${articleId}/share`)
	const response = await axios.get(url)
	return response.data
}

/** Share-Link anlegen (idempotent). password/expiresAt sind optional. */
export async function createShare(articleId, { password = null, expiresAt = null } = {}) {
	const url = generateUrl(`/apps/merlin/api/articles/${articleId}/share`)
	const response = await axios.post(url, { password, expiresAt })
	return response.data
}

/**
 * Passwort und/oder Ablaufdatum ändern. Nur mitgeschickte Felder werden
 * geändert — `password: null` entfernt den Passwortschutz, `expiresAt: null`
 * entfernt das Ablaufdatum. Ein Feld ganz wegzulassen lässt es unverändert.
 */
export async function updateShare(articleId, fields) {
	const url = generateUrl(`/apps/merlin/api/articles/${articleId}/share`)
	const response = await axios.put(url, fields)
	return response.data
}

/** Token austauschen — alter Link wird sofort ungültig, Passwort/Ablauf bleiben erhalten. */
export async function regenerateShare(articleId) {
	const url = generateUrl(`/apps/merlin/api/articles/${articleId}/share/regenerate`)
	const response = await axios.post(url)
	return response.data
}

/** Share-Link widerrufen. */
export async function deleteShare(articleId) {
	const url = generateUrl(`/apps/merlin/api/articles/${articleId}/share`)
	await axios.delete(url)
}
