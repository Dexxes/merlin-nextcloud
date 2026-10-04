<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method string|null getExcerpt()
 * @method void setExcerpt(?string $excerpt)
 * @method string|null getAuthor()
 * @method void setAuthor(?string $author)
 * @method string|null getAuthorUrl()
 * @method void setAuthorUrl(?string $authorUrl)
 * @method string|null getAuthors()
 * @method void setAuthors(?string $authors)
 * @method string|null getSiteName()
 * @method void setSiteName(?string $siteName)
 * @method string|null getImageUrl()
 * @method void setImageUrl(?string $imageUrl)
 * @method bool getIsRead()
 * @method void setIsRead(bool $isRead)
 * @method \DateTime|null getIsFavorite()
 * @method void setIsFavorite(?\DateTime $isFavorite)
 * @method bool getIsArchived()
 * @method void setIsArchived(bool $isArchived)
 * @method int getReadingTime()
 * @method void setReadingTime(int $readingTime)
 * @method \DateTime|null getPublishedAt()
 * @method void setPublishedAt(?\DateTime $publishedAt)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method \DateTime|null getArchivedAt()
 * @method void setArchivedAt(?\DateTime $archivedAt)
 * @method int getIsProcessing()
 * @method void setIsProcessing(int $isProcessing)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method float getScrollProgress()
 * @method void setScrollProgress(float $scrollProgress)
 * @method int getScrollUpdatedAt()
 * @method void setScrollUpdatedAt(int $scrollUpdatedAt)
 * @method string|null getRequiresLoginDomain()
 * @method void setRequiresLoginDomain(?string $requiresLoginDomain)
 * @method string|null getRequiresLoginPage()
 * @method void setRequiresLoginPage(?string $requiresLoginPage)
 * @method bool getIsPaywalled()
 * @method void setIsPaywalled(bool $isPaywalled)
 * @method string|null getPaywallSubscribeUrl()
 * @method void setPaywallSubscribeUrl(?string $paywallSubscribeUrl)
 * @method string|null getUnsupportedSiteDomain()
 * @method void setUnsupportedSiteDomain(?string $unsupportedSiteDomain)
 * @method string|null getSiteIconUrl()
 * @method void setSiteIconUrl(?string $siteIconUrl)
 */
class Article extends Entity implements JsonSerializable {
	protected $userId;
	protected $url;
	protected $title;
	protected $content;
	protected $excerpt;
	protected $author;
	// Link zum Autorenprofil der Quelle (ContentExtractorService::
	// resolveAuthorMetadata()). null, wenn keiner erkennbar war, bei
	// mehreren Autoren und bei Artikeln aus der Zeit vor der Spalte.
	protected $authorUrl;
	// JSON-Liste [{"name": …, "url": …|null}] je Autor, damit auch Co-Autoren
	// einzeln verlinkt werden können. null, wenn kein Profil-Link erkannt
	// wurde. In der API als Array "authors" (siehe jsonSerialize()).
	protected $authors;
	protected $siteName;
	protected $imageUrl;
	protected $isRead;
	protected $isFavorite;
	protected $isArchived;
	protected $readingTime;
	protected $publishedAt;
	protected $createdAt;
	protected $updatedAt;
	protected $archivedAt;
	protected $isProcessing;
	protected $category;
	protected $scrollProgress;
	protected $scrollUpdatedAt;
	// Gesetzt, wenn die Extraktion an einer Paywall scheiterte, für die der
	// Nutzer keine (gültigen) Zugangsdaten hinterlegt hat (siehe
	// Service\Login\PaywallLoginRequiredException, ArticleController::create()).
	// requiresLoginDomain === null ist der Normalfall (keine Paywall-Sperre).
	protected $requiresLoginDomain;
	protected $requiresLoginPage;
	// Gesetzt, wenn der Extractor per <paywall><marker xpath="…"> (siehe
	// ContentFilterSchema) einen Bezahlartikel erkannt hat, für dessen Domain
	// KEINE <login>-Konfiguration existiert (sonst greift stattdessen
	// requiresLoginDomain, siehe oben). Merlin kann den Artikel dann nicht
	// automatisch freischalten - der Client zeigt einen Hinweis mit den
	// Optionen "Abo abschliessen" (paywallSubscribeUrl) oder "Archivieren".
	protected $isPaywalled;
	protected $paywallSubscribeUrl;
	// Gesetzt, wenn die Domain in content-filters/$unsupported.xml steht (siehe
	// Service\UnsupportedSiteException) - Merlin hat den Fetch gar nicht erst
	// versucht, weil die Seite grundsätzlich nichts scrapbares ausliefert (z. B.
	// PressReader, eine reine JS-SPA/Bild-Viewer). null im Normalfall. Anders
	// als requiresLoginDomain gibt es hier keinen Login-Dialog, der das beheben
	// könnte - der Client zeigt nur einen erklärenden Hinweis.
	protected $unsupportedSiteDomain;
	// Icon der konkreten Artikelseite (apple-touch-icon / <link rel="icon"> / …),
	// beim Extrahieren aus dem HTML gelesen (ContentExtractorService::
	// extractSiteIconUrl()). null bei Artikeln aus der Zeit vor der Spalte.
	// Bewusst NICHT in jsonSerialize(): nur die Support-Infobox braucht es
	// (supportBox.iconUrl, Service\SupportBoxService), Listen bleiben schlank.
	protected $siteIconUrl;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('url', 'string');
		$this->addType('title', 'string');
		$this->addType('content', 'string');
		$this->addType('excerpt', 'string');
		$this->addType('author', 'string');
		$this->addType('authorUrl', 'string');
		$this->addType('authors', 'string');
		$this->addType('siteName', 'string');
		$this->addType('imageUrl', 'string');
		$this->addType('isRead', 'integer');
		// isFavorite ist bewusst kein Bool-Flag + separates Timestamp-Feld
		// (wie is_archived/archivedAt), sondern EIN Feld: NULL = nicht
		// favorisiert, DateTime = Zeitpunkt der Favorisierung. So bleibt der
		// Favoriten-Filter *und* die chronologische Sortierung ohne
		// zusätzliches API-Feld möglich.
		$this->addType('isFavorite', 'datetime');
		$this->addType('isArchived', 'integer');
		$this->addType('readingTime', 'integer');
		$this->addType('publishedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('archivedAt', 'datetime');
		$this->addType('isProcessing', 'integer');
		$this->addType('category', 'string');
		$this->addType('scrollProgress', 'float');
		$this->addType('scrollUpdatedAt', 'integer');
		$this->addType('requiresLoginDomain', 'string');
		$this->addType('requiresLoginPage', 'string');
		$this->addType('isPaywalled', 'integer');
		$this->addType('paywallSubscribeUrl', 'string');
		$this->addType('unsupportedSiteDomain', 'string');
		$this->addType('siteIconUrl', 'string');
	}

	/**
	 * Speicherform für setAuthors(): JSON oder null bei leerer Liste.
	 *
	 * @param list<array{name: string, url: ?string}>|null $authors
	 */
	public static function encodeAuthors(?array $authors): ?string {
		if ($authors === null || $authors === []) {
			return null;
		}
		$json = json_encode(array_values($authors), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return $json === false ? null : $json;
	}

	/**
	 * @return list<array{name: string, url: ?string}>|null
	 */
	public static function decodeAuthors(?string $json): ?array {
		if ($json === null || $json === '') {
			return null;
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return null;
		}
		$authors = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '') {
				$authors[] = [
					'name' => $entry['name'],
					'url'  => is_string($entry['url'] ?? null) ? $entry['url'] : null,
				];
			}
		}
		return $authors !== [] ? $authors : null;
	}

	/**
	 * Archivstatus setzen und archived_at mitführen – die Löschfrist zählt ab
	 * archived_at (RetentionPolicy). Erneutes Archivieren eines bereits
	 * archivierten Artikels behält das ursprüngliche Datum; Herausholen aus dem
	 * Archiv leert es. Alle Archivierungswege (Toggle, update(), Pocket-API)
	 * gehen hierüber, damit kein archivierter Artikel ohne Datum entsteht.
	 */
	public function applyArchived(bool $archived): void {
		if ($archived) {
			if (!$this->getIsArchived() || $this->getArchivedAt() === null) {
				$this->setArchivedAt(new \DateTime());
			}
		} else {
			$this->setArchivedAt(null);
		}
		$this->setIsArchived($archived);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'url' => $this->getUrl(),
			'title' => $this->getTitle(),
			'content' => $this->getContent(),
			'excerpt' => $this->getExcerpt(),
			'author' => $this->getAuthor(),
			'authorUrl' => $this->getAuthorUrl(),
			'authors' => self::decodeAuthors($this->getAuthors()),
			'siteName' => $this->getSiteName(),
			'imageUrl' => $this->getImageUrl(),
			'isRead' => (bool) $this->getIsRead(),
			// Wire-Format: false (nicht favorisiert) ODER ISO8601-Zeitstempel
			// (Favorisierungszeitpunkt) — kein separates favoritedAt-Feld.
			'isFavorite' => $this->getIsFavorite() ? $this->getIsFavorite()->format('c') : false,
			'isArchived' => (bool) $this->getIsArchived(),
			'readingTime' => $this->getReadingTime(),
			'publishedAt' => $this->getPublishedAt() ? $this->getPublishedAt()->format('c') : null,
			'createdAt' => $this->getCreatedAt()->format('c'),
			'updatedAt' => $this->getUpdatedAt()->format('c'),
			'archivedAt'   => $this->getArchivedAt() ? $this->getArchivedAt()->format('c') : null,
			'isProcessing' => (bool) $this->getIsProcessing(),
			'category'     => $this->getCategory(),
			'scrollProgress'  => (float) ($this->getScrollProgress() ?? 0),
			'scrollUpdatedAt' => (int) ($this->getScrollUpdatedAt() ?? 0),
			// null im Normalfall. Gesetzt: Client soll einen Login-Dialog für
			// requiresLoginDomain anbieten (requiresLoginPage als Info-Link),
			// danach das Speichern erneut anstoßen (POST /api/articles erneut,
			// kein dedizierter Retry-Endpunkt).
			'requiresLoginDomain' => $this->getRequiresLoginDomain(),
			'requiresLoginPage'   => $this->getRequiresLoginPage(),
			// false im Normalfall. true: Client soll einen Hinweis "Bezahlartikel"
			// mit den Optionen "Abo abschliessen" (paywallSubscribeUrl, falls
			// gesetzt) und "Archivieren" anzeigen - anders als bei
			// requiresLoginDomain kann Merlin hier NICHT automatisch einloggen.
			'isPaywalled'         => (bool) $this->getIsPaywalled(),
			'paywallSubscribeUrl' => $this->getPaywallSubscribeUrl(),
			// null im Normalfall. Gesetzt: Merlin hat den Fetch abgelehnt, weil die
			// Domain als grundsätzlich nicht scrapbar bekannt ist (siehe
			// Service\UnsupportedSiteException) - der Client zeigt einen
			// erklärenden Hinweis statt eines Retry-Buttons, da ein erneuter
			// Versuch am selben Ergebnis nichts ändert.
			'unsupportedSiteDomain' => $this->getUnsupportedSiteDomain(),
		];
	}
}
