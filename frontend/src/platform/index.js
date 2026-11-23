/**
 * Plattform-Schicht der Vue-App (Plan „gemeinsames Repo“, Etappe 5): alles,
 * was die App von ihrer Umgebung braucht, kommt von hier und nirgends sonst
 * aus @nextcloud/* (Ausnahme: die Komponenten aus @nextcloud/vue).
 * 'merlin-platform' ist ein Alias der Vite-Konfiguration: vite.config.mjs
 * (Nextcloud) zeigt auf ./nextcloud.js, vite.standalone.config.mjs
 * (Standalone-Server) auf ./standalone.js. Komponenten, Store und api/
 * bleiben in beiden Builds gleich.
 *
 * - http: Axios-Instanz, die die Anmeldung und den CSRF-Schutz der Plattform
 *   mitschickt (Nextcloud: requesttoken, Standalone: X-Requested-With)
 * - appUrl(path): URL eines Pfads der App, wie er in core/src/Api/routes.php
 *   steht, z. B. appUrl('/api/articles') (Nextcloud: /index.php/apps/merlin/api/articles)
 * - imageUrl(file): Bild aus img/ der App
 * - loadState(key, fallback): Startwerte, die die Seite mitliefert
 * - showSuccess(text), showError(text): kurze Rückmeldungen (Toasts)
 * - translate, translatePlural: Texte der App (Keys nextcloudWeb.*)
 * - accountLinks(): Konto-Links der Plattform für die Seitenleiste, je
 *   { id: 'account'|'contentFilters'|'admin'|'logout', href }. Nextcloud
 *   hat dafür seine eigene Kopfleiste (leere Liste).
 *
 * frontend/tools/check-platform-imports.mjs prüft, dass nur dieser Ordner
 * @nextcloud/* (außer @nextcloud/vue) importiert.
 */
export { http, appUrl, imageUrl, loadState, showSuccess, showError, translate, translatePlural, accountLinks } from 'merlin-platform'
