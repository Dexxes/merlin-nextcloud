<?php

declare(strict_types=1);

namespace Merlin\Standalone\Controller;

use Merlin\Core\Port\UserSettings;
use Merlin\Standalone\Auth\ApiTokenService;
use Merlin\Standalone\Auth\PasswordHasher;
use Merlin\Standalone\Auth\PasswordResetService;
use Merlin\Standalone\Auth\SessionService;
use Merlin\Standalone\Db\LoginFlowRepository;
use Merlin\Standalone\Db\ServerSettings;
use Merlin\Standalone\Db\UserRepository;
use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;
use Merlin\Standalone\I18n\LocaleResolver;
use Merlin\Standalone\I18n\Translator;

/**
 * HTML-Seiten für Login/Registrierung/Passwort-Reset/Konto/Admin/Login-Flow -
 * die "nötigsten HTML-Seiten" aus dem Plan, kein Vue-Build. Formulare senden
 * klassische POSTs; die Konto-/Admin-Seiten laden ihre Daten per Fetch von
 * der JSON-API (AccountController/AdminController) nach.
 */
final class PageController {
    /**
     * Startseite nach dem Login. Eine Leseoberfläche gibt es im neuen
     * Standalone-Server erst mit dem Vue-Frontend (Etappe 5), bis dahin das Konto.
     */
    public const HOME = '/account';

    public function __construct(
        private readonly UserRepository $users,
        private readonly ServerSettings $settings,
        private readonly PasswordHasher $hasher,
        private readonly PasswordResetService $passwordReset,
        private readonly SessionService $sessions,
        private readonly LoginFlowRepository $loginFlows,
        private readonly ApiTokenService $apiTokenService,
        private readonly UserSettings $userSettings,
    ) {
    }

    public function loginForm(Request $request): Response {
        if ($this->sessions->currentUserId() !== null) {
            return Response::redirect(self::HOME);
        }
        return $this->render('login', $request, [
            'allowSelfRegistration' => $this->settings->getBool(ServerSettings::ALLOW_SELF_REGISTRATION),
        ]);
    }

    public function login(Request $request): Response {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $t = $this->translator($request);

        $user = $this->users->findByUsername($username);
        if ($user === null || !$this->hasher->verify($password, $user['password_hash'])) {
            return $this->render('login', $request, ['error' => $t->t('login.invalidCredentials')]);
        }
        if (!(bool) $user['is_active']) {
            return $this->render('login', $request, ['error' => $t->t('login.accountDisabled')]);
        }

        $this->sessions->login((int) $user['id']);
        return Response::redirect(self::HOME);
    }

    public function logout(Request $request): Response {
        $this->sessions->logout();
        return Response::redirect('/login');
    }

    /**
     * Setzt die Sprachpräferenz (Session, plus user_settings falls
     * eingeloggt) und springt zur aufrufenden Seite zurück. `return` kommt
     * unauthentifiziert aus der URL - nur ein Pfad-Präfix wird akzeptiert
     * (kein "//host"-Protocol-Relative-Redirect), sonst Fallback /library.
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

        $return = (string) $request->query('return', self::HOME);
        if (!str_starts_with($return, '/') || str_starts_with($return, '//')) {
            $return = self::HOME;
        }
        return Response::redirect($return);
    }

    public function registerForm(Request $request): Response {
        if (!$this->settings->getBool(ServerSettings::ALLOW_SELF_REGISTRATION)) {
            return Response::redirect('/login');
        }
        return $this->render('register', $request);
    }

    public function register(Request $request): Response {
        if (!$this->settings->getBool(ServerSettings::ALLOW_SELF_REGISTRATION)) {
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

        $user = $this->users->create($username, $email, $this->hasher->hash($password), 'user');
        $this->sessions->login((int) $user['id']);
        return Response::redirect(self::HOME);
    }

    public function passwordForgotForm(Request $request): Response {
        return $this->render('password_forgot', $request);
    }

    public function passwordForgot(Request $request): Response {
        $email = trim((string) $request->input('email', ''));
        $this->passwordReset->requestReset($email);
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

        return $this->render('admin_users', $request);
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

        return $this->render('login_flow', $request, ['state' => 'form', 'flowToken' => $flowToken]);
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

        $user = $this->users->findByUsername($username);
        if ($user === null || !$this->hasher->verify($password, $user['password_hash'])) {
            return $this->render('login_flow', $request, [
                'state' => 'form',
                'flowToken' => $flowToken,
                'error' => $t->t('login.invalidCredentials'),
            ]);
        }
        if (!(bool) $user['is_active']) {
            return $this->render('login_flow', $request, [
                'state' => 'form',
                'flowToken' => $flowToken,
                'error' => $t->t('login.accountDisabled'),
            ]);
        }

        $result = $this->apiTokenService->create((int) $user['id'], 'Login Flow');
        $this->loginFlows->complete((int) $flow['id'], (int) $user['id'], $result['plainText']);

        // Gleich auch die Browser-Session einloggen - ein Klick auf /account
        // direkt nach dem Verbinden fragt sonst erneut nach Zugangsdaten.
        $this->sessions->login((int) $user['id']);

        return $this->render('login_flow', $request, ['state' => 'done']);
    }

    private function validateNewAccount(Translator $t, string $username, string $email, string $password): ?string {
        if ($username === '' || $email === '' || strlen($password) < 8) {
            return $t->t('register.fillAllFields');
        }
        if ($this->users->findByUsername($username) !== null) {
            return $t->t('register.usernameTaken');
        }
        if ($this->users->findByEmail($email) !== null) {
            return $t->t('register.emailTaken');
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
    private function render(string $template, Request $request, array $vars = [], array $extraHeaders = []): Response {
        $vars['t'] = $this->translator($request);
        $vars['requestPath'] = $request->path();
        extract($vars);
        ob_start();
        include __DIR__ . "/../../templates/{$template}.php";
        return Response::html((string) ob_get_clean(), 200, $extraHeaders);
    }
}
