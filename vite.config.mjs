import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
    main: 'src/main.js',
    // Eigener, schlanker Bundle für die öffentliche Share-Ansicht (kein Login,
    // kein Vuex-Store/Sidebar – nur Reader + Passwort-Gate).
    public: 'src/public-main.js',
    // Verwaltungseinstellungen (Content-Filter-Pflege). Eigener Entry, damit die
    // Admin-Oberfläche nicht in jedem Reader-Aufruf mitgeladen wird.
    admin: 'src/admin-main.js',
    // Persönliche Einstellungen (eigener Content-Filter-Override). Eigener
    // Entry aus demselben Grund wie admin.
    personal: 'src/personal-main.js',
}, {
    config: {
        // pdf.js-Worker (PdfViewer.vue, Import mit ?worker) landet sonst in
        // assets/, das weder im Tarball (Makefile kopiert nur js/) noch im
        // Repo vorgesehen ist - er gehört zu den Build-Artefakten in js/.
        worker: {
            rollupOptions: {
                output: { entryFileNames: 'js/[name]-[hash].js' },
            },
        },
    },
})
