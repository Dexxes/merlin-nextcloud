import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import ContentFilterAdmin from './components/admin/ContentFilterAdmin.vue'
import RetentionAdmin from './components/admin/RetentionAdmin.vue'

// Kein Vuex-Store: die Admin-Oberfläche hält ihren Zustand lokal in der
// Wurzelkomponente und teilt ihn mit niemandem (siehe vite.config.mjs).
import '@nextcloud/dialogs/style.css'

// Die Merlin-Admin-Sektion besteht aus zwei ISettings (RetentionAdminSettings,
// AdminSettings), die sich dieses Skript teilen: jede App nur montieren, wenn
// ihr Container auf der Seite steht.
const mounts = [
	['#merlin-admin-retention', RetentionAdmin],
	['#merlin-admin-settings', ContentFilterAdmin],
]

for (const [selector, component] of mounts) {
	if (!document.querySelector(selector)) {
		continue
	}
	const app = createApp(component)
	app.config.globalProperties.t = translate
	app.config.globalProperties.n = translatePlural
	app.mount(selector)
}
