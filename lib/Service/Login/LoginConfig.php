<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Login;

/**
 * Geparste <login>-Sektion einer Bundle-Domain-Config (z. B. content-filters/
 * tagesspiegel.de.xml). Bewusst BUNDLE-ONLY: <login> ist kein Teil von
 * ContentFilterSchema::SECTIONS und läuft deshalb nie durch
 * ContentFilterMerger/-Validator - weder Admin noch Nutzer können darüber
 * einen eigenen Login-Endpoint definieren (das wäre ein SSRF-/Credential-
 * Exfiltrations-Vektor: ein Nutzer könnte sonst sein eigenes Passwort-Feld an
 * eine beliebige URL schicken lassen). Gelesen wird ausschliesslich das rohe
 * Bundle-XML (ContentFilterRepository::readBundle()), siehe
 * SiteCredentialService::loadLoginConfig().
 */
final class LoginConfig {
	/**
	 * @param list<string> $persistCookieNames
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $page,
		public readonly string $ajaxEndpointUrl,
		public readonly array $persistCookieNames,
		/** Anbieter-Kennung innerhalb einer Plattform, z. B. Pianos "service"-Feld ("tsp" für Tagesspiegel). Optional, providerspezifisch. */
		public readonly string $service = '',
		/**
		 * Optionales PCRE-Pattern, das im rohen HTML einer Artikelseite auf
		 * "diese Seite ist (noch) hinter der Paywall" prüft, z. B.
		 * '/"showPaywall"\s*:\s*true/' bei Tagesspiegel. Erkennt
		 * ContentExtractorService das Pattern trotz gesetztem Session-Cookie
		 * (oder mangels Zugangsdaten) im HTML, wirft es
		 * PaywallLoginRequiredException statt einen leeren/kaputten Artikel
		 * zu liefern. null, wenn die Domain keinen Marker konfiguriert hat
		 * (Paywall-Erkennung entfällt dann, Cookie-Injektion bleibt aktiv).
		 */
		public readonly ?string $paywallMarkerPattern = null,
		/** OAuth-Client-ID beim Identity-Provider (<authorize-endpoint client-id>), z. B. "spark-fe-bmo" für Morgenpost. Nur keycloak-form-post. */
		public readonly string $clientId = '',
		/** Authorization-Endpoint des Identity-Providers (<authorize-endpoint url>). Nur keycloak-form-post. */
		public readonly string $authorizeUrl = '',
		/**
		 * redirect_uri, an die der Identity-Provider die Tokens per
		 * form_post zurückschickt und die daraus die Session-Cookies der
		 * Zeitung macht (<redirect-endpoint url>). Nur keycloak-form-post.
		 */
		public readonly string $redirectUrl = '',
		/**
		 * Cookies, die beim POST an redirectUrl mitgeschickt werden, weil das
		 * Seiten-JS sie im Browser vor dem Login setzt (<redirect-endpoint>
		 * <cookie name value>). Nur keycloak-form-post.
		 *
		 * @var array<string,string>
		 */
		public readonly array $redirectCookies = [],
	) {
	}

	public static function fromXml(\SimpleXMLElement $login): self {
		$ajax = $login->{'ajax-endpoint'};
		$persistCookieNames = [];
		foreach ($login->{'persist-cookie'} as $cookie) {
			$name = trim((string) ($cookie['name'] ?? ''));
			if ($name !== '') {
				$persistCookieNames[] = $name;
			}
		}

		$markerNode = $login->{'paywall-marker'};
		$marker     = isset($markerNode['regex']) ? trim((string) $markerNode['regex']) : '';

		$authorize = $login->{'authorize-endpoint'};
		$redirect  = $login->{'redirect-endpoint'};
		$redirectCookies = [];
		foreach ($redirect->cookie ?? [] as $cookie) {
			$name = trim((string) ($cookie['name'] ?? ''));
			if ($name !== '') {
				$redirectCookies[$name] = trim((string) ($cookie['value'] ?? ''));
			}
		}

		return new self(
			type: trim((string) ($login['type'] ?? '')),
			page: trim((string) ($login['page'] ?? '')),
			ajaxEndpointUrl: trim((string) ($ajax['url'] ?? '')),
			persistCookieNames: $persistCookieNames,
			service: trim((string) ($login['service'] ?? '')),
			paywallMarkerPattern: $marker !== '' ? $marker : null,
			clientId: trim((string) ($authorize['client-id'] ?? '')),
			authorizeUrl: trim((string) ($authorize['url'] ?? '')),
			redirectUrl: trim((string) ($redirect['url'] ?? '')),
			redirectCookies: $redirectCookies,
		);
	}

	public function isValid(): bool {
		if ($this->type === ''
			|| filter_var($this->page, FILTER_VALIDATE_URL) === false
			|| $this->persistCookieNames === []) {
			return false;
		}

		return match ($this->type) {
			'keycloak-form-post' => $this->clientId !== ''
				&& filter_var($this->authorizeUrl, FILTER_VALIDATE_URL) !== false
				&& filter_var($this->redirectUrl, FILTER_VALIDATE_URL) !== false,
			default => filter_var($this->ajaxEndpointUrl, FILTER_VALIDATE_URL) !== false,
		};
	}
}
