<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Integration;

use OCA\Merlin\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Checks the wiring between the app and the framework-free core inside a real
 * Nextcloud: every port of Application::CORE_PORTS and every class of
 * Application::CORE_SERVICES must be buildable by the app container, and so
 * must every class Nextcloud itself instantiates (controllers, background
 * job, occ command, settings, listeners, middleware, search provider).
 *
 * tools/test-core-wiring.php checks the lists offline; this test catches what
 * only the real container sees (missing constructor dependencies, wrong
 * aliases, classes Nextcloud would build in the server container).
 */
class AppWiringTest extends TestCase {
	private static function container(): ContainerInterface {
		$container = \OC::$server->getRegisteredAppContainer(Application::APP_ID);
		self::assertInstanceOf(ContainerInterface::class, $container);
		return $container;
	}

	/** @return iterable<string, array{class-string, class-string}> */
	public static function ports(): iterable {
		foreach (Application::CORE_PORTS as $port => $implementation) {
			yield self::shortName($port) => [$port, $implementation];
		}
	}

	#[DataProvider('ports')]
	public function testPortResolvesToItsAdapter(string $port, string $implementation): void {
		$service = self::container()->get($port);
		$this->assertInstanceOf($port, $service);
		$this->assertInstanceOf($implementation, $service);
	}

	/** @return iterable<string, array{class-string}> */
	public static function coreServices(): iterable {
		foreach (Application::CORE_SERVICES as $class) {
			yield self::shortName($class) => [$class];
		}
	}

	#[DataProvider('coreServices')]
	public function testCoreServiceIsBuiltByTheAppContainer(string $class): void {
		$this->assertInstanceOf($class, self::container()->get($class));
	}

	/** @return iterable<string, array{class-string}> */
	public static function appClasses(): iterable {
		// The installed app, not the checkout the tests come from.
		$lib = self::appPath() . '/lib';
		foreach (['Controller', 'BackgroundJob', 'Command', 'Settings', 'Listener', 'Middleware', 'Search'] as $dir) {
			foreach (glob("{$lib}/{$dir}/*.php") ?: [] as $file) {
				$class = 'OCA\\Merlin\\' . $dir . '\\' . basename($file, '.php');
				if (!class_exists($class)) {
					continue; // interfaces, exceptions
				}
				$reflection = new \ReflectionClass($class);
				if ($reflection->isAbstract() || $reflection->isSubclassOf(\Throwable::class)) {
					continue;
				}
				yield "{$dir}\\" . $reflection->getShortName() => [$class];
			}
		}
	}

	#[DataProvider('appClasses')]
	public function testAppClassIsBuiltByTheAppContainer(string $class): void {
		$instance = self::container()->get($class);
		$this->assertInstanceOf($class, $instance);
		if (str_contains($class, '\\Controller\\')) {
			$this->assertInstanceOf(Controller::class, $instance);
		}
	}

	public function testCoreIsLoadedFromTheAppVendorDirectory(): void {
		$appPath = realpath(self::appPath());
		$this->assertNotFalse($appPath);
		foreach ([\Merlin\Core\Extraction\ContentExtractorService::class, \Merlin\Core\ContentFilter\ContentFilterSchema::class] as $class) {
			$file = (string) (new \ReflectionClass($class))->getFileName();
			$this->assertStringStartsWith(
				$appPath . '/vendor/merlin/core/',
				$file,
				"{$class} must come from the app's own vendor/merlin/core (copied, not linked)"
			);
		}
	}

	/** @return iterable<string, array{string}> */
	public static function tables(): iterable {
		foreach ([
			'merlin_articles', 'merlin_article_tags', 'merlin_tags', 'merlin_highlights',
			'merlin_comments', 'merlin_comment_guests', 'merlin_shares', 'merlin_cfilter',
			'merlin_site_cred',
		] as $table) {
			yield $table => [$table];
		}
	}

	#[DataProvider('tables')]
	public function testMigrationsCreatedTable(string $table): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$this->assertTrue($db->tableExists($table), "table {$table} is missing");
	}

	public function testRetentionJobIsRegistered(): void {
		$jobs = \OCP\Server::get(\OCP\BackgroundJob\IJobList::class);
		$this->assertTrue($jobs->has(\OCA\Merlin\BackgroundJob\RetentionCleanupJob::class, null));
	}

	private static function appPath(): string {
		return \OCP\Server::get(\OCP\App\IAppManager::class)->getAppPath(Application::APP_ID);
	}

	private static function shortName(string $class): string {
		return substr($class, (int) strrpos($class, '\\') + 1);
	}
}
