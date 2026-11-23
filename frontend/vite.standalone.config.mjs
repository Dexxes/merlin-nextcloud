import { fileURLToPath } from 'node:url'
import { createAppConfig } from '@nextcloud/vite-config'

// Build der Vue-App für den Standalone-Server (Plan „gemeinsames Repo“,
// Etappe 5): dieselben Quellen wie vite.config.mjs, aber mit
// src/platform/standalone.js als Plattform-Schicht und dem Theme-Shim
// (src/standalone/), Ausgabe nach standalone/public/app/ (js/, css/).
// Reader, öffentliche Share-Ansicht, Verwaltung (Löschfrist,
// Content-Filter) und persönliche Content-Filter.
export default createAppConfig({
    main: 'src/standalone/main.js',
    public: 'src/standalone/public-main.js',
    admin: 'src/standalone/admin-main.js',
    personal: 'src/standalone/personal-main.js',
}, {
    config: {
        resolve: {
            alias: {
                'merlin-platform': fileURLToPath(new URL('./src/platform/standalone.js', import.meta.url)),
            },
        },
        build: {
            outDir: '../standalone/public/app',
            emptyOutDir: true,
        },
        worker: {
            rollupOptions: {
                output: { entryFileNames: 'js/[name]-[hash].js' },
            },
        },
    },
})
