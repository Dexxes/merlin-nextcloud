// Plattform-Schicht (Plan „gemeinsames Repo“, Etappe 5): außerhalb von
// src/platform/ darf die Vue-App nichts aus @nextcloud/* importieren, außer
// den Komponenten aus @nextcloud/vue. Sonst liefe der Standalone-Build
// später gegen Nextcloud-Globals (OC, requesttoken, /index.php/apps/…).
//
// Aufruf: node frontend/tools/check-platform-imports.mjs
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const src = fileURLToPath(new URL('../src', import.meta.url))
const platform = join(src, 'platform')
const allowed = /^@nextcloud\/vue(\/|$)/
const pattern = /(?:from\s+|import\s*\(\s*|require\s*\(\s*|^\s*import\s+)['"](@nextcloud\/[^'"]+)['"]/gm

function* files(dir) {
	for (const name of readdirSync(dir)) {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) {
			if (path !== platform) {
				yield* files(path)
			}
		} else if (/\.(js|mjs|vue|ts)$/.test(name)) {
			yield path
		}
	}
}

const errors = []
let checked = 0
for (const file of files(src)) {
	checked++
	const text = readFileSync(file, 'utf8')
	for (const match of text.matchAll(pattern)) {
		if (!allowed.test(match[1])) {
			const line = text.slice(0, match.index).split('\n').length
			errors.push(`${relative(src, file)}:${line}: ${match[1]} nur über src/platform/ importieren`)
		}
	}
	if (/\b(?:window\.)?OC\.|\/apps\/merlin\b/.test(text.replace(/\/\/.*$|\/\*[\s\S]*?\*\//gm, ''))) {
		errors.push(`${relative(src, file)}: OC.* oder /apps/merlin nur in src/platform/ (appUrl(), imageUrl())`)
	}
}

if (errors.length > 0) {
	console.error(errors.join('\n'))
	console.error(`\n${errors.length} Fehler`)
	process.exit(1)
}
console.log(`${checked} Dateien ok: @nextcloud/* (außer @nextcloud/vue) nur in src/platform/`)
