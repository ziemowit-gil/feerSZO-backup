<?php
/**
 * Rejestr zaświadczeń — stary (certificates.php) + nowy moduł EZD zaświadczeń własnych.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/certificates.php';
require_once dirname(dirname(__DIR__)) . '/includes/zaswiadczenia_ezd.php';
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

$tab          = $_GET['tab'] ?? 'wlasne';
$q_old        = trim($_GET['q'] ?? '');
$q_new        = trim($_GET['qn'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_typ    = (int)($_GET['typ_id'] ?? 0);

$rows_old     = ezd_zaswiadczenia_all($q_old);
$rows_new     = ezd_zas_all(array_filter(['status'=>$filter_status,'typ_id'=>$filter_typ,'q'=>$q_new]));
$typy_all     = ezd_zas_typy_all();
$jrwa         = ezd_cert_jrwa();
$cert_enabled = module_enabled('certificates_enabled');
$PAGE_TITLE   = 'Zaświadczenia EZD';

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Zaświadczenia</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-award text-primary me-2"></i>Zaświadczenia</h4>
  <div class="d-flex gap-2">
    <?php if(ezd_is_manager() || can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/zaswiadczenia/typy.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-sliders me-1"></i>Typy</a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/ezd/zaswiadczenia/new.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Złóż wniosek</a>
  </div>
</div>

<?= flash_html() ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="zasTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab==='wlasne'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-wlasne" type="button" role="tab">
      <i class="bi bi-award-fill me-1"></i>Zaświadczenia własne
      <?php if(count($rows_new)): ?><span class="badge bg-primary ms-1" style="font-size:.65rem"><?= count($rows_new) ?></span><?php endif; ?>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $tab==='stary'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-stary" type="button" role="tab">
      <i class="bi bi-archive me-1"></i>Stary rejestr
      <?php if(count($rows_old)): ?><span class="badge bg-secondary ms-1" style="font-size:.65rem"><?= count($rows_old) ?></span><?php endif; ?>
    </button>
  </li>
</ul>

<div class="tab-content" id="zasTabContent">

<!-- TAB: Zaświadczenia własne -->
<div class="tab-pane fade <?= $tab==='wlasne'?'show active':'' ?>" id="tab-wlasne" role="tabpanel">
  <form method="get" class="mb-3 d-flex gap-2 flex-wrap align-items-center">
    <input type="hidden" name="tab" value="wlasne">
    <div class="input-group input-group-sm" style="max-width:300px">
      <input type="text" name="qn" class="form-control" value="<?= h($q_new) ?>" placeholder="Szukaj: nazwa, numer…">
      <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </div>
    <select name="status" class="form-select form-select-sm" style="max-width:160px" onchange="this.form.submit()">
      <option value="">Wszystkie statusy</option>
      <?php foreach(EZD_ZAS_STATUSY as $sv=>$sl): ?><option value="<?= $sv ?>" <?= $filter_status===$sv?'selected':'' ?>><?= h($sl['label']) ?></option><?php endforeach; ?>
    </select>
    <?php if($typy_all): ?>
    <select name="typ_id" class="form-select form-select-sm" style="max-width:200px" onchange="this.form.submit()">
      <option value="">Wszystkie typy</option>
      <?php foreach($typy_all as $t): ?><option value="<?= $t['id'] ?>" <?= $filter_typ===$t['id']?'selected':'' ?>><?= h($t['nazwa']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if($q_new||$filter_status||$filter_typ): ?><a href="?tab=wlasne" class="btn btn-outline-secondary btn-sm">×&nbsp;Wyczyść</a><?php endif; ?>
  </form>

  <div class="card shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-award-fill me-1 text-primary"></i>Wnioski i zaświadczenia (<?= count($rows_new) ?>)</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem">
        <thead class="table-light">
          <tr>
            <th style="width:36px">Lp.</th>
            <th>Numer</th>
            <th>Typ</th>
            <th>Wnioskodawca</th>
            <th class="text-nowrap">Złożono</th>
            <th>Status</th>
            <th style="width:60px"></th>
          </tr>
        </thead>
        <tbody>
        <?php $lp=0; foreach($rows_new as $r): $lp++; ?>
          <tr>
            <td class="text-muted"><?= $lp ?></td>
            <td class="font-monospace fw-semibold"><?= $r['nr_zaswiadczenia'] ? h($r['nr_zaswiadczenia']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= h($r['typ_nazwa']) ?></td>
            <td><?= h($r['wnioskodawca_name'] ?: '—') ?></td>
            <td class="text-nowrap"><?= date_pl(substr($r['created_at'],0,10)) ?></td>
            <td><?= ezd_zas_status_badge($r['status']) ?></td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/ezd/zaswiadczenia/view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-1" title="Otwórz"><i class="bi bi-eye"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$rows_new): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="bi bi-award" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
            Brak zaświadczeń<?= ($q_new||$filter_status||$filter_typ)?' dla wybranych filtrów':'' ?>.
            <?php if(!$q_new && !$filter_status && !$filter_typ): ?>
            <br><a href="<?= APP_URL ?>/ezd/zaswiadczenia/new.php">Złóż pierwszy wniosek.</a>
            <?php endif; ?>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- TAB: Stary rejestr -->
<div class="tab-pane fade <?= $tab==='stary'?'show active':'' ?>" id="tab-stary" role="tabpanel">
  <?php if(!$cert_enabled): ?>
  <div class="alert alert-warning py-2 mb-3" style="font-size:.84rem"><i class="bi bi-exclamation-triangle me-1"></i>Moduł zaświadczeń jest wyłączony — nowe zaświadczenia nie będą się pojawiać. Rejestr pokazuje dane już zarejestrowane.</div>
  <?php endif; ?>
  <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <form method="get" class="d-flex gap-2">
      <input type="hidden" name="tab" value="stary">
      <div class="input-group input-group-sm" style="max-width:300px">
        <input type="text" name="q" class="form-control" value="<?= h($q_old) ?>" placeholder="Szukaj: numer, odbiorca…">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
        <?php if($q_old): ?><a href="?tab=stary" class="btn btn-outline-secondary">×</a><?php endif; ?>
      </div>
    </form>
    <?php if($cert_enabled): ?>
    <a href="<?= APP_URL ?>/admin/certificates.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-box-arrow-up-right me-1"></i>Moduł zaświadczeń</a>
    <?php endif; ?>
    <?php if(can_edit()): ?>
    <form method="post" onsubmit="return confirm('Zarejestrować w EZD wszystkie wydane zaświadczenia, które nie są jeszcze ujęte?')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="backfill">
      <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Zarejestruj zaległe</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span class="fw-semibold" style="font-size:.88rem"><i class="bi bi-award me-1 text-secondary"></i>Stary rejestr — JRWA <span class="font-monospace"><?= h($jrwa) ?></span> (<?= count($rows_old) ?>)</span>
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
            <th style="width:64px"></th>
          </tr>
        </thead>
        <tbody>
        <?php $lp=0; foreach($rows_old as $r): $lp++; ?>
          <tr>
            <td class="text-muted"><?= $lp ?></td>
            <td class="font-monospace fw-semibold"><?= h($r['cert_number'] ?: '—') ?></td>
            <td><?= h($r['requester_name'] ?: '—') ?></td>
            <td><?= h(mb_substr($r['cel'] ?: '—', 0, 45)) ?></td>
            <td class="text-nowrap"><?= $r['issued_at'] ? date_pl(substr($r['issued_at'],0,10)) : '—' ?></td>
            <td class="text-nowrap">
              <?php if($r['sprawa_id']): ?>
              <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $r['sprawa_id'] ?>" class="font-monospace text-decoration-none"><?= h($r['sygnatura'] ?: $r['znak_sprawy']) ?></a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?php if(function_exists('certificate_status_badge')): ?><?= certificate_status_badge($r['status']) ?><?php else: ?><span class="badge bg-light text-dark border"><?= h($r['status']) ?></span><?php endif; ?></td>
            <td class="text-end">
              <?php if($r['verify_code'] && function_exists('certificate_verify_url')): ?>
              <a href="<?= h(certificate_verify_url($r['verify_code'])) ?>" target="_blank" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Weryfikacja"><i class="bi bi-patch-check"></i></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$rows_old): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="bi bi-award" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
            Brak zarejestrowanych zaświadczeń<?= $q_old?' dla tego wyszukiwania':'' ?>.
            <?php if(can_edit() && !$q_old): ?><br>Wydaj zaświadczenie w module zaświadczeń lub kliknij „Zarejestruj zaległe".<?php endif; ?>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="text-muted mt-2" style="font-size:.74rem"><i class="bi bi-info-circle me-1"></i>Zaświadczenia trafiają automatycznie do sprawy ciągłej w teczce JRWA <?= h($jrwa) ?>. Symbol JRWA zmienisz w <a href="<?= APP_URL ?>/admin/ezd_settings.php">ustawieniach EZD</a>.</div>
</div>

</div><!-- tab-content -->

<script>
// Przywróć aktywną zakładkę po stronie klienta
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(btn => {
  btn.addEventListener('shown.bs.tab', e => {
    const id = e.target.dataset.bsTarget.replace('#tab-','');
    const url = new URL(window.location);
    url.searchParams.set('tab', id);
    history.replaceState(null,'',url);
  });
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
