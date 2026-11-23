import { createApp } from 'vue'
import App from './App.vue'
import store from './store'
import { loadState, translate, translatePlural } from './platform/index.js'

// Nextcloud Vue styles

const app = createApp(App)

// Add translation methods globally
app.config.globalProperties.t = translate
app.config.globalProperties.n = translatePlural

// Use Vuex store
app.use(store)

// Mount to DOM
app.mount('#merlin-app')

// Ensure viewport-fit=cover for iOS safe area insets (Dynamic Island, home indicator)
const viewportMeta = document.querySelector('meta[name="viewport"]')
if (viewportMeta && !viewportMeta.content.includes('viewport-fit')) {
	viewportMeta.content += ', viewport-fit=cover'
}

// Register PWA service worker
const swUrl = loadState('swUrl', null)
if ('serviceWorker' in navigator && swUrl) {
	window.addEventListener('load', () => {
		navigator.serviceWorker.register(swUrl)
			.catch(err => console.warn('[Merlin] SW registration failed:', err))
	})
}