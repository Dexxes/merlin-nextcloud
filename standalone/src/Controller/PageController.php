<?php

declare(strict_types=1);

namespace Merlin\Standalone\Controller;

use Merlin\Core\Port\ThrottledException;
use Merlin\Core\Port\Throttler;
use Merlin\Core\Port\UserSettings;
use Merlin\Standalone\Auth\ApiTokenService;
use Merlin\Standalone\Auth\PasswordHasher;
use Merlin\Standalone\Auth\PasswordResetService;
use Merlin\Standalone\Auth\RegistrationService;
use Merlin\Standalone\Auth\SessionService;
use Merlin\Standalone\Db\LoginFlowRepository;
use Merlin\Standalone\Db\UserRepository;
use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;
use Merlin\Standalone\I18n\LocaleResolver;
use Merlin\Standalone\I18n\Translator;
use Psr\Log\LoggerInterface;

/**
 * HTML-Seiten für Login/Registrierung/Passwort-Reset/Konto/Admin/Login-Flow -
 * die "nötigsten HTML-Seiten" aus dem Plan, kein Vue-Build. Formulare senden
 * klassische POSTs; die Konto-/Admin-Seiten laden ihre Daten per Fetch von
 * der JSON-API (AccountController/AdminController) nach.
 */
final class PageController {
    /** Startseite nach dem Login: der Reader (AppController::reader). */
    public const HOME = '/';

    /** Throttler-Aktion für Passwortprüfungen (Login und Login Flow), je IP. */
    public const THROTTLE_LOGIN = 'merlin_login';

    /**
     * Hash eines zufälligen Passworts: unbekannte Anmeldenamen brauchen so
     * genauso lange wie falsche Passwörter (keine Konten-Erkennung über die
     * Antwortzeit).
     */
    private const DUMMY_HASH = '$2y$10$SmYY3R8piIRo4cdkjEY9a.4aFuvo7WkKtXb8HR5YtEyz3ULWpWv8G';

    /**
     * Sicherheits-Header der HTML-Seiten (Login, Registrierung, Konto, Admin,
     * Login Flow): nicht einbettbar (Clickjacking), kein Referrer (der
     * Reset-Link trägt das Token in der URL).
     */
    private const SECURITY_HEADERS = [
        'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly PasswordResetService $passwordReset,
        private readonly SessionService $sessions,
        private readonly LoginFlowRepository $loginFlows,
        private readonly ApiTokenService $apiTokenService,
        private readonly UserSettings $userSettings,
        private readonly Throttler $throttler,
        private readonly LoginFlowController $loginFlowApi,
        private readonly RegistrationService $registration,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function loginForm(Request $request): Response {
        if ($this->sessions->currentUserId() !== null) {
            return Response::redirect(self::HOME);
        }
        return $this->render('login', $request, [
            'allowSelfRegistration' => $this->registration->isOpen(),
            'return' => self::safeReturn($request->query('return')),
        ]);
    }

    public function login(Request $request): Response {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $return = self::safeReturn($request->input('return'));
        $t = $this->translator($request);

        try {
            $user = $this->checkPassword($request, $username, $password);
        } catch (ThrottledException $e) {
            return $this->throttled('login', $request, $e, ['return' => $return]);
        }
        if ($user === null) {
            return $this->render('login', $request, ['error' => $t->t('login.invalidCredentials'), 'return' => $return]);
        }
        if (!(bool) $user['is_active']) {
            return $this->render('login', $request, ['error' => $t->t('login.accountDisabled'), 'return' => $return]);
        }

        $this->sessions->login((int) $user['id']);
        return Response::redirect($return);
    }

    /**
     * GET /logout?token=… (Links der Oberfläche) und POST /logout (Formular
     * der Rückfrage): abmelden nur mit dem Token der Session. Ohne gültiges
     * Token fragt eine Seite nach, damit fremde Links nicht abmelden.
     */
    public function logout(Request $request): Response {
        $token = (string) ($request->method() === 'POST' ? $request->input('token', '') : $request->query('token', ''));
        if ($this->sessions->currentUserId() === null) {
            return Response::redirect('/login');
        }
        if (!$this->sessions->isValidLogoutToken($token)) {
            return $this->render('logout', $request, ['logoutToken' => $this->sessions->logoutToken()]);
        }
        $this->sessions->logout();
        return Response::redirect('/login');
    }

    /** Abmelde-Link mit Token für Templates und die App-Seitenleiste. */
    public static function logoutUrl(SessionService $sessions): string {
        return '/logout?token=' . rawurlencode($sessions->logoutToken());
    }

    /**
     * Setzt die Sprachpräferenz (Session, plus user_settings falls
     * eingeloggt) und springt zur aufrufenden Seite zurück (safeReturn).
     */
    public function setLanguage(Request $request): Response {
        $code = (string) $request->routeParam('code');
        if (in_array($code, LocaleResolver::SUPPORTED, true)) {
            $this->sessions->setLanguage($code);
            $userId = $this->sessions->currentUserId();
            if ($userId !== null) {
                $this->userSettings->set((string) $userId, LocaleResolver::SETTINGS_SECTION, LocaleResolver::SETTINGS_KEY, $code);
            }
        }

        return Response::redirect(self::safeReturn($request->query('return')));
    }

    /**
     * Rücksprungziel aus `return` (Sprachumschalter, Login nach abgelaufener
     * Session, siehe frontend/src/platform/standalone.js). Kommt
     * unauthentifiziert aus der URL: nur ein Pfad auf diesem Server
     * (kein "//host" und kein "/\host"), sonst die Startseite.
     */
    private static function safeReturn(?string $return): string {
        $return = (string) $return;
        if (!str_starts_with($return, '/') || str_starts_with($return, '//') || str_starts_with($return, '/\\')
            || preg_match('/[\x00-\x1f]/', $return)) {
            return self::HOME;
        }
        return $return;
    }

    public function registerForm(Request $request): Response {
        if (!$this->registration->isOpen()) {
            return Response::redirect('/login');
        }
        return $this->render('register', $request);
    }

    public function register(Request $request): Response {
        if (!$this->registration->isOpen()) {
            return Response::redirect('/login');
        }

        $username = trim((string) $request->input('username', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $t = $this->translator($request);

        $error = $this->validateNewAccount($t, $username, $email, $password);
        if ($error !== null) {
            return $this->render('register', $request, ['error' => $error]);
        }

        try {
            $this->registration->register($username, $email, $password);
        } catch (\Throwable $e) {
            $this->logger->error('Registrierung: Bestätigungsmail nicht verschickt', ['exception' => $e]);
            return $this->render('register', $request, ['error' => $t->t('register.mailFailed')]);
        }
        // Gleiche Antwort für neue und schon bekannte Adressen (RegistrationService).
        return $this->render('register', $request, ['message' => $t->t('register.checkMail')]);
    }

    /** GET /register/confirm?token=…: Link aus der Bestätigungsmail. */
    public function registerConfirm(Request $request): Response {
        $userId = $this->registration->confirm((string) $request->query('token', ''));
        if ($userId === null) {
            return $this->render('register', $request, ['error' => $this->translator($request)->t('register.confirmInvalid'), 'confirmFailed' => true]);
        }
        $this->sessions->login($userId);
        return Response::redirect(self::HOME);
    }

    public function passwordForgotForm(Request $request): Response {
        return $this->render('password_forgot', $request, ['mailAvailable' => $this->registration->mailAvailable()]);
    }

    public function passwordForgot(Request $request): Response {
        $email = trim((string) $request->input('email', ''));
        try {
            $this->passwordReset->requestReset($email);
        } catch (\Throwable $e) {
            // Mail ging nicht raus: nur ins Log, die Antwort bleibt dieselbe.
            $this->logger->error('Passwort vergessen: Mail nicht verschickt', ['exception' => $e]);
        }
        // Immer dieselbe Meldung, unabhängig davon ob die E-Mail existiert -
        // sonst liesse sich die Existenz von Accounts erraten.
        return $this->render('password_forgot', $request, [
            'message' => $this->translator($request)->t('passwordForgot.sentIfKnown'),
        ]);
    }

    public function passwordResetForm(Request $request): Response {
        return $this->render('password_reset', $request, ['token' => $request->query('token', '')]);
    }

    public function passwordReset(Request $request): Response {
        $token = (string) $request->input('token', '');
        $password = (string) $request->input('password', '');
        $t = $this->translator($request);

        if (strlen($password) < 8) {
            return $this->render('password_reset', $request, [
                'token' => $token,
                'error' => $t->t('passwordReset.tooShort'),
            ]);
        }

        try {
            $this->passwordReset->resetPassword($token, $password);
        } catch (\RuntimeException) {
            return $this->render('password_reset', $request, [
                'token' => $token,
                'error' => $t->t('passwordReset.invalidToken'),
            ]);
        }

        return Response::redirect('/login');
    }

    public function account(Request $request): Response {
        $userId = $this->sessions->currentUserId();
        if ($userId === null) {
            return Response::redirect('/login');
        }
        $user = $this->users->findById($userId);
        if ($user === null) {
            return Response::redirect('/login');
        }

        return $this->render('account', $request, [
            'logoutUrl' => self::logoutUrl($this->sessions),
            'username' => $user['username'],
            'isAdmin' => $user['role'] === 'admin',
        ]);
    }

    public function admin(Request $request): Response {
        $userId = $this->sessions->currentUserId();
        $user = $userId === null ? null : $this->users->findById($userId);
        if ($user === null || $user['role'] !== 'admin') {
            return Response::redirect('/login');
        }

        return $this->render('admin_users', $request, ['mailAvailable' => $this->registration->mailAvailable()]);
    }

    /**
     * HTML-Teil des Login-Flow-v2-Klons (siehe LoginFlowController für den
     * JSON-Teil): Browser-Login-Formular, das der Client-seitige Login-Flow
     * in einem neuen Tab öffnet. Wiederverwendet dasselbe Template wie /login,
     * nur mit dem Flow-Token durchgereicht statt direkt einzuloggen.
     */
    public function loginFlowForm(Request $request): Response {
        $flowToken = (string) $request->routeParam('token');
        $flow = $this->loginFlows->findByFlowToken($flowToken);

        if ($flow === null || $flow['expires_at'] < gmdate('c')) {
            return $this->render('login_flow', $request, ['state' => 'invalid']);
        }
        if ($flow['user_id'] !== null) {
            // Bereits abgeschlossen (z.B. Reload nach Erfolg) - kein erneutes Token ausstellen.
            return $this->render('login_flow', $request, ['state' => 'done']);
        }

        return $this->render('login_flow', $request, ['state' => 'form', 'flowToken' => $flowToken, 'clientName' => $flow['client_name'] ?? null]);
    }

    public function loginFlowSubmit(Request $request): Response {
        $flowToken = (string) $request->routeParam('token');
        $flow = $this->loginFlows->findByFlowToken($flowToken);

        if ($flow === null || $flow['expires_at'] < gmdate('c') || $flow['user_id'] !== null) {
            return $this->render('login_flow', $request, ['state' => 'invalid']);
        }

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $t = $this->translator($request);

        try {
            $user = $this->checkPassword($request, $username, $password);
        } catch (ThrottledException $e) {
            return $this->throttled('login_flow', $request, $e, [
                'state' => 'form',
                'flowToken' => $flowToken,
                'clientName' => $flow['client_name'] ?? null,
            ]);
        }
        if ($user === null) {
            return $this->render('login_flow', $request, [
                'state' => 'form',
                'flowToken' => $flowToken,
                'clientName' => $flow['client_name'] ?? null,
                'error' => $t->t('login.invalidCredentials'),
            ]);
        }
        if (!(bool) $user['is_active']) {
            return $this->render('login_flow', $request, [
                'state' => 'form',
                'flowToken' => $flowToken,
                'clientName' => $flow['client_name'] ?? null,
                'error' => $t->t('login.accountDisabled'),
            ]);
        }

        $client = trim((string) ($flow['client_name'] ?? ''));
        $result = $this->apiTokenService->create((int) $user['id'], $client === '' ? 'Login Flow' : 'Login Flow: ' . mb_substr($client, 0, 80));
        $this->loginFlows->complete((int) $flow['id'], (int) $user['id'], $this->loginFlowApi->sealToken($result['plainText']));

        // Gleich auch die Browser-Session einloggen - ein Klick auf /account
        // direkt nach dem Verbinden fragt sonst erneut nach Zugangsdaten.
        $this->sessions->login((int) $user['id']);

        return $this->render('login_flow', $request, ['state' => 'done']);
    }

    /**
     * Passwortprüfung mit Brute-Force-Bremse wie Nextclouds IThrottler: jeder
     * Fehlversuch dieser IP verlängert die Wartezeit vor dem nächsten
     * (SqlThrottler, bis 25 Sekunden, 12 Stunden lang). Lange Wartezeiten
     * weist SqlThrottler mit ThrottledException ab (throttled(): 429).
     *
     * @return array<string, mixed>|null users-Zeile bei richtigem Passwort
     * @throws ThrottledException
     */
    private function checkPassword(Request $request, string $username, string $password): ?array {
        $address = $request->remoteAddress();
        $this->throttler->sleepDelay(self::THROTTLE_LOGIN, $address);
        $user = $username === '' ? null : $this->users->findByUsername($username);
        $valid = $this->hasher->verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if ($user === null || !$valid) {
            $this->throttler->registerFailure(self::THROTTLE_LOGIN, $address);
            return null;
        }
        return $user;
    }

    private function validateNewAccount(Translator $t, string $username, string $email, string $password): ?string {
        if ($username === '' || $email === '' || strlen($password) < 8) {
            return $t->t('register.fillAllFields');
        }
        if ($this->users->findByUsername($username) !== null) {
            return $t->t('register.usernameTaken');
        }
        // Eine vergebene E-Mail-Adresse verrät die Antwort nicht (RegistrationService).
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $t->t('register.invalidEmail');
        }
        return null;
    }

    private function translator(Request $request): Translator {
        $userId = $this->sessions->currentUserId();
        return Translator::forRequest($request, $this->sessions, $this->userSettings, $userId === null ? null : (string) $userId);
    }

    /**
     * `t`/`requestPath` landen per extract() in jedem Template - requestPath
     * (Base-Path-frei, siehe Request::fromGlobals) speist den
     * Sprachumschalter in partials/footer.php (GET /lang/{code}?return=...).
     */
    /** Formular erneut mit Hinweis, Status 429 und Retry-After. */
    private function throttled(string $template, Request $request, ThrottledException $e, array $vars): Response {
        $vars['error'] = $this->translator($request)->t('login.throttled', ['seconds' => $e->retryAfter]);
        return $this->render($template, $request, $vars, ['Retry-After' => (string) $e->retryAfter], 429);
    }

    private function render(string $template, Request $request, array $vars = [], array $extraHeaders = [], int $status = 200): Response {
        $vars['t'] = $this->translator($request);
        $vars['requestPath'] = $request->path();
        extract($vars);
        ob_start();
        include __DIR__ . "/../../templates/{$template}.php";
        return Response::html((string) ob_get_clean(), $status, $extraHeaders + self::SECURITY_HEADERS);
    }
}
