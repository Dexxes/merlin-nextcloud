<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCA\Merlin\Db\ArticleMapper;
use OCA\Merlin\Db\HighlightMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Speicherverbrauch des Nutzers in der Datenbank (Artikel- + Highlight-
 * Textspalten), für die "Speicher"-Anzeige in den iOS-Einstellungen.
 * Pendant zu AccountController::storageUsage() in merlin-standalone-server.
 *
 * NoCSRFRequired analog zu SettingsController - der Endpunkt wird von
 * nativen Clients (Basic/Bearer-Auth) angesprochen, siehe Docblock dort.
 */
class StorageController extends Controller {
	private ArticleMapper $articleMapper;
	private HighlightMapper $highlightMapper;
	private ?string $userId;

	public function __construct(
		string $appName,
		IRequest $request,
		ArticleMapper $articleMapper,
		HighlightMapper $highlightMapper,
		?string $userId
	) {
		parent::__construct($appName, $request);
		$this->articleMapper = $articleMapper;
		$this->highlightMapper = $highlightMapper;
		$this->userId = $userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(): DataResponse {
		$articles = $this->articleMapper->getStorageStats($this->userId);
		$highlights = $this->highlightMapper->getStorageStats($this->userId);

		return new DataResponse([
			'articleCount' => $articles['count'],
			'highlightCount' => $highlights['count'],
			'articleBytes' => $articles['bytes'],
			'highlightBytes' => $highlights['bytes'],
			'totalBytes' => $articles['bytes'] + $highlights['bytes'],
		]);
	}
}
