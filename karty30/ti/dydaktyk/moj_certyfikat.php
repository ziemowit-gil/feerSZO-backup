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

<p class="small text-body-secondary mt-3">
  Przy zatwierdzaniu protokołu wgrywasz plik .p12 i wpisujesz hasło z SMS-a. Hasło nie jest zapisywane w systemie.
</p>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
