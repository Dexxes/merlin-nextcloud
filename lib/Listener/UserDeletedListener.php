<?php

declare(strict_types=1);

namespace OCA\Merlin\Listener;

use OCA\Merlin\Db\SiteCredentialMapper;
use OCA\Merlin\Service\ContentFilterRepository;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Räumt private Content-Filter-Overrides (scope='user') auf, wenn ein Nutzer
 * gelöscht wird.
 *
 * Kein Fremdschlüssel auf oc_users nötig (Nextcloud-App-Tabellen verzichten
 * i. d. R. auf harte FKs zwischen App- und Core-Tabellen) — dieser Listener
 * ist der einzige Aufräummechanismus für verwaiste user_id-Zeilen in
 * merlin_cfilter. Ohne ihn blieben Overrides eines gelöschten Nutzers
 * unsichtbar, aber für immer in der DB liegen.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private ContentFilterRepository $repository,
		private SiteCredentialMapper $siteCredentialMapper,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}

		$userId = $event->getUser()->getUID();

		try {
			$this->repository->deleteAllUserCustom($userId);
		} catch (\Throwable $e) {
			// Nutzerlöschung darf an einem Merlin-Aufräumfehler nicht scheitern –
			// verwaiste Zeilen sind unschön, aber harmlos (nie wieder erreichbar,
			// da user_id nirgends sonst referenziert wird).
			$this->logger->error('content-filters: Aufräumen der User-Overrides nach Nutzerlöschung fehlgeschlagen', [
				'userId'    => $userId,
				'exception' => $e,
			]);
		}

		try {
			$this->siteCredentialMapper->deleteAllForUser($userId);
		} catch (\Throwable $e) {
			// Gleiches Fail-open-Prinzip wie oben: verwaiste, aber verschlüsselte
			// Zeilen sind unschön, dürfen die Nutzerlöschung aber nicht blockieren.
			$this->logger->error('site-credentials: Aufräumen der Paywall-Zugangsdaten nach Nutzerlöschung fehlgeschlagen', [
				'userId'    => $userId,
				'exception' => $e,
			]);
		}
	}
}
