/**
 * Standalone-Umsetzung der Plattform-Schicht (siehe ./index.js). Die Seite
 * (standalone/templates/app.php) liefert die Startwerte als JSON in
 * <script id="merlin-state" type="application/json">: base (Pfad des
 * Servers ohne / am Ende), dazu die Werte, die loadState() liest.
 *
 * Anmeldung: Session-Cookie (SameSite=Lax). Schreibende API-Anfragen mit
 * Cookie verlangt der Server mit X-Requested-With: XMLHttpRequest
 * (Http\Middleware\CsrfHeaderMiddleware); ein fremdes Formular oder <img>
 * kann diesen Header nicht setzen.
 */
import axios from 'axios'
import { register, translate, translatePlural } from '@nextcloud/l10n'

// Toasts wie in Nextcloud: @nextcloud/dialogs braucht keine Nextcloud-Globals.
export { showError, showSuccess } from '@nextcloud/dialogs'
export { translate, translatePlural }

let state = null
function pageState() {
	if (state === null) {
		const element = document.getElementById('merlin-state')
		try {
			state = element ? JSON.parse(element.textContent || '{}') : {}
		} catch (e) {
			state = {}
		}
	}
	return state
}

// Texte: @nextcloud/l10n mit den Übersetzungen, die die Seite mitliefert
// (nextcloud/l10n/{Sprache}.json, für Englisch keine). Nextcloud stellt
// außerdem t() und n() global bereit; einige Module (author-box.js,
// support-box.js, Skriptteile der Komponenten) rufen sie so auf.
if (pageState().l10n) {
	register('merlin', pageState().l10n)
}
globalThis.t ??= translate
globalThis.n ??= translatePlural

function base() {
	return String(pageState().base || '').replace(/\/+$/, '')
}

export const http = axios.create({
	withCredentials: true,
	headers: { 'X-Requested-With': 'XMLHttpRequest' },
})

// Abgelaufene Session: zur Anmeldung und danach zurück.
http.interceptors.response.use(undefined, (error) => {
	if (error?.response?.status === 401 && pageState().loginUrl) {
		const back = window.location.pathname + window.location.search
		window.location.href = pageState().loginUrl + '?return=' + encodeURIComponent(back)
	}
	return Promise.reject(error)
})

/** @param {string} path Pfad wie in core/src/Api/routes.php, mit führendem / */
export function appUrl(path) {
	return base() + path
}

/** @param {string} file Dateiname in nextcloud/img/ (Standalone: GET /img/{file}) */
export function imageUrl(file) {
	return base() + '/img/' + file
}

/**
 * @param {string} key
 * @param {*} [fallback] fehlt er, wirft loadState() bei fehlendem Wert (wie Nextcloud)
 */
export function loadState(key, fallback) {
	const values = pageState().values || {}
	if (Object.prototype.hasOwnProperty.call(values, key)) {
		return values[key]
	}
	if (fallback !== undefined) {
		return fallback
	}
	throw new Error(`Could not find initial state ${key} of merlin`)
}
