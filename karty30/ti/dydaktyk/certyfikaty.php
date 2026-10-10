<?php
/**
 * karty30/ti/dydaktyk/certyfikaty.php — wystawianie certyfikatów prowadzącym (kierownik TI / admin).
 * Certyfikat .p12 idzie mailem, hasło SMS-em — patrz includes/ti_cert_issue.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/x509_login.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_cert_issue.php';

$me      = dyd_require();
$uid     = (int)$me['user_id'];
$me_name = (string)($me['name'] ?? '');
karty30_migrate();
x509_init();

$moze = is_admin() || ti_panel_role($uid) === 'kierownik';
if (!$moze) {
    http_response_code(403);
    die('Wystawianie certyfikatów jest dla kierownika TI.');
}

$wynik = null;  // wynik ostatniego wystawienia (hasło tylko gdy SMS się nie udał)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $target = (int)($_POST['target_id'] ?? 0);
    $ids    = array_map(fn($i) => (int)$i['id'], ti_cert_instructors());
    if (!$target || !in_array($target, $ids, true)) {
        flash_set('danger', 'Nie ma takiego prowadzącego.');
    } elseif (x509_user_has_active_cert($target) && ($_POST['_op'] ?? '') !== 'regen') {
        flash_set('danger', 'Ten prowadzący ma już aktywny certyfikat. Użyj „Wygeneruj ponownie”.');
    } else {
        // Ponowne wygenerowanie: stare certyfikaty są odwoływane, więc przestają działać przy podpisie.
        if (($_POST['_op'] ?? '') === 'regen') {
            foreach (x509_list_for_user($target) as $_c) {
                if (empty($_c['revoked_at']) && strtotime((string)$_c['valid_to']) > time()) {
                    x509_revoke((int)$_c['id']);
                }
            }
        }
        // Brak telefonu: pytamy o numer w wierszu i zapisujemy go w koncie przed wysyłką SMS.
        $tel_u    = db_one("SELECT phone_number FROM users WHERE id = ?", [$target]);
        $tel_ok   = true;
        if (preg_replace('/\D/', '', (string)($tel_u['phone_number'] ?? '')) === '') {
            $tel = trim((string)($_POST['phone'] ?? ''));
            if (strlen(preg_replace('/\D/', '', $tel)) < 9) {
                flash_set('danger', 'Podaj numer telefonu prowadzącego (min. 9 cyfr), żeby mógł dostać hasło SMS-em.');
                $tel_ok = false;
            } else {
                db_exec("UPDATE users SET phone_number = ? WHERE id = ?", [$tel, $target]);
            }
        }
        if ($tel_ok) {
            $wynik = ti_cert_issue_for_instructor($target, $me_name);
            if (!$wynik['ok']) {
                flash_set('danger', $wynik['error']);
                $wynik = null;
            } elseif ($wynik['sms']) {
                flash_set('success', 'Certyfikat wystawiony. Mail z plikiem i SMS z hasłem zostały wysłane.');
                $wynik = null;
            }
        }
    }
}

$prowadzacy = ti_cert_instructors();

include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<link rel="stylesheet" href="../assets/ti_skin.css">

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:820px">
<?= flash_html() ?>

<h1 class="h4 fw-bold mb-2"><i class="bi bi-shield-check text-primary me-2" aria-hidden="true"></i>Certyfikaty prowadzących</h1>
<p class="text-body-secondary small mb-3">
  Certyfikat służy prowadzącemu do podpisywania protokołów. Wystawienie wysyła plik .p12 mailem
  na adres prowadzącego, a hasło przychodzi SMS-em na jego telefon. Hasła nie zobaczysz w systemie.
</p>

<?php if ($wynik && !$wynik['sms']): ?>
<div class="alert alert-warning">
  <div class="fw-semibold"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> SMS z hasłem się nie wysłał</div>
  <p class="mb-1">Certyfikat jest wystawiony i wysłany mailem. Hasło przekaż prowadzącemu osobiście albo telefonicznie — pokażę je tylko raz:</p>
  <p class="mb-0"><code class="fs-5"><?= h($wynik['password']) ?></code></p>
</div>
<?php endif; ?>

<?php if (!$prowadzacy): ?>
<div class="alert alert-info">Nie ma prowadzących przypisanych do kursów.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead><tr><th scope="col">Prowadzący</th><th scope="col">Certyfikat</th><th scope="col" class="text-end">Akcja</th></tr></thead>
    <tbody>
    <?php foreach ($prowadzacy as $p):
      $ma = x509_user_has_active_cert((int)$p['id']);
      $braki = [];
      if (trim((string)$p['email']) === '') $braki[] = 'brak e-maila';
      if (preg_replace('/\D/', '', (string)$p['phone_number']) === '') $braki[] = 'brak telefonu';
    ?>
      <tr>
        <td><?= h($p['name']) ?><?php if ($braki): ?><div class="small text-danger"><?= h(implode(', ', $braki)) ?></div><?php endif; ?></td>
        <td>
          <?php if ($ma): ?><span class="badge bg-success">aktywny</span>
          <?php else: ?><span class="badge bg-secondary">brak</span><?php endif; ?>
        </td>
        <td class="text-end">
          <form method="post" class="d-inline" onsubmit="return confirm('<?= $ma ? 'Wygenerować nowy certyfikat? Obecny przestanie działać i prowadzący dostanie nowy plik i hasło.' : 'Wystawić certyfikat i wysłać go prowadzącemu?' ?>');">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="target_id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="_op" value="<?= $ma ? 'regen' : 'new' ?>">
            <?php if (in_array('brak telefonu', $braki, true)): ?>
            <input type="tel" name="phone" class="form-control form-control-sm d-inline-block mb-1" style="width:10rem"
                   placeholder="numer telefonu" aria-label="Numer telefonu prowadzącego" required>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm <?= $ma ? 'btn-outline-warning' : 'btn-primary' ?>" <?= in_array('brak e-maila', $braki, true) ? 'disabled' : '' ?>>
              <i class="bi bi-<?= $ma ? 'arrow-repeat' : 'send' ?> me-1" aria-hidden="true"></i><?= $ma ? 'Wygeneruj ponownie' : 'Wystaw i wyślij' ?>
            </button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
