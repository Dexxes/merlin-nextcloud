<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Gespeicherter Artikel oder Datei-Eintrag (Tabelle merlin_articles).
 *
 * Getter und Setter heißen wie beim Nextcloud-Entity OCA\Merlin\Db\Article und
 * haben dessen Laufzeittypen: is_read, is_archived, is_processing und
 * is_paywalled sind 0/1 (int), is_favorite ist der Zeitpunkt der
 * Favorisierung oder null. jsonSerialize() liefert dasselbe Wire-Format.
 *
 * Wie ein Entity merkt sich das Objekt, welche Felder gesetzt wurden
 * (changedFields()). Der Store schreibt beim Einfügen und Ändern nur diese
 * Spalten, damit z. B. das Speichern der Leseposition nicht den Inhalt
 * überschreibt, den die Extraktion im Hintergrund gerade geschrieben hat.
 */
final class Article implements \JsonSerializable {
	/** Feldnamen ohne id, in der Reihenfolge der Spalten. */
	public const FIELDS = ['userId', 'url', 'title', 'content', 'excerpt', 'author', 'authorUrl', 'authors', 'siteName', 'imageUrl', 'isRead', 'isFavorite', 'isArchived', 'readingTime', 'publishedAt', 'createdAt', 'updatedAt', 'archivedAt', 'isProcessing', 'category', 'scrollProgress', 'scrollUpdatedAt', 'requiresLoginDomain', 'requiresLoginPage', 'isPaywalled', 'paywallSubscribeUrl', 'unsupportedSiteDomain', 'siteIconUrl', 'fileId', 'fileMime', 'fileText'];

	private ?int $id = null;
	private string $userId = '';
	private string $url = '';
	private string $title = '';
	private string $content = '';
	private ?string $excerpt = null;
	private ?string $author = null;
	private ?string $authorUrl = null;
	private ?string $authors = null;
	private ?string $siteName = null;
	private ?string $imageUrl = null;
	private int $isRead = 0;
	private ?\DateTimeInterface $isFavorite = null;
	private int $isArchived = 0;
	private ?int $readingTime = 0;
	private ?\DateTimeInterface $publishedAt = null;
	private ?\DateTimeInterface $createdAt = null;
	private ?\DateTimeInterface $updatedAt = null;
	private ?\DateTimeInterface $archivedAt = null;
	private int $isProcessing = 0;
	private ?string $category = null;
	private ?float $scrollProgress = 0.0;
	private ?int $scrollUpdatedAt = 0;
	private ?string $requiresLoginDomain = null;
	private ?string $requiresLoginPage = null;
	private int $isPaywalled = 0;
	private ?string $paywallSubscribeUrl = null;
	private ?string $unsupportedSiteDomain = null;
	private ?string $siteIconUrl = null;
	private ?int $fileId = null;
	private ?string $fileMime = null;
	private ?string $fileText = null;

	/** @var array<string, true> */
	private array $changed = [];

	public function getId(): ?int {
		return $this->id;
	}

	public function setId(?int $id): void {
		$this->id = $id;
	}

	public function getUserId(): string {
		return $this->userId;
	}

	public function setUserId(string $userId): void {
		$this->userId = $userId;
		$this->changed['userId'] = true;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setUrl(string $url): void {
		$this->url = $url;
		$this->changed['url'] = true;
	}

	public function getTitle(): string {
		return $this->title;
	}

	public function setTitle(string $title): void {
		$this->title = $title;
		$this->changed['title'] = true;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function setContent(string $content): void {
		$this->content = $content;
		$this->changed['content'] = true;
	}

	public function getExcerpt(): ?string {
		return $this->excerpt;
	}

	public function setExcerpt(?string $excerpt): void {
		$this->excerpt = $excerpt;
		$this->changed['excerpt'] = true;
	}

	public function getAuthor(): ?string {
		return $this->author;
	}

	public function setAuthor(?string $author): void {
		$this->author = $author;
		$this->changed['author'] = true;
	}

	public function getAuthorUrl(): ?string {
		return $this->authorUrl;
	}

	public function setAuthorUrl(?string $authorUrl): void {
		$this->authorUrl = $authorUrl;
		$this->changed['authorUrl'] = true;
	}

	public function getAuthors(): ?string {
		return $this->authors;
	}

	public function setAuthors(?string $authors): void {
		$this->authors = $authors;
		$this->changed['authors'] = true;
	}

	public function getSiteName(): ?string {
		return $this->siteName;
	}

	public function setSiteName(?string $siteName): void {
		$this->siteName = $siteName;
		$this->changed['siteName'] = true;
	}

	public function getImageUrl(): ?string {
		return $this->imageUrl;
	}

	public function setImageUrl(?string $imageUrl): void {
		$this->imageUrl = $imageUrl;
		$this->changed['imageUrl'] = true;
	}

	public function getIsRead(): int {
		return $this->isRead;
	}

	public function setIsRead(int $isRead): void {
		$this->isRead = $isRead;
		$this->changed['isRead'] = true;
	}

	public function getIsFavorite(): ?\DateTimeInterface {
		return $this->isFavorite;
	}

	public function setIsFavorite(?\DateTimeInterface $isFavorite): void {
		$this->isFavorite = $isFavorite;
		$this->changed['isFavorite'] = true;
	}

	public function getIsArchived(): int {
		return $this->isArchived;
	}

	public function setIsArchived(int $isArchived): void {
		$this->isArchived = $isArchived;
		$this->changed['isArchived'] = true;
	}

	public function getReadingTime(): ?int {
		return $this->readingTime;
	}

	public function setReadingTime(?int $readingTime): void {
		$this->readingTime = $readingTime;
		$this->changed['readingTime'] = true;
	}

	public function getPublishedAt(): ?\DateTimeInterface {
		return $this->publishedAt;
	}

	public function setPublishedAt(?\DateTimeInterface $publishedAt): void {
		$this->publishedAt = $publishedAt;
		$this->changed['publishedAt'] = true;
	}

	public function getCreatedAt(): ?\DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt(?\DateTimeInterface $createdAt): void {
		$this->createdAt = $createdAt;
		$this->changed['createdAt'] = true;
	}

	public function getUpdatedAt(): ?\DateTimeInterface {
		return $this->updatedAt;
	}

	public function setUpdatedAt(?\DateTimeInterface $updatedAt): void {
		$this->updatedAt = $updatedAt;
		$this->changed['updatedAt'] = true;
	}

	public function getArchivedAt(): ?\DateTimeInterface {
		return $this->archivedAt;
	}

	public function setArchivedAt(?\DateTimeInterface $archivedAt): void {
		$this->archivedAt = $archivedAt;
		$this->changed['archivedAt'] = true;
	}

	public function getIsProcessing(): int {
		return $this->isProcessing;
	}

	public function setIsProcessing(int $isProcessing): void {
		$this->isProcessing = $isProcessing;
		$this->changed['isProcessing'] = true;
	}

	public function getCategory(): ?string {
		return $this->category;
	}

	public function setCategory(?string $category): void {
		$this->category = $category;
		$this->changed['category'] = true;
	}

	public function getScrollProgress(): ?float {
		return $this->scrollProgress;
	}

	public function setScrollProgress(?float $scrollProgress): void {
		$this->scrollProgress = $scrollProgress;
		$this->changed['scrollProgress'] = true;
	}

	public function getScrollUpdatedAt(): ?int {
		return $this->scrollUpdatedAt;
	}

	public function setScrollUpdatedAt(?int $scrollUpdatedAt): void {
		$this->scrollUpdatedAt = $scrollUpdatedAt;
		$this->changed['scrollUpdatedAt'] = true;
	}

	public function getRequiresLoginDomain(): ?string {
		return $this->requiresLoginDomain;
	}

	public function setRequiresLoginDomain(?string $requiresLoginDomain): void {
		$this->requiresLoginDomain = $requiresLoginDomain;
		$this->changed['requiresLoginDomain'] = true;
	}

	public function getRequiresLoginPage(): ?string {
		return $this->requiresLoginPage;
	}

	public function setRequiresLoginPage(?string $requiresLoginPage): void {
		$this->requiresLoginPage = $requiresLoginPage;
		$this->changed['requiresLoginPage'] = true;
	}

	public function getIsPaywalled(): int {
		return $this->isPaywalled;
	}

	public function setIsPaywalled(int $isPaywalled): void {
		$this->isPaywalled = $isPaywalled;
		$this->changed['isPaywalled'] = true;
	}

	public function getPaywallSubscribeUrl(): ?string {
		return $this->paywallSubscribeUrl;
	}

	public function setPaywallSubscribeUrl(?string $paywallSubscribeUrl): void {
		$this->paywallSubscribeUrl = $paywallSubscribeUrl;
		$this->changed['paywallSubscribeUrl'] = true;
	}

	public function getUnsupportedSiteDomain(): ?string {
		return $this->unsupportedSiteDomain;
	}

	public function setUnsupportedSiteDomain(?string $unsupportedSiteDomain): void {
		$this->unsupportedSiteDomain = $unsupportedSiteDomain;
		$this->changed['unsupportedSiteDomain'] = true;
	}

	public function getSiteIconUrl(): ?string {
		return $this->siteIconUrl;
	}

	public function setSiteIconUrl(?string $siteIconUrl): void {
		$this->siteIconUrl = $siteIconUrl;
		$this->changed['siteIconUrl'] = true;
	}

	public function getFileId(): ?int {
		return $this->fileId;
	}

	public function setFileId(?int $fileId): void {
		$this->fileId = $fileId;
		$this->changed['fileId'] = true;
	}

	public function getFileMime(): ?string {
		return $this->fileMime;
	}

	public function setFileMime(?string $fileMime): void {
		$this->fileMime = $fileMime;
		$this->changed['fileMime'] = true;
	}

	public function getFileText(): ?string {
		return $this->fileText;
	}

	public function setFileText(?string $fileText): void {
		$this->fileText = $fileText;
		$this->changed['fileText'] = true;
	}
	/** @return list<string> seit dem Laden gesetzte Felder */
	public function changedFields(): array {
		return array_keys($this->changed);
	}

	public function resetChanges(): void {
		$this->changed = [];
	}

	/**
	 * Speicherform für setAuthors(): JSON oder null bei leerer Liste.
	 *
	 * @param list<array{name: string, url: ?string, image?: string, bio?: string}>|null $authors
	 */
	public static function encodeAuthors(?array $authors): ?string {
		if ($authors === null || $authors === []) {
			return null;
		}
		$json = json_encode(array_values($authors), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return $json === false ? null : $json;
	}

	/**
	 * @return list<array{name: string, url: ?string, image?: string, bio?: string}>|null
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
				$author = [
					'name' => $entry['name'],
					'url'  => is_string($entry['url'] ?? null) ? $entry['url'] : null,
				];
				// Foto und Kurzbiografie für die Autorenbox (<metadata><author-image>/
				// <author-bio>), nur wenn vorhanden.
				if (is_string($entry['image'] ?? null) && preg_match('#^https?://#i', $entry['image']) === 1) {
					$author['image'] = $entry['image'];
				}
				if (is_string($entry['bio'] ?? null) && $entry['bio'] !== '') {
					$author['bio'] = $entry['bio'];
				}
				$authors[] = $author;
			}
		}
		return $authors !== [] ? $authors : null;
	}

	/**
	 * Archivstatus setzen und archived_at mitführen – die Löschfrist zählt ab
	 * archived_at (RetentionPolicy). Erneutes Archivieren eines bereits
	 * archivierten Artikels behält das ursprüngliche Datum; Herausholen aus dem
	 * Archiv leert es.
	 */
	public function applyArchived(bool $archived): void {
		if ($archived) {
			if (!$this->isArchived || $this->archivedAt === null) {
				$this->setArchivedAt(new \DateTime());
			}
		} else {
			$this->setArchivedAt(null);
		}
		$this->setIsArchived($archived ? 1 : 0);
	}

	/** Wire-Format wie OCA\Merlin\Db\Article::jsonSerialize(). */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'url' => $this->url,
			'title' => $this->title,
			'content' => $this->content,
			'excerpt' => $this->excerpt,
			'author' => $this->author,
			'authorUrl' => $this->authorUrl,
			'authors' => self::decodeAuthors($this->authors),
			'siteName' => $this->siteName,
			'imageUrl' => $this->imageUrl,
			'isRead' => (bool) $this->isRead,
			// false (nicht favorisiert) oder ISO-8601-Zeitpunkt der Favorisierung.
			'isFavorite' => $this->isFavorite ? $this->isFavorite->format('c') : false,
			'isArchived' => (bool) $this->isArchived,
			'readingTime' => $this->readingTime,
			'publishedAt' => $this->publishedAt?->format('c'),
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
			'archivedAt' => $this->archivedAt?->format('c'),
			'isProcessing' => (bool) $this->isProcessing,
			'category' => $this->category,
			'scrollProgress' => (float) ($this->scrollProgress ?? 0),
			'scrollUpdatedAt' => (int) ($this->scrollUpdatedAt ?? 0),
			// null im Normalfall; gesetzt: Client bietet einen Login-Dialog an.
			'requiresLoginDomain' => $this->requiresLoginDomain,
			'requiresLoginPage' => $this->requiresLoginPage,
			// true: Bezahlartikel ohne Login-Konfiguration (Hinweis mit Abo-Link).
			'isPaywalled' => (bool) $this->isPaywalled,
			'paywallSubscribeUrl' => $this->paywallSubscribeUrl,
			// gesetzt: Domain steht in content-filters/$unsupported.xml.
			'unsupportedSiteDomain' => $this->unsupportedSiteDomain,
			// null bei Web-Artikeln, gesetzt bei Einträgen aus „Merlin Dateien“.
			'fileId' => $this->fileId,
			'fileMime' => $this->fileMime,
		];
	}

	/** Felder für Export, PDF, Vorlesen und Support-Box. */
	public function toSnapshot(): ArticleSnapshot {
		return new ArticleSnapshot(
			url: $this->url,
			title: $this->title,
			author: $this->author,
			siteName: $this->siteName,
			siteIconUrl: $this->siteIconUrl,
			content: $this->content,
			excerpt: $this->excerpt,
			category: $this->category,
			createdAt: $this->createdAt,
		);
	}
}
