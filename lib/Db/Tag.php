<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class Tag extends Entity implements JsonSerializable {
	protected $userId;
	protected $name;
	protected $color;
	protected $createdAt;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('name', 'string');
		$this->addType('color', 'string');
		$this->addType('createdAt', 'datetime');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'name' => $this->getName(),
			'color' => $this->getColor(),
			'createdAt' => $this->getCreatedAt()->format('c'),
		];
	}
}
