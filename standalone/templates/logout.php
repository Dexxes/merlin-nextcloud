<?php $title = $t->t('logout.pageTitle'); include __DIR__ . '/partials/header.php'; ?>
<h1><?= htmlspecialchars($t->t('logout.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
<form method="post" action="<?= url('/logout'); ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($logoutToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit"><?= htmlspecialchars($t->t('logout.submit'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
<?php include __DIR__ . '/partials/footer.php'; ?>
