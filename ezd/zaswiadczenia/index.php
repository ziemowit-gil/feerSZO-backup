<?php
/**
 * Rejestr zaświadczeń — zaświadczenia z modułu zaświadczeń zarejestrowane w EZD
 * pod hasłem JRWA zaświadczeń (domyślnie 53), jako pisma wychodzące.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/certificates.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$user_id = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_edit()) { http_response_code(403); exit; }
    if (($_POST['_action'] ?? '') === 'backfill') {
        $n = ezd_zaswiadczenia_backfill($user_id);
        flash_set('success', $n ? "Zarejestrowano w EZD zaległych zaświadczeń: $n." : 'Brak zaległych zaświadczeń do rejestracji.');
    }
    header('Location:'.APP_URL.'/ezd/zaswiadczenia/index.php'); exit;
}

$q    = trim($_GET['q'] ?? '');
$rows = ezd_zaswiadczenia_all($q);
$jrwa = ezd_cert_jrwa();
$cert_enabled = module_enabled('certificates_enabled');
$PAGE_TITLE = 'Rejestr zaświadczeń';

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Rejestr zaświadczeń</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-award text-primary me-2"></i>Rejestr zaświadczeń</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Zaświadczenia z modułu zaświadczeń rejestrowane w JRWA <span class="font-monospace fw-semibold"><?= h($jrwa) ?></span> jako pisma wychodzące</div>
  </div>
  <div class="d-flex gap-2">
    <?php if($cert_enabled): ?>
    <a href="<?= APP_URL ?>/admin/certificates.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Moduł zaświadczeń</a>
    <?php endif; ?>
    <?php if(can_edit()): ?>
    <form method="post" onsubmit="return confirm('Zarejestrować w EZD wszystkie wydane zaświadczenia, które nie są jeszcze ujęte?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="backfill">
      <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Zarejestruj zaległe</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<?php if(!$cert_enabled): ?>
<div class="alert alert-warning py-2" style="font-size:.84rem"><i class="bi bi-exclamation-triangle me-1"></i>Moduł zaświadczeń jest wyłączony — nowe zaświadczenia nie będą się pojawiać. Rejestr pokazuje dane już zarejestrowane.</div>
<?php endif; ?>

<form method="get" class="mb-3" style="max-width:420px">
  <div class="input-group input-group-sm">
    <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Szukaj: numer, odbiorca…">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <?php if($q): ?><a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php" class="btn btn-outline-secondary">×</a><?php endif; ?>
  </div>
</form>

<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-award me-1 text-primary"></i>Zarejestrowane zaświadczenia (<?= count($rows) ?>)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem">
      <thead class="table-light">
        <tr>
          <th style="width:40px">Lp.</th>
          <th>Numer</th>
          <th>Odbiorca</th>
          <th>Cel</th>
          <th class="text-nowrap">Wydano</th>
          <th>Znak EZD</th>
          <th>Status</th>
          <th style="width:80px"></th>
        </tr>
      </thead>
      <tbody>
      <?php $lp=0; foreach($rows as $r): $lp++; ?>
        <tr>
          <td class="text-muted"><?= $lp ?></td>
          <td class="font-monospace fw-semibold"><?= h($r['cert_number'] ?: '—') ?></td>
          <td><?= h($r['requester_name'] ?: '—') ?></td>
          <td><?= h(mb_substr($r['cel'] ?: '—', 0, 45)) ?></td>
          <td class="text-nowrap"><?= $r['issued_at'] ? date_pl(substr($r['issued_at'],0,10)) : '—' ?></td>
          <td class="text-nowrap">
            <?php if($r['sprawa_id']): ?>
            <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $r['sprawa_id'] ?>" class="font-monospace text-decoration-none" title="<?= h($r['znak_sprawy']) ?>"><?= h($r['sygnatura'] ?: $r['znak_sprawy']) ?></a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?php if(function_exists('certificate_status_badge')): ?><?= certificate_status_badge($r['status']) ?><?php else: ?><span class="badge bg-light text-dark border"><?= h($r['status']) ?></span><?php endif; ?></td>
          <td class="text-end">
            <?php if($r['verify_code'] && function_exists('certificate_verify_url')): ?>
            <a href="<?= h(certificate_verify_url($r['verify_code'])) ?>" target="_blank" class="btn btn-xs btn-outline-secondary btn-sm" title="Weryfikacja"><i class="bi bi-patch-check"></i></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-5">
          <i class="bi bi-award" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
          Brak zarejestrowanych zaświadczeń<?= $q?' dla tego wyszukiwania':'' ?>.
          <?php if(can_edit() && !$q): ?><br>Wydaj zaświadczenie w module zaświadczeń lub kliknij „Zarejestruj zaległe".<?php endif; ?>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="text-muted mt-2" style="font-size:.74rem"><i class="bi bi-info-circle me-1"></i>Każde wydane zaświadczenie trafia automatycznie do sprawy ciągłej „Rejestr zaświadczeń {rok}" w teczce JRWA <?= h($jrwa) ?>. Symbol JRWA zmienisz w <a href="<?= APP_URL ?>/admin/ezd_settings.php">ustawieniach EZD</a>.</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
