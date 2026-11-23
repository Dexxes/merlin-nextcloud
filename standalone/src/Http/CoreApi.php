<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http;

use Merlin\Core\Api\ApiRequest;
use Merlin\Core\Api\ApiResult;
use Merlin\Standalone\App;

/**
 * Brücke zwischen dem Router und den Handlern des Kerns
 * (core/src/Api/routes.php): baut aus dem Request ein ApiRequest und aus dem
 * ApiResult eine Response. Den Handler baut erst der Aufruf, damit nicht jede
 * Anfrage alle Kern-Dienste anlegt.
 */
final class CoreApi {
	/** @return list<array{name: string, verb: string, url: string, handler: array{0: class-string, 1: string}, access: string}> */
	public static function routes(): array {
		// Wie in nextcloud/appinfo/routes.php: der Kern unter vendor/ (im Checkout ein Symlink).
		return require dirname(__DIR__, 2) . '/vendor/merlin/core/src/Api/routes.php';
	}

	/** @param array{0: class-string, 1: string} $handler */
	public static function handler(App $app, array $handler): \Closure {
		[$class, $method] = $handler;
		return static function (Request $request) use ($app, $class, $method): Response {
			return self::toResponse($app->get($class)->$method(self::toApiRequest($request)));
		};
	}

	public static function toApiRequest(Request $request): ApiRequest {
		return new ApiRequest(
			$request->authUser() !== null ? $request->authUid() : null,
			$request->params(),
			static fn (): string => $request->rawBody(),
			$request->headers(),
			$request->remoteAddress(),
		);
	}

	public static function toResponse(ApiResult $result): Response {
		return match ($result->kind) {
			ApiResult::STREAM => Response::stream($result->headers, $result->emitter),
			ApiResult::PASSTHROUGH => Response::passthrough($result->emitter),
			ApiResult::BODY => Response::raw($result->status, $result->headers, (string) $result->data),
			default => $result->status === 204 ? Response::noContent() : Response::json($result->data, $result->status),
		};
	}
}
