<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http\Middleware;

use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;

/**
 * CSRF-Schutz für Anfragen mit Session-Cookie: ändernde Methoden (alles
 * außer GET/HEAD/OPTIONS) brauchen den Header X-Requested-With:
 * XMLHttpRequest. Ein fremdes Formular, Bild oder Link kann ihn nicht setzen,
 * ein fremdes Skript nur nach einer CORS-Vorabfrage, die der Server nicht
 * beantwortet. Die Weboberfläche (frontend/src/platform/standalone.js,
 * account.php, admin_users.php) schickt ihn immer mit.
 *
 * Anfragen mit API-Token (Basic Auth, native Clients) betrifft das nicht:
 * der Browser hängt dieses Token nie von selbst an.
 *
 * Muss nach AuthMiddleware laufen (liest Request::authMethod()).
 */
final class CsrfHeaderMiddleware {
	private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

	public function handle(Request $request): ?Response {
		if (in_array($request->method(), self::SAFE_METHODS, true) || $request->authMethod() !== 'session') {
			return null;
		}
		if (strcasecmp((string) $request->header('x-requested-with'), 'XMLHttpRequest') !== 0) {
			return Response::json(['error' => 'CSRF check failed'], 403);
		}
		return null;
	}
}
