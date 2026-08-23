import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import PersonalContentFilters from './components/personal/PersonalContentFilters.vue'

// Kein Vuex-Store: wie die Admin-Oberfläche hält auch diese Seite ihren
// Zustand lokal in der Wurzelkomponente (siehe vite.config.mjs).
import '@nextcloud/dialogs/style.css'

const app = createApp(PersonalContentFilters)

app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

app.mount('#merlin-personal-settings')
