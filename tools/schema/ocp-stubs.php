<?php

declare(strict_types=1);

/**
 * Die wenigen OCP-Typen, die die Migrationsklassen der Nextcloud-App beim
 * Laden und in changeSchema() brauchen. Nur für tools/schema/NextcloudSchema.php,
 * nie zusammen mit echtem Nextcloud laden.
 */

namespace OCP\DB {
	if (!interface_exists(ISchemaWrapper::class)) {
		interface ISchemaWrapper {
		}
	}
	if (!class_exists(Types::class)) {
		final class Types {
			public const BIGINT = 'bigint';
			public const BINARY = 'binary';
			public const BLOB = 'blob';
			public const BOOLEAN = 'boolean';
			public const DATE = 'date';
			public const DATETIME = 'datetime';
			public const DATETIME_TZ = 'datetimetz';
			public const DECIMAL = 'decimal';
			public const FLOAT = 'float';
			public const INTEGER = 'integer';
			public const JSON = 'json';
			public const SMALLINT = 'smallint';
			public const STRING = 'string';
			public const TEXT = 'text';
			public const TIME = 'time';
		}
	}
}

namespace OCP\Migration {
	if (!interface_exists(IOutput::class)) {
		interface IOutput {
		}
	}
	if (!class_exists(SimpleMigrationStep::class)) {
		abstract class SimpleMigrationStep {
			public function name(): string {
				return '';
			}
			public function description(): string {
				return '';
			}
			public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options) {
			}
			public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
				return null;
			}
			public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options) {
			}
		}
	}
}
