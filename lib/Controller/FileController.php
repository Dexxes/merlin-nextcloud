<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\TagMapper;
use OCA\Merlin\Service\MerlinFileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\ISession;
use Psr\Log\LoggerInterface;

/**
 * Dateien vom Handy in „Merlin Dateien“ (siehe Service\MerlinFileService):
 *
 *   POST /api/files/target       Zielpfad für den WebDAV-Upload holen
 *   POST /api/files              hochgeladene Datei als Eintrag anlegen
 *   GET  /api/articles/{id}/file Datei bzw. Vorschaubild über signierten Link
 *
 * NoCSRFRequired wie bei ArticleController (native Clients mit Basic-Auth,
 * siehe Docblock dort).
 */
class FileController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private MerlinFileService $files,
		private ArticleMapper $articleMapper,
		private TagMapper $tagMapper,
		private ISession $session,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Antwort { path, davPath, uploadsPath, kind }. davPath/uploadsPath sind
	 * relativ zur Nextcloud-Basis-URL des Clients.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function target(string $name = '', string $mimeType = ''): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new DataResponse($this->files->uploadTarget($this->userId, $name, $mimeType));
		} catch (\Throwable $e) {
			$this->logger->error('Merlin: could not prepare file upload', ['exception' => $e]);
			return new DataResponse(['error' => 'Could not prepare the Merlin files folder'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Legt den Eintrag für die Datei unter $path an. tagIds wie bei
	 * POST /api/articles (Query-Parameter tagIds[] oder JSON-Body).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function register(string $path = ''): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}
		if ($path === '') {
			return new DataResponse(['error' => 'Missing path'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$article = $this->files->register($this->userId, $path);
		} catch (NotFoundException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('Merlin: could not register file', ['exception' => $e]);
			return new DataResponse(['error' => 'Could not save the file entry'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$rawTagIds = $this->request->getParam('tagIds');
		$tagIds = is_array($rawTagIds)
			? array_values(array_unique(array_filter(array_map('intval', $rawTagIds), fn($v) => $v > 0)))
			: [];
		$existing = array_map(fn($tag) => (int) $tag->getId(), $this->tagMapper->findByArticleId((int) $article->getId()));
		foreach (array_diff($tagIds, $existing) as $tagId) {
			try {
				$this->tagMapper->find($tagId, $this->userId); // nur eigene Tags
				$this->tagMapper->addToArticle((int) $article->getId(), $tagId);
			} catch (\Throwable) {
				// Unbekannter oder fremder Tag: ignorieren wie bei ArticleController::create().
			}
		}

		$data = $article->jsonSerialize();
		$data['tags'] = array_map(fn($tag) => $tag->jsonSerialize(), $this->tagMapper->findByArticleId((int) $article->getId()));
		return new DataResponse($data, Http::STATUS_CREATED);
	}

	/**
	 * Datei eines Eintrags. Ohne Login, aber nur mit gültigem Token t (siehe
	 * MerlinFileService). size = Vorschaubild, download = als Anhang.
	 *
	 * @PublicPage
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function content(int $id, string $t = '', ?int $size = null, int $download = 0): void {
		// Kein Session-Lock während langer Streams (Video spulen = parallele Requests).
		$this->session->close();
		try {
			$article = $this->articleMapper->findById($id);
		} catch (DoesNotExistException) {
			$article = null;
		}
		if ($article === null || !$this->files->verifyToken($article, $t)) {
			http_response_code(404);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'Not found']);
			exit();
		}
		$this->files->stream($article, $size, $download === 1);
	}
}
