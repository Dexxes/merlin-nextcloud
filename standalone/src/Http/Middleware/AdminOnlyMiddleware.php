<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http\Middleware;

use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;

/**
 * Muss nach AuthMiddleware laufen - erwartet, dass Request::authUser()
 * bereits gesetzt ist.
 */
final class AdminOnlyMiddleware {
    public function handle(Request $request): ?Response {
        $user = $request->authUser();
        if ($user === null || $user['role'] !== 'admin') {
            return Response::json(['error' => 'Admin only'], 403);
        }
        return null;
    }
}
