<?php

declare(strict_types=1);

namespace OCA\Merlin\AppInfo;

use Merlin\Core\Api\ArticleApi;
use Merlin\Core\Api\CapabilitiesApi;
use Merlin\Core\Api\CommentApi;
use Merlin\Core\Api\HighlightApi;
use Merlin\Core\Api\PublicCommentApi;
use Merlin\Core\Api\PublicShareApi;
use Merlin\Core\Api\ShareApi;
use Merlin\Core\Api\TagApi;
use Merlin\Core\ContentFilter\ContentFilterMerger;
use Merlin\Core\ContentFilter\ContentFilterSerializer;
use Merlin\Core\ContentFilter\ContentFilterValidator;
use Merlin\Core\Extraction\ContentExtractorService;
use Merlin\Core\Extraction\Media\InlineMediaService;
use Merlin\Core\Extraction\Media\MediaHttpClient;
use Merlin\Core\Extraction\Media\MediaProviderRegistry;
use Merlin\Core\Extraction\Media\MediaResolverService;
use Merlin\Core\Extraction\Media\Provider\ArdMediathekProvider;
use Merlin\Core\Extraction\Media\Provider\ArteProvider;
use Merlin\Core\Extraction\Media\Provider\DreiSatProvider;
use Merlin\Core\Extraction\Media\Provider\JsonLdMediaProvider;
use Merlin\Core\Extraction\Media\Provider\XPathMediaProvider;
use Merlin\Core\Extraction\Media\Provider\YoutubeEmbedProvider;
use Merlin\Core\Extraction\Media\Provider\ZdfProvider;
use Merlin\Core\Extraction\Social\BlueskyThreadResolverService;
use Merlin\Core\Extraction\Social\MastodonPostResolverService;
use Merlin\Core\Extraction\Social\TikTokPostResolverService;
use Merlin\Core\Persistence\SqlArticleStore;
use Merlin\Core\Persistence\SqlCommentGuestStore;
use Merlin\Core\Persistence\SqlCommentStore;
use Merlin\Core\Persistence\SqlHighlightStore;
use Merlin\Core\Persistence\SqlShareStore;
use Merlin\Core\Persistence\SqlTagStore;
use Merlin\Core\Port\AppSettings;
use Merlin\Core\Port\ArticleDeletion;
use Merlin\Core\Port\ArticleFiles;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\CommentGuestStore;
use Merlin\Core\Port\CommentStore;
use Merlin\Core\Port\Connection;
use Merlin\Core\Port\ContentFilterSource;
use Merlin\Core\Port\ContentFilterStore;
use Merlin\Core\Port\Crypto;
use Merlin\Core\Port\Database;
use Merlin\Core\Port\FilePreviews;
use Merlin\Core\Port\HighlightStore;
use Merlin\Core\Port\InstanceSecret;
use Merlin\Core\Port\Platform;
use Merlin\Core\Port\RetentionStore;
use Merlin\Core\Port\Scheduler;
use Merlin\Core\Port\Session;
use Merlin\Core\Port\ShareStore;
use Merlin\Core\Port\SiteCredentialStore;
use Merlin\Core\Port\SiteSessions;
use Merlin\Core\Port\TagStore;
use Merlin\Core\Port\Throttler;
use Merlin\Core\Port\UrlGenerator;
use Merlin\Core\Port\UserDirectory;
use Merlin\Core\Port\UserSettings;
use Merlin\Core\Service\ArticleDeletionService;
use Merlin\Core\Service\ArticleExtractionService;
use Merlin\Core\Service\CommentService;
use Merlin\Core\Service\ExportService;
use Merlin\Core\Service\FileEntryService;
use Merlin\Core\Service\PdfProxyService;
use Merlin\Core\Service\ReportBackendService;
use Merlin\Core\Service\RetentionService;
use Merlin\Core\Service\ShareAccessService;
use Merlin\Core\Service\ShutdownScheduler;
use Merlin\Core\Service\SiteCredentialService;
use Merlin\Core\Service\SupportBoxService;
use Merlin\Core\Service\TtsStreamService;
use OCA\Merlin\Adapter\NextcloudAppSettings;
use OCA\Merlin\Adapter\NextcloudArticleFiles;
use OCA\Merlin\Adapter\NextcloudConnection;
use OCA\Merlin\Adapter\NextcloudContentFilterStore;
use OCA\Merlin\Adapter\NextcloudCrypto;
use OCA\Merlin\Adapter\NextcloudDatabase;
use OCA\Merlin\Adapter\NextcloudFilePreviews;
use OCA\Merlin\Adapter\NextcloudInstanceSecret;
use OCA\Merlin\Adapter\NextcloudPlatform;
use OCA\Merlin\Adapter\NextcloudRetentionStore;
use OCA\Merlin\Adapter\NextcloudSession;
use OCA\Merlin\Adapter\NextcloudSiteCredentialStore;
use OCA\Merlin\Adapter\NextcloudThrottler;
use OCA\Merlin\Adapter\NextcloudUrlGenerator;
use OCA\Merlin\Adapter\NextcloudUserDirectory;
use OCA\Merlin\Adapter\NextcloudUserSettings;
use OCA\Merlin\Listener\AddContentSecurityPolicyListener;
use OCA\Merlin\Listener\UserDeletedListener;
use OCA\Merlin\Middleware\CsrfCookieAuthMiddleware;
use OCA\Merlin\Search\ArticleSearchProvider;
use OCA\Merlin\Service\ContentFilterRepository;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'merlin';

	/**
	 * Port des Kerns => Nextcloud-Implementierung.
	 *
	 * @var array<class-string, class-string>
	 */
	public const CORE_PORTS = [
		AppSettings::class         => NextcloudAppSettings::class,
		ArticleDeletion::class     => ArticleDeletionService::class,
		ArticleFiles::class        => NextcloudArticleFiles::class,
		ArticleStore::class        => SqlArticleStore::class,
		CommentGuestStore::class   => SqlCommentGuestStore::class,
		CommentStore::class        => SqlCommentStore::class,
		Connection::class          => NextcloudConnection::class,
		ContentFilterSource::class => ContentFilterRepository::class,
		ContentFilterStore::class  => NextcloudContentFilterStore::class,
		Crypto::class              => NextcloudCrypto::class,
		Database::class            => NextcloudDatabase::class,
		FilePreviews::class        => NextcloudFilePreviews::class,
		HighlightStore::class      => SqlHighlightStore::class,
		InstanceSecret::class      => NextcloudInstanceSecret::class,
		Platform::class            => NextcloudPlatform::class,
		RetentionStore::class      => NextcloudRetentionStore::class,
		Scheduler::class           => ShutdownScheduler::class,
		Session::class             => NextcloudSession::class,
		ShareStore::class          => SqlShareStore::class,
		SiteCredentialStore::class => NextcloudSiteCredentialStore::class,
		SiteSessions::class        => SiteCredentialService::class,
		TagStore::class            => SqlTagStore::class,
		Throttler::class           => NextcloudThrottler::class,
		UrlGenerator::class        => NextcloudUrlGenerator::class,
		UserDirectory::class       => NextcloudUserDirectory::class,
		UserSettings::class        => NextcloudUserSettings::class,
	];

	/**
	 * Kern-Klassen, die per Dependency Injection gebaut werden (direkt oder als
	 * Abhängigkeit einer anderen). tools/test-core-wiring.php prüft, dass die
	 * Liste vollständig ist.
	 *
	 * @var list<class-string>
	 */
	public const CORE_SERVICES = [
		ContentFilterMerger::class,
		ContentFilterSerializer::class,
		ContentFilterValidator::class,
		ContentExtractorService::class,
		InlineMediaService::class,
		MediaHttpClient::class,
		MediaProviderRegistry::class,
		MediaResolverService::class,
		ArdMediathekProvider::class,
		ArteProvider::class,
		DreiSatProvider::class,
		JsonLdMediaProvider::class,
		XPathMediaProvider::class,
		YoutubeEmbedProvider::class,
		ZdfProvider::class,
		BlueskyThreadResolverService::class,
		MastodonPostResolverService::class,
		TikTokPostResolverService::class,
		ExportService::class,
		PdfProxyService::class,
		ReportBackendService::class,
		SupportBoxService::class,
		TtsStreamService::class,
		RetentionService::class,
		SiteCredentialService::class,
		CommentService::class,
		ShareAccessService::class,
		ArticleDeletionService::class,
		FileEntryService::class,
		ArticleExtractionService::class,
		ShutdownScheduler::class,
		SqlArticleStore::class,
		SqlTagStore::class,
		SqlCommentStore::class,
		SqlCommentGuestStore::class,
		SqlHighlightStore::class,
		SqlShareStore::class,
		ArticleApi::class,
		TagApi::class,
		CapabilitiesApi::class,
		HighlightApi::class,
		CommentApi::class,
		ShareApi::class,
		PublicShareApi::class,
		PublicCommentApi::class,
	];

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		// Load vendor autoloader for external dependencies
		$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
		if (file_exists($vendorAutoload)) {
			require_once $vendorAutoload;
		}
	}

	public function register(IRegistrationContext $context): void {
		// Ports des framework-freien Kerns (core/, Merlin\Core\Port) und ihre
		// Nextcloud-Implementierungen.
		foreach (self::CORE_PORTS as $port => $implementation) {
			$context->registerServiceAlias($port, $implementation);
		}
		// Kern-Klassen liegen nicht im Namespace OCA\Merlin. Ohne eigene
		// Registrierung würde Nextcloud sie im Server-Container auflösen: der
		// kennt die Ports oben nicht und gibt einen Logger ohne App-Kennung.
		// Deshalb baut der App-Container sie selbst, per Reflection wie das
		// normale Autowiring.
		foreach (self::CORE_SERVICES as $class) {
			$context->registerService($class, static fn (ContainerInterface $c): object => self::autowire($c, $class));
		}

		$context->registerSearchProvider(ArticleSearchProvider::class);
		$context->registerEventListener(
			AddContentSecurityPolicyEvent::class,
			AddContentSecurityPolicyListener::class,
		);
		// Räumt die Merlin-Daten gelöschter Nutzer auf, siehe
		// UserDeletedListener-Docblock.
		$context->registerEventListener(
			UserDeletedEvent::class,
			UserDeletedListener::class,
		);

		// Zustandsabhängiger CSRF-Schutz: erzwingt einen requesttoken nur für
		// cookie-authentifizierte Web-UI-Writes, lässt native Clients (Basic/Bearer)
		// unangetastet. Ohne globalen Flag → gilt nur für Merlin-Controller.
		$context->registerMiddleware(CsrfCookieAuthMiddleware::class);
	}

	/**
	 * Baut $class mit den Konstruktor-Abhängigkeiten aus dem App-Container.
	 */
	private static function autowire(ContainerInterface $container, string $class): object {
		$constructor = (new \ReflectionClass($class))->getConstructor();
		$args = [];
		foreach ($constructor?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
				$args[] = $container->get($type->getName());
			} elseif ($parameter->isDefaultValueAvailable()) {
				$args[] = $parameter->getDefaultValue();
			} else {
				throw new \LogicException('Kann ' . $class . '::$' . $parameter->getName() . ' nicht auflösen');
			}
		}
		return new $class(...$args);
	}

	public function boot(IBootContext $context): void {
		// Boot the app
	}
}
