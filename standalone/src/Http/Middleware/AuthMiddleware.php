<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http\Middleware;

use Merlin\Standalone\Auth\ApiTokenService;
use Merlin\Standalone\Auth\SessionService;
use Merlin\Standalone\Db\ApiTokenRepository;
use Merlin\Standalone\Db\UserRepository;
use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;

/**
 * Zwei unterstützte Auth-Wege, je nach Client:
 *  - HTTP Basic Auth (Username + API-Token) für /api/* - native Clients
 *  - PHP-Session-Cookie für die HTML-Seiten (Login, Account, Admin-UI)
 * Beide lösen auf dieselbe users-Zeile auf, die dann per
 * Request::setAuthUser() für die Controller verfügbar ist.
 */
final class AuthMiddleware {
    public function __construct(
        private readonly UserRepository $users,
        private readonly ApiTokenRepository $apiTokens,
        private readonly ApiTokenService $tokenService,
        private readonly SessionService $sessions,
    ) {
    }

    public function handle(Request $request): ?Response {
        $user = $this->resolveViaApiToken($request) ?? $this->resolveViaSession();

        if ($user === null) {
            return Response::json(['error' => 'Not authenticated'], 401);
        }
        if (!(bool) $user['is_active']) {
            return Response::json(['error' => 'Account disabled'], 403);
        }

        $request->setAuthUser($user);
        return null;
    }

    private function resolveViaApiToken(Request $request): ?array {
        $credentials = $request->basicAuthCredentials();
        if ($credentials === null) {
            return null;
        }
        [$username, $plainTextToken] = $credentials;

        $tokenRow = $this->tokenService->verify($plainTextToken);
        if ($tokenRow === null) {
            return null;
        }

        $user = $this->users->findById((int) $tokenRow['user_id']);
        if ($user === null || $user['username'] !== $username) {
            return null;
        }

        $this->apiTokens->touchLastUsed((int) $tokenRow['id']);
        return $user;
    }

    private function resolveViaSession(): ?array {
        $userId = $this->sessions->currentUserId();
        return $userId === null ? null : $this->users->findById($userId);
    }
}
