<?php
/**
 * dostepnosc/admin.php — Hub modułu „Dostępność NGO".
 *
 * Przegląd obu rejestrów: zgłoszeń asysty i kart doradztwa.
 * Linki do paneli szczegółowych i formularzy publicznych.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/assistance.php';
require_once dirname(__DIR__) . '/includes/consultations.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('dostepnosc_ngo_enabled', 'Moduł Dostępność NGO');

asr_migrate();
cc_migrate();

/* ── Statystyki zgłoszeń asysty ───────────────────────────────────────────── */
$asr_all = asr_list();
$asr = ['total' => count($asr_all), 'open' => 0, 'done' => 0, 'unassigned' => 0];
foreach ($asr_all as $r) {
    $terminal = ['done', 'rejected', 'rejected_external', 'volunteer_rejected'];
    if ($r['status'] === 'done') $asr['done']++;
    elseif (!in_array($r['status'], $terminal, true)) $asr['open']++;
    if (empty($r['assigned_volunteer_id']) && empty($r['assigned_user_id'])
        && !in_array($r['status'], $terminal, true)) {
        $asr['unassigned']++;
    }
}

/* ── Statystyki kart doradztwa ────────────────────────────────────────────── */
$cc_all    = cc_list();
$cc_total  = count($cc_all);
$cc_months = [];
foreach ($cc_all as $c) {
    $ym = substr((string)$c['date_of_consultation'], 0, 7);
    if ($ym) $cc_months[$ym] = ($cc_months[$ym] ?? 0) + 1;
}
krsort($cc_months);
$cc_this_month = $cc_months[date('Y-m')] ?? 0;

/* ── Ostatnie zgłoszenia asysty (5) ───────────────────────────────────────── */
$asr_recent = array_slice($asr_all, 0, 5);

/* ── Ostatnie karty doradztwa (5) ─────────────────────────────────────────── */
$cc_recent = array_slice($cc_all, 0, 5);

$PAGE_TITLE = 'Dostępność NGO';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-3">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-universal-access text-primary me-1" aria-hidden="true"></i>Dostępność NGO
    </h1>
  </div>

  <!-- ════ Sekcja: Zgłoszenia asysty ════ -->
  <h2 class="h6 fw-bold text-secondary text-uppercase mb-2">
    <i class="bi bi-universal-access-circle me-1" aria-hidden="true"></i>Zgłoszenia asysty i specjalnych potrzeb
  </h2>
  <div class="row g-3 mb-2">
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Wszystkich</div>
        <div class="h3 fw-bold mb-0"><?= $asr['total'] ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">W toku</div>
        <div class="h3 fw-bold mb-0 text-primary"><?= $asr['open'] ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Bez przypisania</div>
        <div class="h3 fw-bold mb-0 <?= $asr['unassigned'] ? 'text-warning' : 'text-secondary' ?>"><?= $asr['unassigned'] ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Zrealizowanych</div>
        <div class="h3 fw-bold mb-0 text-secondary"><?= $asr['done'] ?></div>
      </div></div>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 mb-4">
    <a href="<?= APP_URL ?>/asysta/admin.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Lista zgłoszeń asysty
    </a>
    <a href="<?= APP_URL ?>/extforms/asystaFEER/" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Formularz: asysta na wydarzeniu
    </a>
    <a href="<?= APP_URL ?>/extforms/wolontariuszPotrzeby/" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Formularz: potrzeby wolontariusza
    </a>
  </div>

  <!-- ════ Sekcja: Karty doradztwa ════ -->
  <h2 class="h6 fw-bold text-secondary text-uppercase mb-2">
    <i class="bi bi-clipboard2-pulse me-1" aria-hidden="true"></i>Karty doradztwa ADNGO
  </h2>
  <div class="row g-3 mb-2">
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Wszystkich</div>
        <div class="h3 fw-bold mb-0"><?= $cc_total ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">W tym miesiącu</div>
        <div class="h3 fw-bold mb-0 text-primary"><?= $cc_this_month ?></div>
      </div></div>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2 mb-4">
    <a href="<?= APP_URL ?>/extforms/konsultacjeADNGO/admin.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Lista kart doradztwa
    </a>
    <a href="<?= APP_URL ?>/extforms/konsultacjeADNGO/" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Formularz publiczny
    </a>
  </div>

  <!-- ════ Ostatnie zgłoszenia asysty ════ -->
  <?php if ($asr_recent): ?>
  <h2 class="h6 fw-bold text-secondary text-uppercase mb-2">Ostatnie zgłoszenia asysty</h2>
  <div class="card border-0 shadow-sm mb-4">
    <div class="table-responsive">
      <table class="table table-hover table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th scope="col">Nr</th>
            <th scope="col">Data</th>
            <th scope="col">Zgłaszający</th>
            <th scope="col">Wydarzenie</th>
            <th scope="col">Status</th>
            <th scope="col"></th>
          </tr>
        </thead>
        <tbody>
          <?php $statuses = asr_statuses(); foreach ($asr_recent as $r): ?>
          <tr>
            <td class="text-secondary small"><?= h(asr_number($r)) ?></td>
            <td class="text-nowrap small"><?= h(substr((string)$r['created_at'], 0, 10)) ?></td>
            <td><?= h($r['participant_name']) ?></td>
            <td class="small"><?= h($r['event_name'] ?: '—') ?></td>
            <td><span class="badge <?= h(asr_status_class($r['status'])) ?>"><?= h(asr_label($statuses, $r['status'])) ?></span></td>
            <td class="text-end"><a href="<?= APP_URL ?>/asysta/view.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline-primary btn-sm py-0">Otwórz</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ════ Ostatnie karty doradztwa ════ -->
  <?php if ($cc_recent): ?>
  <h2 class="h6 fw-bold text-secondary text-uppercase mb-2">Ostatnie karty doradztwa</h2>
  <div class="card border-0 shadow-sm mb-4">
    <div class="table-responsive">
      <table class="table table-hover table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th scope="col">Nr</th>
            <th scope="col">Data</th>
            <th scope="col">Organizacja</th>
            <th scope="col">Doradca</th>
            <th scope="col">Obszar</th>
            <th scope="col"></th>
          </tr>
        </thead>
        <tbody>
          <?php $cc_areas = cc_areas(); foreach ($cc_recent as $c): ?>
          <tr>
            <td class="text-secondary small"><?= h(cc_card_number($c)) ?></td>
            <td class="text-nowrap small"><?= h($c['date_of_consultation'] ?? '—') ?></td>
            <td><?= h($c['org_name'] ?? '—') ?></td>
            <td class="small"><?= h($c['advisor_name'] ?? '—') ?></td>
            <td class="small"><?= h($cc_areas[$c['area'] ?? ''] ?? ($c['area'] ?? '—')) ?></td>
            <td class="text-end"><a href="<?= APP_URL ?>/extforms/konsultacjeADNGO/admin.php?id=<?= (int)$c['id'] ?>" class="btn btn-outline-primary btn-sm py-0">Otwórz</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
