<?php

declare(strict_types=1);

namespace Merlin\Core\Domain;

/**
 * Unveränderliche Sicht auf die Felder eines gespeicherten Artikels, die
 * Kern-Services zum Ausgeben brauchen (Export, PDF-Durchreichung, Vorlesen,
 * Support-Box). Die Getter heißen wie die des Nextcloud-Entities, damit der
 * Code der Services gleich bleibt.
 *
 * Nextcloud: OCA\Merlin\Db\Article::toSnapshot(). Etappe 3 (gemeinsame
 * Persistenz) ersetzt das durch ein vollständiges Domain-Objekt.
 */
final class ArticleSnapshot {
	public function __construct(
		private string $url,
		private ?string $title = null,
		private ?string $author = null,
		private ?string $siteName = null,
		private ?string $siteIconUrl = null,
		private ?string $content = null,
		private ?string $excerpt = null,
		private ?string $category = null,
		private ?\DateTimeInterface $createdAt = null,
	) {
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function getTitle(): ?string {
		return $this->title;
	}

	public function getAuthor(): ?string {
		return $this->author;
	}

	public function getSiteName(): ?string {
		return $this->siteName;
	}

	public function getSiteIconUrl(): ?string {
		return $this->siteIconUrl;
	}

	public function getContent(): ?string {
		return $this->content;
	}

	public function getExcerpt(): ?string {
		return $this->excerpt;
	}

	public function getCategory(): ?string {
		return $this->category;
	}

	public function getCreatedAt(): ?\DateTimeInterface {
		return $this->createdAt;
	}
}
