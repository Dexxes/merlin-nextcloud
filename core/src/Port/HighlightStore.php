<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\Highlight;

/**
 * Ablage der Markierungen (Tabelle merlin_highlights), begrenzt auf den
 * Besitzer des Artikels ($userId).
 */
interface HighlightStore {
	/** @return list<Highlight> */
	public function findByArticleId(int $articleId, string $userId): array;

	public function findById(int $id, string $userId): ?Highlight;

	public function findInArticle(int $id, int $articleId, string $userId): ?Highlight;

	/** Speichert eine neue Markierung und setzt deren id. */
	public function insert(Highlight $highlight): Highlight;

	public function update(Highlight $highlight): Highlight;

	public function deleteById(int $id, string $userId): void;

	public function countGuestSince(int $articleId, string $userId, \DateTime $since): int;

	/** Änderungsmarke über alle Markierungen eines Artikels. */
	public function signature(int $articleId, string $userId): string;
}
