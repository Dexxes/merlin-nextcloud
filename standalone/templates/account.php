<?php $title = $t->t('account.pageTitle'); include __DIR__ . '/partials/header.php'; ?>
<h1><?= htmlspecialchars($t->t('account.heading', ['username' => $username ?? '']), ENT_QUOTES, 'UTF-8') ?></h1>
<p class="muted"><a href="<?= url('/'); ?>"><?= htmlspecialchars($t->t('account.navLibrary'), ENT_QUOTES, 'UTF-8') ?></a> · <a href="<?= url('/logout'); ?>"><?= htmlspecialchars($t->t('account.navLogout'), ENT_QUOTES, 'UTF-8') ?></a><?php if (!empty($isAdmin)): ?> · <a href="<?= url('/admin'); ?>"><?= htmlspecialchars($t->t('account.navAdmin'), ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?></p>

<h2 style="font-size:1.1em;margin-top:2em;"><?= htmlspecialchars($t->t('account.apiTokensHeading'), ENT_QUOTES, 'UTF-8') ?></h2>
<p class="muted"><?= htmlspecialchars($t->t('account.apiTokensHint'), ENT_QUOTES, 'UTF-8') ?></p>
<table id="tokens"><tbody></tbody></table>

<form id="create-token" style="margin-top:1.5em;">
    <label for="token-name"><?= htmlspecialchars($t->t('account.deviceNameLabel'), ENT_QUOTES, 'UTF-8') ?></label>
    <input type="text" id="token-name" name="name" placeholder="<?= htmlspecialchars($t->t('account.deviceNamePlaceholder'), ENT_QUOTES, 'UTF-8') ?>" required>
    <button type="submit"><?= htmlspecialchars($t->t('account.createTokenSubmit'), ENT_QUOTES, 'UTF-8') ?></button>
</form>
<p id="new-token" class="success" style="display:none;"></p>

<script>
const I18N = <?= json_encode($t->forJs([
    'account.neverUsed',
    'account.revoke',
    'account.newTokenPrefix',
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

async function loadTokens() {
    const res = await fetch(basePath + '/account/tokens', { credentials: 'same-origin' });
    const tokens = await res.json();
    const body = document.querySelector('#tokens tbody');
    body.innerHTML = '';
    for (const t of tokens) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${esc(t.name)}</td><td class="muted">${esc(t.lastUsedAt ?? I18N['account.neverUsed'])}</td><td class="row-actions"><button data-id="${esc(t.id)}" class="revoke">${esc(I18N['account.revoke'])}</button></td>`;
        body.appendChild(tr);
    }
    body.querySelectorAll('.revoke').forEach(btn => btn.addEventListener('click', async () => {
        await fetch(basePath + '/account/tokens/' + btn.dataset.id, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        loadTokens();
    }));
}

document.getElementById('create-token').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = document.getElementById('token-name').value;
    const res = await fetch(basePath + '/account/tokens', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ name }),
    });
    const data = await res.json();
    const el = document.getElementById('new-token');
    el.style.display = 'block';
    el.textContent = I18N['account.newTokenPrefix'].replace('{token}', data.plainText);
    document.getElementById('token-name').value = '';
    loadTokens();
});

loadTokens();

</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
