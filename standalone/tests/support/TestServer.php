<?php

declare(strict_types=1);

namespace Merlin\Standalone\Tests;

/**
 * Standalone-Server für HTTP-Tests: frische SQLite-Datenbank in einem
 * temporären Verzeichnis, Admin-Konto, eingebauter PHP-Server mit mehreren
 * Workern (Server-Sent Events halten einen Worker fest).
 */
final class TestServer {
	public readonly string $dir;
	public readonly string $base;
	private readonly string $root;
	/** @var resource */
	private $process;

	public function __construct(string $prefix = 'merlin-test') {
		$this->root = dirname(__DIR__, 2);
		$this->dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
		mkdir($this->dir, 0700, true);
		$probe = stream_socket_server('tcp://127.0.0.1:0');
		$name = (string) stream_socket_get_name($probe, false);
		$port = (int) substr($name, strrpos($name, ':') + 1);
		fclose($probe);
		$this->base = "http://127.0.0.1:{$port}";
		file_put_contents($this->dir . '/config.php', '<?php return ' . var_export([
			'db_path' => $this->dir . '/merlin.sqlite',
			'log_path' => $this->dir . '/merlin.log',
			'base_url' => $this->base,
			'secret' => base64_encode(random_bytes(32)),
		], true) . ';');
	}

	/** @return array{0: int, 1: string} Exit-Code, Ausgabe von bin/merlin */
	public function cli(string $args): array {
		exec('MERLIN_CONFIG=' . escapeshellarg($this->dir . '/config.php') . ' ' . escapeshellarg(PHP_BINARY) . ' '
			. escapeshellarg($this->root . '/bin/merlin') . ' ' . $args . ' 2>&1', $out, $code);
		return [$code, implode("\n", $out)];
	}

	public function start(): void {
		$port = (int) substr($this->base, strrpos($this->base, ':') + 1);
		$this->process = proc_open(
			[PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $this->root . '/public', $this->root . '/public/index.php'],
			[0 => ['pipe', 'r'], 1 => ['file', $this->dir . '/server.log', 'a'], 2 => ['file', $this->dir . '/server.log', 'a']],
			$pipes,
			null,
			['MERLIN_CONFIG' => $this->dir . '/config.php', 'PHP_CLI_SERVER_WORKERS' => '4'] + getenv(),
		);
		for ($i = 0; $i < 50; $i++) {
			$socket = @fsockopen('127.0.0.1', $port);
			if ($socket !== false) {
				fclose($socket);
				return;
			}
			usleep(100_000);
		}
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{0: int, 1: mixed, 2: string, 3: array<string, string>} Status, JSON, Body, Header
	 */
	public function http(string $method, string $path, ?array $json = null, array $headers = [], ?array $form = null, ?string $cookieJar = null): array {
		$ch = curl_init($this->base . $path);
		$sendHeaders = [];
		foreach ($headers as $name => $value) {
			$sendHeaders[] = "{$name}: {$value}";
		}
		if ($json !== null) {
			$sendHeaders[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
		} elseif ($form !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
		}
		if ($cookieJar !== null) {
			curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
			curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
		}
		$responseHeaders = [];
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $sendHeaders,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
				if (str_contains($line, ':')) {
					[$name, $value] = explode(':', $line, 2);
					$responseHeaders[strtolower(trim($name))] = trim($value);
				}
				return strlen($line);
			},
		]);
		$body = (string) curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return [$status, json_decode($body, true), $body, $responseHeaders];
	}

	/**
	 * Server-Sent Events lesen, bis das Ereignis mit $until ganz da ist oder
	 * $seconds um sind;
	 * dann die Verbindung schließen. Nur für Streams, die nicht von selbst
	 * enden: Nach einem Abbruch bleibt connection_aborted() im eingebauten
	 * PHP-Server für diesen Worker gesetzt, spätere Streams dort enden sofort.
	 *
	 * @param array<string, string> $headers
	 * @return array{0: int, 1: string} Status, empfangener Text
	 */
	public function sse(string $path, string $until, array $headers = [], ?string $cookieJar = null, int $seconds = 5): array {
		$received = '';
		$ch = curl_init($this->base . $path);
		$sendHeaders = [];
		foreach ($headers as $name => $value) {
			$sendHeaders[] = "{$name}: {$value}";
		}
		if ($cookieJar !== null) {
			curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
		}
		curl_setopt_array($ch, [
			CURLOPT_HTTPHEADER => $sendHeaders,
			CURLOPT_TIMEOUT => $seconds,
			CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$received, $until): int {
				$received .= $chunk;
				// Erst abbrechen (0 statt der Länge), wenn das Ereignis mit
				// $until vollständig ist, also die Leerzeile danach kam.
				$at = strpos($received, $until);
				return $at !== false && strpos($received, "\n\n", $at) !== false ? 0 : strlen($chunk);
			},
		]);
		curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);
		return [$status, $received];
	}

	/** Konto anmelden (Session) und ein API-Token anlegen; gibt den Basic-Auth-Header zurück. */
	public function tokenFor(string $username, string $password): array {
		$jar = $this->dir . '/cookies-' . $username . '.txt';
		$this->http('POST', '/login', null, [], ['username' => $username, 'password' => $password], $jar);
		[, $token] = $this->http('POST', '/account/tokens', ['name' => 'Test'], ['X-Requested-With' => 'XMLHttpRequest'], null, $jar);
		return ['Authorization' => 'Basic ' . base64_encode($username . ':' . ($token['plainText'] ?? ''))];
	}

	public function pdo(): \PDO {
		$pdo = new \PDO('sqlite:' . $this->dir . '/merlin.sqlite');
		$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		return $pdo;
	}

	public function log(): string {
		return (string) @file_get_contents($this->dir . '/merlin.log') . (string) @file_get_contents($this->dir . '/server.log');
	}

	public function stop(): void {
		if (is_resource($this->process ?? null)) {
			proc_terminate($this->process);
			proc_close($this->process);
		}
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->dir);
	}
}
