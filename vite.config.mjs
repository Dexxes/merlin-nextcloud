import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
    main: 'src/main.js',
    // Eigener, schlanker Bundle für die öffentliche Share-Ansicht (kein Login,
    // kein Vuex-Store/Sidebar – nur Reader + Passwort-Gate).
    public: 'src/public-main.js',
})
