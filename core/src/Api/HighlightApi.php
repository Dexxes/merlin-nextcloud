<?php

declare(strict_types=1);

namespace Merlin\Core\Api;

use Merlin\Core\Domain\Highlight;
use Merlin\Core\Port\ArticleStore;
use Merlin\Core\Port\HighlightStore;
use Merlin\Core\Service\CommentRules;
use Merlin\Core\Service\CommentService;

/**
 * Markierungen des Besitzers (/api/articles/{articleId}/highlights,
 * /api/highlights/{id}), Antworten wie bisher in
 * OCA\Merlin\Controller\HighlightController.
 *
 * In PDF-Artikeln (Kategorie 'PDF') verankern pdfPage und pdfRects die
 * Stelle statt XPaths; die XPath-Felder bekommen dann den Platzhalter
 * CommentRules::pdfXpath().
 */
final class HighlightApi {
	public function __construct(
		private HighlightStore $highlights,
		private ArticleStore $articles,
		private CommentService $comments,
	) {
	}

	/** GET /api/articles/{articleId}/highlights */
	public function index(ApiRequest $request): ApiResult {
		$list = $this->highlights->findByArticleId((int) $request->int('articleId'), $request->userId());
		return ApiResult::json(array_map(static fn (Highlight $h) => $h->jsonSerialize(), $list));
	}

	/**
	 * POST /api/articles/{articleId}/highlights: highlightedText, startXpath,
	 * startOffset, endXpath, endOffset, color, bei PDF pdfPage und pdfRects.
	 */
	public function create(ApiRequest $request): ApiResult {
		$userId = $request->userId();
		$articleId = (int) $request->int('articleId');
		// Nur an eigenen Artikeln (keine Markierungen an geratenen IDs).
		$article = $this->articles->find($articleId, $userId);
		if ($article === null) {
			return ApiResult::error('Article not found', 404);
		}
		$text = $request->string('highlightedText');
		if ($text === null) {
			return ApiResult::error('highlight_invalid', 400);
		}
		$startXpath = (string) $request->string('startXpath', '');
		$startOffset = (int) $request->int('startOffset', 0);
		$endXpath = (string) $request->string('endXpath', '');
		$endOffset = (int) $request->int('endOffset', 0);
		$pdfPage = $request->int('pdfPage');

		$rectsJson = null;
		if ($pdfPage !== null) {
			$rectsJson = CommentRules::normalizePdfRects($request->param('pdfRects'));
			if ($article->getCategory() !== 'PDF' || !CommentRules::isValidPdfPage($pdfPage) || $rectsJson === null) {
				return ApiResult::error('highlight_invalid', 400);
			}
			$startXpath = $endXpath = CommentRules::pdfXpath($pdfPage);
			$startOffset = $endOffset = 0;
		}

		// Dieselben Regeln wie für Gäste (nur mit längerem Text): Markierungen
		// des Besitzers sieht auch jeder Besucher des Share-Links.
		$error = CommentRules::validateHighlight(
			$text, $startXpath, $startOffset, $endXpath, $endOffset, CommentRules::OWNER_HIGHLIGHT_TEXT_MAX);
		if ($error !== null) {
			return ApiResult::error($error, 400);
		}

		$highlight = new Highlight();
		$highlight->setUserId($userId);
		$highlight->setArticleId($articleId);
		$highlight->setHighlightedText(CommentRules::normalizeHighlightText($text));
		$highlight->setStartXpath($startXpath);
		$highlight->setStartOffset($startOffset);
		$highlight->setEndXpath($endXpath);
		$highlight->setEndOffset($endOffset);
		$highlight->setColor(CommentRules::sanitizeColor((string) $request->string('color', 'yellow')));
		$highlight->setCreatedAt(new \DateTime());
		$highlight->setPdfPage($pdfPage);
		$highlight->setPdfRects($rectsJson);

		return ApiResult::json($this->highlights->insert($highlight)->jsonSerialize(), 201);
	}

	/**
	 * PUT /api/highlights/{id}: Stelle (im Reader länger oder kürzer gezogen)
	 * und optional Farbe einer Markierung ändern. Die ID bleibt, Threads
	 * bleiben daran, ihr Zitat folgt der neuen Stelle
	 * (CommentService::updateHighlight()).
	 *
	 * Stelle und Farbe ändern sich nur bei eigenen Farbmarkierungen.
	 * Gast-Markierungen und Kommentar-Anker ('comment') dürfen nur mit
	 * demselben Text neu verankert werden: ihre XPaths beziehen sich auf die
	 * Marken aller älteren Markierungen, eine Größenänderung verschiebt also
	 * die Anker neuerer im selben Absatz, und der Client schickt diese mit.
	 * Eine PDF-Markierung bleibt eine PDF-Markierung, eine HTML-Markierung
	 * eine HTML-Markierung.
	 */
	public function update(ApiRequest $request): ApiResult {
		$highlight = $this->highlights->findById((int) $request->int('id'), $request->userId());
		if ($highlight === null) {
			return ApiResult::error('Not found', 404);
		}
		$text = $request->string('highlightedText');
		if ($text === null) {
			return ApiResult::error('highlight_invalid', 400);
		}
		$color = $request->string('color');
		$pdfPage = $request->int('pdfPage');
		$startXpath = (string) $request->string('startXpath', '');
		$startOffset = (int) $request->int('startOffset', 0);
		$endXpath = (string) $request->string('endXpath', '');
		$endOffset = (int) $request->int('endOffset', 0);

		$ownColour = ($highlight->getAuthorType() ?? 'owner') === 'owner'
			&& $highlight->getColor() !== CommentRules::COLOR_COMMENT;
		if (!$ownColour && ($color !== null || $highlight->getPdfPage() !== null
			|| CommentRules::normalizeHighlightText($text) !== $highlight->getHighlightedText())) {
			return ApiResult::error('highlight_not_editable', 403);
		}
		if ($color !== null && !in_array($color, CommentRules::COLORS, true)) {
			return ApiResult::error('highlight_invalid', 400);
		}

		$rectsJson = null;
		if ($highlight->getPdfPage() !== null) {
			$rectsJson = CommentRules::normalizePdfRects($request->param('pdfRects'));
			if ($pdfPage === null || !CommentRules::isValidPdfPage($pdfPage) || $rectsJson === null) {
				return ApiResult::error('highlight_invalid', 400);
			}
			$startXpath = $endXpath = CommentRules::pdfXpath($pdfPage);
			$startOffset = $endOffset = 0;
		} elseif ($pdfPage !== null) {
			return ApiResult::error('highlight_invalid', 400);
		}

		$error = CommentRules::validateHighlight(
			$text, $startXpath, $startOffset, $endXpath, $endOffset, CommentRules::OWNER_HIGHLIGHT_TEXT_MAX);
		if ($error !== null) {
			return ApiResult::error($error, 400);
		}

		$highlight->setHighlightedText(CommentRules::normalizeHighlightText($text));
		$highlight->setStartXpath($startXpath);
		$highlight->setStartOffset($startOffset);
		$highlight->setEndXpath($endXpath);
		$highlight->setEndOffset($endOffset);
		if ($color !== null) {
			$highlight->setColor($color);
		}
		if ($rectsJson !== null) {
			$highlight->setPdfPage($pdfPage);
			$highlight->setPdfRects($rectsJson);
		}
		return ApiResult::json($this->comments->updateHighlight($highlight)->jsonSerialize());
	}

	/**
	 * DELETE /api/highlights/{id}: auch eine Gast-Markierung (der Besitzer
	 * moderiert alles an seinem Artikel). Threads daran bleiben mit ihrem
	 * Zitat (CommentService::deleteHighlight()).
	 */
	public function destroy(ApiRequest $request): ApiResult {
		$highlight = $this->highlights->findById((int) $request->int('id'), $request->userId());
		if ($highlight === null) {
			return ApiResult::error('Not found', 404);
		}
		$this->comments->deleteHighlight($highlight);
		return ApiResult::noContent();
	}
}
