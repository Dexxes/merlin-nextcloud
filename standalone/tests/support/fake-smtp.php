<?php

declare(strict_types=1);

/**
 * Minimaler SMTP-Server für test-mail.php: php fake-smtp.php <port> <log>
 * [<user> <password>]. Bietet AUTH PLAIN und LOGIN an, prüft die
 * Zugangsdaten (falsch: 535) und schreibt jede angenommene Mail als
 * JSON-Zeile (from, to, auth, data) ins Log. Läuft, bis er beendet wird.
 */

[, $port, $log] = $argv;
$user = $argv[3] ?? '';
$password = $argv[4] ?? '';
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($server === false) {
	fwrite(STDERR, $error);
	exit(1);
}

while ($conn = @stream_socket_accept($server, -1)) {
	$say = static fn (string $line) => fwrite($conn, $line . "\r\n");
	$read = static fn (): string => rtrim((string) fgets($conn), "\r\n");
	$say('220 fake ESMTP');
	$mail = ['from' => null, 'to' => [], 'auth' => null, 'data' => null];
	while (($line = fgets($conn)) !== false) {
		$line = rtrim($line, "\r\n");
		$verb = strtoupper(strtok($line, ' ') ?: '');
		if ($verb === 'EHLO') {
			$say('250-fake');
			$say('250-AUTH PLAIN LOGIN');
			$say('250 8BITMIME');
		} elseif ($verb === 'AUTH') {
			$parts = explode(' ', $line);
			if (strtoupper($parts[1] ?? '') === 'PLAIN') {
				[, $u, $p] = array_pad(explode("\0", (string) base64_decode($parts[2] ?? '')), 3, '');
				$mail['auth'] = 'PLAIN';
			} else {
				$say('334 VXNlcm5hbWU6');
				$u = (string) base64_decode($read());
				$say('334 UGFzc3dvcmQ6');
				$p = (string) base64_decode($read());
				$mail['auth'] = 'LOGIN';
			}
			$say($u === $user && $p === $password ? '235 ok' : '535 bad credentials');
		} elseif ($verb === 'MAIL') {
			$mail['from'] = $line;
			$say('250 ok');
		} elseif ($verb === 'RCPT') {
			$mail['to'][] = $line;
			$say('250 ok');
		} elseif ($verb === 'DATA') {
			$say('354 go');
			$data = '';
			while (($l = fgets($conn)) !== false && $l !== ".\r\n") {
				$data .= $l;
			}
			$mail['data'] = $data;
			file_put_contents($log, json_encode($mail) . "\n", FILE_APPEND);
			$say('250 queued');
		} elseif ($verb === 'QUIT') {
			$say('221 bye');
			break;
		} else {
			$say('500 unknown');
		}
	}
	fclose($conn);
}
