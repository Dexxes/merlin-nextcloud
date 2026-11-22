<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Port\Translator;
use OCP\IL10N;

/**
 * Translator-Port des Kerns über ein IL10N (eine Sprache). Wird je Nutzer
 * gebaut (MerlinFileService), nicht per DI.
 */
class NextcloudTranslator implements Translator {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function t(string $text, array $parameters = []): string {
		return $this->l10n->t($text, $parameters);
	}
}
