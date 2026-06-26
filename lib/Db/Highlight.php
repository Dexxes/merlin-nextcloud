<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getArticleId()
 * @method void setArticleId(int $articleId)
 * @method string getHighlightedText()
 * @method void setHighlightedText(string $highlightedText)
 * @method string getStartXpath()
 * @method void setStartXpath(string $startXpath)
 * @method int getStartOffset()
 * @method void setStartOffset(int $startOffset)
 * @method string getEndXpath()
 * @method void setEndXpath(string $endXpath)
 * @method int getEndOffset()
 * @method void setEndOffset(int $endOffset)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Highlight extends Entity implements JsonSerializable {
	protected $userId;
	protected $articleId;
	protected $highlightedText;
	protected $startXpath;
	protected $startOffset;
	protected $endXpath;
	protected $endOffset;
	protected $color;
	protected $createdAt;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('articleId', 'integer');
		$this->addType('highlightedText', 'string');
		$this->addType('startXpath', 'string');
		$this->addType('startOffset', 'integer');
		$this->addType('endXpath', 'string');
		$this->addType('endOffset', 'integer');
		$this->addType('color', 'string');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id'              => $this->getId(),
			'articleId'       => $this->getArticleId(),
			'highlightedText' => $this->getHighlightedText(),
			'startXpath'      => $this->getStartXpath(),
			'startOffset'     => $this->getStartOffset(),
			'endXpath'        => $this->getEndXpath(),
			'endOffset'       => $this->getEndOffset(),
			'color'           => $this->getColor(),
			'createdAt'       => $this->getCreatedAt()->format('c'),
		];
	}
}
