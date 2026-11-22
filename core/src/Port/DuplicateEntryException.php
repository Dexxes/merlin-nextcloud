<?php

declare(strict_types=1);

namespace Merlin\Core\Port;

/**
 * Ein Store konnte nicht speichern, weil ein eindeutiger Index schon belegt
 * ist (z. B. zwei Gäste wählen gleichzeitig denselben Namen).
 */
final class DuplicateEntryException extends \RuntimeException {
}
