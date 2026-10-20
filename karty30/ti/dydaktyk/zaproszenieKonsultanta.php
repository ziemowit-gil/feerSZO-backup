<?php
/**
 * karty30/ti/dydaktyk/zaproszenieKonsultanta.php — publiczna strona zaproszenia konsultanta zewnętrznego
 * (modules/ti_consultant_access). Link z tokenem od kierownika → osoba sama ustawia hasło → konto panelu
 * tylko w trybie Konsultacje → logowanie e-mailem i hasłem na stronie logowania TI, przy pierwszym wejściu kod TOTP.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultant_access/logic/consultantAccess.php';
require_once dirname(__DIR__, 3) . '/modules/ti_login/logic/TiLogin.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$inv   = TiConsultantAccess::inviteFind($token);
$error = ''; $done = false;

if ($inv && $_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $r = TiConsultantAccess::inviteAccept($inv, (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
    if ($r['ok']) $done = true; else $error = (string)$r['error'];
}

$KP_TITLE = 'Zaproszenie do panelu konsultacji';
$KP_TOPBAR = ['brand' => 'Panel konsultacji', 'icon' => 'clipboard2-check', 'user' => '', 'logout' => '', 'no_enrich' => true];
$KP_BODY_CLASS = 'ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<main id="main" class="container py-4" style="max-width:560px">
  <h1 class="h4 fw-bold mb-3"><i class="bi bi-person-plus text-primary me-2" aria-hidden="true"></i>Konto konsultanta</h1>
  <?php if ($done): ?>
    <div class="alert alert-success" role="status">Konto gotowe. Zaloguj się adresem <strong><?= h($inv['email']) ?></strong> i ustawionym hasłem.
      Przy pierwszym wejściu skonfigurujesz kod z aplikacji uwierzytelniającej (np. Microsoft Authenticator).</div>
    <a class="btn btn-primary" href="<?= h(TiLogin::url()) ?>">Przejdź do logowania</a>
  <?php elseif (!$inv): ?>
    <div class="alert alert-warning" role="alert">Link zaproszenia jest nieważny — wygasł, został już użyty albo cofnięty.
      Poproś osobę, która Cię zaprosiła, o nowy link.</div>
    <a href="<?= h(TiLogin::url()) ?>">Strona logowania</a>
  <?php else: ?>
    <p>Dzień dobry, <strong><?= h($inv['name']) ?></strong>. Zostałeś(-aś) zaproszony(-a) do panelu konsultacji
      jako konsultant(ka). Ustaw hasło — loginem będzie adres <strong><?= h($inv['email']) ?></strong>.</p>
    <p class="small text-body-secondary">Dostęp obejmuje tylko konsultacje (karty, rezerwacje terminów, klienci).
      Link jest ważny do <?= h(date('d.m.Y H:i', strtotime((string)$inv['expires_at']))) ?>.</p>
    <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off" class="card border-0 shadow-sm"><div class="card-body">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="t" value="<?= h($token) ?>">
      <input type="email" class="visually-hidden" name="username" value="<?= h($inv['email']) ?>" autocomplete="username" tabindex="-1" aria-hidden="true">
      <label class="form-label" for="zk-p1">Hasło (min. <?= TiConsultantAccess::MIN_PASS ?> znaków)</label>
      <input class="form-control mb-2" type="password" id="zk-p1" name="password" required minlength="<?= TiConsultantAccess::MIN_PASS ?>" maxlength="72" autocomplete="new-password">
      <label class="form-label" for="zk-p2">Powtórz hasło</label>
      <input class="form-control mb-3" type="password" id="zk-p2" name="password2" required maxlength="72" autocomplete="new-password">
      <button class="btn btn-primary">Załóż konto</button>
    </div></form>
  <?php endif; ?>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
