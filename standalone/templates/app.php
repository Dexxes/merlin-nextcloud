<?php
/**
 * Hülle für die Vue-App (Controller\AppController): Reader oder öffentliche
 * Share-Ansicht. Ohne Build (npm run build:standalone) ein Hinweis statt
 * einer leeren Seite.
 *
 * @var \Merlin\Standalone\I18n\Translator $t
 * @var string $basePath
 * @var string $entry
 * @var string $mountId
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
<title>Merlin</title>
<link rel="icon" href="<?= $e($basePath . '/img/app.svg') ?>">
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
<div id="<?= $e($mountId) ?>"></div>
<?php else: ?>
<main class="narrow">
<h1>Merlin</h1>
<p><?= $e($t->t('app.notBuilt')) ?></p>
<p><code>cd frontend &amp;&amp; npm ci &amp;&amp; npm run build:standalone</code></p>
</main>
<?php endif; ?>
</body>
</html>
