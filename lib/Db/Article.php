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
 * @method string|null getSiteName()
 * @method void setSiteName(?string $siteName)
 * @method string|null getImageUrl()
 * @method void setImageUrl(?string $imageUrl)
 * @method bool getIsRead()
 * @method void setIsRead(bool $isRead)
 * @method bool getIsFavorite()
 * @method void setIsFavorite(bool $isFavorite)
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
 */
class Article extends Entity implements JsonSerializable {
	protected $userId;
	protected $url;
	protected $title;
	protected $content;
	protected $excerpt;
	protected $author;
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

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('url', 'string');
		$this->addType('title', 'string');
		$this->addType('content', 'string');
		$this->addType('excerpt', 'string');
		$this->addType('author', 'string');
		$this->addType('siteName', 'string');
		$this->addType('imageUrl', 'string');
		$this->addType('isRead', 'integer');
		$this->addType('isFavorite', 'integer');
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
			'siteName' => $this->getSiteName(),
			'imageUrl' => $this->getImageUrl(),
			'isRead' => (bool) $this->getIsRead(),
			'isFavorite' => (bool) $this->getIsFavorite(),
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
		];
	}
}
