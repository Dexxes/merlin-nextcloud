import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Löschfrist archivierter Artikel. Die Nutzerwerte selbst (retentionDays,
// retentionFavoritesDays) laufen über /api/settings, siehe api/settings.js.

/** Einmaligen Hinweis bestätigen; liefert die aktualisierten retention*-Werte. */
export async function acknowledgeRetentionNotice() {
	const { data } = await axios.post(generateUrl('/apps/merlin/api/retention/notice'))
	return data
}

export async function getAdminRetention() {
	const { data } = await axios.get(generateUrl('/apps/merlin/api/admin/retention'))
	return data
}

export async function saveAdminRetention(days, favoritesDays) {
	const { data } = await axios.put(generateUrl('/apps/merlin/api/admin/retention'), { days, favoritesDays })
	return data
}

/** Anzahl archivierter Artikel, die der nächste Lauf mit diesen Werten löschen würde. */
export async function previewAdminRetention(days, favoritesDays) {
	const { data } = await axios.get(generateUrl('/apps/merlin/api/admin/retention/preview'), {
		params: { days, favoritesDays },
	})
	return data.count
}
