<?php

declare(strict_types=1);

namespace Merlin\Core\Service;

use Merlin\Core\Port\Scheduler;

/**
 * Scheduler-Port über register_shutdown_function(): unter PHP-FPM geht die
 * Antwort per fastcgi_finish_request() sofort raus, die Session-Sperre wird
 * gelöst (sonst wartet z. B. /api/events desselben Browsers auf
 * session_start()), und der Auftrag bekommt bis zu 120 Sekunden.
 */
final class ShutdownScheduler implements Scheduler {
	public function afterResponse(callable $job): void {
		register_shutdown_function(static function () use ($job): void {
			if (function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();
			}
			if (session_status() === PHP_SESSION_ACTIVE) {
				session_write_close();
			}
			set_time_limit(120);
			$job();
		});
	}
}
