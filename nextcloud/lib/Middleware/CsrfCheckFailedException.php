<?php

declare(strict_types=1);

namespace OCA\Merlin\Middleware;

/**
 * Interne Markierungs-Exception: signalisiert der CsrfCookieAuthMiddleware, dass
 * ein Cookie-authentifizierter Web-Request ohne gültigen requesttoken abgelehnt
 * werden muss. Wird ausschließlich in afterException() der Middleware in eine
 * 412-JSON-Antwort übersetzt.
 */
class CsrfCheckFailedException extends \Exception {
}
