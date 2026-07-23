import { createApp } from 'vue'
import PublicArticleView from './components/PublicArticleView.vue'
import { translate, translatePlural } from '@nextcloud/l10n'

// Kein Vuex-Store, keine Sidebar/Navigation — die öffentliche Ansicht ist ein
// eigenständiger, schlanker Bundle ohne Login-Abhängigkeiten (siehe vite.config.mjs).
const app = createApp(PublicArticleView)

app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

app.mount('#merlin-public-app')
