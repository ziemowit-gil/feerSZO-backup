<?php
/**
 * asysta/admin.php — Panel zgłoszeń asysty i specjalnych potrzeb.
 *
 * Lista wszystkich zgłoszeń (od najnowszych) z filtrem statusu i wyszukiwarką,
 * kartami statystyk oraz linkiem do karty zgłoszenia (status, przypisanie
 * wolontariusza, kanał powiadomień, notatki wewnętrzne).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/assistance.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_module_enabled('dostepnosc_ngo_enabled', 'Moduł Dostępność NGO');
asr_migrate();

$base     = APP_URL . '/asysta';
$statuses = asr_statuses();

/* ── Filtry ───────────────────────────────────────────────────────────────── */
$status = trim($_GET['status'] ?? '');
$q      = trim($_GET['q'] ?? '');
if ($status !== '' && !array_key_exists($status, $statuses)) $status = '';

$rows = asr_list($status, $q);

/* ── Eksport CSV ──────────────────────────────────────────────────────────── */
if (isset($_GET['_export']) && $_GET['_export'] === 'csv') {
    $filename = 'zgloszenia-asysta-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM — Excel UTF-8
    fputcsv($out, ['Nr zgłoszenia','Data','Imię i nazwisko','E-mail','Telefon','Opiekun','Wydarzenie','Data i miejsce','Zakres asysty','Status','Wolontariusz','Notatki'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            asr_number($r),
            substr((string)$r['created_at'], 0, 16),
            $r['participant_name'],
            $r['participant_email'],
            $r['participant_phone'],
            (int)$r['is_guardian'] ? 'Tak' : 'Nie',
            $r['event_name'],
            $r['event_when_where'],
            implode('; ', asr_needs_labels($r['needs'])),
            asr_label($statuses, $r['status']),
            $r['assigned_name'],
            $r['internal_notes'],
        ], ';');
    }
    fclose($out);
    exit;
}

// Statystyki po wszystkich zgłoszeniach (niezależnie od filtra).
$all = asr_list();
$cnt = ['total' => count($all), 'open' => 0, 'done' => 0, 'unassigned' => 0];
foreach ($all as $r) {
    if (in_array($r['status'], ['done'], true)) $cnt['done']++;
    elseif (!in_array($r['status'], ['rejected', 'rejected_external'], true)) $cnt['open']++;
    if (empty($r['assigned_volunteer_id']) && !in_array($r['status'], ['done','rejected','rejected_external'], true)) {
        $cnt['unassigned']++;
    }
}

$PAGE_TITLE = 'Zgłoszenia asysty';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-3">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 fw-bold mb-0">
      <i class="bi bi-universal-access-circle text-primary me-1" aria-hidden="true"></i>Zgłoszenia asysty
    </h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm"
         href="<?= h($base) ?>/admin.php?<?= http_build_query(['status' => $status, 'q' => $q, '_export' => 'csv']) ?>">
        <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Eksport CSV
      </a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(APP_URL) ?>/extforms/asystaFEER/" target="_blank" rel="noopener">
        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Formularz publiczny
      </a>
    </div>
  </div>

  <!-- Statystyki -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Wszystkich</div>
        <div class="h3 fw-bold mb-0"><?= (int)$cnt['total'] ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">W toku</div>
        <div class="h3 fw-bold mb-0 text-primary"><?= (int)$cnt['open'] ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Zrealizowane</div>
        <div class="h3 fw-bold mb-0 text-secondary"><?= (int)$cnt['done'] ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
        <div class="text-secondary small text-uppercase">Bez wolontariusza</div>
        <div class="h3 fw-bold mb-0 text-warning"><?= (int)$cnt['unassigned'] ?></div>
      </div></div>
    </div>
  </div>

  <!-- Filtry -->
  <form method="get" action="<?= h($base) ?>/admin.php" class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
      <div class="row g-2 align-items-end">
        <div class="col-sm-4 col-md-3">
          <label for="f-status" class="form-label small fw-semibold mb-1">Status</label>
          <select class="form-select form-select-sm" id="f-status" name="status">
            <option value="">— wszystkie —</option>
            <?php foreach ($statuses as $k => $lbl): ?>
              <option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-5 col-md-5">
          <label for="f-q" class="form-label small fw-semibold mb-1">Szukaj (imię, e-mail, wydarzenie)</label>
          <input type="search" class="form-control form-control-sm" id="f-q" name="q" value="<?= h($q) ?>">
        </div>
        <div class="col-sm-3 col-md-4 d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtruj
          </button>
          <?php if ($status !== '' || $q !== ''): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= h($base) ?>/admin.php">Wyczyść</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </form>

  <!-- Tabela -->
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <caption class="visually-hidden">Lista zgłoszeń asysty posortowana od najnowszego</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Nr</th>
            <th scope="col">Data</th>
            <th scope="col">Zgłaszający</th>
            <th scope="col">Wydarzenie</th>
            <th scope="col">Zakres asysty</th>
            <th scope="col">Wolontariusz</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center text-secondary py-5">
              <i class="bi bi-inbox fs-3 d-block mb-2" aria-hidden="true"></i>
              Brak zgłoszeń spełniających kryteria.
            </td></tr>
          <?php else: foreach ($rows as $r):
            $needs = asr_needs_labels($r['needs']); ?>
            <tr>
              <td class="text-nowrap text-secondary small"><?= h(asr_number($r)) ?></td>
              <td class="text-nowrap"><?= h(date_pl(substr((string)$r['created_at'], 0, 10))) ?></td>
              <td>
                <?= h($r['participant_name']) ?>
                <?php if ((int)$r['is_guardian'] === 1): ?>
                  <i class="bi bi-people-fill text-secondary" title="Zgłasza jako opiekun" aria-label="Zgłasza jako opiekun"></i>
                <?php endif; ?>
                <div class="small text-secondary text-truncate" style="max-width:14rem"><?= h($r['participant_email']) ?></div>
              </td>
              <td class="small"><?= h($r['event_name'] ?: '—') ?></td>
              <td class="small">
                <?php foreach (array_slice($needs, 0, 3) as $lbl): ?>
                  <span class="badge text-bg-light border me-1 mb-1"><?= h($lbl) ?></span>
                <?php endforeach; ?>
                <?php if (count($needs) > 3): ?>
                  <span class="badge text-bg-secondary">+<?= count($needs) - 3 ?></span>
                <?php endif; ?>
              </td>
              <td class="small"><?= h($r['assigned_name'] ?: '—') ?></td>
              <td><span class="badge <?= h(asr_status_class($r['status'])) ?>"><?= h(asr_label($statuses, $r['status'])) ?></span></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-outline-primary btn-sm" href="<?= h($base) ?>/view.php?id=<?= (int)$r['id'] ?>">
                  <i class="bi bi-pencil-square" aria-hidden="true"></i>
                  <span class="d-none d-xl-inline">Otwórz</span>
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
