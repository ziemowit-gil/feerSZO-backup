<?php
/**
 * karty30/ti/raporty.php — Raporty TI: kreatory raportów (per uczestnik / grupa /
 * prowadzący) oraz wejście do sprawozdania do WUP. Wydzielone z głównej strony TI.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();
if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień.'); }

$courses        = k30_ti_courses(false);
$ti_instructors = k30_ti_instructors();

// Pomocniczy render pojedynczego bloku raportu (miesięczny + roczny) z filtrem.
$report_block = function (string $title, string $icon, string $desc, string $monthly, string $annual, string $filter_name, string $filter_all, array $filter_opts) {
    ?>
    <div class="col-lg-4">
      <div class="border rounded h-100 p-3">
        <div class="fw-semibold mb-1"><i class="bi bi-<?= h($icon) ?> me-1 text-primary"></i><?= h($title) ?></div>
        <div class="text-body-secondary mb-2" style="font-size:.8rem"><?= h($desc) ?></div>
        <form method="get" action="<?= h($monthly) ?>" target="_blank" rel="noopener" class="mb-2">
          <label class="form-label small mb-1"><?= h($filter_all === 'Wszystkie kursy' ? 'Kurs' : 'Prowadzący') ?></label>
          <select name="<?= h($filter_name) ?>" class="form-select form-select-sm mb-2">
            <option value="0"><?= h($filter_all) ?></option>
            <?php foreach ($filter_opts as $o): ?>
            <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="input-group input-group-sm">
            <input type="month" name="m" value="<?= h(date('Y-m')) ?>" class="form-control">
            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>Miesięczny</button>
          </div>
        </form>
        <form method="get" action="<?= h($annual) ?>" target="_blank" rel="noopener">
          <select name="<?= h($filter_name) ?>" class="form-select form-select-sm mb-2">
            <option value="0"><?= h($filter_all) ?></option>
            <?php foreach ($filter_opts as $o): ?>
            <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="input-group input-group-sm">
            <input type="number" name="y" value="<?= (int)date('Y') ?>" min="2000" max="2100" class="form-control">
            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>Roczny</button>
          </div>
        </form>
      </div>
    </div>
    <?php
};

$PAGE_TITLE = 'Raporty TI';
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.85rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/ti/index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Raporty</li>
</ol></nav>

<?= flash_html() ?>

<h4 class="fw-bold mb-3"><i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>Raporty TI</h4>

<div class="card border-0 shadow-sm mb-4" id="raporty-ti">
  <div class="card-header bg-white d-flex align-items-center">
    <i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>
    <span class="fw-semibold">Frekwencja i rozliczenia</span>
    <span class="ms-2 small text-body-secondary">PDF · wybierz okres i zakres</span>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <?php
        $report_block(
          'Per uczestnik', 'file-earmark-person',
          'Frekwencja we wszystkich kursach + rozliczenia (należności/wpłaty) i saldo konta — sekcja na kursanta.',
          'participant_monthly.php', 'participant_annual.php', 'course_id', 'Wszystkie kursy', $courses);
        $report_block(
          'Per grupa', 'collection',
          'Frekwencja uczestników w grupie + ich rozliczenia i saldo — sekcja na kurs. Śr. frekwencja grupy.',
          'group_monthly.php', 'group_annual.php', 'course_id', 'Wszystkie kursy', $courses);
        $report_block(
          'Per prowadzący', 'person-badge',
          'Frekwencja grup prowadzącego (lekcje odbyte/odwołane, śr. frekwencja) — bez kwot.',
          'instructor_monthly.php', 'instructor_annual.php', 'instructor_id', 'Wszyscy prowadzący', $ti_instructors);
      ?>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm" id="raport-wup">
  <div class="card-header bg-white d-flex align-items-center">
    <i class="bi bi-bank me-2 text-primary"></i>
    <span class="fw-semibold">Sprawozdanie do Wojewódzkiego Urzędu Pracy</span>
    <span class="ms-2 small text-body-secondary">ustawa o promocji zatrudnienia · podgląd → PDF</span>
  </div>
  <div class="card-body">
    <p class="text-body-secondary small mb-3">
      <i class="bi bi-info-circle me-1"></i>
      Podgląd przed wydrukiem: zajęcia wg prowadzących i grup, wskaźniki (zatrudnieni, uczestnicy w tym z niepełnosprawnością, zajęcia online/stacjonarne, odwołania, frekwencja),
      pola narracyjne i wgranie podpisanego dokumentu. Dane RIS i kierownika ustawisz w zakładce
      <a href="ris.php">Dane do RIS</a>.
    </p>
    <form id="wupPreviewForm" action="wup_report.php" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label small mb-1">Od</label>
        <input type="date" name="from" value="<?= h(date('Y-m-d', strtotime('first day of last month'))) ?>" class="form-control form-control-sm">
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1">Do</label>
        <input type="date" name="to" value="<?= h(date('Y-m-d', strtotime('last day of last month'))) ?>" class="form-control form-control-sm">
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1">Prowadzący</label>
        <select name="instructor_id" class="form-select form-select-sm">
          <option value="0">Wszyscy prowadzący</option>
          <?php foreach ($ti_instructors as $it): ?>
          <option value="<?= (int)$it['id'] ?>"><?= h($it['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1"></i>Otwórz podgląd</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: podgląd sprawozdania WUP -->
<div class="modal fade" id="wupModal" tabindex="-1" aria-labelledby="wupModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="wupModalLabel"><i class="bi bi-bank me-2 text-primary"></i>Sprawozdanie do WUP — podgląd</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body" id="wupModalBody">
        <div class="text-center text-body-secondary py-5"><div class="spinner-border" role="status" aria-hidden="true"></div><div class="mt-2 small">Ładowanie podglądu…</div></div>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form  = document.getElementById('wupPreviewForm');
  var modalEl = document.getElementById('wupModal');
  if (!form || !modalEl || !window.bootstrap) return; // brak Bootstrapa → zwykły submit (fallback)
  var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  var body  = document.getElementById('wupModalBody');
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var qs = new URLSearchParams(new FormData(form)).toString();
    body.innerHTML = '<div class="text-center text-body-secondary py-5"><div class="spinner-border" role="status"></div><div class="mt-2 small">Ładowanie podglądu…</div></div>';
    modal.show();
    fetch('wup_report.php?embed=1&' + qs, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) { body.innerHTML = html; })
      .catch(function (err) { body.innerHTML = '<div class="alert alert-danger m-3">Nie udało się wczytać podglądu: ' + err.message + '</div>'; });
  });
});
</script>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
