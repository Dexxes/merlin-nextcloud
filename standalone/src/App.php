<?php

declare(strict_types=1);

namespace Merlin\Standalone;

use Merlin\Standalone\Db\Connection;

/**
 * Handgeschriebener Container: einmal je Request (public/index.php) oder
 * CLI-Aufruf (bin/merlin) gebaut, Dienste entstehen erst beim ersten Zugriff.
 */
final class App {
	private ?Connection $connection = null;

	public function __construct(private Config $config) {
	}

	public static function fromConfigFile(?string $file = null): self {
		return new self(Config::load($file));
	}

	public function config(): Config {
		return $this->config;
	}

	public function connection(): Connection {
		return $this->connection ??= Connection::sqlite(
			$this->config->string('db_path', dirname(__DIR__) . '/data/merlin.sqlite')
		);
	}
}
