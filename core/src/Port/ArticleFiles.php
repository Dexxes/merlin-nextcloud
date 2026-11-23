<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

use Merlin\Core\Domain\Article;

/**
 * Einträge aus „Merlin Dateien“ (Artikel mit fileId) neu aufbauen: Titel =
 * Dateiname, Inhalt mit Metadaten und Download-Link. Die Artikel-API ruft das
 * nur für Artikel mit fileId auf.
 *
 * In beiden Varianten Service\FileEntries (über den Port FileStorage).
 */
interface ArticleFiles {
	/**
	 * Beim Öffnen: neu aufbauen, wenn die Datei umbenannt wurde oder der
	 * Inhalt von vor Metadaten/Download-Link stammt.
	 *
	 * @return Article der neu geladene oder der unveränderte Eintrag
	 */
	public function refreshIfNeeded(Article $article): Article;

	/**
	 * „Neu laden“: Eintrag aus der Datei neu aufbauen.
	 *
	 * @return Article der neu geladene Eintrag, unverändert wenn die Datei fehlt
	 */
	public function refresh(Article $article): Article;

	/**
	 * Die Datei eines Eintrags mit fileId ausliefern (PDF im Reader, auch
	 * hinter einem Share-Link). Schreibt die Antwort selbst und beendet den
	 * Request (wie PdfProxyService).
	 */
	public function stream(Article $article): never;
}
