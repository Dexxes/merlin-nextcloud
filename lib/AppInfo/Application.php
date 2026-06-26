<?php

declare(strict_types=1);

namespace OCA\Merlin\AppInfo;

use OCA\Merlin\Listener\AddContentSecurityPolicyListener;
use OCA\Merlin\Search\ArticleSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'merlin';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		// Load vendor autoloader for external dependencies
		$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
		if (file_exists($vendorAutoload)) {
			require_once $vendorAutoload;
		}
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSearchProvider(ArticleSearchProvider::class);
		$context->registerEventListener(
			AddContentSecurityPolicyEvent::class,
			AddContentSecurityPolicyListener::class,
		);
	}

	public function boot(IBootContext $context): void {
		// Boot the app
	}
}
