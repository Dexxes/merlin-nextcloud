import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import PersonalContentFilters from './components/personal/PersonalContentFilters.vue'
import PersonalSiteCredentials from './components/personal/PersonalSiteCredentials.vue'

// Kein Vuex-Store: wie die Admin-Oberfläche hält auch diese Seite ihren
// Zustand lokal in der Wurzelkomponente (siehe vite.config.mjs).
import '@nextcloud/dialogs/style.css'

function mount(component, elementId) {
	const app = createApp(component)
	app.config.globalProperties.t = translate
	app.config.globalProperties.n = translatePlural
	app.mount(elementId)
}

// Zwei unabhängige Wurzelkomponenten in einem Bundle statt eines eigenen
// Vite-Entrys für die Paywall-Zugangsdaten: beide laufen auf derselben
// Personal-Settings-Seite, ein zweiter Entry brächte nur zusätzlichen
// Build-/Ladeaufwand ohne echten Vorteil.
mount(PersonalSiteCredentials, '#merlin-site-credentials-settings')
mount(PersonalContentFilters, '#merlin-personal-settings')
