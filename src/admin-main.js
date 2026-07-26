import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import ContentFilterAdmin from './components/admin/ContentFilterAdmin.vue'

// Kein Vuex-Store: die Admin-Oberfläche hält ihren Zustand lokal in der
// Wurzelkomponente und teilt ihn mit niemandem (siehe vite.config.mjs).
import '@nextcloud/dialogs/style.css'

const app = createApp(ContentFilterAdmin)

app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

app.mount('#merlin-admin-settings')
