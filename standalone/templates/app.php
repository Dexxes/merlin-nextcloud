<?php
/**
 * Hülle für die Vue-App (Controller\AppController): Reader oder öffentliche
 * Share-Ansicht. Ohne Build (npm run build:standalone) ein Hinweis statt
 * einer leeren Seite.
 *
 * @var \Merlin\Standalone\I18n\Translator $t
 * @var string $basePath
 * @var string $entry
 * @var list<string> $mountIds
 * @var string|null $pageTitle Einstellungsseite: Titel-Key, mit Navigationsleiste
 * @var bool $isAdmin
 * @var bool $pwa Reader: Manifest und Apple-Angaben für „Zum Startbildschirm“
 * @var bool $built
 * @var string $stateJson
 */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $e($t->locale()) ?>" data-locale="<?= $e($t->locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<title><?= $pageTitle === null ? 'Merlin' : $e($t->t($pageTitle)) . ' · Merlin' ?></title>
<link rel="icon" href="<?= $e($basePath . '/img/app.svg') ?>">
<?php if ($pwa): ?>
<link rel="manifest" href="<?= $e($basePath . '/manifest.webmanifest') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Merlin">
<meta name="theme-color" content="#0082c9">
<link rel="apple-touch-icon" href="<?= $e($basePath . '/img/apple-touch-icon.png') ?>">
<?php endif; ?>
<?php if ($built): ?>
<link rel="stylesheet" href="<?= $e($basePath . '/app/css/merlin-' . $entry . '.css') ?>">
<script type="application/json" id="merlin-state"><?= $stateJson ?></script>
<script type="module" src="<?= $e($basePath . '/app/js/merlin-' . $entry . '.mjs') ?>"></script>
<?php else: ?>
<link rel="stylesheet" href="<?= $e($basePath . '/css/merlin.css') ?>">
<?php endif; ?>
</head>
<body>
<?php if ($built): ?>
<?php /* Ziel der Tastatur-Sprunglinks von NcContent; in Nextcloud Teil der Seite. */ ?>
<div id="skip-actions"></div>
<?php if ($pageTitle === null): ?>
<?php foreach ($mountIds as $mountId): ?>
<div id="<?= $e($mountId) ?>"></div>
<?php endforeach; ?>
<?php else: ?>
<nav class="merlin-settings-nav">
<strong><?= $e($t->t($pageTitle)) ?></strong>
<a href="<?= $e($basePath . '/') ?>"><?= $e($t->t('account.navLibrary')) ?></a>
<a href="<?= $e($basePath . '/account') ?>"><?= $e($t->t('library.navMyAccount')) ?></a>
<?php if ($pageTitle !== 'app.personalSettings'): ?><a href="<?= $e($basePath . '/settings') ?>"><?= $e($t->t('app.personalSettings')) ?></a><?php endif; ?>
<?php if ($isAdmin && $pageTitle !== 'app.adminSettings'): ?><a href="<?= $e($basePath . '/settings/admin') ?>"><?= $e($t->t('app.adminSettings')) ?></a><?php endif; ?>
<a href="<?= $e($basePath . '/logout') ?>"><?= $e($t->t('library.navLogout')) ?></a>
</nav>
<main class="merlin-settings-main">
<?php foreach ($mountIds as $mountId): ?>
<div id="<?= $e($mountId) ?>" class="section"></div>
<?php endforeach; ?>
</main>
<?php endif; ?>
<?php else: ?>
<main class="narrow">
<h1>Merlin</h1>
<p><?= $e($t->t('app.notBuilt')) ?></p>
<p><code>cd frontend &amp;&amp; npm ci &amp;&amp; npm run build:standalone</code></p>
</main>
<?php endif; ?>
</body>
</html>
