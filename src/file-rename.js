/**
 * „Umbenennen…“ für Einträge aus „Merlin Dateien“ (Karte und Reader): fragt
 * den neuen Namen ohne Endung ab und benennt Datei und Eintrag über den Store
 * um. Die Endung behält der Server bei.
 */
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

/**
 * @param {string} fileName Dateiname mit Endung
 * @return {{ base: string, extension: string }}
 */
export function splitFileName(fileName) {
	const name = fileName || ''
	const dot = name.lastIndexOf('.')
	if (dot <= 0 || dot === name.length - 1) return { base: name, extension: '' }
	return { base: name.slice(0, dot), extension: name.slice(dot + 1) }
}

/**
 * @param {object} store Vuex-Store
 * @param {object} article Datei-Eintrag (fileId gesetzt)
 * @return {Promise<boolean>} ob umbenannt wurde
 */
export async function promptRenameFile(store, article) {
	const { base, extension } = splitFileName(article.title)
	const label = extension
		? t('merlin', 'New name (the extension .{extension} stays):', { extension })
		: t('merlin', 'New name:')
	const input = window.prompt(label, base)
	if (input === null) return false
	const name = input.trim()
	if (!name || name === base) return false
	try {
		await store.dispatch('renameFile', { articleId: article.id, name })
		showSuccess(t('merlin', 'File renamed'))
		return true
	} catch (error) {
		showError(error?.response?.status === 409
			? t('merlin', 'A file with this name already exists in the folder')
			: t('merlin', 'Could not rename the file'))
		return false
	}
}
