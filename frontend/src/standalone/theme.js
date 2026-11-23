// Theme-Shim (siehe theme.css): Nextcloud markiert das dunkle Theme
// zusätzlich mit data-theme-dark auf <body>, worauf einige Stile der App
// reagieren. Hier folgt das Attribut der Systemeinstellung.
import './theme.css'

const dark = window.matchMedia('(prefers-color-scheme: dark)')
function sync() {
	if (dark.matches) {
		document.body.setAttribute('data-theme-dark', '')
	} else {
		document.body.removeAttribute('data-theme-dark')
	}
}
sync()
dark.addEventListener('change', sync)
