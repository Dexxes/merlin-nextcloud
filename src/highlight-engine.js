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
		mark.merlin-highlight[data-highlight-color="yellow"] { background-color: #fde68a !important; color: inherit !important; }
		mark.merlin-highlight[data-highlight-color="green"]  { background-color: #bbf7d0 !important; color: inherit !important; }
		mark.merlin-highlight[data-highlight-color="blue"]   { background-color: #bfdbfe !important; color: inherit !important; }
		mark.merlin-highlight[data-highlight-color="pink"]   { background-color: #fbcfe8 !important; color: inherit !important; }
		mark.merlin-highlight[data-highlight-color="orange"] { background-color: #fed7aa !important; color: inherit !important; }
	`
	document.head.appendChild(style)
}

// ─── XPath helpers ───────────────────────────────────────────────────────────

function getXPathForNode(node, root) {
	if (node === root) return '.'
	const parts = []
	let current = node
	while (current && current !== root) {
		if (current.nodeType === Node.TEXT_NODE) {
			let index = 0
			let sib = current.previousSibling
			while (sib) {
				if (sib.nodeType === Node.TEXT_NODE) index++
				sib = sib.previousSibling
			}
			parts.unshift(`text()[${index + 1}]`)
		} else {
			const tag = current.nodeName.toLowerCase()
			let index = 1
			let sib = current.previousElementSibling
			while (sib) {
				if (sib.nodeName.toLowerCase() === tag) index++
				sib = sib.previousElementSibling
			}
			parts.unshift(`${tag}[${index}]`)
		}
		current = current.parentNode
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
		const textMatch = part.match(/^text\(\)\[(\d+)\]$/)
		if (textMatch) {
			const targetIdx = parseInt(textMatch[1], 10) - 1
			let count = 0
			let found = null
			for (const child of node.childNodes) {
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
			for (const child of node.children) {
				if (child.nodeName.toLowerCase() === tag) {
					if (count === idx) { found = child; break }
					count++
				}
			}
			node = found
		}
	}
	return node || null
}

// ─── DOM wrapping ─────────────────────────────────────────────────────────────

function createMarkEl(color, highlightId) {
	const span = document.createElement('mark')
	span.className = 'merlin-highlight'
	span.dataset.highlightId = String(highlightId)
	span.dataset.highlightColor = color
	// Inline style as additional reinforcement alongside the CSS class rules
	span.style.backgroundColor = HIGHLIGHT_COLORS.find(c => c.id === color)?.hex ?? '#fde68a'
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

// ─── HighlightEngine class ────────────────────────────────────────────────────

export class HighlightEngine {
	/**
	 * @param {HTMLElement} container
	 * @param {{ onCreate: Function, onDelete: Function }} callbacks
	 */
	constructor(container, { onCreate, onDelete }) {
		this._container = container
		this._onCreate = onCreate
		this._onDelete = onDelete

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
	}

	/** Swap a temp id written by wrapRange with the real server id. */
	updateTempId(tempId, realId) {
		document.querySelectorAll(`mark.merlin-highlight[data-highlight-id="${tempId}"]`)
			.forEach(el => { el.dataset.highlightId = String(realId) })
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
		if (clickedMark) {
			this._removeToolbar()
			this._showDeleteMenu(e.clientX, e.clientY, parseInt(clickedMark.dataset.highlightId, 10))
			return
		}

		const sel = window.getSelection()
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
		if (clickedMark) {
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

		const btn = document.createElement('button')
		btn.type = 'button'
		btn.textContent = 'Markierung entfernen'
		btn.style.cssText = `
			display: block; width: 100%; padding: 8px 14px;
			border: none; background: none; cursor: pointer;
			text-align: left; font-size: 14px; color: #c00;
			white-space: nowrap;
		`
		btn.addEventListener('mouseenter', () => { btn.style.background = '#fee2e2' })
		btn.addEventListener('mouseleave', () => { btn.style.background = 'none' })
		btn.addEventListener('click', () => {
			this._onDelete(highlightId)
			document.querySelectorAll(`mark.merlin-highlight[data-highlight-id="${highlightId}"]`)
				.forEach(el => {
					const parent = el.parentNode
					while (el.firstChild) parent.insertBefore(el.firstChild, el)
					parent.removeChild(el)
					parent.normalize()
				})
			this._removeToolbar()
		})

		menu.appendChild(btn)
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

	_createHighlight(color) {
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
