import { reactive } from 'vue'

/**
 * Hält Threads und Markierungen eines Artikels aktuell, ohne Polling: ein
 * EventSource auf den Push-Kanal des Servers (CommentService::stream()) liefert
 * bei jeder Änderung den kompletten Stand. Der Server beendet jede Verbindung
 * nach rund 50 s; EventSource verbindet dann selbst neu und schickt die zuletzt
 * empfangene Änderungsmarke als Last-Event-ID mit.
 *
 * Das Ereignis `closed` heißt: es gibt (für den Besitzer) keinen Share-Link
 * mehr bzw. der Link gilt nicht mehr – dann bleibt der Kanal zu, bis
 * reconnect() aufgerufen wird (z. B. nach dem Anlegen eines Links).
 *
 * @param {object} client ownerCommentsClient() oder guestCommentsClient()
 * @param {object} [options]
 * @param {Function} [options.onUpdate] wird nach jedem neuen Stand aufgerufen
 */
export function createCommentSession(client, { onUpdate = null } = {}) {
	const state = reactive({
		threads: [],
		highlights: [],
		signature: '',
		generatedAt: 0,
		loaded: false,
	})
	let source = null
	let retryTimer = null
	let stopped = false

	function apply(payload) {
		if (!payload) return
		// Älterer Stand als der gezeigte (verspätetes Push-Ereignis oder eine
		// überholte Antwort): verwerfen, sonst erscheint Gelöschtes kurz wieder.
		const at = Number(payload.generatedAt) || 0
		if (at && at < state.generatedAt) return
		if (at) state.generatedAt = at
		state.threads = payload.comments || []
		state.highlights = payload.highlights || []
		state.signature = payload.signature || ''
		state.loaded = true
		onUpdate?.(state)
	}

	async function refresh() {
		apply(await client.list())
	}

	function disconnect() {
		clearTimeout(retryTimer)
		retryTimer = null
		if (source) {
			source.close()
			source = null
		}
	}

	function connect() {
		if (stopped || source || typeof EventSource === 'undefined') return
		const es = new EventSource(client.streamUrl(state.signature))
		source = es
		es.addEventListener('comments', (event) => {
			try {
				apply(JSON.parse(event.data))
			} catch {
				// unvollständiges Ereignis – das nächste bringt den Stand
			}
		})
		es.addEventListener('closed', () => disconnect())
		es.onerror = () => {
			// Bei normalem Verbindungsende verbindet EventSource selbst neu
			// (readyState CONNECTING). Nur bei einer Fehlerantwort (CLOSED)
			// selbst nach einer Pause neu versuchen.
			if (es.readyState === EventSource.CLOSED && source === es) {
				source = null
				clearTimeout(retryTimer)
				retryTimer = setTimeout(connect, 15000)
			}
		}
	}

	function reconnect() {
		disconnect()
		connect()
	}

	function stop() {
		stopped = true
		disconnect()
	}

	/** Anzahl lesbarer Kommentare je Markierung (Wurzel + Antworten). */
	function countsByHighlight() {
		const counts = {}
		for (const thread of state.threads) {
			if (thread.highlightId == null) continue
			const n = (thread.deleted ? 0 : 1) + thread.replies.filter(r => !r.deleted).length
			counts[thread.highlightId] = (counts[thread.highlightId] || 0) + n
		}
		return counts
	}

	return { state, apply, refresh, connect, reconnect, stop, countsByHighlight }
}

const GUEST_NAME_KEY = 'merlin.guestName'

/** Gewählter Gast-Name aus dem Browser (leer, wenn noch keiner gewählt). */
export function loadGuestName() {
	try {
		return localStorage.getItem(GUEST_NAME_KEY) || ''
	} catch {
		return ''
	}
}

export function saveGuestName(name) {
	try {
		localStorage.setItem(GUEST_NAME_KEY, name)
	} catch {
		// privater Modus – Name gilt dann nur für diese Seite
	}
}

/** Gleiche Normalisierung wie CommentRules::nameKey() im Backend. */
export function guestNameKey(name) {
	return (name || '').replace(/[\u0000-\u001f\u007f-\u009f‪-‮⁦-⁩]+/g, ' ')
		.replace(/\s+/g, ' ').trim().toLowerCase()
}
