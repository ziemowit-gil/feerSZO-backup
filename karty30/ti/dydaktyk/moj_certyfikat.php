<?php
/**
 * karty30/ti/dydaktyk/moj_certyfikat.php — prowadzący sam wystawia sobie certyfikat do podpisu protokołów.
 * Plik .p12 przychodzi mailem, hasło SMS-em (includes/ti_cert_issue.php). Kierownik może to zrobić
 * też za prowadzącego — ta strona dotyczy tylko własnego konta.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/x509_login.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_cert_issue.php';

$me      = dyd_require();
$uid     = (int)$me['user_id'];
$me_name = (string)($me['name'] ?? '');
karty30_migrate();
x509_init();

$ma   = x509_user_has_active_cert($uid);
$konto = db_one("SELECT email, phone_number FROM users WHERE id = ?", [$uid]);
$brak_tel   = preg_replace('/\D/', '', (string)($konto['phone_number'] ?? '')) === '';
$brak_email = trim((string)($konto['email'] ?? '')) === '';

// Ustawienie własnego hasła: nowy certyfikat z tym hasłem, stare certyfikaty odwołane.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'pwd') {
    dyd_token_check();
    $p1 = (string)($_POST['new_password'] ?? '');
    $p2 = (string)($_POST['new_password2'] ?? '');
    if ($p1 !== $p2) {
        flash_set('danger', 'Hasła nie są takie same.');
    } else {
        $r = ti_cert_set_own_password($uid, $p1);
        if ($r['ok']) flash_set('success', 'Ustawiono Twoje hasło. Stare certyfikaty są odwołane, nowy plik przyszedł na e-mail.');
        else flash_set('danger', $r['error']);
    }
    header('Location: moj_certyfikat.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $tel = trim((string)($_POST['phone'] ?? ''));
    if ($brak_tel && strlen(preg_replace('/\D/', '', $tel)) < 9) {
        flash_set('danger', 'Podaj numer telefonu (min. 9 cyfr), na który przyjdzie hasło SMS-em.');
    } elseif ($brak_email) {
        flash_set('danger', 'Brak adresu e-mail w koncie — zgłoś to kierownikowi.');
    } else {
        if ($brak_tel) db_exec("UPDATE users SET phone_number = ? WHERE id = ?", [$tel, $uid]);
        if ($ma) {
            // Nowy certyfikat zastępuje stary — stary zostaje odwołany.
            foreach (x509_list_for_user($uid) as $c) {
                if (empty($c['revoked_at']) && strtotime((string)$c['valid_to']) > time()) x509_revoke((int)$c['id']);
            }
        }
        $wynik = ti_cert_issue_for_instructor($uid, $me_name);
        if (!$wynik['ok']) {
            flash_set('danger', $wynik['error']);
        } elseif ($wynik['sms']) {
            flash_set('success', 'Certyfikat wygenerowany. Plik przyszedł na e-mail, hasło SMS-em.');
        } else {
            // SMS się nie udał — hasło pokazujemy tylko raz, na tym ekranie.
            flash_set('warning', 'Certyfikat wygenerowany i wysłany mailem, ale SMS z hasłem się nie wysłał. Hasło: ' . $wynik['password'] . ' — zapisz je teraz, drugi raz nie zostanie pokazane.');
        }
    }
    header('Location: moj_certyfikat.php'); exit;
}

$KP_TITLE      = 'Mój certyfikat — Panel dydaktyka';
$KP_TOPBAR     = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<link rel="stylesheet" href="../assets/ti_skin.css">

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:720px">
<?= flash_html() ?>

<h1 class="h4 fw-bold mb-2"><i class="bi bi-shield-check text-primary me-2" aria-hidden="true"></i>Mój certyfikat</h1>
<p class="text-body-secondary small mb-3">
  Certyfikat podpisuje Twoje protokoły miesięczne. Możesz go wygenerować samodzielnie: plik .p12 przyjdzie
  na Twój e-mail, a hasło SMS-em na telefon z konta.
</p>

<div class="card border-0 shadow-sm">
  <div class="card-body">
    <p class="mb-2">Status: <?= $ma ? '<span class="badge bg-success">aktywny</span>' : '<span class="badge bg-secondary">brak</span>' ?></p>

    <?php if ($brak_email): ?>
    <div class="alert alert-warning small py-2">Konto nie ma adresu e-mail. Poproś kierownika o jego uzupełnienie.</div>
    <?php else: ?>
    <p class="small mb-2">
      System wygeneruje certyfikat z losowym hasłem. <strong>Plik przyjdzie mailem, hasło SMS-em.</strong>
      <?= $ma ? 'Obecny certyfikat przestanie wtedy działać.' : '' ?>
    </p>
    <form method="post" onsubmit="return confirm('<?= $ma ? 'Wygenerować nowy certyfikat? Obecny przestanie działać.' : 'Wygenerować certyfikat?' ?>');">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <?php if ($brak_tel): ?>
      <label class="form-label small mb-1" for="myPhone">Numer telefonu (na niego przyjdzie hasło)</label>
      <input type="tel" class="form-control form-control-sm mb-2" id="myPhone" name="phone" style="max-width:14rem" required>
      <?php endif; ?>
      <button type="submit" class="btn <?= $ma ? 'btn-outline-warning' : 'btn-primary' ?>">
        <i class="bi bi-<?= $ma ? 'arrow-repeat' : 'key' ?> me-1" aria-hidden="true"></i><?= $ma ? 'Wygeneruj nowy certyfikat' : 'Wygeneruj certyfikat' ?>
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card border-0 shadow-sm mt-3">
  <div class="card-body">
    <h2 class="h6 fw-semibold mb-2">Hasło do pliku certyfikatu</h2>
    <ul class="small mb-3 ps-3">
      <li><strong>Hasło losowe</strong> — wygenerowane przez system, przychodzi SMS-em (przycisk powyżej).</li>
      <li><strong>Własne hasło</strong> — ustawiasz je sam w okienku. Plik z nowym hasłem przychodzi mailem.</li>
    </ul>
    <?php if (!$brak_email): ?>
    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#ustawHaslo">
      <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Ustaw własne hasło
    </button>
    <?php endif; ?>
  </div>
</div>

<?php if (!$brak_email): ?>
<div class="modal fade" id="ustawHaslo" tabindex="-1" aria-labelledby="ustawHasloLbl" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" onsubmit="return confirm('Ustawić hasło? Stare certyfikaty zostaną odwołane.');">
        <div class="modal-header">
          <h2 class="modal-title h5" id="ustawHasloLbl">Ustaw własne hasło</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="pwd">
          <p class="small text-body-secondary mb-3">
            Nowy certyfikat z tym hasłem przyjdzie mailem. <strong>Stare certyfikaty przestaną działać.</strong>
            Hasła nie wysyłamy nigdzie — zapamiętaj je.
          </p>
          <div class="mb-2">
            <label class="form-label small mb-1" for="newPwd">Nowe hasło (min. 8 znaków)</label>
            <input type="password" class="form-control" id="newPwd" name="new_password" minlength="8" autocomplete="new-password" required>
          </div>
          <div>
            <label class="form-label small mb-1" for="newPwd2">Powtórz hasło</label>
            <input type="password" class="form-control" id="newPwd2" name="new_password2" minlength="8" autocomplete="new-password" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">Ustaw hasło i wyślij plik</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<p class="small text-body-secondary mt-3">
  Przy zatwierdzaniu protokołu wgrywasz plik .p12 i wpisujesz hasło z SMS-a. Hasło nie jest zapisywane w systemie.
</p>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
