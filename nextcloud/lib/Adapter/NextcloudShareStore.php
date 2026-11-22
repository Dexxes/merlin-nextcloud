<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Domain\ArticleShare;
use Merlin\Core\Port\ShareStore;
use OCA\Merlin\Db\ArticleShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * ShareStore-Port des Kerns über ArticleShareMapper (nur lesend; Anlegen und
 * Ändern von Links bleibt in ShareController).
 */
class NextcloudShareStore implements ShareStore {
	public function __construct(
		private ArticleShareMapper $mapper,
	) {
	}

	public function findByToken(string $token): ?ArticleShare {
		try {
			$entity = $this->mapper->findByToken($token);
		} catch (DoesNotExistException) {
			return null;
		}
		$share = new ArticleShare();
		EntityCopy::toDomain($entity, $share, ['userId', 'articleId', 'token', 'passwordHash', 'expiresAt', 'allowComments']);
		return $share;
	}
}
