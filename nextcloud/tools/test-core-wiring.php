<?php

declare(strict_types=1);

/**
 * Prüft die Dependency-Injection-Verdrahtung zwischen Nextcloud-App und Kern.
 *
 * Aufruf (im Repository, nach `composer install` in nextcloud/):
 *   php nextcloud/tools/test-core-wiring.php
 *
 * Klassen aus core/ (Namespace Merlin\Core\) liegen außerhalb von OCA\Merlin.
 * Nextcloud würde sie ohne eigene Registrierung im Server-Container bauen, der
 * die Ports des Kerns nicht kennt. Application registriert sie deshalb selbst
 * (CORE_SERVICES, CORE_PORTS). Dieses Skript sucht alle Kern-Klassen, die eine
 * Klasse aus nextcloud/lib im Konstruktor verlangt, folgt deren Konstruktoren
 * und meldet jede, die in den beiden Listen fehlt.
 *
 * Exit-Code 0 = alles verdrahtet, 1 = mindestens ein Fehler.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$libDir = __DIR__ . '/../lib';
$errors = [];

/**
 * use-Importe einer Datei: Kurzname => vollständiger Name.
 *
 * @return array<string, string>
 */
function importsOf(string $code): array {
	$imports = [];
	preg_match_all('/^use ([A-Za-z0-9_\\\\]+)(?: as (\w+))?;/m', $code, $m, PREG_SET_ORDER);
	foreach ($m as $u) {
		$imports[$u[2] ?? substr(strrchr('\\' . $u[1], '\\'), 1)] = $u[1];
	}
	return $imports;
}

function resolveName(string $name, array $imports, string $namespace): string {
	if (str_starts_with($name, '\\')) {
		return ltrim($name, '\\');
	}
	$first = explode('\\', $name)[0];
	if (isset($imports[$first])) {
		return $imports[$first] . substr($name, strlen($first));
	}
	return $namespace . '\\' . $name;
}

// ── Listen aus Application.php lesen (die Klasse selbst braucht OCP) ─────────
$appCode    = (string) file_get_contents($libDir . '/AppInfo/Application.php');
$appImports = importsOf($appCode);
$listOf = static function (string $const) use ($appCode, $appImports): array {
	if (!preg_match('/const ' . $const . ' = \[(.*?)\];/s', $appCode, $m)) {
		return [];
	}
	preg_match_all('/([\\\\A-Za-z0-9_]+)::class(?:\s*=>\s*([\\\\A-Za-z0-9_]+)::class)?/', $m[1], $mm, PREG_SET_ORDER);
	$out = [];
	foreach ($mm as $e) {
		$key = resolveName($e[1], $appImports, 'OCA\\Merlin\\AppInfo');
		$out[$key] = isset($e[2]) ? resolveName($e[2], $appImports, 'OCA\\Merlin\\AppInfo') : true;
	}
	return $out;
};
$services = $listOf('CORE_SERVICES');
$ports    = $listOf('CORE_PORTS');
if ($services === [] || $ports === []) {
	$errors[] = 'CORE_SERVICES oder CORE_PORTS in Application.php nicht gefunden';
}
foreach (array_keys($services) as $class) {
	if (!class_exists($class)) {
		$errors[] = "CORE_SERVICES: $class existiert nicht";
	}
}
foreach ($ports as $port => $impl) {
	if (!interface_exists($port)) {
		$errors[] = "CORE_PORTS: $port ist keine Schnittstelle";
	}
	if (str_starts_with((string) $impl, 'Merlin\\Core\\')) {
		// Implementierung im Kern: muss selbst per DI gebaut werden.
		if (!class_exists($impl) || !is_subclass_of($impl, $port)) {
			$errors[] = "CORE_PORTS: $impl implementiert $port nicht";
		} elseif (!isset($services[$impl])) {
			$errors[] = "CORE_PORTS: $impl fehlt in Application::CORE_SERVICES";
		}
	} elseif (!is_file($libDir . '/' . str_replace('\\', '/', substr((string) $impl, strlen('OCA\\Merlin\\'))) . '.php')) {
		$errors[] = "CORE_PORTS: Implementierung $impl nicht gefunden";
	} elseif (!preg_match('/class \w+[^{]*implements[^{]*\b' . preg_quote(substr(strrchr('\\' . $port, '\\'), 1), '/') . '\b/', (string) file_get_contents($libDir . '/' . str_replace('\\', '/', substr((string) $impl, strlen('OCA\\Merlin\\'))) . '.php'))) {
		$errors[] = "CORE_PORTS: $impl implementiert $port nicht";
	}
}

// ── Kern-Typen in den Konstruktoren von nextcloud/lib ────────────────────────
$queue = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libDir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
	if (!str_ends_with($file->getFilename(), '.php')) {
		continue;
	}
	$code = (string) file_get_contents($file->getPathname());
	if (!preg_match('/^namespace ([^;]+);/m', $code, $ns) || !preg_match('/function __construct\s*\((.*?)\)\s*\{/s', $code, $ctor)) {
		continue;
	}
	$imports = importsOf($code);
	$params  = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $ctor[1]);
	preg_match_all('/(?:^|[(,])\s*(?:(?:public|protected|private|readonly)\s+)*\??([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s+\$/', (string) $params, $tm);
	foreach ($tm[1] as $type) {
		$fqcn = resolveName($type, $imports, $ns[1]);
		if (str_starts_with($fqcn, 'Merlin\\Core\\')) {
			$queue[$fqcn] = substr($file->getPathname(), strlen($libDir) + 1);
		}
	}
}
if ($queue === []) {
	$errors[] = 'keine Kern-Klasse in einem Konstruktor von nextcloud/lib gefunden (Parser kaputt?)';
}

// ── Konstruktoren der Kern-Klassen verfolgen ─────────────────────────────────
$seen = [];
while ($queue !== []) {
	$class = array_key_first($queue);
	$from  = $queue[$class];
	unset($queue[$class]);
	if (isset($seen[$class])) {
		continue;
	}
	$seen[$class] = true;
	if (interface_exists($class)) {
		if (!isset($ports[$class])) {
			$errors[] = "Port $class (gebraucht von $from) fehlt in Application::CORE_PORTS";
		} elseif (str_starts_with((string) $ports[$class], 'Merlin\\Core\\')) {
			// Implementierung im Kern: deren Konstruktor ebenfalls verfolgen.
			$queue[$ports[$class]] ??= $class;
		}
		continue;
	}
	if (!class_exists($class)) {
		$errors[] = "Kern-Klasse $class (gebraucht von $from) existiert nicht";
		continue;
	}
	if (!isset($services[$class])) {
		$errors[] = "Kern-Klasse $class (gebraucht von $from) fehlt in Application::CORE_SERVICES";
	}
	foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $p) {
		$t = $p->getType();
		if ($t instanceof ReflectionNamedType && !$t->isBuiltin() && str_starts_with($t->getName(), 'Merlin\\Core\\')) {
			$queue[$t->getName()] ??= $class;
		}
	}
}

echo 'Kern-Klassen über DI erreichbar: ' . count($seen) . "\n";
foreach (array_keys($seen) as $c) {
	echo "  ✓ $c\n";
}
foreach (array_keys($services) as $c) {
	if (!isset($seen[$c])) {
		echo "  (Hinweis: $c steht in CORE_SERVICES, wird aber von keiner App-Klasse gebraucht)\n";
	}
}
if ($errors !== []) {
	foreach ($errors as $e) {
		echo "  ✗ $e\n";
	}
	exit(1);
}
echo "Alle Prüfungen bestanden.\n";
exit(0);
