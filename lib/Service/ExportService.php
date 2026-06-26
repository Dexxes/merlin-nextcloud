<?php

declare(strict_types=1);

namespace OCA\Merlin\Service;

use OCA\Merlin\Db\Article;

class ExportService {
	/**
	 * Export article as standalone HTML
	 */
	public function exportHtml(Article $article): string {
		$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{$this->escape($article->getTitle())}</title>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
			line-height: 1.6;
			max-width: 800px;
			margin: 0 auto;
			padding: 20px;
			color: #333;
		}
		h1 {
			font-size: 2em;
			margin-bottom: 0.5em;
			color: #000;
		}
		.metadata {
			color: #666;
			font-style: italic;
			margin-bottom: 2em;
			padding-bottom: 1em;
			border-bottom: 1px solid #eee;
		}
		.content {
			font-size: 1.1em;
		}
		.content img {
			max-width: 100%;
			height: auto;
		}
		.content a {
			color: #0082c9;
			text-decoration: none;
		}
		.content a:hover {
			text-decoration: underline;
		}
		@media (prefers-color-scheme: dark) {
			body {
				background-color: #1e1e1e;
				color: #e0e0e0;
			}
			h1 {
				color: #fff;
			}
			.metadata {
				color: #999;
				border-bottom-color: #333;
			}
		}
	</style>
</head>
<body>
	<article>
		<h1>{$this->escape($article->getTitle())}</h1>
		<div class="metadata">
HTML;

		$metadata = [];
		if ($article->getAuthor()) {
			$metadata[] = 'By ' . $this->escape($article->getAuthor());
		}
		if ($article->getSiteName()) {
			$metadata[] = $this->escape($article->getSiteName());
		}
		$metadata[] = $article->getCreatedAt()->format('F j, Y');

		$html .= implode(' • ', $metadata);

		$html .= <<<HTML
		</div>
		<div class="content">
			{$article->getContent()}
		</div>
	</article>
	<footer style="margin-top: 3em; padding-top: 1em; border-top: 1px solid #eee; color: #999; font-size: 0.9em;">
		<p>Saved from: <a href="{$this->escape($article->getUrl())}">{$this->escape($article->getUrl())}</a></p>
		<p>Exported from Nextcloud Reader on {$this->escape(date('F j, Y'))}</p>
	</footer>
</body>
</html>
HTML;

		return $html;
	}

	/**
	 * Escape HTML entities
	 */
	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}
