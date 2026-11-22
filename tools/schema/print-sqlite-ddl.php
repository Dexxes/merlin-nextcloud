<?php

declare(strict_types=1);

/**
 * Gibt das Schema der Nextcloud-App als SQLite-DDL aus.
 * Aufruf: php tools/schema/print-sqlite-ddl.php [tabelle ...]
 */

require_once __DIR__ . '/NextcloudSchema.php';
require_once __DIR__ . '/SqliteDdl.php';

use Merlin\Tools\Schema\NextcloudSchema;
use Merlin\Tools\Schema\SqliteDdl;

$only = array_slice($argv, 1);
foreach (NextcloudSchema::load() as $name => $table) {
	if ($only !== [] && !in_array($name, $only, true)) {
		continue;
	}
	echo SqliteDdl::createTable($name, $table), "\n";
}
