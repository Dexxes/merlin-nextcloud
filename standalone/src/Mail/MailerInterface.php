<?php

declare(strict_types=1);

namespace Merlin\Standalone\Mail;

interface MailerInterface {
    /** @throws \RuntimeException wenn die Mail nicht rausgeht */
    public function send(string $toAddress, string $subject, string $bodyText): void;

    /** false: kein Mailversand eingerichtet (DisabledMailer). */
    public function isAvailable(): bool;
}
