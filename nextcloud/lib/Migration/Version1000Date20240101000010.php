<?php

declare(strict_types=1);

namespace OCA\Merlin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Retroactively assign category = 'Video' to existing articles whose URL
 * matches a known video-platform domain (previously added before the category
 * feature existed).
 */
class Version1000Date20240101000010 extends SimpleMigrationStep {

	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * No schema changes – this migration only backfills data.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Domains that should receive category = 'Video'.
		// Keep in sync with appinfo/content-filters/*.xml files.
		$videoDomains = [
			'youtube.com',
			'youtu.be',
			'ardmediathek.de',
			'zdf.de',
			'3sat.de',
			'arte.tv',
			'vimeo.com',
			'twitch.tv',
		];

		$updated = 0;

		foreach ($videoDomains as $domain) {
			$qb = $this->db->getQueryBuilder();

			// Match both bare domain and www. prefix variants.
			$qb->update('merlin_articles')
				->set('category', $qb->createNamedParameter('Video'))
				->where($qb->expr()->isNull('category'))
				->andWhere(
					$qb->expr()->orX(
						$qb->expr()->like('url', $qb->createNamedParameter('%://' . $domain . '/%')),
						$qb->expr()->like('url', $qb->createNamedParameter('%://www.' . $domain . '/%')),
						// Handle URLs without trailing path (e.g. https://youtu.be/abc)
						$qb->expr()->like('url', $qb->createNamedParameter('%://' . $domain . '?%')),
						$qb->expr()->like('url', $qb->createNamedParameter('%://www.' . $domain . '?%'))
					)
				);

			$updated += (int) $qb->executeStatement();
		}

		$output->info(sprintf('Merlin: retroactively categorised %d article(s) as Video.', $updated));
	}
}
