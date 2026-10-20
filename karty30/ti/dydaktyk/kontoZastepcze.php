<?php
/**
 * karty30/ti/dydaktyk/kontoZastepcze.php — login zastępczy konsultanta (modules/ti_consultant_access).
 * Konsultant sam ustawia login „…-kz” i hasło, żeby wejść do Konsultacji bez Microsoft 365 (logowanie TI + kod TOTP).
 * Kierownik (sesja pełna, nie zastępcza) widzi wszystkie loginy zastępcze i może je wyłączyć.
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultant_access/logic/consultantAccess.php';
require_once dirname(__DIR__, 3) . '/modules/ti_login/logic/TiLogin.php';
TiConsultantAccess::migrate();
$isAlt = !empty($me['alt_konsultacje']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'save') {
        $err = TiConsultantAccess::altSave($uid, (string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
        flash_set($err === '' ? 'success' : 'danger', $err === '' ? 'Zapisano login zastępczy. Logowanie: ' . TiLogin::altUrl() . '.' : $err);
    } elseif ($op === 'disable_own') {
        TiConsultantAccess::altDisable($uid, $uid);
        flash_set('success', 'Login zastępczy wyłączony.');
    } elseif ($op === 'disable' && $staff && !$isAlt) {
        TiConsultantAccess::altDisable((int)($_POST['user_id'] ?? 0), $uid);
        flash_set('success', 'Login zastępczy wyłączony.');
    }
    header('Location: kontoZastepcze.php'); exit;
}

$alt = TiConsultantAccess::altFor($uid);
$suggest = $alt['login'] ?? TiConsultantAccess::suggestLogin((string)($me['email'] ?? ''));
$list = ($staff && !$isAlt) ? TiConsultantAccess::altList() : [];
$RZ_TITLE = 'Login zastępczy'; $RZ_CUR = 'kontoZastepcze.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:900px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-key text-primary me-2" aria-hidden="true"></i>Login zastępczy do Konsultacji</h1>
<?= flash_html() ?>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
  <p class="small mb-2">Gdy logowanie <strong>Microsoft 365</strong> nie działa albo pracujesz na komputerze bez konta organizacji,
    wejdź do panelu loginem zastępczym. Na <strong>osobnej stronie logowania konsultantów</strong> (<a href="<?= h(TiLogin::altUrl()) ?>"><?= h(TiLogin::altUrl()) ?></a>)
    wpisz login z końcówką <code>-kz</code> i hasło — bez kodu z aplikacji. Ustaw mocne hasło, bo to jedyne zabezpieczenie tego wejścia.</p>
  <ul class="small text-body-secondary mb-3">
    <li>Login zastępczy otwiera <strong>tylko tryb Konsultacje</strong> (bez Szkoleń i funkcji kierownika).</li>
    <li>Karty i wpisy zapisują się na Twoje konto, tak jak po zalogowaniu Microsoft 365.</li>
    <li>Każde logowanie loginem zastępczym jest zapisywane w dzienniku zdarzeń.</li>
  </ul>
  <?php if ($alt): ?>
  <p class="mb-2">Twój login zastępczy: <code class="fs-6"><?= h($alt['login']) ?></code>
    <?= $alt['is_active'] ? '<span class="badge text-bg-success ms-1">aktywny</span>' : '<span class="badge text-bg-secondary ms-1">wyłączony</span>' ?>
    <?php if ($alt['last_login_at']): ?><span class="small text-body-secondary ms-2">ostatnie użycie <?= h(date('d.m.Y H:i', strtotime((string)$alt['last_login_at']))) ?></span><?php endif; ?></p>
  <?php endif; ?>
  <form method="post" class="row g-2 align-items-end" autocomplete="off">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save">
    <div class="col-md-4"><label class="form-label small mb-0" for="kz-login">Login (kończy się na -kz)</label>
      <input class="form-control form-control-sm font-monospace" id="kz-login" name="login" required maxlength="43" value="<?= h($suggest) ?>" autocomplete="username"></div>
    <div class="col-md-3"><label class="form-label small mb-0" for="kz-p1">Hasło (min. <?= TiConsultantAccess::MIN_PASS ?> znaków)</label>
      <input class="form-control form-control-sm" type="password" id="kz-p1" name="password" minlength="<?= TiConsultantAccess::MIN_PASS ?>" maxlength="72" autocomplete="new-password"<?= $alt ? '' : ' required' ?>></div>
    <div class="col-md-3"><label class="form-label small mb-0" for="kz-p2">Powtórz hasło</label>
      <input class="form-control form-control-sm" type="password" id="kz-p2" name="password2" maxlength="72" autocomplete="new-password"<?= $alt ? '' : ' required' ?>></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><?= $alt ? 'Zapisz' : 'Ustaw login' ?></button></div>
    <?php if ($alt): ?><div class="col-12 form-text mt-0">Puste hasło = zmiana samego loginu (hasło bez zmian).</div><?php endif; ?>
  </form>
  <?php if ($alt && $alt['is_active']): ?>
  <form method="post" class="mt-2" onsubmit="return confirm('Wyłączyć login zastępczy? Wejdziesz wtedy tylko przez Microsoft 365.')">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="disable_own">
    <button class="btn btn-sm btn-link text-danger px-0">Wyłącz mój login zastępczy</button>
  </form>
  <?php endif; ?>
</div></div>

<?php if ($list): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h2 class="h6 fw-bold">Loginy zastępcze konsultantów</h2>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th scope="col">Osoba</th><th scope="col">Login</th><th scope="col">Stan</th><th scope="col">Ostatnie użycie</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
      <tr><td><?= h($r['name']) ?><div class="small text-body-secondary"><?= h($r['email']) ?></div></td>
        <td><code><?= h($r['login']) ?></code></td>
        <td><?= $r['is_active'] ? 'aktywny' : 'wyłączony' ?></td>
        <td class="small"><?= $r['last_login_at'] ? h(date('d.m.Y H:i', strtotime((string)$r['last_login_at']))) : '—' ?></td>
        <td class="text-end"><?php if ($r['is_active']): ?>
          <form method="post" onsubmit="return confirm('Wyłączyć login zastępczy: <?= h(addslashes((string)$r['name'])) ?>?')">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="disable"><input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0">Wyłącz</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div></div>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
