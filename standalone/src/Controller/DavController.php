<?php

declare(strict_types=1);

namespace Merlin\Standalone\Controller;

use Merlin\Standalone\Adapter\LocalFileStorage;
use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;

/**
 * Upload für „Merlin Dateien“ über dieselben WebDAV-Pfade wie in Nextcloud,
 * damit die Apps unverändert hochladen können (Ziel aus POST /api/files/target):
 *
 *   PUT    /remote.php/dav/files/{user}/{path}            ganze Datei
 *   MKCOL  /remote.php/dav/uploads/{user}/{transfer}      Chunked Upload v2 beginnen
 *   PUT    /remote.php/dav/uploads/{user}/{transfer}/{n}  ein Stück
 *   MOVE   /remote.php/dav/uploads/{user}/{transfer}/.file  zusammensetzen
 *          (Header Destination: URL der Zieldatei, optional OC-Total-Length)
 *   DELETE /remote.php/dav/uploads/{user}/{transfer}      abbrechen
 *
 * Nur das, was die Apps brauchen: kein PROPFIND, keine Ordner anlegen (das
 * macht POST /api/files/target). {user} ist die Nutzer-ID aus davPath; der
 * Anmeldename geht auch. Fehler kommen als JSON statt als Sabre-XML; die
 * Apps werten nur den Status aus.
 */
final class DavController {
	private const CHUNK_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

	public function __construct(private readonly LocalFileStorage $storage) {
	}

	/** PUT /remote.php/dav/files/{user}/{path} */
	public function putFile(Request $request): Response {
		$userId = $this->user($request);
		if ($userId === null) {
			return self::error(403, 'Wrong user');
		}
		$path = rawurldecode((string) $request->routeParam('path'));
		$target = $this->storage->resolve($userId, $path);
		if ($target === null) {
			return self::error(400, 'Invalid path');
		}
		if (is_dir($target)) {
			return self::error(409, 'Target is a folder');
		}
		if (!is_dir(dirname($target))) {
			return self::error(409, 'Parent folder does not exist');
		}
		self::unlimited();
		$temp = self::tempFile(dirname($target));
		$out = @fopen($temp, 'wb');
		if ($out === false) {
			return self::error(500, 'Could not write file');
		}
		$in = $request->bodyStream();
		$written = stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		$expected = $request->header('content-length');
		if ($written === false || ($expected !== null && $expected !== '' && (int) $expected !== $written)) {
			@unlink($temp);
			return self::error(400, 'Incomplete upload');
		}
		return $this->finish($userId, $path, $temp, $request->header('content-type'));
	}

	/** MKCOL /remote.php/dav/uploads/{user}/{transfer} */
	public function startUpload(Request $request): Response {
		$dir = $this->uploadDir($request);
		if ($dir === null) {
			return self::error(403, 'Wrong user or invalid upload id');
		}
		if (is_dir($dir)) {
			return self::error(405, 'Upload already exists');
		}
		LocalFileStorage::ensureDir($dir);
		return Response::raw(201, [], '');
	}

	/** PUT /remote.php/dav/uploads/{user}/{transfer}/{chunk} */
	public function putChunk(Request $request): Response {
		$dir = $this->uploadDir($request);
		$chunk = (string) $request->routeParam('chunk');
		if ($dir === null || !preg_match(self::CHUNK_PATTERN, $chunk)) {
			return self::error(403, 'Wrong user or invalid upload id');
		}
		if (!is_dir($dir)) {
			return self::error(404, 'Upload not found');
		}
		self::unlimited();
		$temp = self::tempFile($dir);
		$out = @fopen($temp, 'wb');
		if ($out === false) {
			return self::error(500, 'Could not write chunk');
		}
		$in = $request->bodyStream();
		$written = stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		$expected = $request->header('content-length');
		if ($written === false || ($expected !== null && $expected !== '' && (int) $expected !== $written)
			|| !@rename($temp, $dir . '/' . $chunk)) {
			@unlink($temp);
			return self::error(400, 'Incomplete chunk');
		}
		@touch($dir);
		return Response::raw(201, [], '');
	}

	/** MOVE /remote.php/dav/uploads/{user}/{transfer}/.file */
	public function assemble(Request $request): Response {
		$dir = $this->uploadDir($request);
		if ($dir === null) {
			return self::error(403, 'Wrong user or invalid upload id');
		}
		if (!is_dir($dir)) {
			return self::error(404, 'Upload not found');
		}
		$destination = $this->destination($request);
		if ($destination === null) {
			return self::error(400, 'Invalid Destination');
		}
		[$userId, $path] = $destination;
		if ($userId !== $request->authUid()) {
			return self::error(403, 'Wrong user');
		}
		$target = $this->storage->resolve($userId, $path);
		if ($target === null) {
			return self::error(400, 'Invalid path');
		}
		if (is_dir($target) || !is_dir(dirname($target))) {
			return self::error(409, 'Parent folder does not exist');
		}
		$chunks = array_values(array_filter(scandir($dir) ?: [], static fn (string $name): bool => (bool) preg_match(self::CHUNK_PATTERN, $name)));
		if ($chunks === []) {
			return self::error(400, 'No chunks');
		}
		usort($chunks, 'strnatcmp');
		self::unlimited();
		$temp = self::tempFile(dirname($target));
		$out = @fopen($temp, 'wb');
		if ($out === false) {
			return self::error(500, 'Could not write file');
		}
		$total = 0;
		foreach ($chunks as $chunk) {
			$in = @fopen($dir . '/' . $chunk, 'rb');
			$copied = $in === false ? false : stream_copy_to_stream($in, $out);
			if ($in !== false) {
				fclose($in);
			}
			if ($copied === false) {
				fclose($out);
				@unlink($temp);
				return self::error(500, 'Could not read chunk');
			}
			$total += $copied;
		}
		fclose($out);
		$expected = $request->header('oc-total-length');
		if ($expected !== null && $expected !== '' && (int) $expected !== $total) {
			@unlink($temp);
			return self::error(400, 'Size does not match OC-Total-Length');
		}
		$response = $this->finish($userId, $path, $temp, null);
		LocalFileStorage::removeDir($dir);
		return $response;
	}

	/** DELETE /remote.php/dav/uploads/{user}/{transfer} */
	public function cancelUpload(Request $request): Response {
		$dir = $this->uploadDir($request);
		if ($dir === null) {
			return self::error(403, 'Wrong user or invalid upload id');
		}
		if (!is_dir($dir)) {
			return self::error(404, 'Upload not found');
		}
		LocalFileStorage::removeDir($dir);
		return Response::noContent();
	}

	private function finish(string $userId, string $path, string $temp, ?string $mime): Response {
		try {
			$stored = $this->storage->store($userId, $path, $temp, $mime);
		} catch (\Throwable) {
			return self::error(500, 'Could not store file');
		}
		$headers = ['OC-FileId' => sprintf('%08dmerlin', $stored['id'])];
		return Response::raw($stored['created'] ? 201 : 204, $headers, '');
	}

	/** Nutzer-ID, wenn {user} der angemeldete Nutzer ist (ID oder Anmeldename). */
	private function user(Request $request): ?string {
		return $this->matchesUser($request, rawurldecode((string) $request->routeParam('user')))
			? $request->authUid() : null;
	}

	private function matchesUser(Request $request, string $segment): bool {
		$user = $request->authUser() ?? [];
		return $segment === $request->authUid() || $segment === (string) ($user['username'] ?? "\0");
	}

	private function uploadDir(Request $request): ?string {
		$userId = $this->user($request);
		return $userId === null ? null
			: $this->storage->uploadDir($userId, rawurldecode((string) $request->routeParam('transfer')));
	}

	/**
	 * Ziel aus dem Destination-Header (volle URL oder absoluter Pfad):
	 * [Nutzer-ID, Pfad im Ordner des Nutzers].
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function destination(Request $request): ?array {
		$path = parse_url((string) $request->header('destination'), PHP_URL_PATH);
		if (!is_string($path) || $path === '') {
			return null;
		}
		$base = rtrim(Response::getBasePath(), '/');
		if ($base !== '' && str_starts_with($path, $base . '/')) {
			$path = substr($path, strlen($base));
		}
		$prefix = Request::DAV_PREFIX . 'files/';
		if (!str_starts_with($path, $prefix)) {
			return null;
		}
		$rest = substr($path, strlen($prefix));
		$slash = strpos($rest, '/');
		if ($slash === false) {
			return null;
		}
		$segment = rawurldecode(substr($rest, 0, $slash));
		if (!$this->matchesUser($request, $segment)) {
			return null;
		}
		return [$request->authUid(), rawurldecode(substr($rest, $slash))];
	}

	private static function tempFile(string $dir): string {
		return $dir . '/.upload-' . bin2hex(random_bytes(8)) . '.part';
	}

	private static function unlimited(): void {
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		ignore_user_abort(false);
	}

	private static function error(int $status, string $message): Response {
		return Response::json(['error' => $message], $status);
	}
}
