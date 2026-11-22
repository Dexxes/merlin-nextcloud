<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Service\Media\MediaResolverService;
use OCA\Merlin\Service\Media\MediaResult;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Veralteter Endpunkt GET /api/articles/{id}/video-stream, nur noch für
 * bestehende Clients. Neue Clients nutzen GET /api/articles/{id}/media
 * (MediaController), das zusätzlich Audio, direkte Dateien und Embeds
 * liefert.
 *
 * Antwortet im alten Format und nur für HLS-Videos, also genau für die
 * Fälle, die der Endpunkt vor Einführung der Medien-Provider abdeckte (ARD
 * Mediathek, ZDF, Arte).
 */
class VideoStreamController extends Controller {
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
		if ($resolved === null
			|| $resolved->kind !== MediaResult::KIND_VIDEO
			|| $resolved->delivery !== MediaResult::DELIVERY_HLS) {
			return new DataResponse(['available' => false]);
		}

		return new DataResponse([
			'available'    => true,
			'type'         => 'hls',
			'variants'     => $resolved->variants,
			'defaultIndex' => $resolved->defaultIndex,
		]);
	}
}
