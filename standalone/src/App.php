<?php

declare(strict_types=1);

namespace Merlin\Standalone;

use Merlin\Core\Persistence\PdoConnection;
use Merlin\Core\Persistence\SqlCommentGuestStore;
use Merlin\Core\Persistence\SqlCommentStore;
use Merlin\Core\Persistence\SqlContentFilterStore;
use Merlin\Core\Persistence\SqlDatabase;
use Merlin\Core\Persistence\SqlHighlightStore;
use Merlin\Core\Persistence\SqlRetentionStore;
use Merlin\Core\Persistence\SqlShareStore;
use Merlin\Core\Persistence\SqlSiteCredentialStore;
use Merlin\Core\Port;
use Merlin\Core\Service\ArticleDeletionService;
use Merlin\Core\Service\SiteCredentialService;
use Merlin\Standalone\Adapter\ConfigInstanceSecret;
use Merlin\Standalone\Adapter\PhpSession;
use Merlin\Standalone\Adapter\SodiumCrypto;
use Merlin\Standalone\Adapter\StandaloneAppSettings;
use Merlin\Standalone\Adapter\StandaloneContentFilterRepository;
use Merlin\Standalone\Adapter\StandaloneTranslator;
use Merlin\Standalone\Adapter\StandaloneUrlGenerator;
use Merlin\Standalone\Adapter\StandaloneUserDirectory;
use Merlin\Standalone\Adapter\StandaloneUserSettings;
use Merlin\Standalone\Auth\PasswordResetService;
use Merlin\Standalone\Db\Sqlite;
use Merlin\Standalone\Mail\MailerInterface;
use Merlin\Standalone\Mail\PhpMailMailer;
use Merlin\Standalone\Logging\FileLogger;
use Psr\Log\LoggerInterface;

/**
 * Container des Servers: einmal je Request (public/index.php) oder
 * CLI-Aufruf (bin/merlin) gebaut. Ports des Kerns zeigen auf die Adapter in
 * PORTS; Klassen aus Merlin\Core\ und Merlin\Standalone\ baut get() per
 * Reflection aus ihren Konstruktor-Typen, wie das Autowiring in Nextcloud
 * (Application::autowire()). Jede Klasse entsteht höchstens einmal.
 */
final class App {
	/**
	 * Port => Implementierung. Fehlt ein Port hier, ist er im Standalone-Server
	 * (noch) nicht verfügbar (siehe UNAVAILABLE_PORTS). tests/test-wiring.php
	 * prüft, dass jeder Port in core/src/Port/ in einer der beiden Listen steht.
	 *
	 * @var array<class-string, class-string>
	 */
	public const PORTS = [
		Port\AppSettings::class         => StandaloneAppSettings::class,
		Port\ArticleDeletion::class     => ArticleDeletionService::class,
		Port\CommentGuestStore::class   => SqlCommentGuestStore::class,
		Port\CommentStore::class        => SqlCommentStore::class,
		Port\Connection::class          => PdoConnection::class,
		Port\ContentFilterSource::class => StandaloneContentFilterRepository::class,
		Port\ContentFilterStore::class  => SqlContentFilterStore::class,
		Port\Crypto::class              => SodiumCrypto::class,
		Port\Database::class            => SqlDatabase::class,
		Port\HighlightStore::class      => SqlHighlightStore::class,
		Port\InstanceSecret::class      => ConfigInstanceSecret::class,
		Port\RetentionStore::class      => SqlRetentionStore::class,
		Port\Session::class             => PhpSession::class,
		Port\ShareStore::class          => SqlShareStore::class,
		Port\SiteCredentialStore::class => SqlSiteCredentialStore::class,
		Port\SiteSessions::class        => SiteCredentialService::class,
		Port\Translator::class          => StandaloneTranslator::class,
		Port\UrlGenerator::class        => StandaloneUrlGenerator::class,
		Port\UserDirectory::class       => StandaloneUserDirectory::class,
		Port\UserSettings::class        => StandaloneUserSettings::class,
		LoggerInterface::class          => FileLogger::class,
		MailerInterface::class          => PhpMailMailer::class,
	];

	/**
	 * Ports für „Merlin Dateien“: gibt es im Standalone-Server vorerst nicht
	 * (Entscheidung 22.11.2026), /api/capabilities meldet "files" als aus.
	 */
	public const UNAVAILABLE_PORTS = [
		Port\FilePreviews::class,
		Port\StoredFile::class,
	];

	/** @var array<class-string, object> */
	private array $instances = [];

	/**
	 * @param array<class-string, object> $instances vorgegebene Instanzen, z. B.
	 *        In-Memory-Stores in Testharnesses
	 */
	public function __construct(private Config $config, array $instances = []) {
		$this->instances = $instances;
	}

	public static function fromConfigFile(?string $file = null): self {
		return new self(Config::load($file));
	}

	public function config(): Config {
		return $this->config;
	}

	public function connection(): PdoConnection {
		return $this->get(PdoConnection::class);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	public function get(string $class): object {
		if (isset($this->instances[$class])) {
			return $this->instances[$class];
		}
		if (in_array($class, self::UNAVAILABLE_PORTS, true)) {
			throw new \LogicException("{$class} ist im Standalone-Server nicht verfügbar (Merlin Dateien folgt später).");
		}
		$implementation = self::PORTS[$class] ?? $class;
		$object = $this->instances[$implementation] ?? $this->build($implementation);
		return $this->instances[$class] = $this->instances[$implementation] = $object;
	}

	private function build(string $class): object {
		$root = dirname(__DIR__);
		return match ($class) {
			PdoConnection::class => Sqlite::open($this->config->string('db_path', $root . '/data/merlin.sqlite')),
			FileLogger::class => new FileLogger($this->config->string('log_path', $root . '/data/merlin.log'),
				$this->config->string('log_level', 'info')),
			ConfigInstanceSecret::class => new ConfigInstanceSecret($this->config->string('secret')),
			StandaloneUrlGenerator::class => new StandaloneUrlGenerator($this->config->string('base_url', 'http://localhost')),
			\PDO::class => $this->connection()->pdo(),
			PhpMailMailer::class => new PhpMailMailer(
				(string) ($this->config->section('mail')['from_address'] ?? 'merlin@localhost'),
				(string) ($this->config->section('mail')['from_name'] ?? 'Merlin'),
			),
			PasswordResetService::class => $this->autowire($class, [
				'baseUrl' => $this->config->string('base_url'),
				'ttlSeconds' => $this->config->int('password_reset_ttl', 3600),
			]),
			default => $this->autowire($class),
		};
	}

	/** @param array<string, mixed> $scalars Werte für Parameter ohne Klassentyp, nach Name */
	private function autowire(string $class, array $scalars = []): object {
		if (!str_starts_with($class, 'Merlin\\Core\\') && !str_starts_with($class, 'Merlin\\Standalone\\')) {
			throw new \LogicException("Kein Adapter für {$class}");
		}
		$reflection = new \ReflectionClass($class);
		if (!$reflection->isInstantiable()) {
			throw new \LogicException("Kein Adapter für {$class} (Schnittstelle oder abstrakt, fehlt in App::PORTS?)");
		}
		$args = [];
		foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if (array_key_exists($parameter->getName(), $scalars)) {
				$args[] = $scalars[$parameter->getName()];
			} elseif ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
				$args[] = $this->get($type->getName());
			} elseif ($parameter->isDefaultValueAvailable()) {
				$args[] = $parameter->getDefaultValue();
			} else {
				throw new \LogicException("Kann {$class}::\${$parameter->getName()} nicht auflösen");
			}
		}
		return $reflection->newInstanceArgs($args);
	}
}
