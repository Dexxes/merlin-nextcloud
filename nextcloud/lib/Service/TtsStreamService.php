<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Db\Article;

/**
 * TTS-Streaming: HTML → Plaintext → Piper-Daemon → MP3-Stream direkt an den Client.
 *
 * Ausgelagert aus TtsController, damit sowohl der authentifizierte Endpunkt
 * (TtsController, Basic Auth) als auch der öffentliche Share-Endpunkt
 * (PublicShareController, Token/Passwort) dieselbe – sicherheitskritische –
 * curl-Proxy-Logik verwenden, statt sie zu duplizieren.
 */
class TtsStreamService {
	/** Piper-Daemon (nur localhost, kein Auth) */
	private const DAEMON = 'http://127.0.0.1:5051';

	/** Erlaubte Sprachen (müssen mit geladenen Piper-Modellen übereinstimmen) */
	private const SUPPORTED_LANGS = ['de', 'en', 'es', 'fr', 'it'];

	// Verzögerungsstrings, um den Decoder aufzuwärmen (überbrücken den
	// AVPlayer-Player-Start, siehe PiperAudioService.swift-Gegenstück auf iOS).
	private const START_TEXT = [
		'de' => 'DODODODODODODODODODODO. ',
		'en' => 'DODODODODODODODODODODODODO. ',
		'es' => 'DODODODODODODODODODODODODODO. ',
		'fr' => 'DODO-KO-KO. ',
		'it' => 'DODODODODODODODODODODODODODODODODO. ',
	];

	/**
	 * Extrahiert Text aus dem Artikel, spricht ihn per Piper-Daemon und streamt
	 * das Ergebnis direkt als MP3 an den Client. Beendet den PHP-Prozess selbst
	 * per exit() – siehe Ablaufkommentar unten. Läuft daher NIE normal zurück.
	 */
	public function stream(Article $article, string $lang = 'de', int $speaker = -1): void {
		// Deutsches Modell hat nur einen brauchbaren Sprecher (Index 7) –
		// unabhängig davon, was der Aufrufer übergeben hat.
		if (strtolower(substr($lang, 0, 2)) === 'de') {
			$speaker = 7;
		}

		// ── 1. Sprache normalisieren ──────────────────────────────────────────
		$lang = strtolower(substr($lang, 0, 2));
		if (!in_array($lang, self::SUPPORTED_LANGS, true)) {
			$lang = 'de';
		}

		// ── 2. HTML → Plaintext ───────────────────────────────────────────────
		$html      = $article->getContent() ?? $article->getExcerpt() ?? '';
		$plaintext = $this->extractPlainText($article->getTitle() ?? '', $html, $lang);

		if (empty($plaintext)) {
			http_response_code(422);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'Article has no readable text']);
			exit();
		}

		// ── 3. Daemon-Session anlegen (POST /synthesize) ──────────────────────
		// speaker: -1 = nicht übergeben (Modell-Default), >= 0 = expliziter Speaker-Index
		$payload = json_encode(
			$speaker >= 0
				? ['text' => $plaintext, 'lang' => $lang, 'speaker' => $speaker]
				: ['text' => $plaintext, 'lang' => $lang],
			JSON_THROW_ON_ERROR,
		);

		$ch = curl_init(self::DAEMON . '/synthesize');
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_CONNECTTIMEOUT => 3,
		]);

		$body     = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr  = curl_errno($ch);

		if ($curlErr !== 0 || $httpCode !== 201) {
			http_response_code(503);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'TTS daemon unavailable']);
			exit();
		}

		$data = json_decode($body, true);
		if (!is_array($data) || !isset($data['session_id'])) {
			http_response_code(502);
			header('Content-Type: application/json');
			echo json_encode(['error' => 'Invalid daemon response']);
			exit();
		}

		$sessionId = $data['session_id'];

		// ── 4. Output-Buffering deaktivieren + Timeouts anpassen ─────────────
		// set_time_limit(0): PHP-Standard ist max_execution_time = 30 Sekunden.
		// Ein langer Artikel (10+ Minuten Audio) würde mitten im Stream mit
		// einem Fatal Error abgebrochen. ignore_user_abort() verhindert NUR den
		// Abbruch bei Client-Disconnect – nicht den Execution-Timeout.
		set_time_limit(0);

		// ignore_user_abort: PHP weiterlaufen lassen wenn der Client trennt.
		// Ohne dieses Flag beendet PHP das Script beim nächsten flush()-Aufruf,
		// bevor connection_aborted() im WRITEFUNCTION überhaupt ausgewertet wird.
		ignore_user_abort(true);
		while (ob_get_level()) {
			ob_end_clean();
		}
		ini_set('output_buffering', 'off');
		ini_set('zlib.output_compression', 'off');
		ob_implicit_flush(true);

		// ── 5. Response-Header ────────────────────────────────────────────────
		header('Content-Type: audio/mpeg');
		header('Cache-Control: no-cache, no-store');
		// X-Accel-Buffering: Deaktiviert Nginx-Proxy-Puffern (Synology Reverse Proxy).
		// Für Apache mod_proxy_fcgi greift dieser Header NICHT – dort muss in der
		// Apache-Konfiguration der Nextcloud-VHost ergänzt werden:
		//   <LocationMatch "^/index\.php/apps/merlin/(api|s)/.*/tts">
		//       ProxyFCGISetEnvIf "true" proxy-sendcl ""
		//       SetEnv proxy-sendchunks 1
		//   </LocationMatch>
		header('X-Accel-Buffering: no');
		header('Content-Encoding: identity');  // Apache mod_deflate deaktivieren
		// Accept-Ranges: none → AVPlayer weiß, dass kein Seeking möglich ist
		// und stellt keine Range-Requests – reduziert Sekundäranfragen.
		header('Accept-Ranges: none');

		// ── 6. MP3-Stream vom Daemon direkt an den Client pipen ──────────────
		$url = self::DAEMON . '/stream/' . $sessionId;
		$ch  = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_TIMEOUT        => 3600,  // Lange Artikel können dauern
			CURLOPT_CONNECTTIMEOUT => 5,
			// Nagle-Algorithmus deaktivieren: Verhindert, dass libcurl kleine
			// Pakete zusammenfasst. Auf localhost kaum messbar, aber konsistent
			// mit dem Ziel minimaler Latenz.
			CURLOPT_TCP_NODELAY    => true,
			// libcurl-Lesepuffer auf 1 KB setzen: Standardmäßig puffert libcurl
			// 16 KB bevor es die WRITEFUNCTION aufruft. Mit 1 KB wird der
			// Callback bei jedem ankommenden Daemon-Chunk ausgelöst statt erst
			// nach dem Ansammeln mehrerer Chunks – direkte Weiterleitung.
			CURLOPT_BUFFERSIZE     => 1024,
			// Range-Header entfernen: AVPlayer sendet manchmal "Range: bytes=0-",
			// was als zweiter Request ankäme. Für einen Live-Stream sinnlos.
			CURLOPT_HTTPHEADER     => ['Range:'],
			CURLOPT_WRITEFUNCTION  => static function ($ch, string $data): int {
				if (connection_aborted()) {
					// Client hat die Verbindung getrennt (z.B. zweiter Player-
					// Request, der abgebrochen wurde). -1 signalisiert curl,
					// den Transfer abzubrechen; der Daemon-Session wird dadurch
					// ebenfalls sauber beendet (CancelledError im Python-Daemon).
					return -1;
				}
				echo $data;
				if (ob_get_level()) {
					ob_flush();
				}
				flush();
				return strlen($data);
			},
		]);

		curl_exec($ch);
		$streamErrno = curl_errno($ch);

		// CURLE_WRITE_ERROR (23) = Client hat getrennt → normal, kein Cleanup nötig,
		// da der Daemon-Prozess via TCP-Reset + CancelledError selbst aufräumt.
		// Bei jedem anderen curl-Fehler (Timeout, DNS, etc.) ist die Daemon-Session
		// noch offen → explizit per DELETE freigeben, damit der Semaphore-Slot
		// nicht erst nach SESSION_TTL (60 s) zurückgegeben wird.
		if ($streamErrno !== 0 && $streamErrno !== 23 /* CURLE_WRITE_ERROR */) {
			$delCh = curl_init(self::DAEMON . '/stream/' . $sessionId);
			curl_setopt_array($delCh, [
				CURLOPT_CUSTOMREQUEST  => 'DELETE',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 3,
				CURLOPT_CONNECTTIMEOUT => 2,
			]);
			curl_exec($delCh);
		}

		// KRITISCH: PHP/Nextcloud sofort beenden, bevor das Framework noch eigene
		// Response-Bytes (JSON-Envelope, leerer Body, o.ä.) anhängt.
		// Ohne exit() empfängt der Player ungültige Bytes am Ende des Streams
		// und wirft CoreMediaErrorDomain -16830 (Format-Parsing-Fehler).
		exit();
	}

	// ── Hilfsmethoden ─────────────────────────────────────────────────────────

	private function entferneBildunterschriften(string $html): string {
		// 1. Entferne <figcaption>-Tags und deren Inhalt
		$html = preg_replace('/<figcaption[^>]*>.*?<\/figcaption>/is', '', $html);

		// 2. Entferne alt- und title-Attribute aus <img>-Tags
		$html = preg_replace('/<img([^>]*)\s(alt|title)=["\'][^"\']*["\']/i', '<img$1', $html);

		// 3. Entferne leere alt- und title-Attribute aus <img>-Tags
		$html = preg_replace('/<img([^>]*)\s(alt|title)=["\']\s*["\']/i', '<img$1', $html);

		// 4. Optional: Entferne Textknoten direkt nach <img>-Tags (z. B. <img>Bildunterschrift)
		$html = preg_replace('/<img([^>]*)>\s*([^<]+)/i', '<img$1>', $html);

		return $html;
	}

	private function extractPlainText(string $title, string $html, string $lang = 'de'): string {
		// Platzhalter für Block-Grenzen – eindeutig genug, dass er nicht im
		// Fließtext vorkommt; wird nach strip_tags durch ein Komma ersetzt.
		// Komma → Piper erzeugt eine kurze Atempause (~0,1 s) statt der
		// langen Satzpause (~0,5 s) eines Punktes.
		$sep = " , , , ";
		$html = $this->entferneBildunterschriften($html);
		// ── Überschriften mit Pausen einrahmen ────────────────────────────────
		// Vor dem öffnenden Tag: PARA-Trenner + zwei Punkte (je ~0,3 s Pause).
		// Nach dem schließenden Tag: zwei Punkte + PARA-Trenner.
		// Ergebnis im Reintext: ", . . Überschrift. . ,"
		// → Überschriften sind klar vom Fließtext abgesetzt.
		$html = (string) preg_replace('#<h[1-6](\s[^>]*)?>#i', $sep . '. ', $html);
		$html = (string) preg_replace('#</h[1-6]\s*>#i', '. ' . $sep, $html);

		// Block-Level-Abschluss-Tags mit Platzhalter markieren (vor strip_tags).
		// Doppelte Grenzen (</p></div>) werden später zusammengeführt.
		// h[1-6]-Tags wurden bereits oben ersetzt, hier als Fallback für malformed HTML.
		$html = (string) preg_replace(
			'#</(?:p|div|h[1-6]|li|blockquote|section|article)\s*>#i',
			$sep,
			$html,
		);
		$html = (string) preg_replace('#<br\s*/?>#i', $sep, $html);

		$text = strip_tags($html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Whitespace zuerst normalisieren, damit benachbarte Platzhalter sauber
		// zusammengeführt werden.
		$text = (string) preg_replace('/\s+/', ' ', $text);

		// Mehrfach aufeinanderfolgende Platzhalter → ein einziges Komma.
		$text = (string) preg_replace(
			'/(' . preg_quote($sep, '/') . '\s*)+/',
			', ',
			$text,
		);

		// Kollisionen bereinigen: "Satz., Weiter" → "Satz. Weiter"
		// Nur wenn Satzzeichen direkt nach einem Wortzeichen steht – nicht bei
		// den Überschriften-Pausen ". . ," (dort steht ein Punkt vor dem Komma).
		$text = (string) preg_replace('/([a-zäöüßA-ZÄÖÜ0-9])([.!?])\s*,\s*/u', '$1$2 ', $text);

		$text = trim($text);

		// ── Deutsche Abkürzungen auflösen ─────────────────────────────────────
		if ($lang === 'de') {
			$text = $this->expandGermanAbbreviations($text);
		}

		// Pausen, um das Hochfahren des Players zu überbrücken
		$text = (self::START_TEXT[$lang] ?? self::START_TEXT['de']) . $text;

		return $text;
	}

	/**
	 * Löst gängige deutsche Abkürzungen in ausgeschriebene Formen auf,
	 * damit Piper sie korrekt vorliest statt zu buchstabieren.
	 *
	 * Reihenfolge: Mehrwort-Abkürzungen (z.B., d.h., …) zuerst, damit ihre
	 * Teile nicht einzeln durch spätere Einwort-Regeln ersetzt werden.
	 */
	private function expandGermanAbbreviations(string $text): string {
		$replacements = [
			// ── Mehrwort-Abkürzungen ──────────────────────────────────────────
			'/\bz\.\s*B\./u'  => 'zum Beispiel',
			'/\bu\.\s*a\./u'  => 'unter anderem',
			'/\bd\.\s*h\./u'  => 'das heißt',
			'/\bo\.\s*ä\./u'  => 'oder ähnlichem',
			'/\bu\.\s*U\./u'  => 'unter Umständen',
			'/\bv\.\s*a\./u'  => 'vor allem',
			// ── Einwort-Abkürzungen ───────────────────────────────────────────
			'/\bca\./u'       => 'circa',
			'/\bbzw\./u'      => 'beziehungsweise',
			'/\busw\./u'      => 'und so weiter',
			'/\betc\./u'      => 'et cetera',
			'/\bvgl\./iu'     => 'vergleiche',
			'/\bggf\./u'      => 'gegebenenfalls',
			'/\bevtl\./u'     => 'eventuell',
			'/\bbspw\./u'     => 'beispielsweise',
			'/\bsog\./u'      => 'sogenannte',
			'/\binkl\./u'     => 'inklusive',
			'/\bexkl\./u'     => 'exklusive',
			'/\bMrd\./u'      => 'Milliarden',
			'/\bMio\./u'      => 'Millionen',
			'/\bNr\./u'       => 'Nummer',
			'/\bProf\./u'     => 'Professor',
			'/\bDr\./u'       => 'Doktor',
			'/\bvs\./u'       => 'versus',
		];

		return (string) preg_replace(
			array_keys($replacements),
			array_values($replacements),
			$text,
		);
	}
}
