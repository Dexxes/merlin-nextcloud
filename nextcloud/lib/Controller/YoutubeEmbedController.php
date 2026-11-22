<?php

declare(strict_types=1);

namespace OCA\Merlin\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;

/**
 * Liefert eine winzige, eigenständige HTML-Seite aus, die NICHTS als ein
 * YouTube-<iframe> enthält.
 *
 * Warum überhaupt ein eigener Endpunkt statt direkt auf youtube-nocookie.com
 * zu verlinken? Der native Reader (iOS/iPad) lädt den Artikel-Inhalt über
 * `WKWebView.loadFileURL(...)` (file://-Origin), weil das der einzige Weg
 * ist, dem WebView Lesezugriff auf lokal gecachte Bilder zu geben (siehe
 * ArticleReaderView.swift). YouTubes eingebetteter Player prüft aber Origin/
 * Referrer des einbettenden Dokuments – bei file:// bekommt er keinen
 * gültigen Wert und bricht mit "Error 153: Video player configuration error" ab.
 *
 * Diese Seite läuft über die echte https-Domain der Nextcloud-Instanz und hat
 * damit selbst eine gültige Origin für den darin eingebetteten Player.
 *
 * WICHTIG zur Einbettung durch den Reader: Ein erster Versuch, DIESE Seite
 * ihrerseits als <iframe> in die file://-Artikelseite zu verschachteln
 * (iframe-in-iframe), scheiterte – WKWebView wertet CSP `frame-ancestors`
 * für eine file://-Elternseite nicht zuverlässig aus und zeigt dann einfach
 * eine leere Fläche statt eines Fehlers. Der Reader nutzt diese Seite deshalb
 * per TOP-LEVEL-Navigation in einer separaten WKWebView (siehe
 * YouTubePlayerView.swift in merlin-ios/merlin-ipad) statt sie zu
 * verschachteln – dort existiert dann gar keine Elternseite, gegen die
 * X-Frame-Options/frame-ancestors überhaupt greifen könnten. Die
 * frame-ancestors-Freigabe unten bleibt trotzdem gesetzt (schadet nicht,
 * falls die Seite doch einmal gerahmt aufgerufen wird).
 *
 * Bewusst ohne Login (#[PublicPage]): die Seite trägt keine Nutzerdaten,
 * nur eine öffentliche YouTube-Video-ID, die ohnehin im (potenziell
 * öffentlich geteilten) Artikel-HTML steht.
 */
class YoutubeEmbedController extends Controller {
	// 11 Zeichen ist YouTubes Standard-ID-Länge; a-z/A-Z/0-9/-/_ ist der volle
	// Base64url-Zeichensatz, den YouTube für Video-IDs verwendet.
	private const VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

	public function __construct(
		string $appName,
		IRequest $request,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $v, string $t = ''): DataDisplayResponse {
		if (!preg_match(self::VIDEO_ID_PATTERN, $v)) {
			return new DataDisplayResponse('Invalid video id', Http::STATUS_BAD_REQUEST);
		}

		// Startzeit ist optional und rein numerisch (Sekunden) – alles andere
		// wird verworfen statt validiert/durchgereicht, das spart eine weitere
		// Fehlerklasse an dieser bewusst minimalen Seite.
		$startSeconds = ctype_digit($t) ? $t : '';

		$embedParams = [
			'controls'    => '1',
			'modestbranding' => '1',
			'playsinline' => '1',
			'rel'         => '0',
			// origin MUSS zur tatsächlichen Origin dieser Seite passen, sonst
			// verweigert YouTube den Embed genau wie beim ursprünglichen
			// file://-Problem, nur eine Ebene tiefer.
			'origin'      => $this->request->getServerProtocol() . '://' . $this->request->getServerHost(),
		];
		if ($startSeconds !== '') {
			$embedParams['start'] = $startSeconds;
		}

		$videoIdEscaped = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
		$embedUrl = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($v)
			. '?' . http_build_query($embedParams);
		$embedUrlEscaped = htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8');

		$html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="referrer" content="strict-origin-when-cross-origin">
<title>YouTube: {$videoIdEscaped}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { width: 100%; height: 100%; overflow: hidden; background: #000; }
  iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
</style>
</head>
<body>
<iframe
  src="{$embedUrlEscaped}"
  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
  allowfullscreen
  referrerpolicy="strict-origin-when-cross-origin"
></iframe>
</body>
</html>
HTML;

		$response = new DataDisplayResponse($html, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);

		// frame-src für den nachgelagerten YouTube-Player explizit auch hier
		// setzen (zusätzlich zur globalen Policy aus
		// AddContentSecurityPolicyListener) – unabhängig davon, ob Nextcloud
		// Response- und Event-Policies zusammenführt oder die Response-Policy
		// Vorrang hat, ist diese Seite so in beiden Fällen korrekt.
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain('https://www.youtube-nocookie.com');

		// Nextcloud setzt standardmäßig X-Frame-Options: SAMEORIGIN, das JEDE
		// Einbettung dieser Seite in ein fremdes Dokument verbietet – auch die
		// gewollte hier (Elternseite ist der Reader mit file://-Origin, die
		// nie "SAMEORIGIN" zur https-Domain sein kann). Ergebnis ohne diese
		// Zeile: kein Fehler, nur eine leere weiße Fläche, weil WKWebView den
		// Frame still verwirft. frame-ancestors '*' überschreibt laut CSP-Spec
		// (Level 2) das ältere X-Frame-Options, moderne Browser/WKWebView
		// halten sich daran. Unbedenklich hier: Diese Seite ist ohnehin
		// öffentlich (#[PublicPage]) und zeigt nur eine öffentliche
		// YouTube-Video-ID ohne Nutzerdaten – wer sie einbettet, lernt nichts,
		// was nicht schon im Artikel-HTML selbst öffentlich stand.
		$policy->addAllowedFrameAncestorDomain('*');

		$response->setContentSecurityPolicy($policy);

		return $response;
	}
}
