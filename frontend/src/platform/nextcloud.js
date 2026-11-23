/**
 * Nextcloud-Umsetzung der Plattform-Schicht (siehe ./index.js).
 */
import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { loadState as ncLoadState } from '@nextcloud/initial-state'

export { showError, showSuccess } from '@nextcloud/dialogs'
export { translate, translatePlural } from '@nextcloud/l10n'

const APP_ID = 'merlin'

/** Axios mit requesttoken für Cookie-Anfragen (CsrfCookieAuthMiddleware). */
export const http = axios

/** @param {string} path Pfad wie in core/src/Api/routes.php, mit führendem / */
export function appUrl(path) {
	return generateUrl(`/apps/${APP_ID}${path}`)
}

/** @param {string} file Dateiname in nextcloud/img/ */
export function imageUrl(file) {
	return imagePath(APP_ID, file)
}

/**
 * @param {string} key
 * @param {*} [fallback] fehlt er, wirft loadState() bei fehlendem Wert
 */
export function loadState(key, fallback) {
	return ncLoadState(APP_ID, key, fallback)
}

/** Konto, Einstellungen und Abmelden stehen in Nextclouds Kopfleiste. */
export function accountLinks() {
	return []
}
