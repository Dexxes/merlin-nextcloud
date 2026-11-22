<?php

declare(strict_types=1);

/**
 * Boots the Nextcloud installation in NEXTCLOUD_ROOT (default: the server
 * this app is installed in, ../../.. of an apps/merlin checkout) so tests can
 * use the real DI container, database and app manager.
 *
 * PHPUnit comes from tests/vendor, Nextcloud's classes from its own
 * autoloader; the app's runtime vendor/ (merlin/core etc.) is loaded by
 * Application::__construct() as in production.
 */

require_once __DIR__ . '/vendor/autoload.php';

$root = getenv('NEXTCLOUD_ROOT') ?: dirname(__DIR__, 3);
if (!is_file($root . '/lib/base.php')) {
	fwrite(STDERR, "Nextcloud not found in {$root}: set NEXTCLOUD_ROOT to the server directory.\n");
	exit(1);
}

define('PHPUNIT_RUN', 1);
require_once $root . '/lib/base.php';

if (!\OCP\Server::get(\OCP\IConfig::class)->getSystemValueBool('installed', false)) {
	fwrite(STDERR, "Nextcloud in {$root} is not installed (occ maintenance:install).\n");
	exit(1);
}

\OC_App::loadApps();
$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
// isEnabledForAnyone() exists since Nextcloud 32, isInstalled() is deprecated there.
$enabled = method_exists($appManager, 'isEnabledForAnyone')
	? $appManager->isEnabledForAnyone('merlin')
	: $appManager->isInstalled('merlin');
if (!$enabled) {
	fwrite(STDERR, "The merlin app is not enabled (occ app:enable merlin).\n");
	exit(1);
}
\OC_App::loadApp('merlin');
