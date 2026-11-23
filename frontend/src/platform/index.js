/**
 * Plattform-Schicht der Vue-App (Plan „gemeinsames Repo“, Etappe 5): alles,
 * was die App von ihrer Umgebung braucht, kommt von hier und nirgends sonst
 * aus @nextcloud/* (Ausnahme: die Komponenten aus @nextcloud/vue). Heute gibt
 * es nur die Nextcloud-Umsetzung (./nextcloud.js); der Standalone-Build
 * tauscht dieses Modul später gegen eine eigene aus, ohne dass sich
 * Komponenten, Store oder api/ ändern.
 *
 * - http: Axios-Instanz, die die Anmeldung und den CSRF-Schutz der Plattform
 *   mitschickt (Nextcloud: requesttoken)
 * - appUrl(path): URL eines Pfads der App, wie er in core/src/Api/routes.php
 *   steht, z. B. appUrl('/api/articles') (Nextcloud: /index.php/apps/merlin/api/articles)
 * - imageUrl(file): Bild aus img/ der App
 * - loadState(key, fallback): Startwerte, die die Seite mitliefert
 * - showSuccess(text), showError(text): kurze Rückmeldungen (Toasts)
 * - translate, translatePlural: Texte der App (Nextcloud-l10n, Keys nextcloudWeb.*)
 *
 * frontend/tools/check-platform-imports.mjs prüft, dass nur dieser Ordner
 * @nextcloud/* (außer @nextcloud/vue) importiert.
 */
export { http, appUrl, imageUrl, loadState, showSuccess, showError, translate, translatePlural } from './nextcloud.js'
