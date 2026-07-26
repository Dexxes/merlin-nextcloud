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
})
