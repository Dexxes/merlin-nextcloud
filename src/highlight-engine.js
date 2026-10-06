/**
 * Merlin Highlight Engine
 *
 * Shows a floating color-picker toolbar above the text selection after mouseup.
 * Right-clicking an existing highlight shows a delete option.
 *
 * Usage:
 *   const engine = new HighlightEngine(containerEl, callbacks)
 *   engine.applyHighlights(highlightsArray)   // restore saved highlights
 *   engine.destroy()                           // clean up listeners
 */

const HIGHLIGHT_COLORS = [
	{ id: 'yellow', hex: '#fde68a' },
	{ id: 'green',  hex: '#bbf7d0' },
	{ id: 'blue',   hex: '#bfdbfe' },
	{ id: 'pink',   hex: '#fbcfe8' },
	{ id: 'orange', hex: '#fed7aa' },
]

// Inject shared CSS once (keyframes + mark colours with !important so Nextcloud
// resets cannot override the highlight background-colours)
if (typeof document !== 'undefined' && !document.getElementById('merlin-hl-style')) {
	const style = document.createElement('style')
	style.id = 'merlin-hl-style'
	style.textContent = `
		@keyframes merlinHlMenuIn {
			from { opacity:0; transform:scale(0.92) translateY(-4px); }
			to   { opacity:1; transform:scale(1)    translateY(0); }
		}
		mark.merlin-highlight {
			border-radius: 2px !important;
			padding: 0 1px !important;
			cursor: pointer !important;
			box-decoration-break: clone !important;
			-webkit-box-decoration-break: clone !important;
			display: inline !important;
		}
		/* Alle fünf Highlight-Farben sind helle Pastelltöne, daher fixe dunkle
		   Schrift statt color:inherit – im Dark-Theme ist die geerbte Schrift
		   fast weiß und auf dem hellen Hintergrund unlesbar. #1c1c1e = Light-
		   Theme-Textfarbe der Apps (identisch in iOS/iPad/Android gelöst). */
		mark.merlin-highlight[data-highlight-color="yellow"] { background-color: #fde68a !important; color: #1c1c1e !important; }
		mark.merlin-highlight[data-highlight-color="green"]  { background-color: #bbf7d0 !important; color: #1c1c1e !important; }
		mark.merlin-highlight[data-highlight-color="blue"]   { background-color: #bfdbfe !important; color: #1c1c1e !important; }
		mark.merlin-highlight[data-highlight-color="pink"]   { background-color: #fbcfe8 !important; color: #1c1c1e !important; }
		mark.merlin-highlight[data-highlight-color="orange"] { background-color: #fed7aa !important; color: #1c1c1e !important; }
		/* Kommentierte Textstelle ohne Markierungsfarbe: unterstrichen statt
		   eingefärbt, Schrift bleibt wie im Text. Die Linie trägt die Farbe des
		   Verfassers (--mh-author, siehe _paintCommentCounts). */
		mark.merlin-highlight[data-highlight-color="comment"] {
			background-color: transparent !important;
			color: inherit !important;
			padding: 0 !important;
			text-decoration: underline !important;
			text-decoration-color: var(--mh-author, #c2410c) !important;
			text-decoration-thickness: 2px !important;
			text-underline-offset: 3px !important;
		}
		/* Kommentar-Hinweis: nur am letzten <mark> einer Markierung (data-comment-tail),
		   damit eine über mehrere Absätze gehende Markierung ein Symbol trägt. */
		mark.merlin-highlight[data-comment-tail]::after {
			content: attr(data-comment-count);
			display: inline-block;
			margin-left: 3px;
			padding: 0 5px;
			min-width: 8px;
			border-radius: 8px 8px 8px 2px;
			background: var(--mh-author, #1c1c1e);
			color: #fff;
			font-size: 0.7em;
			line-height: 1.5;
			font-weight: 600;
			vertical-align: super;
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
		}
		mark.merlin-highlight.merlin-highlight--flash {
			outline: 2px solid #1c1c1e !important;
			outline-offset: 1px;
		}
	`
	document.head.appendChild(style)
}

// ─── XPath helpers ───────────────────────────────────────────────────────────
//
// XPaths are generated on iOS/Android against the *raw* article-content HTML,
// injected as a flat run of top-level nodes directly under a bare container
// (no extra wrapper elements). merlin-nextcloud's authenticated reader
// (ArticleReader.vue) instead splits that same content into two separate
// `v-html` wrapper <div>s (hero image / rest) so a <MediaPlayer> can be
// inserted between them — an extra level of nesting, plus a sibling element
// that doesn't exist in the original content at all. Resolving a
// cross-platform XPath directly against that live DOM would look for e.g.
// `p[3]` among `.article-body`'s direct children (two wrapper <div>s) and
// never find it.
//
// To keep XPaths resolvable across platforms without duplicating the content
// HTML, elements marked `data-hl-flatten` are transparent for indexing
// purposes — their children are promoted to their parent's child list — and
// elements marked `data-hl-exclude` (e.g. the video player) are omitted
// entirely, as if they weren't in the tree. Markup with neither attribute
// behaves exactly as before (plain childNodes/parentNode traversal).

const FLATTEN_ATTR = 'data-hl-flatten'
const EXCLUDE_ATTR = 'data-hl-exclude'

function isFlatten(el) {
	return el.nodeType === Node.ELEMENT_NODE && el.hasAttribute(FLATTEN_ATTR)
}

function isExcluded(el) {
	return el.nodeType === Node.ELEMENT_NODE && el.hasAttribute(EXCLUDE_ATTR)
}

/** `node`'s children as they'd appear in the reference (cross-platform) content:
 * excluded subtrees dropped, flatten wrappers replaced by their own effective
 * children (recursively), everything else unchanged. */
function effectiveChildren(node) {
	const result = []
	for (const child of node.childNodes) {
		if (isExcluded(child)) continue
		if (isFlatten(child)) result.push(...effectiveChildren(child))
		else result.push(child)
	}
	return result
}

/** Nearest ancestor whose effective-children list `node` itself appears in —
 * i.e. skips past any chain of flatten wrappers directly enclosing `node`. */
function effectiveParent(node) {
	let parent = node.parentNode
	while (parent && isFlatten(parent)) parent = parent.parentNode
	return parent
}

function getXPathForNode(node, root) {
	if (node === root) return '.'
	const parts = []
	let current = node
	while (current && current !== root) {
		const parent = effectiveParent(current)
		if (!parent) return null
		const siblings = effectiveChildren(parent)
		if (current.nodeType === Node.TEXT_NODE) {
			let index = 0
			for (const sib of siblings) {
				if (sib === current) break
				if (sib.nodeType === Node.TEXT_NODE) index++
			}
			parts.unshift(`text()[${index + 1}]`)
		} else {
			const tag = current.nodeName.toLowerCase()
			let index = 0
			for (const sib of siblings) {
				if (sib === current) break
				if (sib.nodeType === Node.ELEMENT_NODE && sib.nodeName.toLowerCase() === tag) index++
			}
			parts.unshift(`${tag}[${index + 1}]`)
		}
		current = parent
	}
	if (!current) return null
	return parts.join('/')
}

function resolveXPath(xpath, root) {
	if (xpath === '.') return root
	const parts = xpath.split('/')
	let node = root
	for (const part of parts) {
		if (!node) return null
		const children = effectiveChildren(node)
		const textMatch = part.match(/^text\(\)\[(\d+)\]$/)
		if (textMatch) {
			const targetIdx = parseInt(textMatch[1], 10) - 1
			let count = 0
			let found = null
			for (const child of children) {
				if (child.nodeType === Node.TEXT_NODE) {
					if (count === targetIdx) { found = child; break }
					count++
				}
			}
			node = found
		} else {
			const elemMatch = part.match(/^([a-z0-9]+)\[(\d+)\]$/i)
			if (!elemMatch) return null
			const tag = elemMatch[1].toLowerCase()
			const idx = parseInt(elemMatch[2], 10) - 1
			let count = 0
			let found = null
			for (const child of children) {
				if (child.nodeType === Node.ELEMENT_NODE && child.nodeName.toLowerCase() === tag) {
					if (count === idx) { found = child; break }
					count++
				}
			}
			node = found
		}
	}
	return node || null
}

/** Farbe einer Stelle, die nur kommentiert (nicht markiert) wurde. */
export const COMMENT_COLOR = 'comment'

// ─── DOM wrapping ─────────────────────────────────────────────────────────────

function createMarkEl(color, highlightId) {
	const span = document.createElement('mark')
	span.className = 'merlin-highlight'
	span.dataset.highlightId = String(highlightId)
	span.dataset.highlightColor = color
	// Kommentar-Stelle: nur unterstrichen (CSS oben), keine Hintergrundfarbe.
	if (color === COMMENT_COLOR) return span
	// Inline style as additional reinforcement alongside the CSS class rules
	span.style.backgroundColor = HIGHLIGHT_COLORS.find(c => c.id === color)?.hex ?? '#fde68a'
	// Fixe dunkle Schrift auch inline (siehe CSS-Kommentar oben): im Dark-Theme
	// wäre die geerbte, fast weiße Textfarbe auf dem Pastell-Grund unlesbar.
	span.style.color = '#1c1c1e'
	return span
}

/**
 * Wraps all text portions of a Range in <mark> elements using splitText +
 * manual DOM insertion — avoids surroundContents() which throws whenever the
 * selection partially overlaps any inline element (<a>, <em>, <strong>, …).
 *
 * Algorithm per text node:
 *   1. trim the tail  (split at endOffset)  — offsets stay valid
 *   2. trim the head  (split at startOffset) — returns the highlighted slice
 *   3. insertBefore + appendChild           — wrap slice in <mark>
 */
function wrapRange(range, color, highlightId) {
	const marks = []
	if (range.collapsed) return marks

	// Collect all text nodes that fall inside the range
	const root = range.commonAncestorContainer.nodeType === Node.TEXT_NODE
		? range.commonAncestorContainer.parentNode
		: range.commonAncestorContainer

	const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
	const textNodes = []
	let n
	while ((n = walker.nextNode())) {
		if (range.intersectsNode(n)) textNodes.push(n)
	}

	for (let i = 0; i < textNodes.length; i++) {
		let tn = textNodes[i]
		const isFirst = i === 0
		const isLast  = i === textNodes.length - 1

		const startOff = isFirst && tn === range.startContainer ? range.startOffset : 0
		const endOff   = isLast  && tn === range.endContainer   ? range.endOffset   : tn.length

		if (startOff >= endOff) continue  // nothing to highlight in this node

		// Step 1: trim tail — split off everything after endOff
		if (endOff < tn.length) tn.splitText(endOff)

		// Step 2: trim head — split off everything before startOff
		//         splitText returns the new node starting at startOff
		const slice = startOff > 0 ? tn.splitText(startOff) : tn

		// Step 3: wrap slice in <mark>
		const mark = createMarkEl(color, highlightId)
		slice.parentNode.insertBefore(mark, slice)
		mark.appendChild(slice)
		marks.push(mark)
	}

	return marks
}

/** Entfernt alle <mark>-Elemente einer Markierung (oder alle) aus dem Container. */
function unwrapMarks(container, highlightId = null) {
	const selector = highlightId === null
		? 'mark.merlin-highlight'
		: `mark.merlin-highlight[data-highlight-id="${highlightId}"]`
	const parents = new Set()
	container.querySelectorAll(selector).forEach(el => {
		const parent = el.parentNode
		while (el.firstChild) parent.insertBefore(el.firstChild, el)
		parent.removeChild(el)
		parents.add(parent)
	})
	parents.forEach(p => p.normalize())
}

// ─── HighlightEngine class ────────────────────────────────────────────────────

export class HighlightEngine {
	/**
	 * @param {HTMLElement} container
	 * @param {object} callbacks
	 * @param {Function} callbacks.onCreate neue Markierung ({ …, tempId })
	 * @param {Function} [callbacks.onCommentSelection] „Kommentieren“ an einer
	 *        Textauswahl ({ highlightedText, startXpath, startOffset, endXpath, endOffset }).
	 *        Im Text ändert sich dabei nichts; die Stelle wird erst mit dem
	 *        abgeschickten Kommentar gespeichert und dann unterstrichen.
	 * @param {Function} callbacks.onDelete Markierung löschen (id)
	 * @param {Function} [callbacks.onOpenComments] Thread einer Markierung öffnen (id)
	 * @param {Function} [callbacks.canDelete] darf diese Markierung gelöscht werden? (id) => bool
	 * @param {Function} [callbacks.describe] Zusatzzeile im Menü, z. B. „Markiert von Anna“ (id) => string|null
	 * @param {Function} [callbacks.canCreate] darf gerade markiert werden? () => bool
	 * @param {Function} [callbacks.t] Übersetzungsfunktion (Text) => Text
	 */
	constructor(container, { onCreate, onDelete, onOpenComments = null, onCommentSelection = null, canDelete = null, describe = null, canCreate = null, t = null }) {
		this._container = container
		this._onCreate = onCreate
		this._onCommentSelection = onCommentSelection
		this._onDelete = onDelete
		this._onOpenComments = onOpenComments
		this._canDelete = canDelete ?? (() => true)
		this._describe = describe ?? (() => null)
		this._canCreate = canCreate ?? (() => true)
		this._t = t ?? (text => text)
		this._highlights = []
		this._commentCounts = {}

		this._toolbar = null        // floating colour-picker element
		this._pendingRange = null   // cloned selection range

		this._onMouseUp      = this._handleMouseUp.bind(this)
		this._onContextMenu  = this._handleContextMenu.bind(this)
		this._onDocMouseDown = this._handleDocMouseDown.bind(this)

		// mouseup on document so we catch drags that end outside the container
		document.addEventListener('mouseup', this._onMouseUp)
		container.addEventListener('contextmenu', this._onContextMenu)
		// mousedown anywhere outside the toolbar dismisses it
		document.addEventListener('mousedown', this._onDocMouseDown, true)
	}

	destroy() {
		document.removeEventListener('mouseup', this._onMouseUp)
		this._container.removeEventListener('contextmenu', this._onContextMenu)
		document.removeEventListener('mousedown', this._onDocMouseDown, true)
		this._removeToolbar()
	}

	/** Apply an array of saved highlights from the server. */
	applyHighlights(highlights) {
		for (const h of highlights) {
			this._restoreHighlight(h)
		}
		this._highlights = [...highlights]
		this._paintCommentCounts()
	}

	/**
	 * Ersetzt alle Markierungen durch den Stand vom Server (Push-Kanal). Alle
	 * <mark> werden entfernt und in Erstellungsreihenfolge neu gesetzt – in
	 * dieser Reihenfolge wurden auch ihre XPaths berechnet. Markierungen, die
	 * hier gerade erst angelegt und noch nicht bestätigt sind (temporäre ID),
	 * bleiben dabei unberührt im DOM.
	 */
	setHighlights(highlights) {
		const known = new Set(highlights.map(h => String(h.id)))
		const pending = Array.from(this._container.querySelectorAll('mark.merlin-highlight'))
			.some(el => !known.has(el.dataset.highlightId) && !this._highlights.some(h => String(h.id) === el.dataset.highlightId))
		if (pending) {
			// Eine eigene Markierung wartet noch auf ihre Server-ID: nicht neu
			// zeichnen, sonst ginge sie verloren. Der nächste Push holt es nach.
			return false
		}
		const same = highlights.length === this._highlights.length
			&& highlights.every((h, i) => String(h.id) === String(this._highlights[i]?.id))
		if (!same) {
			unwrapMarks(this._container)
			for (const h of highlights) {
				this._restoreHighlight(h)
			}
		}
		this._highlights = [...highlights]
		this._paintCommentCounts()
		return true
	}

	/** Anzahl Kommentare je Markierung ({ [highlightId]: n }) als Hinweis anzeigen. */
	setCommentCounts(counts) {
		this._commentCounts = { ...counts }
		this._paintCommentCounts()
	}

	/** Zur Markierung scrollen und sie kurz hervorheben. */
	focusHighlight(highlightId) {
		const marks = this._container.querySelectorAll(`mark.merlin-highlight[data-highlight-id="${highlightId}"]`)
		if (!marks.length) return false
		marks[0].scrollIntoView({ behavior: 'smooth', block: 'center' })
		marks.forEach(el => el.classList.add('merlin-highlight--flash'))
		setTimeout(() => marks.forEach(el => el.classList.remove('merlin-highlight--flash')), 1600)
		return true
	}

	/**
	 * Verfasser-Farbe (`authorColor` vom Server) als CSS-Variable an jedes
	 * <mark>: färbt Unterstreichung und Zähler. Läuft bei jedem Stand neu,
	 * damit auch eine später vergebene Gast-Farbe ankommt.
	 */
	_paintAuthorColors() {
		const colors = new Map()
		for (const h of this._highlights) {
			if (typeof h.authorColor === 'string' && /^#[0-9a-f]{6}$/i.test(h.authorColor)) {
				colors.set(String(h.id), h.authorColor)
			}
		}
		this._container.querySelectorAll('mark.merlin-highlight').forEach(el => {
			const color = colors.get(el.dataset.highlightId)
			if (color) el.style.setProperty('--mh-author', color)
			else el.style.removeProperty('--mh-author')
		})
	}

	_paintCommentCounts() {
		this._paintAuthorColors()
		this._container.querySelectorAll('mark.merlin-highlight[data-comment-tail]').forEach(el => {
			el.removeAttribute('data-comment-tail')
			el.removeAttribute('data-comment-count')
		})
		for (const [id, count] of Object.entries(this._commentCounts)) {
			if (!count) continue
			const marks = this._container.querySelectorAll(`mark.merlin-highlight[data-highlight-id="${id}"]`)
			const last = marks[marks.length - 1]
			if (last) {
				last.dataset.commentTail = ''
				last.dataset.commentCount = String(count)
			}
		}
	}

	/** Swap a temp id written by wrapRange with the real server id. */
	updateTempId(tempId, realId, highlight = null) {
		document.querySelectorAll(`mark.merlin-highlight[data-highlight-id="${tempId}"]`)
			.forEach(el => { el.dataset.highlightId = String(realId) })
		if (highlight && !this._highlights.some(h => String(h.id) === String(realId))) {
			this._highlights.push(highlight)
		}
	}

	/** Eine angelegte Markierung zurücknehmen, z. B. wenn der Server sie ablehnt. */
	removeHighlight(highlightId) {
		unwrapMarks(this._container, highlightId)
		this._highlights = this._highlights.filter(h => String(h.id) !== String(highlightId))
	}

	// ── private ──────────────────────────────────────────────────────────────

	_handleMouseUp(e) {
		// Clicks INSIDE the toolbar (e.g. a colour button) must not disturb
		// _pendingRange or the toolbar — _createHighlight handles clean-up.
		if (this._toolbar && this._toolbar.contains(e.target)) return

		// Don't show for right-clicks (handled by contextmenu)
		if (e.button !== 0) return

		// Left-click on an existing highlight → show delete menu
		const clickedMark = e.target.closest?.('mark.merlin-highlight')
		const sel = window.getSelection()
		if (clickedMark && this._container.contains(clickedMark) && (!sel || sel.isCollapsed)) {
			this._removeToolbar()
			const id = parseInt(clickedMark.dataset.highlightId, 10)
			// Unterstrichene Kommentar-Stelle: gleich die Kommentare zeigen.
			if (clickedMark.dataset.highlightColor === COMMENT_COLOR && this._onOpenComments) {
				this._onOpenComments(id)
				return
			}
			this._showDeleteMenu(e.clientX, e.clientY, id)
			return
		}

		if (!this._canCreate()) return
		if (!sel || sel.isCollapsed || sel.rangeCount === 0) return
		const range = sel.getRangeAt(0)
		if (!this._container.contains(range.commonAncestorContainer)) return

		// Clone BEFORE _showColorToolbar, which calls _removeToolbar() internally
		// and would clear _pendingRange if we set it first.
		const cloned = range.cloneRange()
		this._showColorToolbar(range)   // calls _removeToolbar() → _pendingRange = null
		this._pendingRange = cloned     // restore after the clear
	}

	_handleContextMenu(e) {
		// Right-clicking an existing mark → show delete option
		const clickedMark = e.target.closest?.('mark.merlin-highlight')
		if (clickedMark && this._container.contains(clickedMark)) {
			e.preventDefault()
			this._removeToolbar()
			this._showDeleteMenu(e.clientX, e.clientY, parseInt(clickedMark.dataset.highlightId, 10))
		}
	}

	_handleDocMouseDown(e) {
		if (this._toolbar && !this._toolbar.contains(e.target)) {
			this._removeToolbar()
		}
	}

	// ── colour toolbar (shown above selection) ───────────────────────────────

	_showColorToolbar(range) {
		this._removeToolbar()

		const toolbar = document.createElement('div')
		toolbar.className = 'merlin-highlight-toolbar'
		toolbar.style.cssText = `
			position: fixed;
			display: flex;
			align-items: center;
			gap: 4px;
			padding: 5px 8px;
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 20px;
			box-shadow: 0 2px 12px rgba(0,0,0,.18);
			z-index: 99999;
			animation: merlinHlMenuIn .12s ease;
			pointer-events: all;
		`

		for (const color of HIGHLIGHT_COLORS) {
			const btn = document.createElement('button')
			btn.type = 'button'
			btn.title = color.id
			btn.style.cssText = `
				width: 22px; height: 22px; border-radius: 50%;
				border: 2px solid transparent;
				background: ${color.hex};
				cursor: pointer; padding: 0; flex-shrink: 0;
				transition: transform .1s, border-color .1s;
			`
			btn.addEventListener('mouseenter', () => {
				btn.style.transform = 'scale(1.25)'
				btn.style.borderColor = '#666'
			})
			btn.addEventListener('mouseleave', () => {
				btn.style.transform = ''
				btn.style.borderColor = 'transparent'
			})
			btn.addEventListener('mousedown', (ev) => {
				ev.preventDefault() // keep selection alive
				ev.stopPropagation()
			})
			btn.addEventListener('click', (ev) => {
				ev.stopPropagation()
				this._createHighlight(color.id)
			})
			toolbar.appendChild(btn)
		}

		if (this._onOpenComments) {
			const divider = document.createElement('span')
			divider.style.cssText = 'width:1px;height:18px;background:#e0e0e0;margin:0 2px;'
			toolbar.appendChild(divider)

			const commentBtn = document.createElement('button')
			commentBtn.type = 'button'
			commentBtn.textContent = this._t('Comment')
			commentBtn.title = this._t('Comment on this passage')
			commentBtn.style.cssText = `
				border: none; background: none; cursor: pointer;
				padding: 2px 6px; font-size: 13px; color: #1c1c1e;
				white-space: nowrap; border-radius: 10px;
			`
			commentBtn.addEventListener('mouseenter', () => { commentBtn.style.background = '#f0f0f0' })
			commentBtn.addEventListener('mouseleave', () => { commentBtn.style.background = 'none' })
			commentBtn.addEventListener('mousedown', (ev) => {
				ev.preventDefault()
				ev.stopPropagation()
			})
			commentBtn.addEventListener('click', (ev) => {
				ev.stopPropagation()
				this._startComment()
			})
			toolbar.appendChild(commentBtn)
		}

		document.body.appendChild(toolbar)
		this._toolbar = toolbar
		this._positionToolbar(toolbar, range)
	}

	_positionToolbar(toolbar, range) {
		const rect = range.getBoundingClientRect()
		const tbRect = toolbar.getBoundingClientRect()

		let left = rect.left + (rect.width / 2) - (tbRect.width / 2)
		let top  = rect.top - tbRect.height - 8

		// Flip below if too close to top
		if (top < 8) top = rect.bottom + 8

		// Keep within horizontal viewport
		left = Math.max(8, Math.min(left, window.innerWidth - tbRect.width - 8))

		toolbar.style.left = `${left}px`
		toolbar.style.top  = `${top}px`
	}

	// ── delete menu ──────────────────────────────────────────────────────────

	_showDeleteMenu(x, y, highlightId) {
		this._removeToolbar()

		const menu = document.createElement('div')
		menu.className = 'merlin-highlight-toolbar'
		menu.style.cssText = `
			position: fixed; left: ${x}px; top: ${y}px;
			background: #fff; border: 1px solid #e0e0e0;
			border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,.15);
			z-index: 99999; overflow: hidden;
			animation: merlinHlMenuIn .1s ease;
		`

		const description = this._describe(highlightId)
		if (description) {
			const info = document.createElement('div')
			info.textContent = description
			info.style.cssText = `
				padding: 8px 14px 4px; font-size: 12px; color: #666;
				white-space: nowrap;
			`
			menu.appendChild(info)
		}

		const addItem = (label, color, hover, onClick) => {
			const btn = document.createElement('button')
			btn.type = 'button'
			btn.textContent = label
			btn.style.cssText = `
				display: block; width: 100%; padding: 8px 14px;
				border: none; background: none; cursor: pointer;
				text-align: left; font-size: 14px; color: ${color};
				white-space: nowrap;
			`
			btn.addEventListener('mouseenter', () => { btn.style.background = hover })
			btn.addEventListener('mouseleave', () => { btn.style.background = 'none' })
			btn.addEventListener('click', onClick)
			menu.appendChild(btn)
		}

		if (this._onOpenComments) {
			const count = this._commentCounts[highlightId] || 0
			const label = count > 0
				? this._t('Comments ({count})').replace('{count}', String(count))
				: this._t('Comment')
			addItem(label, '#1c1c1e', '#f0f0f0', () => {
				this._removeToolbar()
				this._onOpenComments(highlightId)
			})
		}

		if (this._canDelete(highlightId)) {
			addItem(this._t('Remove highlight'), '#c00', '#fee2e2', () => {
				this._onDelete(highlightId)
				unwrapMarks(this._container, highlightId)
				this._highlights = this._highlights.filter(h => String(h.id) !== String(highlightId))
				this._removeToolbar()
			})
		}

		if (!menu.childElementCount) return

		document.body.appendChild(menu)
		this._toolbar = menu

		// Keep within viewport
		requestAnimationFrame(() => {
			const r = menu.getBoundingClientRect()
			if (r.right  > window.innerWidth  - 8) menu.style.left = `${x - r.width}px`
			if (r.bottom > window.innerHeight - 8) menu.style.top  = `${y - r.height}px`
		})
	}

	_removeToolbar() {
		if (this._toolbar) {
			this._toolbar.remove()
			this._toolbar = null
		}
		this._pendingRange = null
	}

	// ── create highlight ─────────────────────────────────────────────────────

	/**
	 * „Kommentieren“: Stelle nur ausmessen und an den Kommentar-Dialog geben –
	 * nichts einfärben. Ohne onCommentSelection wie bisher gelb markieren.
	 */
	_startComment() {
		if (!this._onCommentSelection) {
			this._createHighlight('yellow', true)
			return
		}
		const range = this._pendingRange
		this._removeToolbar()
		if (!range || range.collapsed) return
		const startXpath = getXPathForNode(range.startContainer, this._container)
		const endXpath   = getXPathForNode(range.endContainer,   this._container)
		const highlightedText = range.toString().trim()
		if (!startXpath || !endXpath || !highlightedText) return
		window.getSelection()?.removeAllRanges()
		this._onCommentSelection({
			highlightedText,
			startXpath,
			startOffset: range.startOffset,
			endXpath,
			endOffset: range.endOffset,
		})
	}

	_createHighlight(color, comment = false) {
		const range = this._pendingRange
		this._removeToolbar()
		if (!range || range.collapsed) return

		const startXpath = getXPathForNode(range.startContainer, this._container)
		const endXpath   = getXPathForNode(range.endContainer,   this._container)
		if (!startXpath || !endXpath) return

		const highlightedText = range.toString().trim()
		if (!highlightedText) return

		// Capture offsets BEFORE wrapRange modifies the DOM (surroundContents
		// moves nodes, which invalidates the original range offsets)
		const startOffset = range.startOffset
		const endOffset   = range.endOffset

		const tempId = Date.now()
		wrapRange(range, color, tempId)
		window.getSelection()?.removeAllRanges()

		this._onCreate({
			highlightedText,
			startXpath,
			startOffset,
			endXpath,
			endOffset,
			color,
			tempId,
			comment,
		})
	}

	/** Re-apply a single saved highlight from the server to the DOM. */
	_restoreHighlight(h) {
		const startNode = resolveXPath(h.startXpath, this._container)
		const endNode   = resolveXPath(h.endXpath,   this._container)
		if (!startNode || !endNode) return

		try {
			const range = document.createRange()
			range.setStart(startNode, h.startOffset)
			range.setEnd(endNode, h.endOffset)
			if (!range.collapsed) {
				wrapRange(range, h.color, h.id)
			}
		} catch {
			// Stale highlight (article text changed) — silently skip
		}
	}
}
