<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use Merlin\Core\ContentFilter\ContentFilterMerger;
use Merlin\Core\ContentFilter\ContentFilterRepository as CoreContentFilterRepository;
use Merlin\Core\Port\ContentFilterSource;
use OCA\Merlin\Adapter\NextcloudContentFilterStore;
use Psr\Log\LoggerInterface;

/**
 * Content-Filter der Nextcloud-App: die Logik steht im Kern
 * (Merlin\Core\ContentFilter\ContentFilterRepository), hier kommen nur die
 * Ablage (merlin_cfilter über NextcloudContentFilterStore) und die Orte des
 * Bundle-Ordners dazu.
 */
class ContentFilterRepository extends CoreContentFilterRepository implements ContentFilterSource {
	public function __construct(
		NextcloudContentFilterStore $store,
		LoggerInterface $logger,
		ContentFilterMerger $merger,
	) {
		parent::__construct($store, $logger, $merger);
	}

	/**
	 * Appstore-Tarball und Docker-Image kopieren content-filters/ in das
	 * App-Verzeichnis (merlin/content-filters/), das hat Vorrang. Im Checkout
	 * ohne Kopie greift der Ordner an der Repository-Wurzel.
	 */
	protected function bundleDirCandidates(): array {
		return [__DIR__ . '/../../content-filters', __DIR__ . '/../../../content-filters'];
	}
}
