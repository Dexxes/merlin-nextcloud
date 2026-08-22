<?php

declare(strict_types=1);

namespace OCA\Merlin\Listener;

use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/**
 * Allow the Merlin service worker to be registered, and permit the one
 * kind of iframe embed ContentExtractorService::sanitizeHtml() lets through
 * the reader content: YouTube (isAllowedYoutubeEmbedSrc()).
 *
 * Nextcloud's default CSP has no worker-src directive, so browsers
 * fall back to script-src (which requires a per-request nonce) and
 * block navigator.serviceWorker.register().  Adding worker-src 'self'
 * here permits the sw.js script to run as a Worker on the same origin.
 *
 * Without an explicit frame-src, browsers fall back to default-src, which
 * does not include YouTube – the sanitizer would let the <iframe> markup
 * through but the browser would still refuse to load it. Host list must
 * stay in sync with isAllowedYoutubeEmbedSrc().
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
		$event->addPolicy($policy);
	}
}
