import { h, render } from 'vue'
import MediaPlayer from './components/MediaPlayer.vue'
import './inline-media.css'

/**
 * Videos mitten im Artikeltext (siehe Service/Media/InlineMediaService.php):
 * der gespeicherte Content enthält je Video eine
 *
 *   <figure class="merlin-inline-media">
 *     <img src="Vorschaubild">
 *     <div class="merlin-inline-media-source" data-media-kind data-media-delivery data-media-src>
 *       <a class="merlin-inline-media-fallback-link">Zum Video</a>
 *     </div>
 *     <figcaption>…</figcaption>
 *   </figure>
 *
 * Auf jede solche Figure wird hier ein MediaPlayer gelegt, mit dem
 * Vorschaubild als Poster. Der Player hängt in einem eigenen
 * data-hl-exclude-Container: highlight-engine.js übergeht ihn, Bild,
 * Marker und figcaption bleiben unverändert im DOM (nur per CSS
 * ausgeblendet), Highlight-XPaths lösen also weiter auf wie im gespeicherten
 * Content. Kann der Player nicht abspielen, rendert er nichts und Bild samt
 * "Zum Video"-Link bleiben sichtbar.
 *
 * Idempotent: der Content wird per v-html gerendert und bei jeder Änderung
 * (z. B. nachgeladene Support-Box) komplett ersetzt. mountInlineMedia() nach
 * jedem Rendern aufrufen - es hängt nur an Figures ohne Player einen an und
 * räumt Player ab, deren Figure nicht mehr im DOM steht.
 */

const FIGURE_SELECTOR = 'figure.merlin-inline-media'
const SOURCE_SELECTOR = 'div.merlin-inline-media-source[data-media-kind]'
const PLAYER_CLASS = 'merlin-inline-media__player'
const PLAYABLE_CLASS = 'merlin-inline-media--playable'

/**
 * Marker im Format, das MediaPlayer selbst liest (parseMediaMarker()) - so
 * prüft der Player die Werte wie beim Aufmacher-Medium.
 *
 * @param {Element} source div.merlin-inline-media-source
 * @return {string}
 */
function playerContent(source) {
	const marker = document.createElement('div')
	marker.className = 'merlin-media'
	for (const name of ['data-media-kind', 'data-media-delivery', 'data-media-src']) {
		const value = source.getAttribute(name)
		if (value !== null) marker.setAttribute(name, value)
	}
	return marker.outerHTML
}

/**
 * Legt auf jede Inline-Medien-Figure in root einen MediaPlayer.
 *
 * @param {?Element} root Artikel-Container
 * @param {object} appContext appContext der aufrufenden Komponente (this.$.appContext), damit t() & Co. verfügbar sind
 * @param {Set<Element>} mounted Container der bereits gemounteten Player (vom Aufrufer gehalten)
 */
export function mountInlineMedia(root, appContext, mounted) {
	for (const container of mounted) {
		if (!container.isConnected) {
			render(null, container)
			mounted.delete(container)
		}
	}
	if (!root) return

	root.querySelectorAll(FIGURE_SELECTOR).forEach(figure => {
		if (figure.querySelector(':scope > .' + PLAYER_CLASS)) return
		const source = figure.querySelector(SOURCE_SELECTOR)
		if (!source) return

		const container = document.createElement('div')
		container.className = PLAYER_CLASS
		container.setAttribute('data-hl-exclude', '')
		figure.insertBefore(container, figure.firstChild)

		const vnode = h(MediaPlayer, {
			content: playerContent(source),
			posterUrl: figure.querySelector(':scope > img')?.src ?? '',
			onStateChange: state => figure.classList.toggle(PLAYABLE_CLASS, !!state?.playable),
		})
		vnode.appContext = appContext
		render(vnode, container)
		mounted.add(container)
	})
}

/**
 * Räumt alle von mountInlineMedia() angelegten Player ab.
 *
 * @param {Set<Element>} mounted
 */
export function unmountInlineMedia(mounted) {
	for (const container of mounted) {
		render(null, container)
	}
	mounted.clear()
}
