<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Farbe eines Gast-Namens an einem Artikel. Jeder Name (über nameKey
 * verglichen) hat an einem Artikel genau eine Farbe, und keine Farbe gehört
 * zwei Namen (eindeutige Indizes). Der Besitzer hat immer
 * CommentRules::OWNER_COLOR und steht nicht in dieser Tabelle.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getArticleId()
 * @method void setArticleId(int $articleId)
 * @method string getNameKey()
 * @method void setNameKey(string $nameKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class CommentGuest extends Entity {
	protected $userId;
	protected $articleId;
	protected $nameKey;
	protected $name;
	protected $color;
	protected $createdAt;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('articleId', 'integer');
		$this->addType('nameKey', 'string');
		$this->addType('name', 'string');
		$this->addType('color', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
