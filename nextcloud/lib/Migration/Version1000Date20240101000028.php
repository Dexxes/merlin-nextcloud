<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use Merlin\Core\Service\ArticleDeletionService;
use OCA\Merlin\Adapter\NextcloudDatabase;
use OCA\Merlin\Db\ArticleMapper;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Vorbereitung der Löschfrist für archivierte Artikel (RetentionService):
 *
 * 1. Index (user_id, is_archived, archived_at) für die Stichtagsabfrage des
 *    täglichen RetentionCleanupJob.
 * 2. Archivierte Artikel ohne archived_at bekommen den Zeitpunkt dieser
 *    Migration. Bisher setzten update() und die Pocket-API is_archived, ohne
 *    archived_at zu füllen; die Frist dieser Artikel beginnt damit erst jetzt.
 * 3. Altlasten aufräumen: Highlights, Tag-Zuordnungen und Shares ohne Artikel
 *    (destroy() löschte bisher nur die Artikelzeile).
 *
 * Daten bereits gelöschter Nutzer räumt diese Migration bewusst nicht auf:
 * IUserManager::userExists() liefert auch dann false, wenn ein Backend wie
 * LDAP gerade nicht erreichbar ist, und dann wären echte Nutzerdaten weg.
 * Künftige Nutzerlöschungen erledigt UserDeletedListener.
 *
 * ArticleDeletionService wird hier selbst gebaut statt injiziert: Migrationen
 * laufen bei der Installation, bevor Application::register() die Kern-Klassen
 * im App-Container angemeldet hat (siehe Application::CORE_SERVICES).
 */
class Version1000Date20240101000028 extends SimpleMigrationStep {
	private const INDEX = 'merlin_art_user_arch_idx';

	public function __construct(
		private ArticleMapper $articleMapper,
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('merlin_articles')) {
			return null;
		}

		$table = $schema->getTable('merlin_articles');
		if ($table->hasIndex(self::INDEX)) {
			return null;
		}
		$table->addIndex(['user_id', 'is_archived', 'archived_at'], self::INDEX);

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$backfilled = $this->articleMapper->backfillArchivedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
		if ($backfilled > 0) {
			$output->info(sprintf('Merlin: set archive date for %d archived articles', $backfilled));
		}

		$orphans = (new ArticleDeletionService(new NextcloudDatabase($this->db)))->deleteOrphans();
		if ($orphans > 0) {
			$output->info(sprintf('Merlin: removed %d orphaned highlights, tag links and share links', $orphans));
		}
	}
}
