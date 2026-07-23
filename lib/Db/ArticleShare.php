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
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string|null getPasswordHash()
 * @method void setPasswordHash(?string $passwordHash)
 * @method \DateTime|null getExpiresAt()
 * @method void setExpiresAt(?\DateTime $expiresAt)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class ArticleShare extends Entity implements JsonSerializable {
	protected $userId;
	protected $articleId;
	protected $token;
	protected $passwordHash;
	protected $expiresAt;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('articleId', 'integer');
		$this->addType('token', 'string');
		$this->addType('passwordHash', 'string');
		$this->addType('expiresAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function hasPassword(): bool {
		return $this->getPasswordHash() !== null && $this->getPasswordHash() !== '';
	}

	public function isExpired(): bool {
		$expiresAt = $this->getExpiresAt();
		return $expiresAt !== null && $expiresAt < new \DateTime();
	}

	/**
	 * Wire-Format für die Verwaltungs-UI (Web/iOS/Android). Enthält NIE den
	 * Passwort-Hash selbst, nur ob ein Passwort gesetzt ist — der Hash bleibt
	 * serverseitig.
	 */
	public function jsonSerialize(): array {
		return [
			'articleId'  => $this->getArticleId(),
			'token'      => $this->getToken(),
			'hasPassword' => $this->hasPassword(),
			'expiresAt'  => $this->getExpiresAt() ? $this->getExpiresAt()->format('c') : null,
			'createdAt'  => $this->getCreatedAt()->format('c'),
			'updatedAt'  => $this->getUpdatedAt()->format('c'),
		];
	}
}
