<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCA\Merlin\Service\CommentRules;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Farben für Kommentar-Verfasser: neue Tabelle merlin_comment_guests mit der
 * Farbe je Gast-Name und Artikel. Eindeutig sind (Artikel, Name) und
 * (Artikel, Farbe), damit kein Gast die Farbe eines anderen bekommt.
 *
 * Gäste, die schon vor dieser Version kommentiert oder markiert haben,
 * bekommen in postSchemaChange() der Reihe nach freie Farben.
 */
class Version1000Date20240101000030 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('merlin_comment_guests')) {
			return null;
		}
		$table = $schema->createTable('merlin_comment_guests');
		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull'       => true,
			'unsigned'      => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length'  => 64,
		]);
		$table->addColumn('article_id', Types::INTEGER, [
			'notnull'  => true,
			'unsigned' => true,
		]);
		$table->addColumn('name_key', Types::STRING, [
			'notnull' => true,
			'length'  => 64,
		]);
		$table->addColumn('name', Types::STRING, [
			'notnull' => true,
			'length'  => 64,
		]);
		$table->addColumn('color', Types::STRING, [
			'notnull' => true,
			'length'  => 16,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['article_id', 'user_id', 'name_key'], 'merlin_cguest_name_uq');
		$table->addUniqueIndex(['article_id', 'user_id', 'color'], 'merlin_cguest_color_uq');
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Bisherige Gäste in der Reihenfolge ihres ersten Beitrags je Artikel.
		$authors = [];
		foreach (['merlin_comments', 'merlin_highlights'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('article_id', 'user_id', 'author_name', 'author_name_key', 'created_at')
				->from($table)
				->where($qb->expr()->eq('author_type', $qb->createNamedParameter('guest')))
				->orderBy('created_at', 'ASC');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$key = (string) ($row['author_name_key'] ?? '');
				if ($key === '') {
					continue;
				}
				$id = $row['article_id'] . '|' . $row['user_id'];
				$authors[$id] ??= [];
				$known = $authors[$id][$key] ?? null;
				if ($known === null || (string) $row['created_at'] < $known['at']) {
					$authors[$id][$key] = [
						'article' => (int) $row['article_id'],
						'user'    => (string) $row['user_id'],
						'name'    => (string) $row['author_name'],
						'at'      => (string) $row['created_at'],
					];
				}
			}
			$result->closeCursor();
		}

		$existing = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('article_id', 'user_id', 'name_key', 'color')->from('merlin_comment_guests');
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$id = $row['article_id'] . '|' . $row['user_id'];
			$existing[$id]['keys'][(string) $row['name_key']] = true;
			$existing[$id]['colors'][] = (string) $row['color'];
		}
		$result->closeCursor();

		foreach ($authors as $id => $guests) {
			uasort($guests, fn ($a, $b) => strcmp($a['at'], $b['at']));
			$taken = $existing[$id]['colors'] ?? [];
			foreach ($guests as $key => $guest) {
				if (isset($existing[$id]['keys'][$key])) {
					continue;
				}
				$color = CommentRules::firstFreeColor($taken);
				if ($color === null) {
					break;
				}
				$taken[] = $color;
				$insert = $this->db->getQueryBuilder();
				$insert->insert('merlin_comment_guests')->values([
					'user_id'    => $insert->createNamedParameter($guest['user']),
					'article_id' => $insert->createNamedParameter($guest['article'], IQueryBuilder::PARAM_INT),
					'name_key'   => $insert->createNamedParameter((string) $key),
					'name'       => $insert->createNamedParameter($guest['name']),
					'color'      => $insert->createNamedParameter($color),
					'created_at' => $insert->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE),
				]);
				$insert->executeStatement();
			}
		}
	}
}
