<?php

declare(strict_types=1);

/**
 * Mailversand des Standalone-Servers: SmtpMailer gegen einen Test-SMTP-Server
 * (support/fake-smtp.php, AUTH PLAIN/LOGIN, ohne TLS), MailerFactory, und
 * über HTTP: Registrierung mit Mail per SMTP, ohne Mailversand
 * ('transport' => 'none') gesperrte Registrierung und Hinweis bei
 * "Passwort vergessen".
 *
 * Aufruf: php standalone/tests/test-mail.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/support/TestServer.php';

use Merlin\Standalone\Mail\DisabledMailer;
use Merlin\Standalone\Mail\FileMailer;
use Merlin\Standalone\Mail\MailerFactory;
use Merlin\Standalone\Mail\PhpMailMailer;
use Merlin\Standalone\Mail\SmtpMailer;
use Merlin\Standalone\Tests\TestServer;

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
	if (!$ok) {
		$failures++;
	}
}

$scratch = sys_get_temp_dir() . '/merlin-mail-' . bin2hex(random_bytes(4));
mkdir($scratch, 0700, true);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$name = (string) stream_socket_get_name($probe, false);
$smtpPort = (int) substr($name, strrpos($name, ':') + 1);
fclose($probe);
$smtpLog = $scratch . '/smtp.log';
$smtp = proc_open([PHP_BINARY, __DIR__ . '/support/fake-smtp.php', (string) $smtpPort, $smtpLog, 'merlin', 'pa:ss wört'],
	[0 => ['pipe', 'r'], 1 => ['file', $scratch . '/smtp.out', 'a'], 2 => ['file', $scratch . '/smtp.out', 'a']], $pipes);
for ($i = 0; $i < 50 && ($s = @fsockopen('127.0.0.1', $smtpPort)) === false; $i++) {
	usleep(100_000);
}
if (isset($s) && $s !== false) {
	// Probeverbindung sauber beenden, sonst wartet der Server auf Befehle.
	fgets($s);
	fwrite($s, "QUIT\r\n");
	fgets($s);
	fclose($s);
}
$mails = static function () use ($smtpLog): array {
	clearstatcache();
	return is_file($smtpLog)
		? array_map(static fn (string $l): array => (array) json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($smtpLog)))))
		: [];
};
$smtpConfig = static fn (string $password): array => [
	'from_address' => 'merlin@example.org',
	'from_name' => 'Merlin Test',
	'transport' => 'smtp',
	'smtp' => ['host' => '127.0.0.1', 'port' => $smtpPort, 'encryption' => 'none', 'username' => 'merlin', 'password' => $password, 'timeout' => 5],
];

$servers = [];
try {
	echo "MailerFactory\n";
	check(MailerFactory::create([]) instanceof PhpMailMailer, 'ohne Angabe wie bisher mail()');
	check(MailerFactory::create(['transport' => 'none']) instanceof DisabledMailer && !MailerFactory::create(['transport' => 'none'])->isAvailable(), 'none: kein Versand');
	check(MailerFactory::create(['transport' => 'smtp', 'smtp' => ['host' => '']]) instanceof DisabledMailer, 'smtp ohne Host: kein Versand');
	check(MailerFactory::create(['transport' => 'none', 'file' => $scratch . '/x.log']) instanceof FileMailer, 'file hat Vorrang');
	file_put_contents($scratch . '/pw', "aus-datei\n");
	$fromFile = MailerFactory::create(['transport' => 'smtp', 'smtp' => ['host' => 'h', 'password_file' => $scratch . '/pw']]);
	check($fromFile instanceof SmtpMailer && (new ReflectionProperty(SmtpMailer::class, 'password'))->getValue($fromFile) === 'aus-datei', 'Passwort aus password_file');
	try {
		MailerFactory::create(['transport' => 'smtp', 'smtp' => ['host' => 'h', 'encryption' => 'ssl3']]);
		check(false, 'unbekannte Verschlüsselung wird abgelehnt');
	} catch (InvalidArgumentException) {
		check(true, 'unbekannte Verschlüsselung wird abgelehnt');
	}

	echo "SmtpMailer\n";
	$mailer = MailerFactory::create($smtpConfig('pa:ss wört'));
	$mailer->send('bea@example.org', 'Grüße', "Zeile 1\n.\nZeile 3");
	$mail = $mails()[0] ?? [];
	[$head, $body] = array_pad(explode("\r\n\r\n", (string) ($mail['data'] ?? ''), 2), 2, '');
	check(($mail['auth'] ?? null) === 'PLAIN' && ($mail['from'] ?? null) === 'MAIL FROM:<merlin@example.org>' && ($mail['to'] ?? null) === ['RCPT TO:<bea@example.org>'],
		'Umschlag und AUTH PLAIN', json_encode($mail));
	check(str_contains($head, 'Subject: =?UTF-8?B?' . base64_encode('Grüße') . '?=') && str_contains($head, 'From: =?UTF-8?B?' . base64_encode('Merlin Test') . '?= <merlin@example.org>'),
		'Header kodiert', $head);
	check(base64_decode(str_replace("\r\n", '', $body)) === "Zeile 1\n.\nZeile 3", 'Text unverändert (base64, Punktzeile)', $body);
	try {
		MailerFactory::create($smtpConfig('falsch'))->send('bea@example.org', 'x', 'y');
		check(false, 'falsches Passwort: Exception');
	} catch (RuntimeException $e) {
		check(str_contains($e->getMessage(), '535') && !str_contains($e->getMessage(), base64_encode("\0merlin\0falsch")), 'falsches Passwort: Exception ohne Zugangsdaten', $e->getMessage());
	}
	try {
		$mailer->send("bea@example.org>\r\nRCPT TO:<evil@example.org", 'x', 'y');
		check(false, 'Adresse mit Zeilenumbruch wird abgelehnt');
	} catch (InvalidArgumentException) {
		check(true, 'Adresse mit Zeilenumbruch wird abgelehnt');
	}
	try {
		(new SmtpMailer('127.0.0.1', 1, 'none', '', '', 'merlin@example.org', 'M', 2))->send('bea@example.org', 'x', 'y');
		check(false, 'keine Verbindung: Exception');
	} catch (RuntimeException $e) {
		check(str_contains($e->getMessage(), 'keine Verbindung'), 'keine Verbindung: Exception', $e->getMessage());
	}

	echo "Registrierung per SMTP\n";
	$server = $servers[] = new TestServer('merlin-mail', ['mail' => $smtpConfig('pa:ss wört')]);
	$server->cli('migrate');
	$server->cli('create-admin --username=admin --email=admin@example.org --password=geheim123');
	$server->start();
	$admin = $server->tokenFor('admin', 'geheim123');
	[$status, $settings] = $server->http('PUT', '/admin/settings', ['allowSelfRegistration' => true], $admin);
	check($status === 200 && ($settings['allowSelfRegistration'] ?? null) === true && ($settings['mailAvailable'] ?? null) === true, 'Registrierung freigeben', json_encode($settings));
	$before = count($mails());
	[$status, , $body] = $server->http('POST', '/register', null, [], ['username' => 'carl', 'email' => 'carl@example.org', 'password' => 'passwort1']);
	$mail = $mails()[$before] ?? [];
	[, $mbody] = array_pad(explode("\r\n\r\n", (string) ($mail['data'] ?? ''), 2), 2, '');
	check($status === 200 && str_contains($body, 'class="success"') && ($mail['to'] ?? null) === ['RCPT TO:<carl@example.org>']
		&& str_contains((string) base64_decode(str_replace("\r\n", '', $mbody)), '/register/confirm?token='), 'Bestätigungsmail per SMTP', json_encode($mail));

	echo "Ohne Mailversand\n";
	$off = $servers[] = new TestServer('merlin-nomail', ['mail' => ['transport' => 'none']]);
	$off->cli('migrate');
	$off->cli('create-admin --username=admin --email=admin@example.org --password=geheim123');
	$off->start();
	$admin = $off->tokenFor('admin', 'geheim123');
	[$status, $error] = $off->http('PUT', '/admin/settings', ['allowSelfRegistration' => true], $admin);
	check($status === 409 && ($error['error'] ?? null) === 'mail_unavailable', 'Freigabe ohne Mailversand: 409', json_encode($error));
	[$status, $settings] = $off->http('GET', '/admin/settings', null, $admin);
	check($status === 200 && ($settings['allowSelfRegistration'] ?? null) === false && ($settings['mailAvailable'] ?? null) === false, 'Einstellungen melden fehlenden Mailversand', json_encode($settings));
	// Auch eine früher gesetzte Freigabe wirkt ohne Mailversand nicht.
	$off->pdo()->exec("INSERT OR REPLACE INTO settings (name, value) VALUES ('allow_self_registration', '1')");
	[$status, , , $headers] = $off->http('GET', '/register');
	check($status === 303 && str_ends_with($headers['location'] ?? '', '/login'), 'Registrierung gesperrt');
	[$status, , , $headers] = $off->http('POST', '/register', null, [], ['username' => 'dora', 'email' => 'dora@example.org', 'password' => 'passwort1']);
	check($status === 303 && (int) $off->pdo()->query("SELECT COUNT(*) FROM users WHERE username = 'dora'")->fetchColumn() === 0, 'POST /register legt kein Konto an');
	[, , $body] = $off->http('GET', '/login');
	check(!str_contains($body, '/register'), 'Login-Seite ohne Registrierungs-Link');
	[$status, , $body] = $off->http('GET', '/password/forgot');
	check($status === 200 && !str_contains($body, '<form') && str_contains($body, 'Admin'), '"Passwort vergessen" verweist an den Admin');
	$jar = $off->dir . '/cookies-admin-page.txt';
	$off->http('POST', '/login', null, [], ['username' => 'admin', 'password' => 'geheim123'], $jar);
	[$status, , $body] = $off->http('GET', '/admin', null, [], null, $jar);
	check($status === 200 && str_contains($body, 'id="allow-registration" style="width:auto;display:inline;margin-right:0.5em;" disabled') && str_contains($body, 'MERLIN_SMTP_HOST'), 'Verwaltung: Schalter gesperrt mit Hinweis');
} finally {
	foreach ($servers as $s) {
		$s->stop();
	}
	proc_terminate($smtp);
	proc_close($smtp);
	foreach (glob($scratch . '/*') ?: [] as $file) {
		@unlink($file);
	}
	@rmdir($scratch);
}

echo $failures === 0 ? "\nalle Prüfungen ok\n" : "\n{$failures} Fehler\n";
exit($failures === 0 ? 0 : 1);
