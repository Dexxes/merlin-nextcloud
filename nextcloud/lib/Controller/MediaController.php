<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Service\Media\MediaResolverService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Liefert die abspielbare Audio-/Video-Quelle eines Artikels – siehe
 * MediaResolverService für die Provider-Kette und die bewusste
 * Produktentscheidung bei den Mediathek-Streams. Reine Auflösung pro
 * Request, nichts wird gespeichert/gecacht.
 *
 * Antwort: {available: false} oder
 *          {available: true, kind, delivery, variants[], defaultIndex}
 */
class MediaController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ArticleMapper $articleMapper,
		private MediaResolverService $mediaResolver,
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

		$resolved = $this->mediaResolver->resolveOnRequest(
			$article->getUrl(),
			(string) $article->getContent(),
			$this->userId,
		);
		if ($resolved === null) {
			return new DataResponse(['available' => false]);
		}

		return new DataResponse(['available' => true] + $resolved->toArray());
	}
}
