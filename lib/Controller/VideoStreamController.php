<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Service\VideoStreamResolverService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Liefert die aktuell aufgelöste HLS-Stream-URL für Artikel von ARD
 * Mediathek/ZDF/Arte – siehe VideoStreamResolverService-Docblock für den
 * Hintergrund (bewusste, risikobehaftete Produktentscheidung, kein
 * offizieller Embed-Weg). Reine Auflösung pro Request, nichts wird
 * gespeichert/gecacht.
 */
class VideoStreamController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ArticleMapper $articleMapper,
		private VideoStreamResolverService $videoStreamResolver,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function resolve(int $id): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$article = $this->articleMapper->find($id, $this->userId);
		} catch (DoesNotExistException) {
			return new DataResponse(['error' => 'Article not found'], Http::STATUS_NOT_FOUND);
		}

		$resolved = $this->videoStreamResolver->resolve($article->getUrl());
		if ($resolved === null) {
			return new DataResponse(['available' => false]);
		}

		return new DataResponse([
			'available' => true,
			'type'      => $resolved['type'],
			'url'       => $resolved['url'],
		]);
	}
}
