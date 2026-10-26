/**
 * Trennt eine führende <figure class="merlin-hero-image"> (siehe
 * ContentExtractorService Step 12) vom restlichen Content ab, damit der
 * MediaPlayer zwischen Hero-Bild und Rest platziert werden kann statt
 * immer ganz oben. Nur ein Split, wenn die Figure wirklich das erste
 * Element ist - sonst bleibt alles wie zuvor in restHtml.
 *
 * Gemeinsam genutzt von ArticleReader.vue und PublicArticleView.vue.
 * Beide rendern heroHtml und restHtml in data-hl-flatten-Wrappern, damit
 * Highlight-XPaths so auflösen, als hätte der Split nie stattgefunden
 * (siehe highlight-engine.js).
 */
export function splitHeroContent(html) {
	if (!html) return { heroHtml: '', heroImageUrl: '', heroCaption: '', restHtml: html || '' }

	const doc = new DOMParser().parseFromString(html, 'text/html')
	const hero = doc.body.firstElementChild
	if (!hero || hero.tagName !== 'FIGURE' || !hero.classList.contains('merlin-hero-image')) {
		return { heroHtml: '', heroImageUrl: '', heroCaption: '', restHtml: html }
	}

	const heroHtml = hero.outerHTML
	// Dient bei einem abspielbaren Video als Poster-Bild (bei Audio als
	// Cover) statt separat über der Figure angezeigt zu werden - deshalb
	// schon hier mit heraustrennen.
	const heroImageUrl = hero.querySelector('img')?.src ?? ''
	const heroCaption = hero.querySelector('figcaption')?.textContent?.trim() ?? ''
	hero.remove()
	return { heroHtml, heroImageUrl, heroCaption, restHtml: doc.body.innerHTML }
}
