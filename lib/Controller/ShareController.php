<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\ArticleShare;
use OCA\Merlin\Db\ArticleShareMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Verwaltung von öffentlichen Share-Links für Artikel (Erstellen, Passwort/Ablauf
 * ändern, Token regenerieren, widerrufen). Genau ein Share-Datensatz pro Artikel –
 * "Regenerieren" tauscht nur den Token aus, statt einen zweiten Link anzulegen.
 *
 * Die eigentliche öffentliche Auslieferung (Lesen ohne Login) übernimmt
 * PublicShareController; dieser Controller läuft unter der normalen
 * Nextcloud-Auth wie alle anderen API-Endpunkte.
 *
 * Security note – NoCSRFRequired: siehe HighlightController (gleiches Muster:
 * native Clients nutzen Basic Auth statt Requesttoken).
 */
class ShareController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ArticleMapper $articleMapper,
		private ArticleShareMapper $shareMapper,
		private IURLGenerator $urlGenerator,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	private function buildUrl(ArticleShare $share): string {
		return $this->urlGenerator->linkToRouteAbsolute('merlin.public_share.show', ['token' => $share->getToken()]);
	}

	private function toPayload(ArticleShare $share): array {
		return $share->jsonSerialize() + ['url' => $this->buildUrl($share)];
	}

	private function newToken(): string {
		return bin2hex(random_bytes(16));
	}

	/**
	 * Parst ein optionales ISO-8601-Ablaufdatum aus dem Request-Body.
	 * Leerer String / null = kein Ablauf.
	 */
	private function parseExpiresAt(?string $expiresAt): ?\DateTime {
		if ($expiresAt === null || $expiresAt === '') {
			return null;
		}
		try {
			return new \DateTime($expiresAt);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Aktuellen Share-Status eines Artikels abfragen.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $articleId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->articleMapper->find($articleId, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Article not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$share = $this->shareMapper->findByArticleId($articleId, $this->userId);
			return new DataResponse(['enabled' => true] + $this->toPayload($share));
		} catch (DoesNotExistException) {
			return new DataResponse(['enabled' => false]);
		}
	}

	/**
	 * Share-Link für einen Artikel anlegen (idempotent: existiert bereits einer,
	 * wird dieser unverändert zurückgegeben statt einen zweiten anzulegen).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(int $articleId, ?string $password = null, ?string $expiresAt = null): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->articleMapper->find($articleId, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Article not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$existing = $this->shareMapper->findByArticleId($articleId, $this->userId);
			return new DataResponse(['enabled' => true] + $this->toPayload($existing));
		} catch (DoesNotExistException) {
			// noch kein Share vorhanden → neu anlegen
		}

		$now = new \DateTime();
		$share = new ArticleShare();
		$share->setUserId($this->userId);
		$share->setArticleId($articleId);
		$share->setToken($this->newToken());
		$share->setPasswordHash($password !== null && $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null);
		$share->setExpiresAt($this->parseExpiresAt($expiresAt));
		$share->setCreatedAt($now);
		$share->setUpdatedAt($now);

		$saved = $this->shareMapper->insert($share);
		return new DataResponse(['enabled' => true] + $this->toPayload($saved), Http::STATUS_CREATED);
	}

	/**
	 * Passwort und/oder Ablaufdatum eines bestehenden Share-Links ändern.
	 * `password: null`/leerer String entfernt den Passwortschutz,
	 * `expiresAt: null`/leerer String entfernt das Ablaufdatum.
	 * Beide Felder sind optional – wird ein Feld im Request-Body gar nicht
	 * mitgeschickt, bleibt es unverändert. Unterscheidung "nicht mitgeschickt"
	 * vs. "explizit null" erfolgt über ein Sentinel statt zusätzlicher
	 * Bool-Flags, damit Clients keine Sonderfelder mitschicken müssen.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(int $articleId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$share = $this->shareMapper->findByArticleId($articleId, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Share not found'], Http::STATUS_NOT_FOUND);
		}

		$unset = "\0__unset__\0";
		$password = $this->request->getParam('password', $unset);
		if ($password !== $unset) {
			$share->setPasswordHash($password !== null && $password !== '' ? password_hash((string) $password, PASSWORD_DEFAULT) : null);
		}

		$expiresAt = $this->request->getParam('expiresAt', $unset);
		if ($expiresAt !== $unset) {
			$share->setExpiresAt($this->parseExpiresAt($expiresAt !== null ? (string) $expiresAt : null));
		}

		$share->setUpdatedAt(new \DateTime());

		$saved = $this->shareMapper->update($share);
		return new DataResponse(['enabled' => true] + $this->toPayload($saved));
	}

	/**
	 * Token regenerieren: alte Link wird sofort ungültig, Passwort/Ablauf bleiben
	 * erhalten. Eigener Endpunkt statt Teil von update(), da es sich um eine
	 * bewusst destruktive Aktion handelt (nicht versehentlich per PUT auslösbar).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function regenerate(int $articleId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$share = $this->shareMapper->findByArticleId($articleId, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Share not found'], Http::STATUS_NOT_FOUND);
		}

		$share->setToken($this->newToken());
		$share->setUpdatedAt(new \DateTime());

		$saved = $this->shareMapper->update($share);
		return new DataResponse(['enabled' => true] + $this->toPayload($saved));
	}

	/**
	 * Share-Link widerrufen (Datensatz löschen). Danach liefert der alte Link
	 * über PublicShareController#show sofort 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(int $articleId): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		$this->shareMapper->deleteByArticleId($articleId, $this->userId);
		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}
}
