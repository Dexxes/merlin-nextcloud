<?php

declare(strict_types=1);

namespace Merlin\Standalone\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3-Logger in eine Datei, eine Zeile je Eintrag. Übernommen aus
 * merlin-standalone-server (src/Logging/FileLogger.php, fe8ed23), dazu eine
 * Mindeststufe (Standard: info, also kein debug).
 */
final class FileLogger extends AbstractLogger {
	private const LEVELS = [
		LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
		LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
	];

	public function __construct(private string $logFile, private string $minLevel = LogLevel::INFO) {
	}

	public function log($level, string|\Stringable $message, array $context = []): void {
		if ((self::LEVELS[(string) $level] ?? 7) < (self::LEVELS[$this->minLevel] ?? 1)) {
			return;
		}
		foreach ($context as $key => $value) {
			if ($value instanceof \Throwable) {
				$context[$key] = get_class($value) . ': ' . $value->getMessage() . ' @ ' . $value->getFile() . ':' . $value->getLine();
			}
		}
		// Platzhalter {name} wie in PSR-3 (Nextclouds Logger ersetzt sie auch).
		$message = preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', static function (array $m) use ($context): string {
			$value = $context[$m[1]] ?? null;
			return is_scalar($value) || $value instanceof \Stringable ? (string) $value : $m[0];
		}, (string) $message) ?? (string) $message;
		$line = sprintf(
			'[%s] %s: %s%s' . PHP_EOL,
			gmdate('c'),
			strtoupper((string) $level),
			(string) $message,
			$context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
		);
		@file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
	}
}
