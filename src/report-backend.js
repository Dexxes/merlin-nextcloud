// Zugriff auf das merlin-reports-Meldungsbackend über Merlins eigenen
// Server (ReportController). Ein direkter fetch() aus dem Browser auf eine
// andere Origin scheitert an CORS, sobald das Backend keinen
// Access-Control-Allow-Origin-Header schickt (z. B. bei einem per HTTP Basic
// Auth geschützten Verzeichnis, das mit 401 antwortet).
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Prüft die Backend-URL.
 *
 * @param {string} url eingetragene Backend-URL
 * @return {Promise<'ok'|'auth'|'error'>}
 */
export async function pingReportBackend(url) {
	try {
		const { data } = await axios.get(generateUrl('/apps/merlin/api/report-backend/ping'), {
			params: { url },
			timeout: 15000,
		})
		return ['ok', 'auth'].includes(data?.status) ? data.status : 'error'
	} catch {
		return 'error'
	}
}

/**
 * Meldet einen Artikel an das in den Einstellungen gespeicherte Backend.
 * Wirft bei jedem Fehler; err.reportStatus ist 'auth', wenn das Backend die
 * Anmeldung verweigert hat.
 *
 * @param {string} articleUrl URL des Artikels
 * @param {string} comment Kommentar der Meldung
 */
export async function sendArticleReport(articleUrl, comment) {
	try {
		await axios.post(generateUrl('/apps/merlin/api/report-backend/report'), {
			url: articleUrl,
			comment,
		})
	} catch (err) {
		err.reportStatus = err?.response?.data?.status || 'error'
		throw err
	}
}
