import { appUrl, http } from '../platform/index.js'

/** Aktuellen Share-Status eines Artikels abfragen ({ enabled: false } falls keiner existiert). */
export async function getShare(articleId) {
	const url = appUrl(`/api/articles/${articleId}/share`)
	const response = await http.get(url)
	return response.data
}

/**
 * Share-Link anlegen (idempotent). password/expiresAt sind optional,
 * allowComments (Gäste dürfen markieren und kommentieren) ist standardmäßig an.
 */
export async function createShare(articleId, { password = null, expiresAt = null, allowComments = true } = {}) {
	const url = appUrl(`/api/articles/${articleId}/share`)
	const response = await http.post(url, { password, expiresAt, allowComments })
	return response.data
}

/**
 * Passwort, Ablaufdatum und/oder allowComments ändern. Nur mitgeschickte Felder werden
 * geändert — `password: null` entfernt den Passwortschutz, `expiresAt: null`
 * entfernt das Ablaufdatum. Ein Feld ganz wegzulassen lässt es unverändert.
 */
export async function updateShare(articleId, fields) {
	const url = appUrl(`/api/articles/${articleId}/share`)
	const response = await http.put(url, fields)
	return response.data
}

/** Token austauschen — alter Link wird sofort ungültig, Passwort/Ablauf bleiben erhalten. */
export async function regenerateShare(articleId) {
	const url = appUrl(`/api/articles/${articleId}/share/regenerate`)
	const response = await http.post(url)
	return response.data
}

/** Share-Link widerrufen. */
export async function deleteShare(articleId) {
	const url = appUrl(`/api/articles/${articleId}/share`)
	await http.delete(url)
}
