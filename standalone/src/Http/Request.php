<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http;

final class Request {
    /** Pfade der WebDAV-Uploads (wie in Nextcloud). */
    public const DAV_PREFIX = '/remote.php/dav/';

    /** @var array<string, string> */
    private array $routeParams = [];

    private readonly array $jsonBody;

    /** Vom jeweiligen Auth-Middleware gesetzt: die users-Zeile des angemeldeten Accounts. */
    private ?array $authUser = null;

    /** Wie sich der Nutzer angemeldet hat: 'token' (Basic Auth) oder 'session' (Cookie). */
    private ?string $authMethod = null;

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        private readonly string $rawBody,
        private readonly bool $isHttps = false,
        private readonly string $remoteAddress = '',
        private readonly bool $streamBody = false,
    ) {
        $decoded = json_decode($this->rawBody, true);
        $this->jsonBody = is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $trustedProxies IPs/CIDR-Bereiche der Reverse-Proxys
     *        ('trusted_proxies' in config.php): nur von dort gelten
     *        X-Forwarded-For und X-Forwarded-Proto.
     */
    public static function fromGlobals(string $basePath = '/', array $trustedProxies = []): self {
        $fullPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Entfernt den Base-Path vom Anfang der Request-URL, falls vorhanden
        // (z.B. '/tools/merlin/login' → '/login' wenn basePath='/tools/merlin').
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($fullPath, $basePath . '/')) {
            $path = substr($fullPath, strlen($basePath));
        } elseif ($basePath === $fullPath) {
            $path = '/';
        } else {
            $path = $fullPath;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $headers['php-auth-user'] = $_SERVER['PHP_AUTH_USER'];
            $headers['php-auth-pw'] = $_SERVER['PHP_AUTH_PW'] ?? '';
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        // WebDAV-Uploads (Controller\DavController) können groß sein: den
        // Body dort nicht in den Speicher lesen, sondern über bodyStream().
        $streamBody = str_starts_with($path, self::DAV_PREFIX);

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $path,
            $_GET,
            $headers,
            $streamBody ? '' : (string) file_get_contents('php://input'),
            ($_SERVER['HTTPS'] ?? '') !== ''
                || ($_SERVER['SERVER_PORT'] ?? '') === '443'
                || (self::forwardedHeadersTrusted($remote, $trustedProxies)
                    && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https'),
            self::clientAddress($remote, (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), $trustedProxies),
            $streamBody,
        );
    }

    /**
     * Gelten X-Forwarded-*-Header? Ohne 'trusted_proxies' wie bisher nur
     * X-Forwarded-Proto (für HTTPS-Erkennung hinter einem Proxy, der TLS
     * terminiert), mit Liste nur, wenn die Verbindung von dort kommt.
     *
     * @param list<string> $trustedProxies
     */
    public static function forwardedHeadersTrusted(string $remote, array $trustedProxies): bool {
        return $trustedProxies === [] || self::inRanges($remote, $trustedProxies);
    }

    /**
     * Adresse des Clients für Rate-Limits und Brute-Force-Bremse: kommt die
     * Verbindung von einem vertrauenswürdigen Proxy, der von rechts erste
     * Eintrag in X-Forwarded-For, der kein solcher Proxy ist (weiter links
     * kann der Client selbst Werte vorgeben). Sonst REMOTE_ADDR, nie ein
     * Header, den jeder setzen kann.
     *
     * @param list<string> $trustedProxies
     */
    public static function clientAddress(string $remote, string $forwardedFor, array $trustedProxies): string {
        if ($trustedProxies === [] || !self::inRanges($remote, $trustedProxies)) {
            return $remote;
        }
        $hops = array_reverse(array_filter(array_map('trim', explode(',', $forwardedFor)), static fn (string $v): bool => $v !== ''));
        foreach ($hops as $hop) {
            $hop = trim($hop, '[]');
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!self::inRanges($hop, $trustedProxies)) {
                return $hop;
            }
        }
        return $remote;
    }

    /** @param list<string> $ranges IPs oder CIDR (IPv4 und IPv6) */
    public static function inRanges(string $ip, array $ranges): bool {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$net, $bits] = array_pad(explode('/', trim((string) $range), 2), 2, null);
            $network = @inet_pton((string) $net);
            if ($network === false || strlen($network) !== strlen($address)) {
                continue;
            }
            $bits = $bits === null ? strlen($address) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($address[$bytes]) ^ ord($network[$bytes])) & (0xff << (8 - $rest)) & 0xff) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Body zum Lesen in Stücken: php://input bei WebDAV-Uploads, sonst der
     * schon gelesene Body (auch in Tests).
     *
     * @return resource
     */
    public function bodyStream() {
        if ($this->streamBody) {
            $stream = fopen('php://input', 'rb');
        } else {
            $stream = fopen('php://memory', 'w+b');
            if ($stream !== false) {
                fwrite($stream, $this->rawBody);
                rewind($stream);
            }
        }
        if ($stream === false) {
            throw new \RuntimeException('Could not read request body');
        }
        return $stream;
    }

    public function method(): string {
        return $this->method;
    }

    public function isHttps(): bool {
        return $this->isHttps;
    }

    public function host(): ?string {
        return $this->header('host');
    }

    public function path(): string {
        return $this->path;
    }

    public function setRouteParams(array $params): void {
        $this->routeParams = $params;
    }

    public function routeParam(string $name): ?string {
        return $this->routeParams[$name] ?? null;
    }

    public function query(string $name, ?string $default = null): ?string {
        return isset($this->query[$name]) ? (string) $this->query[$name] : $default;
    }

    public function queryBool(string $name): ?bool {
        $value = $this->query($name);
        if ($value === null) {
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function queryInt(string $name, ?int $default = null): ?int {
        $value = $this->query($name);
        return $value === null ? $default : (int) $value;
    }

    /** Body-Feld: unterstützt sowohl JSON- als auch Form-Requests (letzteres für einfache HTML-Formulare). */
    public function input(string $name, ?string $default = null): ?string {
        if (array_key_exists($name, $this->jsonBody)) {
            return (string) $this->jsonBody[$name];
        }
        if (isset($_POST[$name])) {
            return (string) $_POST[$name];
        }
        return $default;
    }

    public function inputArray(string $name): array {
        if (isset($this->jsonBody[$name]) && is_array($this->jsonBody[$name])) {
            return $this->jsonBody[$name];
        }
        return [];
    }

    /**
     * Alle Parameter wie in Nextclouds Request: Query-String, dann Body (JSON
     * oder Formular), dann Pfad-Platzhalter (gewinnen).
     *
     * @return array<string, mixed>
     */
    public function params(): array {
        $body = $this->jsonBody !== [] ? $this->jsonBody : $_POST;
        return array_merge($this->query, $body, $this->routeParams);
    }

    public function rawBody(): string {
        return $this->rawBody;
    }

    public function header(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> alle Header, Namen klein geschrieben */
    public function headers(): array {
        return $this->headers;
    }

    public function remoteAddress(): string {
        return $this->remoteAddress;
    }

    public function setAuthUser(array $user, ?string $method = null): void {
        $this->authUser = $user;
        $this->authMethod = $method;
    }

    public function authMethod(): ?string {
        return $this->authMethod;
    }

    public function authUser(): ?array {
        return $this->authUser;
    }

    public function authUserId(): int {
        if ($this->authUser === null) {
            throw new \LogicException('No authenticated user on this request');
        }
        return (int) $this->authUser['id'];
    }

    /** Nutzer-ID für den Kern und die merlin_*-Tabellen: users.id als String. */
    public function authUid(): string {
        return (string) $this->authUserId();
    }

    public function basicAuthCredentials(): ?array {
        $user = $this->header('php-auth-user');
        $pass = $this->header('php-auth-pw');
        if ($user !== null && $pass !== null) {
            return [$user, $pass];
        }

        $authHeader = $this->header('authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Basic ')) {
            $decoded = base64_decode(substr($authHeader, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
                return [$user, $pass];
            }
        }

        return null;
    }
}
