<?php

declare(strict_types=1);

/**
 * Testharness für Service\RetentionService (Löschfrist: Einstellungen, Lauf,
 * Vorschau, Hinweis) gegen Speicher-Stubs der Ports.
 *
 * Aufruf: php core/tests/test-retention-service.php
 *
 * Ohne Composer/Nextcloud. Die Ports AppSettings, UserSettings,
 * RetentionStore und ArticleDeletion sind In-Memory-Stubs; geprüft wird, was
 * der Service aus Admin- und Nutzerwerten macht und welche Artikel er löscht.
 */

namespace Psr\Log {
	if (!class_exists(AbstractLogger::class)) {
		interface LoggerInterface {
			public function emergency($message, array $context = []);
			public function alert($message, array $context = []);
			public function critical($message, array $context = []);
			public function error($message, array $context = []);
			public function warning($message, array $context = []);
			public function notice($message, array $context = []);
			public function info($message, array $context = []);
			public function debug($message, array $context = []);
			public function log($level, $message, array $context = []);
		}
		abstract class AbstractLogger implements LoggerInterface {
			public function emergency($message, array $context = []) { $this->log('emergency', $message, $context); }
			public function alert($message, array $context = []) { $this->log('alert', $message, $context); }
			public function critical($message, array $context = []) { $this->log('critical', $message, $context); }
			public function error($message, array $context = []) { $this->log('error', $message, $context); }
			public function warning($message, array $context = []) { $this->log('warning', $message, $context); }
			public function notice($message, array $context = []) { $this->log('notice', $message, $context); }
			public function info($message, array $context = []) { $this->log('info', $message, $context); }
			public function debug($message, array $context = []) { $this->log('debug', $message, $context); }
		}
	}
}

namespace Merlin\RetentionServiceTest {
	require_once __DIR__ . '/autoload.php';

	use Merlin\Core\Port\AppSettings;
	use Merlin\Core\Port\ArticleDeletion;
	use Merlin\Core\Port\RetentionStore;
	use Merlin\Core\Port\UserSettings;

	final class Settings implements AppSettings, UserSettings {
		/** @var array<string, int> */
		public array $app = [];
		/** @var array<string, string> */
		public array $user = [];

		public function getInt(string $key, int $default = 0): int { return $this->app[$key] ?? $default; }
		public function setInt(string $key, int $value): void { $this->app[$key] = $value; }
		public function get(string $userId, string $section, string $key, string $default = ''): string { return $this->user["$userId/$section/$key"] ?? $default; }
		public function set(string $userId, string $section, string $key, string $value): void { $this->user["$userId/$section/$key"] = $value; }
	}

	/** Archivierte Artikel: id => [userId, archivedAt, favorite]; Shares: id => expiresAt. */
	final class Store implements RetentionStore, ArticleDeletion {
		/** @var array<int, array{0:string,1:\DateTimeImmutable,2:bool}> */
		public array $articles = [];
		/** @var array<int, \DateTimeImmutable> */
		public array $shares = [];
		/** @var list<int> */
		public array $deleted = [];

		public function findUserIdsWithArchived(): array {
			return array_values(array_unique(array_map(static fn (array $a): string => $a[0], $this->articles)));
		}
		public function countExpiredArchived(string $userId, \DateTimeImmutable $cutoff, bool $favorites): int {
			return count($this->expired($userId, $cutoff, $favorites));
		}
		public function findExpiredArchivedIds(string $userId, \DateTimeImmutable $cutoff, bool $favorites, int $limit): array {
			return array_slice($this->expired($userId, $cutoff, $favorites), 0, $limit);
		}
		public function deleteSharesExpiredBefore(\DateTimeImmutable $cutoff): int {
			$before = count($this->shares);
			$this->shares = array_filter($this->shares, static fn (\DateTimeImmutable $e): bool => $e >= $cutoff);
			return $before - count($this->shares);
		}
		public function deleteArticles(string $userId, array $articleIds): int {
			$n = 0;
			foreach ($articleIds as $id) {
				if (isset($this->articles[$id]) && $this->articles[$id][0] === $userId) {
					unset($this->articles[$id]);
					$this->deleted[] = $id;
					$n++;
				}
			}
			return $n;
		}
		/** @return list<int> */
		private function expired(string $userId, \DateTimeImmutable $cutoff, bool $favorites): array {
			$ids = [];
			foreach ($this->articles as $id => [$u, $at, $fav]) {
				if ($u === $userId && $fav === $favorites && $at < $cutoff) {
					$ids[] = $id;
				}
			}
			return $ids;
		}
	}

	final class NullLogger extends \Psr\Log\AbstractLogger {
		public function log($level, $message, array $context = []): void {}
	}
}

namespace {
	use Merlin\Core\Service\RetentionService;
	use Merlin\RetentionServiceTest\NullLogger;
	use Merlin\RetentionServiceTest\Settings;
	use Merlin\RetentionServiceTest\Store;

	$failed = 0;
	$check = static function (bool $ok, string $label, string $detail = '') use (&$failed): void {
		echo ($ok ? "  ✓ " : "  ✗ ") . $label . ($ok || $detail === '' ? '' : "\n      $detail") . "\n";
		if (!$ok) {
			$failed++;
		}
	};

	$now = new \DateTimeImmutable('2026-11-22 12:00:00', new \DateTimeZone('UTC'));
	$daysAgo = static fn (int $d): \DateTimeImmutable => $now->modify("-$d days");
	$setup = static function () use ($daysAgo): array {
		$settings = new Settings();
		$store = new Store();
		$store->articles = [
			1 => ['alice', $daysAgo(100), false],
			2 => ['alice', $daysAgo(10), false],
			3 => ['alice', $daysAgo(400), true],
			4 => ['bob', $daysAgo(100), false],
			5 => ['bob', $daysAgo(40), true],
		];
		$store->shares = [10 => $daysAgo(45), 11 => $daysAgo(5)];
		return [$settings, $store, new RetentionService($settings, $settings, $store, $store, new NullLogger())];
	};

	echo "Einstellungen\n";
	[$settings, $store, $service] = $setup();
	$check($service->getAdminLimits() === ['days' => 0, 'favoritesDays' => 0], 'ohne Werte: unbegrenzt');
	$service->setAdminLimits(90, -5);
	$check($settings->app === [RetentionService::APP_KEY_MAX_DAYS => 90, RetentionService::APP_KEY_FAVORITES_MAX_DAYS => 0], 'Admin-Werte normalisiert gespeichert', var_export($settings->app, true));
	$settings->user['alice/' . RetentionService::USER_SETTINGS_APP . '/' . RetentionService::USER_KEY_DAYS] = '30';
	$check($service->getEffective('alice') === ['days' => 30, 'favoritesDays' => 0], 'effektiv: Minimum aus Admin (90) und Nutzer (30)');
	$check($service->getEffective('bob') === ['days' => 90, 'favoritesDays' => 0], 'ohne Nutzerwert: Admin-Maximum');

	$info = $service->getSettingsInfo('alice');
	$check($info['retentionNoticeRequired'] === true && $info['retentionEffectiveDays'] === 30 && $info['retentionMaxDays'] === 90, 'Hinweis fällig, Werte in getSettingsInfo()', var_export($info, true));
	$service->acknowledgeNotice('alice');
	$check($settings->user['alice/reader/' . RetentionService::USER_KEY_NOTICE_ACK] === '30:0', 'Bestätigung speichert den Fingerabdruck der effektiven Frist');
	$check($service->getSettingsInfo('alice')['retentionNoticeRequired'] === false, 'danach kein Hinweis mehr');

	echo "Vorschau und Lauf\n";
	[$settings, $store, $service] = $setup();
	$check($service->preview(90, 0, $now) === 2, 'Vorschau 90/0: je ein Nicht-Favorit von alice und bob');
	$check($service->preview(0, 365, $now) === 1, 'Vorschau 0/365: nur der alte Favorit von alice');

	$service->setAdminLimits(30, 30);
	$dry = $service->run(null, true, $now);
	$check($dry === ['articles' => 4, 'shares' => 0, 'users' => ['alice' => 2, 'bob' => 2]], 'Trockenlauf zählt nur', var_export($dry, true));
	$check($store->deleted === [] && count($store->shares) === 2, 'Trockenlauf löscht nichts');

	$res = $service->run(null, false, $now);
	sort($store->deleted);
	$check($store->deleted === [1, 3, 4, 5], 'Lauf 30/30 löscht alle Artikel älter als 30 Tage', var_export($store->deleted, true));
	$check($res['articles'] === 4 && $res['users'] === ['alice' => 2, 'bob' => 2], 'Ergebnis je Nutzer', var_export($res, true));
	$check($res['shares'] === 1 && array_keys($store->shares) === [11], 'Share-Links nach 30 Tagen Karenz gelöscht', var_export($res['shares'], true));

	[$settings, $store, $service] = $setup();
	$service->setAdminLimits(30, 0);
	$res = $service->run('bob', false, $now);
	$check($store->deleted === [4] && $res['shares'] === 0, 'Lauf für einen Nutzer: nur dessen Artikel, Shares bleiben', var_export([$store->deleted, $res], true));

	echo "\n" . ($failed === 0 ? "Alle Prüfungen bestanden.\n" : "$failed Prüfung(en) fehlgeschlagen.\n");
	exit($failed === 0 ? 0 : 1);
}
