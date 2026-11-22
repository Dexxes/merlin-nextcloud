<?php

declare(strict_types=1);

namespace Merlin\Standalone\Http;

use Merlin\Standalone\App;
use Merlin\Standalone\Controller\AccountController;
use Merlin\Standalone\Controller\AdminController;
use Merlin\Standalone\Controller\AssetController;
use Merlin\Standalone\Controller\CapabilitiesController;
use Merlin\Standalone\Controller\LoginFlowController;
use Merlin\Standalone\Controller\PageController;
use Merlin\Standalone\Http\Middleware\AdminOnlyMiddleware;
use Merlin\Standalone\Http\Middleware\AuthMiddleware;

/**
 * Routen des Servers. Bisher nur die Standalone-eigenen (Konto, Login,
 * Login-Flow, Admin-Nutzer) und /api/capabilities; die gemeinsame API aus
 * dem Kern (Artikel, Tags, Markierungen …) kommt mit Etappe 4 aus der
 * Routentabelle des Kerns dazu. Pfade wie in merlin-standalone-server, damit
 * bestehende Clients (backendKind 'standalone') sie unverändert finden.
 */
final class Routes {
	public static function build(App $app): Router {
		$pages = $app->get(PageController::class);
		$account = $app->get(AccountController::class);
		$admin = $app->get(AdminController::class);
		$loginFlow = $app->get(LoginFlowController::class);
		$capabilities = $app->get(CapabilitiesController::class);
		$assets = $app->get(AssetController::class);
		$auth = $app->get(AuthMiddleware::class)->handle(...);
		$adminOnly = $app->get(AdminOnlyMiddleware::class)->handle(...);

		$router = new Router();

		// HTML-Seiten
		$router->add('GET', '/', fn () => Response::redirect(PageController::HOME));
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

		// Fähigkeiten des Servers (ohne Anmeldung)
		$router->add('GET', '/api/capabilities', $capabilities->show(...));

		// Konto: API-Tokens (Session oder Basic Auth mit Token)
		$router->add('GET', '/account/tokens', $account->listTokens(...), [$auth]);
		$router->add('POST', '/account/tokens', $account->createToken(...), [$auth]);
		$router->add('DELETE', '/account/tokens/{id}', $account->revokeToken(...), [$auth]);

		// Admin: Nutzer und Schalter
		$router->add('GET', '/admin/users', $admin->listUsers(...), [$auth, $adminOnly]);
		$router->add('POST', '/admin/users', $admin->createUser(...), [$auth, $adminOnly]);
		$router->add('PUT', '/admin/users/{id}', $admin->updateUser(...), [$auth, $adminOnly]);
		$router->add('DELETE', '/admin/users/{id}', $admin->deleteUser(...), [$auth, $adminOnly]);
		$router->add('GET', '/admin/settings', $admin->getSettings(...), [$auth, $adminOnly]);
		$router->add('PUT', '/admin/settings', $admin->updateSettings(...), [$auth, $adminOnly]);

		return $router;
	}
}
