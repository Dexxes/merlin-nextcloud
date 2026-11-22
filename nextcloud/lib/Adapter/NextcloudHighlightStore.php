<?php

declare(strict_types=1);

namespace OCA\Merlin\Adapter;

use Merlin\Core\Domain\Highlight;
use Merlin\Core\Port\HighlightStore;
use OCA\Merlin\Db\Highlight as HighlightEntity;
use OCA\Merlin\Db\HighlightMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * HighlightStore-Port des Kerns über HighlightMapper.
 */
class NextcloudHighlightStore implements HighlightStore {
	private const FIELDS = ['userId', 'articleId', 'highlightedText', 'startXpath', 'startOffset', 'endXpath',
		'endOffset', 'color', 'createdAt', 'authorType', 'authorName', 'authorNameKey', 'pdfPage', 'pdfRects', 'revision'];
	private const DATES = ['createdAt'];

	public function __construct(
		private HighlightMapper $mapper,
	) {
	}

	public function findByArticleId(int $articleId, string $userId): array {
		return array_values(array_map(self::toDomain(...), $this->mapper->findByArticleId($articleId, $userId)));
	}

	public function findById(int $id, string $userId): ?Highlight {
		try {
			return self::toDomain($this->mapper->findById($id, $userId));
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findInArticle(int $id, int $articleId, string $userId): ?Highlight {
		try {
			return self::toDomain($this->mapper->findInArticle($id, $articleId, $userId));
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function insert(Highlight $highlight): Highlight {
		$entity = new HighlightEntity();
		EntityCopy::toEntity($highlight, $entity, self::FIELDS, self::DATES);
		$entity = $this->mapper->insert($entity);
		$highlight->setId((int) $entity->getId());
		return $highlight;
	}

	public function update(Highlight $highlight): Highlight {
		$entity = $this->mapper->findById((int) $highlight->getId(), $highlight->getUserId());
		EntityCopy::toEntity($highlight, $entity, self::FIELDS, self::DATES);
		$this->mapper->update($entity);
		return $highlight;
	}

	public function deleteById(int $id, string $userId): void {
		$this->mapper->deleteById($id, $userId);
	}

	public function countGuestSince(int $articleId, string $userId, \DateTime $since): int {
		return $this->mapper->countGuestSince($articleId, $userId, $since);
	}

	public function signature(int $articleId, string $userId): string {
		return $this->mapper->signature($articleId, $userId);
	}

	private static function toDomain(HighlightEntity $entity): Highlight {
		$highlight = new Highlight();
		EntityCopy::toDomain($entity, $highlight, self::FIELDS);
		return $highlight;
	}
}
