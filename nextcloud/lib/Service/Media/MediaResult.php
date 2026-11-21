<?php

declare(strict_types=1);

namespace OCA\Merlin\Service\Media;

/**
 * Ergebnis einer Medien-Auflösung – einheitlich für alle Provider, damit
 * Controller, Extractor und Frontend nichts über den konkreten Sender wissen
 * müssen.
 *
 * kind:     'video' | 'audio'  – steuert, ob der Player ein <video> oder <audio> rendert
 * delivery: 'hls'   – HLS-Manifest(e), per hls.js bzw. nativ (Safari) abgespielt
 *           'file'  – direkte Mediendatei (mp3/m4a/mp4), natives <audio>/<video src>
 *           'embed' – offizieller iframe-Player des Anbieters (z. B. YouTube)
 */
final class MediaResult {
	public const KIND_VIDEO = 'video';
	public const KIND_AUDIO = 'audio';
	public const KINDS      = [self::KIND_VIDEO, self::KIND_AUDIO];

	public const DELIVERY_HLS   = 'hls';
	public const DELIVERY_FILE  = 'file';
	public const DELIVERY_EMBED = 'embed';
	public const DELIVERIES     = [self::DELIVERY_HLS, self::DELIVERY_FILE, self::DELIVERY_EMBED];

	/**
	 * @param list<array{label: string, url: string, subtitleLanguage?: string|null}> $variants
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $delivery,
		public readonly array $variants,
		public readonly int $defaultIndex = 0,
		public readonly bool $transient = false,
	) {
		if (!in_array($kind, self::KINDS, true)) {
			throw new \InvalidArgumentException('Unbekannte Medienart: ' . $kind);
		}
		if (!in_array($delivery, self::DELIVERIES, true)) {
			throw new \InvalidArgumentException('Unbekannte Auslieferung: ' . $delivery);
		}
		if ($variants === [] || !isset($variants[$defaultIndex])) {
			throw new \InvalidArgumentException('MediaResult braucht mindestens eine Variante und einen gültigen defaultIndex.');
		}
	}

	/** Einzelne Quelle ohne Varianten-Auswahl (Datei oder Embed). */
	public static function single(string $kind, string $delivery, string $url, string $label = 'Standard'): self {
		return new self($kind, $delivery, [['label' => $label, 'url' => $url]]);
	}

	/**
	 * Stabile Quellen (DLF-mp3, ARD-Sounds-Datei, YouTube-Embed) ändern ihre
	 * URL nicht und dürfen deshalb beim Speichern als Marker in den
	 * Artikel-Content geschrieben werden – das funktioniert dann auch offline
	 * und in öffentlichen Share-Links. Über die Sender-APIs aufgelöste
	 * Mediathek-Streams ($transient) sind dagegen signiert/kurzlebig und
	 * werden bei jedem Öffnen neu aufgelöst.
	 */
	public function isPersistable(): bool {
		return !$this->transient;
	}

	public function defaultUrl(): string {
		return $this->variants[$this->defaultIndex]['url'];
	}

	/**
	 * @return array{kind: string, delivery: string, variants: list<array{label: string, url: string, subtitleLanguage?: string|null}>, defaultIndex: int}
	 */
	public function toArray(): array {
		return [
			'kind'         => $this->kind,
			'delivery'     => $this->delivery,
			'variants'     => $this->variants,
			'defaultIndex' => $this->defaultIndex,
		];
	}
}
