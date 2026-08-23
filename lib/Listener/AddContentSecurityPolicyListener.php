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
 * Instagram/X widget-loader scripts (isAllowedWidgetScriptSrc()).
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
 * isAllowedWidgetScriptSrc(). The Instagram/X hosts appear in BOTH
 * frame-src and script-src: their widget script itself injects its own
 * iframe (instagram.com/platform.twitter.com) once it runs, so both
 * directives are needed for the embed to actually render.
 *
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class AddContentSecurityPolicyListener implements IEventListener {
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

		$policy->addAllowedScriptDomain('https://www.instagram.com');
		$policy->addAllowedScriptDomain('https://platform.twitter.com');

		$event->addPolicy($policy);
	}
}
