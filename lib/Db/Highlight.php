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
 * @method string getAuthorType()
 * @method void setAuthorType(string $authorType)
 * @method string|null getAuthorName()
 * @method void setAuthorName(?string $authorName)
 * @method string|null getAuthorNameKey()
 * @method void setAuthorNameKey(?string $authorNameKey)
 * @method int|null getPdfPage()
 * @method void setPdfPage(?int $pdfPage)
 * @method string|null getPdfRects()
 * @method void setPdfRects(?string $pdfRects)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 *
 * userId ist immer der Besitzer des Artikels. Gäste markieren über den
 * öffentlichen Link (authorType 'guest', authorName = gewählter Name).
 *
 * Markierungen in PDFs tragen pdfPage und pdfRects (JSON, normierte
 * Rechtecke auf der Seite); start/endXpath ist dort nur der Platzhalter
 * CommentRules::pdfXpath(), den alte Clients nicht finden und überspringen.
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
	protected $authorType = 'owner';
	protected $authorName;
	protected $authorNameKey;
	protected $pdfPage;
	protected $pdfRects;
	protected $revision = 0;

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
		$this->addType('authorType', 'string');
		$this->addType('authorName', 'string');
		$this->addType('authorNameKey', 'string');
		$this->addType('pdfPage', 'integer');
		$this->addType('pdfRects', 'string');
		$this->addType('revision', 'integer');
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
			'authorType'      => $this->getAuthorType() ?? 'owner',
			'authorName'      => $this->getAuthorName(),
			'pdfPage'         => $this->getPdfPage(),
			'pdfRects'        => $this->getPdfRects() !== null ? json_decode($this->getPdfRects(), true) : null,
		];
	}
}
