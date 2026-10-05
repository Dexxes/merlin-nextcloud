<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Ein Kommentar an einem Artikel: Thread-Wurzel (parentId null) oder Antwort.
 * userId ist der Besitzer des Artikels (wie bei Highlight), der Verfasser steht
 * in authorType ('owner' | 'guest') und authorName.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getArticleId()
 * @method void setArticleId(int $articleId)
 * @method int|null getHighlightId()
 * @method void setHighlightId(?int $highlightId)
 * @method string|null getQuotedText()
 * @method void setQuotedText(?string $quotedText)
 * @method int|null getParentId()
 * @method void setParentId(?int $parentId)
 * @method int|null getReplyToId()
 * @method void setReplyToId(?int $replyToId)
 * @method string getAuthorType()
 * @method void setAuthorType(string $authorType)
 * @method string getAuthorName()
 * @method void setAuthorName(string $authorName)
 * @method string getAuthorNameKey()
 * @method void setAuthorNameKey(string $authorNameKey)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method \DateTime|null getDeletedAt()
 * @method void setDeletedAt(?\DateTime $deletedAt)
 */
class Comment extends Entity implements JsonSerializable {
	public const AUTHOR_OWNER = 'owner';
	public const AUTHOR_GUEST = 'guest';

	protected $userId;
	protected $articleId;
	protected $highlightId;
	protected $quotedText;
	protected $parentId;
	protected $replyToId;
	protected $authorType;
	protected $authorName;
	protected $authorNameKey;
	protected $body;
	protected $createdAt;
	protected $updatedAt;
	protected $deletedAt;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('articleId', 'integer');
		$this->addType('highlightId', 'integer');
		$this->addType('quotedText', 'string');
		$this->addType('parentId', 'integer');
		$this->addType('replyToId', 'integer');
		$this->addType('authorType', 'string');
		$this->addType('authorName', 'string');
		$this->addType('authorNameKey', 'string');
		$this->addType('body', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('deletedAt', 'datetime');
	}

	public function isDeleted(): bool {
		return $this->getDeletedAt() !== null;
	}

	/**
	 * Wire-Format für Web und iOS. Der Namensschlüssel bleibt serverseitig;
	 * ein gelöschter Kommentar (Platzhalter einer Wurzel mit Antworten) zeigt
	 * weder Text noch Verfasser.
	 */
	public function jsonSerialize(): array {
		$deleted = $this->isDeleted();
		return [
			'id'          => $this->getId(),
			'articleId'   => $this->getArticleId(),
			'highlightId' => $this->getHighlightId(),
			'quotedText'  => $this->getQuotedText(),
			'parentId'    => $this->getParentId(),
			'replyToId'   => $this->getReplyToId(),
			'authorType'  => $this->getAuthorType(),
			'authorName'  => $deleted ? '' : $this->getAuthorName(),
			'body'        => $deleted ? '' : $this->getBody(),
			'deleted'     => $deleted,
			'createdAt'   => $this->getCreatedAt()->format('c'),
			'updatedAt'   => $this->getUpdatedAt()->format('c'),
			'edited'      => !$deleted && $this->getUpdatedAt() > $this->getCreatedAt(),
		];
	}
}
