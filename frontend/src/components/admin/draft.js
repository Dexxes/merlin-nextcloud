/**
 * Hilfsfunktionen für die Builder-Struktur eines Custom-Filters.
 *
 * Die Struktur ist identisch zu dem, was ContentFilterSerializer auf der
 * Serverseite liest und schreibt. Sie wird hier nur vervollständigt: der Server
 * lässt leere Sektionen weg, die Oberfläche braucht sie als leere Listen, damit
 * v-model direkt darauf arbeiten kann.
 */

/** Sektionen, die als Liste von Regeln geführt werden. */
export function isListSection(schema, section) {
	const def = schema && schema.sections ? schema.sections[section] : null
	if (!def) {
		return false
	}
	return def.kind === 'list' || def.kind === 'list-keyed'
		|| def.kind === 'field-group' || def.kind === 'root-list-keyed'
}

/** Sektionen, die einen einzelnen Textwert haben (category, note). */
export function isTextSection(schema, section) {
	const def = schema && schema.sections ? schema.sections[section] : null
	return !!def && def.kind === 'root-text'
}

/** Leerer Entwurf mit allen Sektionen des Schemas. */
export function emptyDraft(schema) {
	const draft = {
		disable: { sections: [], rules: {} },
	}
	if (!schema || !schema.sections) {
		return draft
	}
	Object.keys(schema.sections).forEach(section => {
		if (isTextSection(schema, section)) {
			draft[section] = ''
		} else {
			draft[section] = []
		}
	})
	return draft
}

/**
 * Baut aus der Serverantwort (`custom` aus GET /content-filters/{domain}) einen
 * vollständigen Entwurf.
 */
export function normalizeDraft(custom, schema) {
	const draft = emptyDraft(schema)
	const rules = custom && custom.rules ? custom.rules : null
	if (!rules) {
		return draft
	}

	Object.keys(draft).forEach(section => {
		if (section === 'disable') {
			return
		}
		const value = rules[section]
		if (isTextSection(schema, section)) {
			draft[section] = typeof value === 'string' ? value : ''
		} else if (Array.isArray(value)) {
			// origin wird bewusst nicht mitgeschleppt: der Server setzt es beim
			// Merge, und beim Speichern wäre es ein verbotenes Attribut.
			draft[section] = value.map(rule => ({
				element: rule.element,
				attributes: { ...(rule.attributes || {}) },
			}))
		}
	})

	if (rules.disable) {
		draft.disable = {
			sections: Array.isArray(rules.disable.sections) ? [...rules.disable.sections] : [],
			rules: {},
		}
		const disableRules = rules.disable.rules || {}
		Object.keys(disableRules).forEach(section => {
			draft.disable.rules[section] = (disableRules[section] || []).map(rule => ({
				element: rule.element,
				attributes: { ...(rule.attributes || {}) },
			}))
		})
	}

	return draft
}

/** Zwei Attributsätze auf Gleichheit prüfen (Reihenfolge irrelevant). */
export function sameAttributes(a = {}, b = {}) {
	const keysA = Object.keys(a).filter(k => a[k] !== '' && a[k] !== undefined)
	const keysB = Object.keys(b).filter(k => b[k] !== '' && b[k] !== undefined)
	if (keysA.length !== keysB.length) {
		return false
	}
	return keysA.every(key => String(a[key]) === String(b[key]))
}

/** Ist diese Bundle-Regel im Entwurf abgeschaltet? */
export function isDisabled(draft, section, rule) {
	const entries = (draft.disable && draft.disable.rules && draft.disable.rules[section]) || []
	return entries.some(entry =>
		entry.element === rule.element && sameAttributes(entry.attributes, rule.attributes),
	)
}

/**
 * Schaltet eine einzelne Bundle-Regel ab bzw. wieder ein.
 *
 * Geschrieben wird der vollständige Attributsatz der Bundle-Regel, damit der
 * Teilmengen-Vergleich auf dem Server genau diese eine Regel trifft und nicht
 * versehentlich gleichnamige Nachbarn.
 */
export function toggleDisabled(draft, section, rule) {
	if (!draft.disable) {
		draft.disable = { sections: [], rules: {} }
	}
	if (!draft.disable.rules[section]) {
		draft.disable.rules[section] = []
	}
	const entries = draft.disable.rules[section]
	const index = entries.findIndex(entry =>
		entry.element === rule.element && sameAttributes(entry.attributes, rule.attributes),
	)
	if (index >= 0) {
		entries.splice(index, 1)
		if (entries.length === 0) {
			delete draft.disable.rules[section]
		}
	} else {
		entries.push({ element: rule.element, attributes: { ...(rule.attributes || {}) } })
	}
}

/** Ist die ganze Bundle-Sektion abgeschaltet? */
export function isSectionDisabled(draft, section) {
	return !!draft.disable && Array.isArray(draft.disable.sections)
		&& draft.disable.sections.includes(section)
}

export function toggleSectionDisabled(draft, section) {
	if (!draft.disable) {
		draft.disable = { sections: [], rules: {} }
	}
	if (!Array.isArray(draft.disable.sections)) {
		draft.disable.sections = []
	}
	const index = draft.disable.sections.indexOf(section)
	if (index >= 0) {
		draft.disable.sections.splice(index, 1)
	} else {
		draft.disable.sections.push(section)
	}
}

/** Erste erlaubte Regelart einer Sektion (Vorbelegung für neue Regeln). */
export function defaultElement(schema, section) {
	const def = schema && schema.sections ? schema.sections[section] : null
	if (!def || !def.children) {
		return ''
	}
	const names = Object.keys(def.children)
	return names.length ? names[0] : ''
}

/** Alle Attributnamen, die eine Regelart tragen darf. */
export function attributeNames(schema, section, element) {
	const def = schema && schema.sections ? schema.sections[section] : null
	const child = def && def.children ? def.children[element] : null
	if (!child) {
		return { required: [], optional: [], oneOf: [] }
	}
	return {
		required: child.required || [],
		optional: child.optional || [],
		oneOf: child.oneOf || [],
	}
}
