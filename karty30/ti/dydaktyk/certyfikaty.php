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

// Jednorazowo po uruchomieniu certyfikatów: kierownicy dostają info, że trzeba wygenerować plik certyfikatu.
if (org_setting('ti_cert_launch_notice') !== '1') {
    ti_panel_roles_migrate();
    $kier = db_all("SELECT u.id, u.name, u.email FROM k30_ti_panel_roles r JOIN users u ON u.id = r.user_id
                     WHERE r.role = 'kierownik' AND u.is_active = 1 AND TRIM(u.email) <> ''");
    foreach ($kier as $k) {
        $txt = "Dzień dobry, {$k['name']},\n\n"
             . "w systemie uruchomiono certyfikaty do podpisu protokołów TI. "
             . "Trzeba wygenerować certyfikat (plik .p12) dla prowadzących — "
             . "w panelu dydaktyka: Certyfikaty prowadzących → „Uruchom certyfikaty do podpisu”.\n\n"
             . "--\nTo jest wiadomość automatyczna, wysłana przez system. Prosimy na nią nie odpowiadać.";
        mail_queue_add((string)$k['email'], (string)$k['name'], 'Certyfikaty do podpisu protokołów — trzeba wygenerować plik',
            '<p>' . nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8')) . '</p>', $txt, 'ti_cert_launch', (int)$k['id']);
    }
    org_setting_set('ti_cert_launch_notice', '1');
}

$wynik = null;  // wynik ostatniego wystawienia (hasło tylko gdy SMS się nie udał)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    // Uruchomienie certyfikatów: prośba do wszystkich prowadzących bez aktywnego certyfikatu.
    if (($_POST['_op'] ?? '') === 'launch') {
        $wyslano = 0; $pominieto = 0;
        foreach (ti_cert_instructors() as $p) {
            if (x509_user_has_active_cert((int)$p['id']) || trim((string)$p['email']) === '') { $pominieto++; continue; }
            [$txt, $html] = ti_cert_request_mail((string)$p['name'], $me_name);
            mail_queue_add((string)$p['email'], (string)$p['name'],
                'Prośba o certyfikat do podpisywania protokołów TI — Fundacja FEER',
                $html, $txt, 'ti_cert_request', (int)$p['id']);
            $wyslano++;
        }
        flash_set('success', "Wysłano prośbę o certyfikat do {$wyslano} prowadzących." . ($pominieto ? " Pominięto {$pominieto} (mają certyfikat albo brak e-maila)." : ''));
        header('Location: certyfikaty.php'); exit;
    }

    // Odwołanie certyfikatu z listy zarządzania — tylko aktywnego (nie odwołanego).
    if (($_POST['_op'] ?? '') === 'revoke') {
        $cid = (int)($_POST['cert_id'] ?? 0);
        if ($cid && db_one("SELECT id FROM admin_x509_certs WHERE id = ? AND revoked_at IS NULL", [$cid])) {
            $_rv = db_one("SELECT c.numer, u.phone_number FROM admin_x509_certs c JOIN users u ON u.id = c.user_id WHERE c.id = ?", [$cid]);
            x509_revoke($cid);
            // Posiadacz dostaje SMS o odwołaniu.
            ti_cert_sms((string)($_rv['phone_number'] ?? ''), 'FEER: certyfikat ' . ($_rv['numer'] ?: '') . ' zostal odwolany i nie bedzie dzialac przy podpisie protokolow. Skontaktuj sie z kierownikiem.', 'ti_dydaktyka_notice');
            flash_set('success', 'Certyfikat odwołany — przestaje działać przy podpisie. Posiadacz dostał SMS.');
        } else {
            flash_set('danger', 'Nie ma takiego aktywnego certyfikatu.');
        }
        header('Location: certyfikaty.php'); exit;
    }
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

$KP_TITLE      = 'Certyfikaty prowadzących — Panel dydaktyka';
$KP_TOPBAR     = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
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

<form method="post" class="mb-3" onsubmit="return confirm('Wysłać prowadzącym bez certyfikatu prośbę o wygenerowanie?');">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op" value="launch">
  <button type="submit" class="btn btn-primary">
    <i class="bi bi-megaphone me-1" aria-hidden="true"></i>Uruchom certyfikaty do podpisu
  </button>
  <span class="small text-body-secondary ms-2">Wyśle prowadzącym bez certyfikatu mail z instrukcją i prośbą o wygenerowanie.</span>
</form>

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

<?php
// Zarządzanie: wszystkie certyfikaty wystawione w systemie, ze statusem i odwołaniem.
$wszystkie = db_all(
    "SELECT c.id, c.subject_cn, c.numer, c.issued_at, c.valid_to, c.revoked_at, u.name
       FROM admin_x509_certs c LEFT JOIN users u ON u.id = c.user_id
      ORDER BY c.issued_at DESC LIMIT 200"
);
?>
<div class="card border-0 shadow-sm mt-3">
  <div class="card-header fw-semibold">Zarządzanie certyfikatami (<?= count($wszystkie) ?>)</div>
  <?php if (!$wszystkie): ?>
  <div class="card-body small text-body-secondary">Nie wystawiono jeszcze żadnego certyfikatu.</div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead><tr><th scope="col">Numer</th><th scope="col">Użytkownik</th><th scope="col">Wystawiony</th><th scope="col">Ważny do</th><th scope="col">Status</th><th scope="col" class="text-end">Akcja</th></tr></thead>
    <tbody>
    <?php foreach ($wszystkie as $c):
      if (!empty($c['revoked_at'])) { $st = ['secondary', 'odwołany']; }
      elseif (strtotime((string)$c['valid_to']) <= time()) { $st = ['warning text-dark', 'wygasły']; }
      else { $st = ['success', 'aktywny']; }
    ?>
      <tr<?= !empty($c['revoked_at']) ? ' class="text-body-secondary"' : '' ?>>
        <td class="font-monospace small"><?= h($c['numer'] ?: '—') ?></td>
        <td><?= h($c['name'] ?: $c['subject_cn']) ?></td>
        <td class="small"><?= h(date('d.m.Y', strtotime((string)$c['issued_at']))) ?></td>
        <td class="small"><?= h(date('d.m.Y', strtotime((string)$c['valid_to']))) ?></td>
        <td><span class="badge bg-<?= $st[0] ?>"><?= h($st[1]) ?></span></td>
        <td class="text-end">
          <?php if ($st[1] === 'aktywny'): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Odwołać certyfikat? Przestanie działać przy podpisie protokołów.');">
            <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="revoke">
            <input type="hidden" name="cert_id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">Odwołaj</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
