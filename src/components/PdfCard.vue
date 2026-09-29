<!--
  SPDX-License-Identifier: AGPL-3.0-or-later

  Karte für PDF-Artikel (category "PDF"), die Dateiname/Quelle zeigt und die PDF
  im Browser-eigenen Viewer öffnet. Fallback von PdfViewer.vue, wenn die
  eingebettete Vorschau nicht geladen werden kann (Quelle nicht erreichbar,
  keine gültige PDF, zu groß).
-->
<template>
	<div class="pdf-card">
		<svg class="pdf-card__icon"
			viewBox="0 0 24 24"
			width="40"
			height="40"
			aria-hidden="true">
			<path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6m4 18H6V4h7v5h5v11m-4.5-4.5c0 .8-.7 1.5-1.5 1.5h-1v1.5H9.5v-5H12c.8 0 1.5.7 1.5 1.5v.5m-1.5-.5h-1v.5h1v-.5m4.5-1H15v1.3h1.5v1H15v1.7h-1v-5h2.5v1z" />
		</svg>
		<div class="pdf-card__text">
			<span class="pdf-card__label">{{ t('merlin', 'PDF document') }}</span>
			<span class="pdf-card__host">{{ host }}</span>
		</div>
		<a class="pdf-card__open button-vue button-vue--vue-primary"
			:href="url"
			target="_blank"
			rel="noopener noreferrer">
			{{ t('merlin', 'Open PDF') }}
		</a>
	</div>
</template>

<script>
export default {
	name: 'PdfCard',
	props: {
		/** Quell-URL der PDF. */
		url: { type: String, required: true },
	},
	computed: {
		host() {
			try {
				return new URL(this.url).hostname.replace(/^www\./, '')
			} catch (e) {
				return ''
			}
		},
	},
}
</script>

<style scoped>
.pdf-card {
	display: flex;
	align-items: center;
	gap: 16px;
	margin: 24px 0;
	padding: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
}

.pdf-card__icon {
	flex: none;
	color: var(--color-error, #c9302c);
}

.pdf-card__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1;
}

.pdf-card__label {
	font-weight: 600;
}

.pdf-card__host {
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pdf-card__open {
	flex: none;
	padding: 8px 16px;
	border-radius: var(--border-radius-pill, 20px);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	text-decoration: none;
	font-weight: 600;
}

@media (max-width: 480px) {
	.pdf-card {
		flex-wrap: wrap;
	}
}
</style>
