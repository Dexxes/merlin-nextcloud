<?php

declare(strict_types=1);

namespace Merlin\Standalone\Controller;

use Merlin\Core\Port\Crypto;
use Merlin\Standalone\Adapter\ConfigInstanceSecret;
use Merlin\Standalone\Db\LoginFlowRepository;
use Merlin\Standalone\Db\UserRepository;
use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;

/**
 * Login-Flow-v2-Klon: bildet Nextclouds Login-Flow-v2-JSON nach (POST
 * /index.php/login/v2 → { login, poll: { token, endpoint } }, danach Polling
 * bis { server, loginName, appPassword }), damit iOS/Android/Firefox/Chrome/
 * Thunderbird ihre bestehende Login-Flow-Logik unverändert gegen
 * merlin-server nutzen können - nur die Start-URL unterscheidet sich
 * clientseitig je Backend-Typ. Der HTML-Formular-Teil (GET/POST
 * /login/v2/flow/{token}) liegt in PageController, da er dasselbe
 * Template wie /login wiederverwendet.
 */
final class LoginFlowController {
    private const TTL_SECONDS = 600; // 10 Minuten, wie der 5-Minuten-Timeout der Clients grosszügig bemessen

    /** Kennzeichen eines verschlüsselt gespeicherten Tokens in api_token_plaintext. */
    private const ENCRYPTED_PREFIX = 'enc:';

    public function __construct(
        private readonly LoginFlowRepository $flows,
        private readonly UserRepository $users,
        private readonly Crypto $crypto,
        private readonly ConfigInstanceSecret $secret,
    ) {
    }

    /**
     * Token für die Zeile bis zum Abholen: verschlüsselt mit dem
     * Instanz-Geheimnis, ohne Geheimnis (Fehlkonfiguration) im Klartext wie
     * bisher, damit der Login Flow nicht ausfällt.
     */
    public function sealToken(string $plainText): string {
        return $this->secret->isConfigured() ? self::ENCRYPTED_PREFIX . $this->crypto->encrypt($plainText) : $plainText;
    }

    private function unsealToken(string $stored): string {
        return str_starts_with($stored, self::ENCRYPTED_PREFIX)
            ? $this->crypto->decrypt(substr($stored, strlen(self::ENCRYPTED_PREFIX)))
            : $stored;
    }

    /** User-Agent des Clients, gekürzt und ohne Steuerzeichen, für die Anmeldeseite. */
    private static function clientName(Request $request): ?string {
        $agent = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $request->header('user-agent')));
        return $agent === '' ? null : mb_substr($agent, 0, 200);
    }

    public function start(Request $request): Response {
        $this->flows->deleteExpired();

        $flowToken = bin2hex(random_bytes(32));
        $pollToken = bin2hex(random_bytes(32));
        $expiresAt = gmdate('c', time() + self::TTL_SECONDS);
        $this->flows->create($flowToken, $pollToken, $expiresAt, self::clientName($request));

        $base = $this->baseUrl($request);

        return Response::json([
            'login' => "{$base}/login/v2/flow/{$flowToken}",
            'poll' => [
                'token' => $pollToken,
                'endpoint' => "{$base}/login/v2/poll",
            ],
        ]);
    }

    public function poll(Request $request): Response {
        $pollToken = (string) $request->input('token', '');
        $flow = $pollToken === '' ? null : $this->flows->findByPollToken($pollToken);

        if ($flow === null || $flow['user_id'] === null || $flow['expires_at'] < gmdate('c')) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $user = $this->users->findById((int) $flow['user_id']);
        if ($user === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        // Single-Use wie Nextclouds Poll-Endpunkt: ein zweiter Poll-Versuch
        // mit demselben Token darf das Klartext-Token nicht erneut liefern.
        $this->flows->delete((int) $flow['id']);

        return Response::json([
            'server' => $this->baseUrl($request),
            'loginName' => $user['username'],
            'appPassword' => $this->unsealToken((string) $flow['api_token_plaintext']),
        ]);
    }

    private function baseUrl(Request $request): string {
        $scheme = $request->isHttps() ? 'https' : 'http';
        $basePath = Response::getBasePath();
        return "{$scheme}://{$request->host()}{$basePath}";
    }
}
