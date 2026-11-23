<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http;

use Merlin\Standalone\App;
use Merlin\Standalone\Controller\AccountController;
use Merlin\Standalone\Controller\AdminController;
use Merlin\Standalone\Controller\AppController;
use Merlin\Standalone\Controller\AssetController;
use Merlin\Standalone\Controller\LoginFlowController;
use Merlin\Standalone\Controller\PageController;
use Merlin\Standalone\Http\Middleware\AdminOnlyMiddleware;
use Merlin\Standalone\Http\Middleware\AuthMiddleware;
use Merlin\Standalone\Http\Middleware\CsrfHeaderMiddleware;
use Merlin\Standalone\Http\Middleware\RateLimitMiddleware;

/**
 * Routen des Servers: zuerst die gemeinsame API aus der Routentabelle des
 * Kerns (core/src/Api/routes.php: /api/capabilities, Artikel, Schlagworte …,
 * Pfade ohne Nextclouds /index.php/apps/merlin), dann die Standalone-eigenen
 * (Konto, Login, Login-Flow, Admin-Nutzer). Pfade wie in
 * merlin-standalone-server, damit bestehende Clients (backendKind
 * 'standalone') sie unverändert finden.
 */
final class Routes {
	public static function build(App $app): Router {
		$pages = $app->get(PageController::class);
		$account = $app->get(AccountController::class);
		$admin = $app->get(AdminController::class);
		$loginFlow = $app->get(LoginFlowController::class);
		$assets = $app->get(AssetController::class);
		$vueApp = $app->get(AppController::class);
		$authOnly = $app->get(AuthMiddleware::class)->handle(...);
		$csrf = $app->get(CsrfHeaderMiddleware::class)->handle(...);
		// Anmeldung plus CSRF-Schutz für Anfragen mit Session-Cookie
		// (CsrfHeaderMiddleware); als Liste wie die anderen Middleware.
		$auth = [$authOnly, $csrf];
		$adminOnly = $app->get(AdminOnlyMiddleware::class)->handle(...);
		$rateLimit = $app->get(RateLimitMiddleware::class);

		$router = new Router();

		// Gemeinsame API (Kern). 'user' braucht eine Anmeldung (Basic Auth mit
		// API-Token oder Session), 'admin' zusätzlich Adminrechte; 'rateLimit'
		// begrenzt öffentliche Schreibrouten je IP, 'userRateLimit' Routen je
		// angemeldetem Nutzer; 'requirements' engt Platzhalter ein.
		foreach (CoreApi::routes() as $route) {
			$middleware = match ($route['access']) {
				'public' => [],
				'admin' => [...$auth, $adminOnly],
				default => $auth,
			};
			if (isset($route['rateLimit'])) {
				$middleware[] = $rateLimit->for($route['name'], ...$route['rateLimit']);
			}
			if (isset($route['userRateLimit'])) {
				$middleware[] = $rateLimit->forUser($route['name'], ...$route['userRateLimit']);
			}
			$router->add($route['verb'], $route['url'], CoreApi::handler($app, $route['handler']), $middleware, $route['requirements'] ?? []);
		}

		// HTML-Seiten
		// Vue-App (frontend/, npm run build:standalone): Reader und
		// öffentliche Share-Ansicht wie page#index und public_share#show in Nextcloud.
		$router->add('GET', '/', $vueApp->reader(...));
		$router->add('GET', '/s/{token}', $vueApp->publicShare(...));
		$router->add('GET', '/login', $pages->loginForm(...));
		$router->add('POST', '/login', $pages->login(...));
		$router->add('GET', '/logout', $pages->logout(...));
		$router->add('GET', '/register', $pages->registerForm(...));
		$router->add('POST', '/register', $pages->register(...));
		$router->add('GET', '/password/forgot', $pages->passwordForgotForm(...));
		$router->add('POST', '/password/forgot', $pages->passwordForgot(...));
		$router->add('GET', '/password/reset', $pages->passwordResetForm(...));
		$router->add('POST', '/password/reset', $pages->passwordReset(...));
		$router->add('GET', '/account', $pages->account(...));
		$router->add('GET', '/admin', $pages->admin(...));
		$router->add('GET', '/lang/{code}', $pages->setLanguage(...));
		$router->add('GET', '/img/{file}', $assets->image(...));

		// Nachbau von Nextclouds Login Flow v2 für die nativen Clients
		$router->add('POST', '/login/v2', $loginFlow->start(...));
		$router->add('POST', '/login/v2/poll', $loginFlow->poll(...));
		$router->add('GET', '/login/v2/flow/{token}', $pages->loginFlowForm(...));
		$router->add('POST', '/login/v2/flow/{token}', $pages->loginFlowSubmit(...));

		// Konto: API-Tokens (Session oder Basic Auth mit Token)
		$router->add('GET', '/account/tokens', $account->listTokens(...), $auth);
		$router->add('POST', '/account/tokens', $account->createToken(...), $auth);
		$router->add('DELETE', '/account/tokens/{id}', $account->revokeToken(...), $auth);

		// Admin: Nutzer und Schalter
		$router->add('GET', '/admin/users', $admin->listUsers(...), [...$auth, $adminOnly]);
		$router->add('POST', '/admin/users', $admin->createUser(...), [...$auth, $adminOnly]);
		$router->add('PUT', '/admin/users/{id}', $admin->updateUser(...), [...$auth, $adminOnly]);
		$router->add('DELETE', '/admin/users/{id}', $admin->deleteUser(...), [...$auth, $adminOnly]);
		$router->add('GET', '/admin/settings', $admin->getSettings(...), [...$auth, $adminOnly]);
		$router->add('PUT', '/admin/settings', $admin->updateSettings(...), [...$auth, $adminOnly]);

		return $router;
	}
}
