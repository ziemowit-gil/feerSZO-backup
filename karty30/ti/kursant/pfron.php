<?php
/**
 * karty30/ti/kursant/pfron.php — Samodzielny portal PFRON dla beneficjenta.
 *
 * Beneficjent PFRON CZĘSTO NIE MA konta w systemie — logowanie odbywa się
 * wyłącznie przez 2FA: numer umowy PFRON + numer telefonu (z karty beneficjenta
 * powiązanego z umową). Brak loginu/hasła. Dane są odseparowane od reszty aplikacji,
 * dostęp tylko na czas sesji (TTL), z throttlingiem i logiem dostępu.
 *
 * Czyta istniejący model PFRON (k30_pfron_contracts + konsultacje w k30_schedules)
 * przez includes/pfron.php — bez modyfikacji danych.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/pfron.php';
require_once __DIR__ . '/auth.php'; // student_start() + student_token() (kontener sesji + CSRF)

karty30_migrate();
pfron_migrate();

if (!k30_pfron_enabled()) {
    http_response_code(404);
    die('Portal PFRON jest wyłączony w tej instalacji.');
}

student_start();

$err = ''; $info = '';

// Wylogowanie z portalu PFRON
if (isset($_GET['logout'])) { pfron_lock_all(); header('Location: pfron.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) {
        http_response_code(403); exit('Nieprawidłowy token sesji.');
    }
    $op = $_POST['_op'] ?? '';
    if ($op === 'pfron_auth') {
        if (pfron_is_locked()) {
            $err = 'Zbyt wiele prób. Spróbuj ponownie za kilka minut.';
        } else {
            $cid = pfron_authenticate((string)($_POST['contract_no'] ?? ''), (string)($_POST['phone'] ?? ''));
            if ($cid > 0) {
                pfron_unlock($cid); pfron_log($cid, 'ok');
                header('Location: pfron.php'); exit;
            }
            pfron_register_fail(); pfron_log(0, 'fail');
            $err = 'Nieprawidłowy numer umowy lub telefon.';
        }
    } elseif ($op === 'pfron_lock') {
        pfron_lock_all(); header('Location: pfron.php'); exit;
    }
}

$unlocked = pfron_unlocked_ids();
$org = defined('ORG_NAME') ? ORG_NAME : 'PFRON';
$KP_TITLE  = 'Portal PFRON';
$KP_TOPBAR = [
    'brand'  => $org . ' — portal PFRON',
    'icon'   => 'shield-lock',
    'user'   => $unlocked ? 'Dostęp odblokowany' : '',
    'logout' => $unlocked ? '?logout=1' : '',
];
$KP_BODY_CLASS = $unlocked ? '' : 'd-flex flex-column';
$tok = student_token();
include __DIR__ . '/_layout_head.php';
?>

<?php if (!$unlocked): ?>
<main id="main" class="container d-flex align-items-center justify-content-center flex-grow-1 py-4">
  <div class="card shadow-lg border-0 w-100" style="max-width:440px">
    <div class="card-body p-4">
      <h1 class="h5 fw-bold d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-shield-lock text-primary" aria-hidden="true"></i>Rozliczenia PFRON
      </h1>
      <p class="text-body-secondary small mb-3">
        Podgląd rozliczeń konsultacji w ramach Twojej umowy PFRON. Aby się zalogować,
        podaj <strong>numer umowy PFRON</strong> oraz <strong>numer telefonu</strong> podany w placówce.
        Konto w systemie nie jest wymagane.
      </p>

      <?php if ($err): ?><div class="alert alert-danger py-2" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= h($err) ?></div><?php endif; ?>
      <?php if (pfron_is_locked()): ?><div class="alert alert-warning py-2 small">Zbyt wiele prób — odczekaj kilka minut.</div><?php endif; ?>
      <?php $_pf_notice = trim((string)org_setting('pfron_notice')); if ($_pf_notice): ?>
      <div class="alert alert-info py-2 small mb-3" role="note">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= nl2br(h($_pf_notice)) ?>
      </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="_token" value="<?= h($tok) ?>">
        <input type="hidden" name="_op"    value="pfron_auth">
        <div class="mb-2">
          <label class="form-label" for="pf_contract">Numer umowy PFRON</label>
          <input type="text" class="form-control font-monospace" id="pf_contract" name="contract_no" required autofocus
                 placeholder="np. PFRON/2026/0001" autocomplete="off">
        </div>
        <div class="mb-3">
          <label class="form-label" for="pf_phone">Numer telefonu</label>
          <input type="tel" class="form-control" id="pf_phone" name="phone" inputmode="tel" autocomplete="tel" required
                 placeholder="np. 600 100 200">
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-semibold py-2" <?= pfron_is_locked() ? 'disabled' : '' ?>>
          <i class="bi bi-unlock me-1" aria-hidden="true"></i>Zaloguj i pokaż rozliczenia
        </button>
      </form>
      <p class="text-body-secondary mt-3 mb-0" style="font-size:.78rem">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Dostęp jest aktywny przez 30 minut. Dane są poufne — nie udostępniaj ich osobom trzecim.
      </p>
    </div>
  </div>
</main>

<?php else: ?>
<main id="main" class="container-xl px-3 py-4">
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <i class="bi bi-shield-check fs-3 text-success" aria-hidden="true"></i>
    <div>
      <h1 class="h5 fw-bold mb-0">Rozliczenia PFRON</h1>
      <p class="text-body-secondary small mb-0">Konsultacje rozliczane w ramach Twojej umowy</p>
    </div>
    <form method="post" class="ms-auto">
      <input type="hidden" name="_token" value="<?= h($tok) ?>">
      <input type="hidden" name="_op"    value="pfron_lock">
      <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Wyloguj</button>
    </form>
  </div>

  <?php $_pf_notice2 = trim((string)org_setting('pfron_notice')); if ($_pf_notice2): ?>
  <div class="alert alert-info py-2 small mb-3" role="note">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= nl2br(h($_pf_notice2)) ?>
  </div>
  <?php endif; ?>

  <?php foreach ($unlocked as $pf_cid):
    $pf_c = pfron_contract_get($pf_cid); if (!$pf_c) continue;
    $pf_cons = pfron_consultations($pf_cid);
    $pf_left = pfron_hours_left($pf_c);
    $pf_amt  = array_sum(array_map(fn($s) => (float)$s['amount_due'], $pf_cons));
  ?>
  <div class="card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <span class="fw-semibold"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Umowa PFRON <?= h($pf_c['contract_number']) ?></span>
      <span class="badge text-bg-<?= ($pf_c['status'] ?? '')==='active' ? 'success' : 'secondary' ?>"><?= h($pf_c['status'] ?? '') ?></span>
      <?php if (!empty($pf_c['valid_from']) || !empty($pf_c['valid_to'])): ?>
      <span class="text-body-secondary small"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i><?= h($pf_c['valid_from'] ?? '—') ?> – <?= h($pf_c['valid_to'] ?? '—') ?></span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-1">
        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center">
          <div class="fs-5 fw-bold lh-1"><?= number_format((float)$pf_c['hours_limit'],1,',','') ?> h</div>
          <div class="text-body-secondary small">Limit godzin</div>
        </div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center">
          <div class="fs-5 fw-bold lh-1"><?= number_format((float)$pf_c['hours_used'],1,',','') ?> h</div>
          <div class="text-body-secondary small">Wykorzystane</div>
        </div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center">
          <div class="fs-5 fw-bold lh-1 <?= $pf_left <= 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($pf_left,1,',','') ?> h</div>
          <div class="text-body-secondary small">Pozostało</div>
        </div></div>
        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center">
          <div class="fs-5 fw-bold lh-1"><?= number_format($pf_amt,2,',',' ') ?> zł</div>
          <div class="text-body-secondary small">Dopłaty (ponad limit)</div>
        </div></div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <caption class="visually-hidden">Konsultacje rozliczane z PFRON dla umowy <?= h($pf_c['contract_number']) ?></caption>
        <thead><tr><th scope="col">Data</th><th scope="col">Prowadzący</th><th scope="col">Godz.</th><th scope="col">Rozliczenie</th></tr></thead>
        <tbody>
          <?php if (!$pf_cons): ?>
          <tr><td colspan="4" class="text-center text-body-secondary py-4">Brak konsultacji rozliczanych z PFRON.</td></tr>
          <?php endif; ?>
          <?php foreach ($pf_cons as $s):
            $sd = $s['start_time'] ? new DateTime($s['start_time']) : null; ?>
          <tr>
            <td class="text-nowrap small"><?= $sd ? h($sd->format('d.m.Y H:i')) : '—' ?></td>
            <td class="small"><?= $s['consultant_name'] ? h($s['consultant_name']) : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="small text-nowrap"><?= number_format((float)$s['billed_hours'],2,',','') ?> h
              <?php if ((float)$s['charged_hours'] > 0): ?><span class="text-danger">(+<?= number_format((float)$s['charged_hours'],2,',','') ?> h płatne)</span><?php endif; ?>
            </td>
            <td class="small"><?= $s['pfron_status'] ? h($s['pfron_status']) : ((float)$s['amount_due']>0 ? number_format((float)$s['amount_due'],2,',',' ').' zł' : '<span class="text-success">w ramach PFRON</span>') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
  <p class="text-body-secondary small"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Dane PFRON są poufne i dostępne wyłącznie po weryfikacji. Dostęp wygasa po 30 minutach bezczynności.</p>
</main>
<?php endif; ?>

<?php include __DIR__ . '/_layout_foot.php'; ?>
