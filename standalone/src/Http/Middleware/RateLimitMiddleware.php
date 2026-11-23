<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http\Middleware;

use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;
use Merlin\Standalone\Security\AttemptLog;

/**
 * Rate-Limit je IP für öffentliche Routen mit 'rateLimit' in der
 * Routentabelle des Kerns, wie #[AnonRateLimit] in Nextcloud: höchstens
 * limit Anfragen in period Sekunden, danach 429.
 */
final class RateLimitMiddleware {
	public function __construct(private AttemptLog $log) {
	}

	/** @return \Closure(Request): ?Response */
	public function for(string $action, int $limit, int $period): \Closure {
		return function (Request $request) use ($action, $limit, $period): ?Response {
			$address = $request->remoteAddress();
			if ($this->log->count('rate:' . $action, $address, $period) >= $limit) {
				return Response::json(['error' => 'Too many requests'], 429);
			}
			$this->log->record('rate:' . $action, $address);
			return null;
		};
	}
}
