<?php

declare(strict_types=1);

namespace Merlin\Standalone\Adapter;

use Merlin\Core\ContentFilter\ContentFilterRepository;

/**
 * ContentFilterSource-Port: der Kern-Repository mit den Bundle-Orten des
 * Servers. Ein Release-Tarball oder Docker-Image legt content-filters/ nach
 * standalone/content-filters/, das hat Vorrang; im Checkout gilt der Ordner
 * an der Repository-Wurzel.
 */
final class StandaloneContentFilterRepository extends ContentFilterRepository {
	protected function bundleDirCandidates(): array {
		return [dirname(__DIR__, 2) . '/content-filters', dirname(__DIR__, 3) . '/content-filters'];
	}
}
