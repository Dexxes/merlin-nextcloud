<?php

declare(strict_types=1);

namespace OCA\Merlin\Listener;

use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Allow the Merlin service worker to be registered, and permit the iframe/
 * script embeds ContentExtractorService::sanitizeHtml() lets through the
 * reader content: video-platform iframes (isAllowedVideoEmbedSrc()) and the
 * Instagram/X/TikTok widget-loader scripts (isAllowedWidgetScriptSrc()).
 *
 * Nextcloud's default CSP has no worker-src directive, so browsers
 * fall back to script-src (which requires a per-request nonce) and
 * block navigator.serviceWorker.register().  Adding worker-src 'self'
 * here permits the sw.js script to run as a Worker on the same origin.
 *
 * Without explicit frame-src/script-src entries, browsers fall back to
 * default-src, which does not include these hosts – the sanitizer would let
 * the markup through but the browser would still refuse to load it. Host
 * list must stay in sync with isAllowedVideoEmbedSrc()/
 * isAllowedWidgetScriptSrc(). The Instagram/X/TikTok hosts appear in BOTH
 * frame-src and script-src: their widget script itself injects its own
 * iframe (instagram.com/platform.twitter.com/tiktok.com) once it runs, so
 * both directives are needed for the embed to actually render.
 *
 * connect-src: hls.js (native ARD/ZDF/Arte-Player, siehe
 * VideoStreamResolverService) lädt HLS-Manifest/-Segmente per fetch/XHR statt
 * über ein natives <video src>, das fällt unter connect-src statt media-src/
 * frame-src. Nextclouds eigene Baseline-CSP setzt hier default `'self'`, was
 * jeden Request zum Sender-CDN blockiert (live bestätigt: "Refused to
 * connect... violates connect-src 'self'" für *.ard-mcdn.de). Die konkreten
 * CDN-Hosts sind pro Video/Sendung unterschiedlich (Wildcard-Subdomains statt
 * fester Hosts wie bei frame-src oben), deshalb hier bewusst per
 * Wildcard-Pattern statt einzeln aufgezählt:
 *   - *.ard-mcdn.de       (zentrales ARD-CDN, mp4-Fallback-Qualitäten)
 *   - *.akamaized.net     (ZDF, z. B. zdfvod.akamaized.net)
 *   - *.akamaihd.net      (ZDF-Fallback-CDN, z. B. nrodlzdf-a.akamaihd.net)
 * Arte nutzt nach bisheriger Recherche ebenfalls Akamai, ist also potenziell
 * durch dieselben beiden Akamai-Wildcards mitabgedeckt.
 *
 * Live bestätigt (SWR-Artikel, siehe Commit-Historie): das eigentliche
 * HLS-Master-Manifest liegt NICHT unter *.ard-mcdn.de, sondern unter einer
 * senderspezifischen Domain der ARD-Anstalt selbst (hier av-adaptive.swr.de)
 * - *.ard-mcdn.de deckt bei diesem Sender nur die mp4-Fallback-Qualitäten ab,
 * nicht die m3u8, an der hls.js tatsächlich hängt. Da die ARD Mediathek
 * Inhalte aller Landesrundfunkanstalten aggregiert, braucht es potenziell
 * für jede Anstalt deren eigene CDN-Domain - deshalb hier zusätzlich die
 * Domains der Anstalten, die dieser Instanz bereits als Artikel-Domains
 * bekannt sind (siehe content-filters/), auf Verdacht mit demselben Muster.
 *
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class AddContentSecurityPolicyListener implements IEventListener {
	/**
	 * Domains von ARD-Landesrundfunkanstalten, deren HLS-CDN (Muster
	 * av-adaptive.<domain>, live bestätigt für swr.de) nicht unter den
	 * zentralen *.ard-mcdn.de-Wildcard fällt - siehe Klassen-Docblock.
	 *
	 * @var list<string>
	 */
	private const ARD_ANSTALT_DOMAINS = [
		'swr.de', 'ndr.de', 'wdr.de', 'br.de', 'hr.de', 'mdr.de',
		'rbb-online.de', 'sr.de', 'radiobremen.de', 'daserste.de',
	];

	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}

		$policy = new EmptyContentSecurityPolicy();
		$policy->addAllowedWorkerSrcDomain("'self'");

		$policy->addAllowedFrameDomain('https://www.youtube.com');
		$policy->addAllowedFrameDomain('https://www.youtube-nocookie.com');
		$policy->addAllowedFrameDomain('https://player.vimeo.com');
		$policy->addAllowedFrameDomain('https://player.twitch.tv');
		$policy->addAllowedFrameDomain('https://www.tiktok.com');
		$policy->addAllowedFrameDomain('https://www.facebook.com');
		$policy->addAllowedFrameDomain('https://www.arte.tv');
		$policy->addAllowedFrameDomain('https://www.instagram.com');
		$policy->addAllowedFrameDomain('https://platform.twitter.com');
		$policy->addAllowedFrameDomain('https://embed.bsky.app');

		$policy->addAllowedScriptDomain('https://www.instagram.com');
		$policy->addAllowedScriptDomain('https://platform.twitter.com');
		$policy->addAllowedScriptDomain('https://embed.bsky.app');
		$policy->addAllowedScriptDomain('https://www.tiktok.com');

		$policy->addAllowedConnectDomain('https://*.ard-mcdn.de');
		$policy->addAllowedConnectDomain('https://*.akamaized.net');
		$policy->addAllowedConnectDomain('https://*.akamaihd.net');
		foreach (self::ARD_ANSTALT_DOMAINS as $domain) {
			$policy->addAllowedConnectDomain('https://*.' . $domain);
		}

		$event->addPolicy($policy);
	}
}
