// Verschachtelte Tags im Browser: jeder Tag hat parentId (null = oberste
// Ebene). Gegenstück zu lib/Service/TagTree.php. Ein Tag, dessen Eltern-Tag
// fehlt, gilt als oberste Ebene, damit er nie aus der Liste verschwindet.

const byName = (a, b) => (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' })

/**
 * Kinder je Eltern-Id, nach Name sortiert. Schlüssel null = oberste Ebene.
 *
 * @param {Array} tags alle Tags des Nutzers
 * @return {Map<number|null, Array>}
 */
export function childrenMap(tags) {
	const ids = new Set(tags.map(t => t.id))
	const map = new Map()
	for (const tag of tags) {
		const parent = tag.parentId != null && ids.has(tag.parentId) ? tag.parentId : null
		if (!map.has(parent)) map.set(parent, [])
		map.get(parent).push(tag)
	}
	for (const list of map.values()) list.sort(byName)
	return map
}

/**
 * Ids aller Nachfahren von tagId (ohne tagId selbst).
 *
 * @param {Array} tags alle Tags des Nutzers
 * @param {number} tagId Tag
 * @param {Map} [children] vorberechnete childrenMap(tags)
 * @return {number[]}
 */
export function descendantIds(tags, tagId, children = childrenMap(tags)) {
	const result = []
	const seen = new Set([tagId])
	const queue = [...(children.get(tagId) || [])]
	while (queue.length) {
		const tag = queue.shift()
		if (seen.has(tag.id)) continue
		seen.add(tag.id)
		result.push(tag.id)
		queue.push(...(children.get(tag.id) || []))
	}
	return result
}

/**
 * Alle Tags in Baumreihenfolge (Eltern vor Kindern, Geschwister nach Name)
 * mit Tiefe, z. B. für eingerückte Auswahllisten.
 *
 * @param {Array} tags alle Tags des Nutzers
 * @return {Array<{tag: object, depth: number}>}
 */
export function flattenTree(tags) {
	const children = childrenMap(tags)
	const result = []
	const seen = new Set()
	const walk = (parent, depth) => {
		for (const tag of children.get(parent) || []) {
			if (seen.has(tag.id)) continue
			seen.add(tag.id)
			result.push({ tag, depth })
			walk(tag.id, depth + 1)
		}
	}
	walk(null, 0)
	return result
}

/**
 * Pfad eines Tags von der obersten Ebene bis zu ihm, z. B. "Reisen › Japan".
 *
 * @param {Array} tags alle Tags des Nutzers
 * @param {object} tag Tag
 * @return {string}
 */
export function tagPath(tags, tag) {
	const byId = new Map(tags.map(t => [t.id, t]))
	const names = [tag.name]
	const seen = new Set([tag.id])
	let parent = byId.get(tag.parentId)
	while (parent && !seen.has(parent.id)) {
		seen.add(parent.id)
		names.unshift(parent.name)
		parent = byId.get(parent.parentId)
	}
	return names.join(' › ')
}
