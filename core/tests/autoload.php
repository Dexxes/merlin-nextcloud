<?php

declare(strict_types=1);

/**
 * Autoloader für die Testharnesses unter core/tests: Merlin\Core\ aus
 * core/src/, ohne Composer (die Harnesses laufen mit der nackten PHP-CLI).
 */
spl_autoload_register(static function (string $class): void {
	$prefix = 'Merlin\\Core\\';
	if (str_starts_with($class, $prefix)) {
		$path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($path)) {
			require_once $path;
		}
	}
});
