<?php

declare(strict_types=1);

namespace Merlin\Standalone\Mail;

interface MailerInterface {
    public function send(string $toAddress, string $subject, string $bodyText): void;
}
