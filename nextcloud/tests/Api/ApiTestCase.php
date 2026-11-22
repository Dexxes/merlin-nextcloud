<?php

declare(strict_types=1);

namespace OCA\Merlin\Tests\Api;

use PHPUnit\Framework\TestCase;

/**
 * Base class of the HTTP tests: talks to a running Nextcloud with the Merlin
 * app enabled, like the iOS/Android apps and the browser extension do
 * (Basic Auth, JSON). URLs go through index.php so the tests work without
 * pretty URLs (php -S, a fresh Apache container).
 *
 * Environment:
 *   MERLIN_TEST_BASE_URL        e.g. http://localhost:8080
 *   MERLIN_TEST_ADMIN_USER      admin account (default admin)
 *   MERLIN_TEST_ADMIN_PASSWORD  its password (default admin)
 *
 * Every test class works with its own users (created through the OCS
 * provisioning API on first use), so the classes don't see each other's data
 * and a re-run against the same instance starts from an empty account.
 */
abstract class ApiTestCase extends TestCase {
	protected const PASSWORD = 'Merlin-CI-Passw0rd!';

	/** @var array<string, true> users created by this run */
	private static array $createdUsers = [];

	protected static function baseUrl(): string {
		return rtrim((string) (getenv('MERLIN_TEST_BASE_URL') ?: 'http://localhost:8080'), '/');
	}

	protected static function admin(): array {
		return [
			(string) (getenv('MERLIN_TEST_ADMIN_USER') ?: 'admin'),
			(string) (getenv('MERLIN_TEST_ADMIN_PASSWORD') ?: 'admin'),
		];
	}

	/**
	 * A fresh user for this test class: deleted and re-created, so data from
	 * an earlier run against the same instance is gone (UserDeletedListener
	 * removes the Merlin rows).
	 *
	 * @return array{0: string, 1: string} user id and password
	 */
	protected static function user(string $suffix = ''): array {
		$name = 'ci-' . strtolower(substr(strrchr(static::class, '\\') ?: static::class, 1)) . $suffix;
		$name = substr(preg_replace('/[^a-z0-9-]/', '', $name) ?? $name, 0, 60);
		if (!isset(self::$createdUsers[$name])) {
			$admin = self::admin();
			self::request('DELETE', '/ocs/v2.php/cloud/users/' . rawurlencode($name), null, $admin, ['OCS-APIRequest: true']);
			$created = self::request('POST', '/ocs/v2.php/cloud/users?format=json', [
				'userid'   => $name,
				'password' => self::PASSWORD,
			], $admin, ['OCS-APIRequest: true']);
			self::assertSame(200, $created['status'], "could not create user {$name}: " . $created['body']);
			self::$createdUsers[$name] = true;
		}
		return [$name, self::PASSWORD];
	}

	/**
	 * Request against the Merlin app, path relative to /index.php/apps/merlin.
	 *
	 * @param array{0: string, 1: string}|null $auth
	 * @return array{status: int, json: mixed, body: string, headers: array<string, string>}
	 */
	protected static function api(string $method, string $path, mixed $body = null, ?array $auth = null, array $headers = []): array {
		return self::request($method, '/index.php/apps/merlin' . $path, $body, $auth, $headers);
	}

	/**
	 * @param array{0: string, 1: string}|null $auth Basic Auth, null = anonymous
	 * @param string|array|null $body array = JSON, string = raw
	 * @param string|null $cookieJar file for cookies (anonymous share sessions)
	 * @return array{status: int, json: mixed, body: string, headers: array<string, string>}
	 */
	protected static function request(string $method, string $path, mixed $body = null, ?array $auth = null, array $headers = [], ?string $cookieJar = null): array {
		$ch = curl_init(self::baseUrl() . $path);
		$responseHeaders = [];
		$options = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
				$parts = explode(':', $line, 2);
				if (count($parts) === 2) {
					$responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
				return strlen($line);
			},
		];
		// Marks native-client requests for Nextcloud's CSRF check.
		$headers[] = 'OCS-APIRequest: true';
		if ($auth !== null) {
			$options[CURLOPT_USERPWD] = $auth[0] . ':' . $auth[1];
		}
		if (is_array($body)) {
			$options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
			$headers[] = 'Content-Type: application/json';
		} elseif (is_string($body)) {
			$options[CURLOPT_POSTFIELDS] = $body;
		}
		if ($cookieJar !== null) {
			$options[CURLOPT_COOKIEFILE] = $cookieJar;
			$options[CURLOPT_COOKIEJAR] = $cookieJar;
		}
		$options[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $options);
		$raw = curl_exec($ch);
		if ($raw === false) {
			self::fail("{$method} {$path}: " . curl_error($ch));
		}
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$raw = (string) $raw;
		$json = json_decode($raw, true);
		return ['status' => $status, 'json' => $json, 'body' => $raw, 'headers' => $responseHeaders];
	}

	/** Asserts the status code and returns the decoded JSON body. */
	protected static function expect(int $status, array $response, string $what = ''): mixed {
		self::assertSame(
			$status,
			$response['status'],
			trim($what . ' – unexpected HTTP status, body: ' . substr($response['body'], 0, 2000), ' –')
		);
		return $response['json'];
	}

	/**
	 * Saves an article the way the browser extension does (page HTML in the
	 * request, so nothing is fetched from the internet) and waits until the
	 * extraction has finished.
	 *
	 * @param array{0: string, 1: string} $auth
	 * @return array<string, mixed> the article as GET /api/articles/{id} returns it
	 */
	protected static function saveArticle(array $auth, string $title = 'CI-Testartikel', ?string $url = null): array {
		$url ??= 'https://example.org/merlin-ci/' . bin2hex(random_bytes(4));
		$html = <<<HTML
			<!DOCTYPE html>
			<html lang="de"><head><meta charset="utf-8"><title>{$title}</title>
			<meta name="author" content="Erika Mustermann"></head>
			<body><article><h1>{$title}</h1>
			<p>Dies ist ein Absatz mit genug Text, damit die Extraktion einen Artikel erkennt.
			Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor
			incididunt ut labore et dolore magna aliqua.</p>
			<p>Zweiter Absatz mit weiterem Text für die Lesezeit und den Auszug des Artikels.
			Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip.</p>
			</article></body></html>
			HTML;
		$saved = self::expect(201, self::api('POST', '/api/v1/add', [
			'url'   => $url,
			'title' => $title,
			'html'  => $html,
		], $auth), 'POST /api/v1/add');
		self::assertSame(1, $saved['status'] ?? null);
		$id = (int) $saved['item']['item_id'];
		self::assertGreaterThan(0, $id);
		return self::waitForExtraction($auth, $id);
	}

	/** @return array<string, mixed> */
	protected static function waitForExtraction(array $auth, int $id, int $timeoutSeconds = 60): array {
		$deadline = time() + $timeoutSeconds;
		do {
			$article = self::expect(200, self::api('GET', "/api/articles/{$id}", null, $auth), "GET /api/articles/{$id}");
			if (empty($article['isProcessing'])) {
				return $article;
			}
			usleep(500_000);
		} while (time() < $deadline);
		self::fail("article {$id} still processing after {$timeoutSeconds}s");
	}
}
