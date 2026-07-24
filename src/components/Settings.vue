<template>
	<div class="settings-scroll">
	<div class="settings-page">
		<div class="settings-page__main">
			<header class="settings-page__header">
				<h1 class="settings-page__title">
					{{ t('merlin', 'Settings') }}
					<transition name="fade">
						<span v-if="savedFlash" class="settings-page__saved-pill">
							<Check :size="12" />
							{{ t('merlin', 'Saved') }}
						</span>
					</transition>
				</h1>
				<p class="settings-page__subtitle">
					{{ t('merlin', 'Customize how Merlin presents your reading list. Changes save automatically.') }}
				</p>
			</header>

			<!-- ── Reading ─────────────────────────────────────── -->
			<section class="section">
				<header class="section__head">
					<span class="section__icon"><BookOpen :size="18" /></span>
					<div>
						<h2 class="section__title">{{ t('merlin', 'Reading') }}</h2>
						<p class="section__desc">{{ t('merlin', 'Typography and layout for the article reader.') }}</p>
					</div>

				</header>

				<!-- Theme -->
				<div class="field">
					<div class="field__label">
						{{ t('merlin', 'Theme') }}
						<div class="field__hint">{{ t('merlin', 'Light, dark, or follow your system.') }}</div>
					</div>
					<div class="field__control">
						<div class="swatch-group">
							<button
								v-for="opt in themeOptions"
								:key="opt.value"
								type="button"
								:class="['swatch', 'swatch--theme', `theme-${opt.value}`,
									{ 'is-active': localSettings.theme === opt.value }]"
								@click="setSetting('theme', opt.value)">
								<span class="swatch__preview">
									<span class="swatch__preview-dot" />
								</span>
								<span class="swatch__label">{{ opt.label }}</span>
								<span class="swatch__check"><Check :size="12" /></span>
							</button>
						</div>
					</div>
				</div>

				<!-- Font family -->
				<div class="field">
					<div class="field__label">
						{{ t('merlin', 'Font family') }}
						<div class="field__hint">{{ t('merlin', 'Default uses Lora — designed for long-form reading.') }}</div>
					</div>
					<div class="field__control">
						<div class="swatch-group">
							<button
								v-for="opt in fontFamilyOptions"
								:key="opt.value"
								type="button"
								:class="['swatch', 'swatch--font', { 'is-active': localSettings.fontFamily === opt.value }]"
								@click="setSetting('fontFamily', opt.value)">
								<span class="swatch__preview" :style="{ fontFamily: opt.css }">Aa</span>
								<span class="swatch__label">{{ opt.label }}</span>
								<span class="swatch__check"><Check :size="12" /></span>
							</button>
						</div>
					</div>
				</div>

				<!-- Font size -->
				<div class="field">
					<div class="field__label">
						{{ t('merlin', 'Font size') }}
						<div class="field__hint">{{ t('merlin', 'Size in pixels for the article body text.') }}</div>
					</div>
					<div class="field__control">
						<div class="slider">
							<div class="slider__track-wrap">
								<div class="slider__track" />
								<div class="slider__fill" :style="{ width: fontSizePct + '%' }" />
								<input
									v-model.number="localSettings.fontSize"
									type="range"
									class="slider__input"
									min="12"
									max="28"
									step="1"
									@change="saveSettings">
								<div class="slider__thumb" :style="{ left: fontSizeThumbLeft }" />
							</div>
							<div class="slider__value">{{ localSettings.fontSize }}px</div>
						</div>
						<div class="slider__ticks slider__ticks--positioned">
							<span class="slider__tick slider__tick--start">{{ t('merlin', 'Small') }}</span>
							<span class="slider__tick slider__tick--default" :style="{ left: fontSizeDefaultTickLeft }">{{ t('merlin', 'Default') }}</span>
							<span class="slider__tick slider__tick--end">{{ t('merlin', 'Large') }}</span>
						</div>
					</div>
				</div>

				<!-- Line height -->
				<div class="field">
					<div class="field__label">
						{{ t('merlin', 'Line height') }}
						<div class="field__hint">{{ t('merlin', 'Looser lines feel calmer; tighter lines feel denser.') }}</div>
					</div>
					<div class="field__control">
						<div class="slider">
							<div class="slider__track-wrap">
								<div class="slider__track" />
								<div class="slider__fill" :style="{ width: lineHeightPct + '%' }" />
								<input
									v-model.number="localSettings.lineHeight"
									type="range"
									class="slider__input"
									min="1.2"
									max="2.0"
									step="0.1"
									@change="saveSettings">
								<div class="slider__thumb" :style="{ left: lineHeightThumbLeft }" />
							</div>
							<div class="slider__value">{{ Number(localSettings.lineHeight).toFixed(1) }}</div>
						</div>
						<div class="slider__ticks">
							<span>{{ t('merlin', 'Tight') }}</span>
							<span>{{ t('merlin', 'Default') }}</span>
							<span>{{ t('merlin', 'Airy') }}</span>
						</div>
					</div>
				</div>
			</section>

			<!-- ── Reading progress ────────────────────────────── -->
			<section class="section">
				<header class="section__head">
					<span class="section__icon"><Clock :size="18" /></span>
					<div>
						<h2 class="section__title">{{ t('merlin', 'Reading progress') }}</h2>
						<p class="section__desc">{{ t('merlin', 'Pick up where you left off, automatically.') }}</p>
					</div>

				</header>

				<div class="toggle-row">
					<div class="toggle-row__text">
						<div class="toggle-row__title">{{ t('merlin', 'Save reading position') }}</div>
						<div class="toggle-row__hint">
							{{ t('merlin', 'Remembers your scroll position in each article so you can resume later.') }}
						</div>
					</div>
					<button
						type="button"
						:class="['toggle', { 'is-on': localSettings.saveProgress }]"
						:aria-pressed="localSettings.saveProgress"
						:aria-label="t('merlin', 'Save reading position')"
						@click="setSetting('saveProgress', !localSettings.saveProgress)">
						<span class="toggle__switch" />
					</button>
				</div>

				<div class="toggle-row">
					<div class="toggle-row__text">
						<div class="toggle-row__title">{{ t('merlin', 'Resume when opening') }}</div>
						<div class="toggle-row__hint">
							{{ t('merlin', 'When you reopen an article, jump back to where you stopped.') }}
						</div>
					</div>
					<button
						type="button"
						:class="['toggle', { 'is-on': localSettings.resumeOnOpen }]"
						:aria-pressed="localSettings.resumeOnOpen"
						:aria-label="t('merlin', 'Resume when opening')"
						@click="setSetting('resumeOnOpen', !localSettings.resumeOnOpen)">
						<span class="toggle__switch" />
					</button>
				</div>

				<div class="field">
					<div class="field__label">
						{{ t('merlin', 'Progress bar position') }}
						<div class="field__hint">{{ t('merlin', 'Where the reading-progress bar appears. "Off" hides it entirely.') }}</div>
					</div>
					<div class="field__control">
						<div
							class="segmented"
							role="radiogroup"
							:aria-label="t('merlin', 'Progress bar position')"
							@keydown="onSegmentedKeydown($event, progressEdgeOptions, 'progressEdge')">
							<button
								v-for="opt in progressEdgeOptions"
								:key="opt.value"
								type="button"
								role="radio"
								:aria-checked="localSettings.progressEdge === opt.value"
								:tabindex="localSettings.progressEdge === opt.value ? 0 : -1"
								:class="['segmented__item', { 'is-active': localSettings.progressEdge === opt.value }]"
								@click="setSetting('progressEdge', opt.value)">
								{{ opt.label }}
							</button>
						</div>
					</div>
				</div>

				<!-- Accent color -->
				<div class="field">
					<div class="field__label">
						{{ t('merlin', 'Accent color') }}
						<div class="field__hint">{{ t('merlin', 'Color of the reading-progress bar, shared with iOS and Android.') }}</div>
					</div>
					<div class="field__control">
						<div class="swatch-group">
							<button
								v-for="opt in accentColorOptions"
								:key="opt.value"
								type="button"
								:class="['swatch', 'swatch--color', { 'is-active': localSettings.accentColor === opt.value }]"
								:aria-label="opt.label"
								@click="setSetting('accentColor', opt.value)">
								<span class="swatch__preview" :style="{ background: opt.value }" />
								<span class="swatch__check"><Check :size="12" /></span>
							</button>
							<label class="swatch swatch--color-custom" :class="{ 'is-active': isCustomAccentColor }">
								<span class="swatch__preview swatch__preview--custom">
									<input
										type="color"
										class="color-input"
										:value="localSettings.accentColor"
										@input="setSetting('accentColor', clampAccentLightness($event.target.value))">
								</span>
								<span class="swatch__label">{{ t('merlin', 'Custom') }}</span>
								<span class="swatch__check"><Check :size="12" /></span>
							</label>
						</div>
					</div>
				</div>
			</section>

			<!-- ── Library ─────────────────────────────────────── -->
			<section class="section">
				<header class="section__head">
					<span class="section__icon"><ViewGrid :size="18" /></span>
					<div>
						<h2 class="section__title">{{ t('merlin', 'Library') }}</h2>
						<p class="section__desc">{{ t('merlin', 'What you see when you open Merlin.') }}</p>
					</div>
	
				</header>

				<div class="field">
					<div class="field__label">
						{{ t('merlin', 'Default view') }}
						<div class="field__hint">{{ t('merlin', 'Where the app lands when you open it.') }}</div>
					</div>
					<div class="field__control">
						<div
							class="segmented"
							role="radiogroup"
							:aria-label="t('merlin', 'Default view')"
							@keydown="onSegmentedKeydown($event, defaultViewOptions, 'defaultView')">
							<button
								v-for="opt in defaultViewOptions"
								:key="opt.value"
								type="button"
								role="radio"
								:aria-checked="localSettings.defaultView === opt.value"
								:tabindex="localSettings.defaultView === opt.value ? 0 : -1"
								:class="['segmented__item', { 'is-active': localSettings.defaultView === opt.value }]"
								@click="setSetting('defaultView', opt.value)">
								<component :is="opt.icon" :size="14" />
								{{ opt.label }}
							</button>
						</div>
					</div>
				</div>
			</section>

			<!-- ── Tags ────────────────────────────────────────── -->
			<section v-if="tags.length" class="section">
				<header class="section__head">
					<span class="section__icon"><TagOutline :size="18" /></span>
					<div>
						<h2 class="section__title">{{ t('merlin', 'Tags') }}</h2>
						<p class="section__desc">{{ t('merlin', 'Hide articles with these tags from your reading list.') }}</p>
					</div>
				</header>

				<div class="field">
					<div class="field__label">
						{{ t('merlin', 'Excluded tags') }}
						<div class="field__hint">{{ t('merlin', 'Articles with an excluded tag are hidden everywhere except when filtering by that tag directly.') }}</div>
					</div>
					<div class="field__control">
						<div class="tag-toggle-group">
							<button
								v-for="tag in tags"
								:key="tag.id"
								type="button"
								:class="['tag-toggle', { 'is-excluded': isTagExcluded(tag.id) }]"
								@click="toggleExcludedTag(tag.id)">
								<span class="tag-toggle__dot" :style="{ backgroundColor: tag.color }" />
								<span class="tag-toggle__label">{{ tag.name }}</span>
								<Close v-if="isTagExcluded(tag.id)" :size="12" />
							</button>
						</div>
					</div>
				</div>
			</section>

			<!-- ── Reporting ───────────────────────────────────── -->
			<section class="section">
				<header class="section__head">
					<span class="section__icon"><AlertCircleOutline :size="18" /></span>
					<div>
						<h2 class="section__title">{{ t('merlin', 'Reporting') }}</h2>
						<p class="section__desc">{{ t('merlin', 'Where reported articles are sent.') }}</p>
					</div>
				</header>

				<div class="field">
					<div class="field__label">
						{{ t('merlin', 'Report backend URL') }}
						<div class="field__hint">
							{{ t('merlin', 'URL of your merlin-reports installation, e.g. https://cloud.example.com/merlin-reports/') }}
						</div>
					</div>
					<div class="field__control">
						<input
							type="url"
							class="report-url-input"
							:value="localSettings.reportBackendUrl"
							:placeholder="t('merlin', 'https://…/merlin-reports/')"
							spellcheck="false"
							autocomplete="off"
							@blur="setSetting('reportBackendUrl', $event.target.value.trim())"
							@keyup.enter="setSetting('reportBackendUrl', $event.target.value.trim())">
						<div v-if="reportUrlStatus" class="report-url-status">
							<span v-if="reportUrlStatus === 'checking'" class="report-url-status__badge report-url-status__badge--checking">
								<span class="report-url-status__spinner" />
								{{ t('merlin', 'Checking…') }}
							</span>
							<span v-else-if="reportUrlStatus === 'ok'" class="report-url-status__badge report-url-status__badge--ok">
								✓ {{ t('merlin', 'Reachable') }}
							</span>
							<span v-else class="report-url-status__badge report-url-status__badge--error">
								✗ {{ t('merlin', 'Not reachable') }}
							</span>
						</div>
					</div>
				</div>
			</section>
			<div class="settings-page__footer">
				<button class="btn btn--ghost" @click="resetAll">
					<Refresh :size="14" />
					{{ t('merlin', 'Reset all to defaults') }}
				</button>
				<span class="settings-page__footer-note">
					{{ t('merlin', 'Settings sync automatically across your devices.') }}
				</span>
			</div>
		</div>
		<SettingsPreview :settings="localSettings" />
		</div>
</div>
</template>

<script>
import { mapState, mapActions } from 'vuex'
import BookOpen from 'vue-material-design-icons/BookOpen.vue'
import Clock from 'vue-material-design-icons/Clock.vue'
import ViewGrid from 'vue-material-design-icons/ViewGrid.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Inbox from 'vue-material-design-icons/Inbox.vue'
import Star from 'vue-material-design-icons/Star.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import SettingsPreview from './SettingsPreview.vue'

// Kleine, abhängigkeitsfreie Hex<->HSL-Konvertierung, nur für clampAccentLightness()
// unten gebraucht (Kontrastproblem: sehr helle Akzentfarben verschlucken das
// schwebende Dock im Reader, siehe ArticleReader.vue dockStyle()).
function hexToHsl(hex) {
	const r = parseInt(hex.slice(1, 3), 16) / 255
	const g = parseInt(hex.slice(3, 5), 16) / 255
	const b = parseInt(hex.slice(5, 7), 16) / 255
	const max = Math.max(r, g, b); const min = Math.min(r, g, b)
	let h = 0; let s = 0; const l = (max + min) / 2
	if (max !== min) {
		const d = max - min
		s = l > 0.5 ? d / (2 - max - min) : d / (max + min)
		switch (max) {
		case r: h = (g - b) / d + (g < b ? 6 : 0); break
		case g: h = (b - r) / d + 2; break
		default: h = (r - g) / d + 4; break
		}
		h /= 6
	}
	return { h, s, l }
}

function hslToHex(h, s, l) {
	let r, g, b
	if (s === 0) {
		r = g = b = l
	} else {
		const hue2rgb = (p, q, t) => {
			if (t < 0) t += 1
			if (t > 1) t -= 1
			if (t < 1 / 6) return p + (q - p) * 6 * t
			if (t < 1 / 2) return q
			if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6
			return p
		}
		const q = l < 0.5 ? l * (1 + s) : l + s - l * s
		const p = 2 * l - q
		r = hue2rgb(p, q, h + 1 / 3)
		g = hue2rgb(p, q, h)
		b = hue2rgb(p, q, h - 1 / 3)
	}
	const toHex = v => Math.round(v * 255).toString(16).padStart(2, '0')
	return `#${toHex(r)}${toHex(g)}${toHex(b)}`.toUpperCase()
}

const DEFAULTS = {
	theme: 'auto',
	fontFamily: 'default',
	fontSize: 17,
	lineHeight: 1.6,
	defaultView: 'unread', // Vereinheitlicht mit PHP-Default in SettingsController.php
	saveProgress: true,
	progressEdge: 'left',
	resumeOnOpen: true,
	reportBackendUrl: '',
	accentColor: '#FF3B30',
	excludedTagIds: [],
}

export default {
	name: 'Settings',

	components: {
		BookOpen, Clock, ViewGrid, Refresh, Check,
		Inbox, Star, AlertCircleOutline, TagOutline, Close, SettingsPreview
	},

	data() {
		return {
			localSettings: { ...DEFAULTS },
			savedFlash: false,
			_flashTimer: null,
			reportUrlStatus: null, // null | 'checking' | 'ok' | 'error'
			// Letzte tatsächlich geprüfte reportBackendUrl: verhindert, dass der
			// settings-Watcher bei jedem Save (z.B. Theme-Wechsel) erneut pingt,
			// obwohl sich die URL gar nicht geändert hat.
			_lastCheckedReportUrl: undefined,
			// Zähler gegen Race-Conditions: eine spät eintreffende Antwort einer
			// überholten Prüfung darf den Status einer neueren nicht überschreiben.
			_reportCheckToken: 0,

			themeOptions: [
				{ value: 'light', label: t('merlin', 'Light') },
				{ value: 'dark',  label: t('merlin', 'Dark') },
				{ value: 'auto',  label: t('merlin', 'Auto') },
				{ value: 'sepia', label: t('merlin', 'Sepia') },
			],
			fontFamilyOptions: [
				{ value: 'default',    label: 'Lora',  css: "'Lora', Georgia, serif" },
				{ value: 'serif',      label: t('merlin', 'Serif'), css: "Georgia, 'Times New Roman', serif" },
				{ value: 'sans-serif', label: t('merlin', 'Sans'),  css: "-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif" },
				{ value: 'monospace',  label: t('merlin', 'Mono'),  css: "'Courier New', Courier, monospace" },
			],
			progressEdgeOptions: [
				{ value: 'left',   label: t('merlin', 'Left') },
				{ value: 'right',  label: t('merlin', 'Right') },
				{ value: 'top',    label: t('merlin', 'Top') },
				{ value: 'bottom', label: t('merlin', 'Bottom') },
				{ value: 'off',    label: t('merlin', 'Off') },
			],
			defaultViewOptions: [
				{ value: 'unread',    label: t('merlin', 'Unread'),    icon: 'Inbox' },
				{ value: 'favorites', label: t('merlin', 'Favorites'), icon: 'Star' },
			],
			// Feste Farbpalette analog zu PreferencesStore (iOS/Android); Reihenfolge
			// orientiert sich am ursprünglichen Default '#FF3B30' (iOS-Rot) an erster Stelle.
			accentColorOptions: [
				{ value: '#FF3B30', label: t('merlin', 'Red') },
				{ value: '#FF9500', label: t('merlin', 'Orange') },
				{ value: '#FFCC00', label: t('merlin', 'Yellow') },
				{ value: '#34C759', label: t('merlin', 'Green') },
				{ value: '#0082C9', label: t('merlin', 'Blue') },
				{ value: '#5856D6', label: t('merlin', 'Purple') },
			],
		}
	},

	computed: {
		...mapState(['settings', 'tags']),

		lineHeightPct() {
			const min = 1.2; const max = 2.0
			return ((this.localSettings.lineHeight - min) / (max - min)) * 100
		},

		fontSizePct() {
			const min = 12; const max = 28
			return ((Number(this.localSettings.fontSize) - min) / (max - min)) * 100
		},

		// Der native Range-Thumb bleibt immer innerhalb der Spurbreite (an den Enden um
		// den halben Thumb-Durchmesser eingerückt). Der Fake-Thumb nutzte bisher reines
		// `left: pct%`, wodurch er an 0%/100% zur Hälfte über den Rand hinausragte.
		// THUMB_RADIUS (9px) entspricht der Hälfte der .slider__thumb-Breite (18px).
		fontSizeThumbLeft() {
			return this._thumbLeft(this.fontSizePct)
		},

		lineHeightThumbLeft() {
			return this._thumbLeft(this.lineHeightPct)
		},

		// Der "Default"-Tick saß bisher optisch mittig (space-between), obwohl 17px auf
		// der Skala 12–28 nicht in der Mitte liegt (das wäre 20). Position wird stattdessen
		// exakt wie beim Thumb aus dem tatsächlichen Default-Wert berechnet.
		fontSizeDefaultTickLeft() {
			const min = 12; const max = 28
			const pct = ((DEFAULTS.fontSize - min) / (max - min)) * 100
			return this._thumbLeft(pct)
		},

		// Zeigt den "Custom"-Swatch als aktiv, wenn die aktuelle Farbe nicht
		// in der vordefinierten Palette enthalten ist (z.B. von Mobile-Apps gesetzt).
		isCustomAccentColor() {
			return !this.accentColorOptions.some(opt => opt.value === this.localSettings.accentColor)
		},
	},

	watch: {
		settings: {
			immediate: true,
			handler(s) {
				// excludedTagIds kommt vom Backend als JSON-String (siehe SettingsController.php
				// DEFAULT_SETTINGS) und muss für die Checkbox-Liste als Array vorliegen.
				const excludedTagIds = this.parseExcludedTagIds(s && s.excludedTagIds)
				this.localSettings = { ...DEFAULTS, ...s, excludedTagIds }
				// Nur pingen, wenn sich die URL seit der letzten Prüfung wirklich geändert
				// hat — sonst löst jedes Save (Theme, Slider, …) einen externen HTTP-Request aus.
				const url = (s && s.reportBackendUrl) || ''
				if (url !== this._lastCheckedReportUrl) {
					this.checkReportBackend(url)
				}
			},
		},
	},

	mounted() {
		// Tags werden normalerweise schon von App.vue geladen; falls die Settings-Seite
		// aber isoliert (z.B. per Deep-Link) aufgerufen wird, fehlt state.tags sonst.
		if (!this.tags.length) {
			this.fetchTags()
		}
	},

	beforeUnmount() {
		clearTimeout(this._flashTimer)
	},

	methods: {
		...mapActions(['updateSettings', 'fetchTags']),

		// Verhindert nahezu weiße/sehr helle Akzentfarben: Der schwebende Dock im Reader
		// hat einen weißen/hellen Icon-Vordergrund bei dunklen Akzenten, aber bei sehr
		// hellen Akzenten (nahe Weiß) verschwindet der Dock selbst fast unsichtbar vor
		// dem hellen Hintergrund. Deckelt die HSL-Helligkeit statt die Farbe abzulehnen,
		// damit der Colorpicker trotzdem nutzbar bleibt.
		clampAccentLightness(hex) {
			const MAX_LIGHTNESS = 0.82
			const { h, s, l } = hexToHsl(hex)
			if (l <= MAX_LIGHTNESS) return hex
			return hslToHex(h, s, MAX_LIGHTNESS)
		},

		// Siehe fontSizeThumbLeft/lineHeightThumbLeft: hält den Fake-Thumb (und den
		// Default-Tick) innerhalb der Spurbreite, analog zum nativen Range-Thumb.
		_thumbLeft(pct) {
			const THUMB_RADIUS = 9
			return `calc(${THUMB_RADIUS}px + (100% - ${THUMB_RADIUS * 2}px) * ${pct / 100})`
		},

		setSetting(key, value) {
			this.localSettings = { ...this.localSettings, [key]: value }
			this.saveSettings()
			if (key === 'reportBackendUrl') {
				this.checkReportBackend(value)
			}
		},

		// Backend liefert/erwartet excludedTagIds als JSON-Array-String (analog zum
		// Set<Int> auf iOS/Android); robust gegen leere/kaputte Werte beim ersten Laden.
		parseExcludedTagIds(raw) {
			if (Array.isArray(raw)) return raw
			try {
				const parsed = JSON.parse(raw || '[]')
				return Array.isArray(parsed) ? parsed : []
			} catch {
				return []
			}
		},

		// WAI-ARIA "radiogroup"-Pattern: Pfeiltasten wechseln die Auswahl und wandern
		// mit dem Fokus mit (roving tabindex), Home/End springen an die Enden.
		// Ersetzt das vorherige role="tablist"/"tab", das ohne zugehörige Tabpanels
		// semantisch falsch war und keine Pfeiltasten-Navigation anbot.
		onSegmentedKeydown(event, options, settingKey) {
			const nav = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }
			let nextIndex
			const currentIndex = options.findIndex(opt => opt.value === this.localSettings[settingKey])

			if (event.key in nav) {
				nextIndex = (currentIndex + nav[event.key] + options.length) % options.length
			} else if (event.key === 'Home') {
				nextIndex = 0
			} else if (event.key === 'End') {
				nextIndex = options.length - 1
			} else {
				return
			}

			event.preventDefault()
			this.setSetting(settingKey, options[nextIndex].value)
			this.$nextTick(() => {
				event.currentTarget.querySelectorAll('[role="radio"]')[nextIndex]?.focus()
			})
		},

		isTagExcluded(tagId) {
			return this.localSettings.excludedTagIds.includes(tagId)
		},

		toggleExcludedTag(tagId) {
			const current = this.localSettings.excludedTagIds
			const next = this.isTagExcluded(tagId)
				? current.filter(id => id !== tagId)
				: [...current, tagId]
			this.setSetting('excludedTagIds', next)
		},

		async checkReportBackend(url) {
			const trimmed = (url || '').trim()
			this._lastCheckedReportUrl = url || ''
			const token = ++this._reportCheckToken

			if (!trimmed) {
				this.reportUrlStatus = null
				return
			}
			this.reportUrlStatus = 'checking'
			try {
				const pingUrl = trimmed.replace(/\/?$/, '/') + 'index.php?action=ping'
				const res = await fetch(pingUrl, {
					method: 'GET',
					signal: AbortSignal.timeout(5000),
				})
				// Eine neuere Prüfung ist inzwischen gestartet — diese Antwort ist veraltet.
				if (token !== this._reportCheckToken) return
				if (res.ok) {
					const json = await res.json().catch(() => null)
					this.reportUrlStatus = (json && json.ok) ? 'ok' : 'error'
				} else {
					this.reportUrlStatus = 'error'
				}
			} catch {
				if (token !== this._reportCheckToken) return
				this.reportUrlStatus = 'error'
			}
		},

		async saveSettings() {
			try {
				// excludedTagIds muss als JSON-String raus, da SettingsController::update()
				// jeden Wert mit (string) castet — ein Array würde sonst zu "Array" verstümmelt.
				const payload = {
					...this.localSettings,
					excludedTagIds: JSON.stringify(this.localSettings.excludedTagIds),
				}
				await this.updateSettings(payload)
				this.savedFlash = true
				clearTimeout(this._flashTimer)
				this._flashTimer = setTimeout(() => { this.savedFlash = false }, 1600)
			} catch (error) {
				console.error('Failed to save settings:', error)
			}
		},

		resetAll() {
			this.localSettings = { ...DEFAULTS }
			this.saveSettings()
		},
	},
}
</script>

<style scoped>
.settings-scroll {
  height: 100%;
  overflow-y: auto;
  padding: 32px 40px 48px;
  box-sizing: border-box;
}

.settings-page {
  max-width: 1160px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 32px;
  align-items: start;
}

@media (max-width: 1080px) {
  .settings-page { grid-template-columns: 1fr; }
}

.settings-page__main { min-width: 0; }

.settings-page__header { margin-bottom: 32px; }

.settings-page__title {
	font-size: 28px;
	font-weight: 700;
	margin: 0 0 8px;
	letter-spacing: -0.2px;
	color: var(--color-main-text);
}

.settings-page__subtitle {
	color: var(--color-text-lighter);
	font-size: 14px;
	margin: 0;
}

.settings-page__saved-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	background: rgba(70, 186, 97, 0.12);
	color: var(--color-success, #46ba61);
	font-size: 12px;
	font-weight: 600;
	border-radius: 999px;
	margin-left: 12px;
	vertical-align: middle;
}

.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }

/* ── Section ─────────────────────────────────── */
.section {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 24px 24px 16px;
	margin-bottom: 20px;
}

.section__head {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	margin-bottom: 20px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.section__icon {
	width: 32px; height: 32px;
	border-radius: 8px;
	background: var(--color-primary-light, #e8f3fa);
	color: var(--color-primary, #0082c9);
	display: grid; place-items: center;
	flex-shrink: 0;
}

.section__title {
	font-size: 16px;
	font-weight: 600;
	margin: 0;
	line-height: 1.3;
	color: var(--color-main-text);
}

.section__desc {
	font-size: 13px;
	color: var(--color-text-lighter);
	margin: 2px 0 0;
}


/* ── Field ─────────────────────────────────────── */
.field {
	display: grid;
	grid-template-columns: 200px 1fr;
	gap: 20px;
	align-items: start;
	padding: 16px 0;
	border-bottom: 1px solid var(--color-border);
}
.field:last-child { border-bottom: none; }

.field__label {
	font-size: 14px;
	font-weight: 500;
	color: var(--color-main-text);
	padding-top: 6px;
}

.field__hint {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin-top: 2px;
	font-weight: 400;
}

.field__control { min-width: 0; }

@media (max-width: 720px) {
	.field { grid-template-columns: 1fr; gap: 8px; }
	.settings-scroll { padding: 16px 16px 40px; }
	.settings-page__title { font-size: 22px; }
	.section { padding: 16px; }
}

/* ── Swatches ─────────────────────────────────── */
.swatch-group {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.swatch {
	border: 2px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
	padding: 12px;
	cursor: pointer;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	min-width: 88px;
	font: inherit;
	color: inherit;
	position: relative;
	transition: border-color 0.15s ease, background 0.15s ease;
}
.swatch:hover {
	border-color: var(--color-border-dark, #dbdbdb);
	background: var(--color-background-hover);
}
.swatch.is-active {
	border-color: var(--color-primary, #0082c9);
	background: var(--color-primary-light, #e8f3fa);
}

.swatch__preview {
	width: 100%;
	height: 36px;
	border-radius: 6px;
	display: grid;
	place-items: center;
	overflow: hidden;
	font-weight: 600;
}

.swatch__label {
	font-size: 12px;
	font-weight: 500;
	color: var(--color-main-text);
}

.swatch__check {
	position: absolute;
	top: 6px; right: 6px;
	width: 18px; height: 18px;
	border-radius: 50%;
	background: var(--color-primary, #0082c9);
	color: white;
	display: grid; place-items: center;
	opacity: 0;
	transform: scale(0.8);
	transition: opacity 0.15s ease, transform 0.15s ease;
}
.swatch.is-active .swatch__check {
	opacity: 1;
	transform: scale(1);
}

/* Theme swatches */
.swatch--theme .swatch__preview {
	position: relative;
	border: 1px solid var(--color-border);
}
.swatch--theme.theme-light .swatch__preview {
	background: linear-gradient(180deg, #ffffff 0%, #f5f5f5 100%);
}
.swatch--theme.theme-dark .swatch__preview {
	background: linear-gradient(180deg, #2a2a2a 0%, #1e1e1e 100%);
}
.swatch--theme.theme-auto .swatch__preview {
	background: linear-gradient(90deg, #ffffff 50%, #1e1e1e 50%);
}
.swatch--theme.theme-sepia .swatch__preview {
	background: linear-gradient(180deg, #f4ecd8 0%, #e8d8b8 100%);
}
.swatch--theme .swatch__preview-dot {
	width: 10px; height: 10px;
	border-radius: 50%;
	background: var(--color-primary, #0082c9);
	position: absolute;
	bottom: 6px; left: 6px;
}

/* Font swatch */
.swatch--font .swatch__preview {
	background: var(--color-background-hover);
	font-size: 18px;
	color: var(--color-main-text);
}

/* Accent-color swatch */
.swatch--color {
	min-width: 44px;
	padding: 8px;
}
.swatch--color .swatch__preview {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	border: 1px solid var(--color-border);
}

.swatch--color-custom {
	min-width: 44px;
	padding: 8px;
	cursor: pointer;
}
.swatch--color-custom .swatch__preview--custom {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	border: 1px dashed var(--color-border-dark, #dbdbdb);
	background: var(--color-background-hover);
	position: relative;
	overflow: hidden;
}
.color-input {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	border: none;
	padding: 0;
	cursor: pointer;
	/* Browser-Farbpicker-Chrome ausblenden, nur die Farbfläche bleibt sichtbar */
	background: none;
}

/* Font-size swatch */
.swatch--size { min-width: 76px; }
.swatch--size .swatch__preview {
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-weight: 700;
	font-family: 'Lora', Georgia, serif;
}

/* ── Slider ───────────────────────────────────── */
.slider {
	display: flex;
	align-items: center;
	gap: 16px;
}

.slider__track-wrap {
	position: relative;
	flex: 1;
	height: 28px;
}

.slider__track {
	position: absolute;
	top: 50%;
	transform: translateY(-50%);
	left: 0; right: 0;
	height: 4px;
	background: var(--color-border);
	border-radius: 2px;
}

.slider__fill {
	position: absolute;
	top: 50%;
	transform: translateY(-50%);
	left: 0;
	height: 4px;
	background: var(--color-primary, #0082c9);
	border-radius: 2px;
	pointer-events: none;
}

.slider__input {
	position: absolute;
	inset: 0;
	width: 100%; height: 100%;
	opacity: 0;
	margin: 0;
	cursor: pointer;
}

.slider__thumb {
	position: absolute;
	top: 50%;
	width: 18px; height: 18px;
	border-radius: 50%;
	background: white;
	border: 2px solid var(--color-primary, #0082c9);
	transform: translate(-50%, -50%);
	pointer-events: none;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
}

.slider__value {
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	font-size: 14px;
	min-width: 48px;
	text-align: right;
	color: var(--color-main-text);
}

.slider__ticks {
	display: flex;
	justify-content: space-between;
	font-size: 11px;
	color: var(--color-text-lighter);
	margin-top: 4px;
	padding: 0 9px;
}

/* Font-size slider: "Default" (17px) liegt nicht in der Mitte der 12–28-Skala,
   darum werden die Ticks hier absolut positioniert statt gleichmäßig verteilt. */
.slider__ticks--positioned {
	position: relative;
	height: 14px;
	padding: 0;
}
.slider__ticks--positioned .slider__tick {
	position: absolute;
	top: 0;
	white-space: nowrap;
}
.slider__ticks--positioned .slider__tick--start { left: 0; }
.slider__ticks--positioned .slider__tick--end { right: 0; }
.slider__ticks--positioned .slider__tick--default { transform: translateX(-50%); }

/* ── Toggle ───────────────────────────────────── */
.toggle-row {
	display: flex;
	align-items: flex-start;
	gap: 16px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}
.toggle-row:last-child { border-bottom: none; }

.toggle-row__text { flex: 1; min-width: 0; }
.toggle-row__title {
	font-size: 14px;
	font-weight: 500;
	color: var(--color-main-text);
}
.toggle-row__hint {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin-top: 2px;
}

.toggle {
	border: none;
	background: transparent;
	padding: 0;
	cursor: pointer;
	flex-shrink: 0;
}

.toggle__switch {
	display: inline-block;
	width: 36px;
	height: 20px;
	border-radius: 999px;
	background: var(--color-border-dark, #dbdbdb);
	position: relative;
	transition: background 0.2s ease;
}

.toggle__switch::after {
	content: "";
	position: absolute;
	top: 2px; left: 2px;
	width: 16px; height: 16px;
	border-radius: 50%;
	background: white;
	transition: transform 0.2s ease;
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
}

.toggle.is-on .toggle__switch {
	background: var(--color-primary, #0082c9);
}
.toggle.is-on .toggle__switch::after {
	transform: translateX(16px);
}

/* ── Segmented ────────────────────────────────── */
.segmented {
	display: inline-flex;
	background: var(--color-background-hover);
	border-radius: 999px;
	padding: 3px;
	gap: 2px;
}

.segmented__item {
	border: none;
	background: transparent;
	padding: 6px 14px;
	border-radius: 999px;
	cursor: pointer;
	color: var(--color-text-lighter);
	font-size: 13px;
	font-weight: 500;
	transition: background 0.15s ease, color 0.15s ease;
	display: inline-flex;
	align-items: center;
	gap: 6px;
}
.segmented__item:hover { color: var(--color-main-text); }
.segmented__item.is-active {
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

/* ── Footer ───────────────────────────────────── */
.settings-page__footer {
	display: flex;
	align-items: center;
	gap: 12px;
	padding-top: 16px;
	margin-top: 8px;
	color: var(--color-text-lighter);
	font-size: 13px;
}

.settings-page__footer-note { margin-left: auto; }

.btn {
	font: inherit;
	padding: 8px 16px;
	border-radius: 999px;
	border: 1px solid transparent;
	cursor: pointer;
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-weight: 500;
	transition: background 0.15s ease, color 0.15s ease;
}

.btn--ghost {
	background: transparent;
	color: var(--color-text-lighter);
}
.btn--ghost:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}


/* ── Tag toggle (excludedTagIds) ──────────────────────────── */
.tag-toggle-group {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.tag-toggle {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 30px;
	padding: 0 10px;
	border-radius: 999px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	font-family: inherit;
	cursor: pointer;
	transition: opacity 0.15s ease, border-color 0.15s ease;
}

.tag-toggle__dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
}

/* Ausgeschlossene Tags werden abgedunkelt dargestellt, analog zum "ausgegraut"
   wirkenden Zustand im TagFilterSheet auf iOS/Android. */
.tag-toggle.is-excluded {
	opacity: 0.5;
	border-color: var(--color-error, #d23232);
	text-decoration: line-through;
}

/* ── Report-URL-Input ────────────────────────────────────── */
.report-url-input {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	font-family: inherit;
	box-sizing: border-box;
	outline: none;
	transition: border-color 0.15s;
}
.report-url-input:focus {
	border-color: var(--color-primary, #0082c9);
}
.report-url-input::placeholder {
	color: var(--color-text-lighter);
}

/* ── Report-URL-Status ───────────────────────────────────── */
.report-url-status {
	margin-top: 6px;
}

.report-url-status__badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 3px 10px;
	border-radius: 999px;
	font-size: 12px;
	font-weight: 600;
}

.report-url-status__badge--checking {
	background: var(--color-background-hover);
	color: var(--color-text-lighter);
}

.report-url-status__badge--ok {
	background: rgba(70, 186, 97, 0.12);
	color: var(--color-success, #46ba61);
}

.report-url-status__badge--error {
	background: rgba(210, 50, 50, 0.10);
	color: var(--color-error, #d23232);
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.report-url-status__spinner {
	display: inline-block;
	width: 10px;
	height: 10px;
	border: 2px solid currentColor;
	border-top-color: transparent;
	border-radius: 50%;
	animation: spin 0.7s linear infinite;
}
</style>
