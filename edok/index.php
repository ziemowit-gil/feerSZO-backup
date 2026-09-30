<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

// Masowe przeliczenie tytułów przelewów do aktualnego formatu (Uchwała 5/2026 §2
// pkt 8-9) — tylko dla dokumentów jeszcze niezaakceptowanych (zaakceptowany ma już
// wygenerowany dokument końcowy z kartą akceptacji, w którym tytuł się nie zmienia).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_titles') {
    csrf_check();
    if (!is_admin() && !edok_has_role('ksiegowy')) {
        flash_set('danger', 'Brak uprawnień do aktualizacji tytułów przelewów.');
    } else {
        $rows = db_all("SELECT * FROM edok_documents WHERE status != 'zaakceptowany'");
        $n = 0;
        foreach ($rows as $r) {
            $nowy = edok_generate_tytul_przelewu($r);
            if ($nowy !== $r['tytul_przelewu']) {
                db_exec("UPDATE edok_documents SET tytul_przelewu=?, updated_at=datetime('now') WHERE id=?", [$nowy, $r['id']]);
                edok_log((int)$r['id'], 'edit', '', $r['status'], $r['status'], 'Tytuł przelewu zaktualizowany do aktualnego formatu (Uchwała 5/2026).');
                $n++;
            }
        }
        flash_set('success', $n > 0 ? "Zaktualizowano tytuły przelewów: {$n}." : 'Wszystkie tytuły są już aktualne.');
    }
    header('Location: ' . APP_URL . '/edok/index.php' . (($_GET['status'] ?? '') !== '' ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}

// Zbiorcze usunięcie dokumentów testowych (numer EODoK-TEST/…, patrz edok/add.php
// checkbox "Dokument testowy") — tylko admin, nie dotyka realnej sekwencji EODoK.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_test_docs') {
    csrf_check();
    if (!is_admin()) {
        flash_set('danger', 'Brak uprawnień do usuwania dokumentów testowych.');
    } else {
        $n = edok_delete_test_documents();
        flash_set('success', $n > 0 ? "Usunięto dokumentów testowych: {$n}." : 'Brak dokumentów testowych do usunięcia.');
    }
    header('Location: ' . APP_URL . '/edok/index.php');
    exit;
}

$filter_status   = $_GET['status'] ?? '';
$filter_kierunek = $_GET['kierunek'] ?? '';
$filter_q        = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($filter_status && isset(EDOK_STATUSES[$filter_status])) {
    $where[]  = 'status = ?';
    $params[] = $filter_status;
}
if (in_array($filter_kierunek, ['wydatek', 'przychod'], true)) {
    $where[]  = "COALESCE(kierunek,'wydatek') = ?";
    $params[] = $filter_kierunek;
}
$filter_proformy = !empty($_GET['proformy_bez_faktury']);
if ($filter_proformy) {
    $where[] = "typ_dokumentu = 'proforma' AND status NOT IN ('odrzucony','wycofany')
        AND NOT EXISTS (SELECT 1 FROM edok_documents f WHERE f.proforma_id = edok_documents.id AND f.status NOT IN ('odrzucony','wycofany'))";
}
if ($filter_q !== '') {
    $where[]  = '(number LIKE ? OR title LIKE ? OR kontrahent_nazwa LIKE ? OR nr_faktury LIKE ?)';
    $q = '%' . $filter_q . '%';
    array_push($params, $q, $q, $q, $q);
}

$docs = db_all(
    "SELECT * FROM edok_documents WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 200",
    $params
);
foreach ($docs as &$d) {
    $d['steps'] = [];
    foreach (db_all("SELECT step_key, status FROM edok_steps WHERE doc_id = ?", [$d['id']]) as $s) {
        $d['steps'][$s['step_key']] = $s['status'];
    }
}
unset($d);
// Proformy z listy, które mają już fakturę końcową (znaczek „proforma ✓”).
$proforma_z_faktura = [];
$pf_ids = array_column(array_filter($docs, fn($d) => $d['typ_dokumentu'] === 'proforma'), 'id');
if ($pf_ids) {
    $ph = implode(',', array_fill(0, count($pf_ids), '?'));
    foreach (db_all("SELECT DISTINCT proforma_id FROM edok_documents WHERE proforma_id IN ($ph) AND status NOT IN ('odrzucony','wycofany')", $pf_ids) as $r) {
        $proforma_z_faktura[(int)$r['proforma_id']] = true;
    }
}

// Zaznaczanie + eksport przelewów z listy — ten sam handler co w Preliminarzu
// (POST export_przelewy na edok/preliminarz.php: potwierdzenie NIP/NRB, pliki ELIXIR-O).
$przelew_rachunki = edok_rachunki_list();
$can_export = $przelew_rachunki && (is_admin() || edok_has_role('zatwierdza') || (function_exists('kdok_has_role') && kdok_has_role('zatwierdza')));

if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="EODoK_dokumenty_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Numer', 'Kierunek', 'Kontrahent', 'NIP', 'Tytuł', 'Netto', 'VAT', 'Brutto', 'Waluta', 'Status', 'Klasyfikacja', 'Dodano'], ';', '"', '\\');
    foreach ($docs as $d) {
        fputcsv($out, [
            $d['number'], ($d['kierunek'] ?? 'wydatek') === 'przychod' ? 'Przychód' : 'Wydatek', $d['kontrahent_nazwa'], $d['kontrahent_nip'], $d['title'],
            $d['kwota_netto'], $d['kwota_vat'], $d['kwota_brutto'], $d['waluta'] ?: 'PLN',
            EDOK_STATUSES[$d['status']]['label'] ?? $d['status'],
            edok_transfer_label_klasyfikacja($d['rodzaj_dzialalnosci'], $d['projekt']),
            $d['created_at'],
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$PAGE_TITLE = 'EODoK — Elektroniczny Obieg Dokumentów Księgowych';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-journal-check"></i> EODoK — Elektroniczny Obieg Dokumentów Księgowych</h4>
  <?php
    $can_upload = is_admin() || edok_has_role('upload');
    $can_fin    = is_admin() || edok_has_role('zatwierdza') || edok_has_role('ksiegowy');
    $can_ksieg  = is_admin() || edok_has_role('ksiegowy');
    $pending_count = array_sum(array_map('count', edok_pending_for_user((int)current_user()['id'])));
    if ($can_upload) { require_once __DIR__ . '/../includes/edok_bank.php'; $bank_open = edok_bank_unassigned_count(); $queue_count = edok_queue_count(); } else { $bank_open = 0; $queue_count = 0; }
    $ksef_on = org_setting('kdok_ksef_enabled') === '1';
  ?>
  <div class="d-flex gap-2 flex-wrap align-items-center">
    <a href="<?= APP_URL ?>/edok/pending.php" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-check2-all"></i> Do akceptacji
      <?php if ($pending_count): ?><span class="badge bg-primary ms-1"><?= $pending_count ?></span><?php endif; ?>
    </a>
    <?php if ($can_upload): ?>
    <a href="<?= APP_URL ?>/edok/queue.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-inboxes"></i> Kolejka do opisu
      <?php if ($queue_count): ?><span class="badge bg-primary ms-1"><?= $queue_count ?></span><?php endif; ?></a>
    <?php endif; ?>

    <?php if ($can_upload): ?>
    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-box-arrow-in-down"></i> Import</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php if ($ksef_on): ?>
        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#salesSyncModal"><i class="bi bi-cloud-download"></i> Pobierz sprzedaż z KSeF</button></li>
        <?php endif; ?>
        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#salesXmlModal"><i class="bi bi-file-earmark-code"></i> Import sprzedaży z plików XML</button></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/ksef_sync.php"><i class="bi bi-cloud-arrow-down"></i> Synchronizacja KSeF (zakupy)</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/mt940_import.php"><i class="bi bi-upload"></i> Import wyciągu (MT940)</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/szablony.php"><i class="bi bi-file-earmark-richtext"></i> Szablony</a></li>
      </ul>
    </div>
    <?php endif; ?>

    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-bank2"></i> Bank i płatności<?php if ($bank_open): ?> <span class="badge bg-warning text-dark"><?= $bank_open ?></span><?php endif; ?></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php if ($can_upload): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/wyciag.php"><i class="bi bi-bank2"></i> Wyciągi bankowe<?php if ($bank_open): ?> <span class="badge bg-warning text-dark"><?= $bank_open ?></span><?php endif; ?></a></li>
        <?php endif; ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/preliminarz.php"><i class="bi bi-calendar-check"></i> Preliminarz płatności</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/transfers.php"><i class="bi bi-arrow-left-right"></i> Przelewy własne</a></li>
        <?php if ($can_fin): ?>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/zaplacone_przed.php"><i class="bi bi-cash-coin"></i> Zapłacone przed akceptacją</a></li>
        <?php endif; ?>
      </ul>
    </div>

    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-file-earmark-bar-graph"></i> Raporty i eksport</button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/index.php?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><i class="bi bi-filetype-csv"></i> Eksport listy (CSV)</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/dla_ksiegowego.php"><i class="bi bi-file-earmark-spreadsheet"></i> Dla księgowego (PDF/Excel)</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/raport_analityczny.php"><i class="bi bi-bar-chart-line"></i> Tabela analityczna</a></li>
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/archiwum.php"><i class="bi bi-archive"></i> Archiwum miesięczne</a></li>
      </ul>
    </div>

    <div class="dropdown">
      <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Więcej"><i class="bi bi-three-dots"></i></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="<?= APP_URL ?>/edok/ustaw_pin.php"><i class="bi bi-shield-lock"></i> Twój PIN</a></li>
        <?php if ($can_ksieg): ?>
        <li><form method="post" onsubmit="return confirm('Przeliczyć tytuły przelewów wszystkich niezaakceptowanych dokumentów do aktualnego formatu?');">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="update_titles">
          <button type="submit" class="dropdown-item"><i class="bi bi-arrow-repeat"></i> Aktualizuj tytuły przelewów</button></form></li>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <li><hr class="dropdown-divider"></li>
        <li><form method="post" onsubmit="return confirm('Usunąć wszystkie dokumenty testowe (numer EODoK-TEST/…) wraz z etapami, audytem i wygenerowanymi PDF-ami? Tej operacji nie można cofnąć.');">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete_test_docs">
          <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash3"></i> Usuń dokumenty testowe</button></form></li>
        <?php endif; ?>
      </ul>
    </div>

    <?php if ($can_upload): ?>
    <a href="<?= APP_URL ?>/edok/add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nowy dokument</a>
    <?php endif; ?>
  </div>
</div>

<form method="get" action="<?= APP_URL ?>/edok/monthly_pdf.php" class="row g-2 mb-3 align-items-end">
  <div class="col-auto">
    <label class="form-label small mb-1">PDF ze wszystkimi dokumentami z miesiąca</label>
    <?php $months_pl = ['', 'Styczeń', 'Luty', 'Marzec', 'Kwiecień', 'Maj', 'Czerwiec', 'Lipiec', 'Sierpień', 'Wrzesień', 'Październik', 'Listopad', 'Grudzień']; ?>
    <div class="d-flex gap-1">
      <select name="miesiac" class="form-select form-select-sm">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m == (int)date('n') ? 'selected' : '' ?>><?= h($months_pl[$m]) ?></option>
        <?php endfor; ?>
      </select>
      <select name="rok" class="form-select form-select-sm" style="max-width:100px">
        <?php for ($r = (int)date('Y'); $r >= (int)date('Y') - 5; $r--): ?>
        <option value="<?= $r ?>" <?= $r == (int)date('Y') ? 'selected' : '' ?>><?= $r ?></option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-file-earmark-pdf"></i> Pobierz PDF</button>
    </div>
  </div>
</form>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto">
    <label class="form-label small mb-1">Status</label>
    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">Wszystkie</option>
      <?php foreach (EDOK_STATUSES as $k => $s): ?>
      <option value="<?= h($k) ?>" <?= $filter_status === $k ? 'selected' : '' ?>><?= h($s['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Kierunek</label>
    <select name="kierunek" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">Wszystkie</option>
      <option value="wydatek" <?= $filter_kierunek === 'wydatek' ? 'selected' : '' ?>>Wydatek</option>
      <option value="przychod" <?= $filter_kierunek === 'przychod' ? 'selected' : '' ?>>Przychód</option>
    </select>
  </div>
  <div class="col-auto">
    <div class="form-check mb-1">
      <input type="checkbox" class="form-check-input" name="proformy_bez_faktury" id="f_proformy" value="1" <?= $filter_proformy ? 'checked' : '' ?> onchange="this.form.submit()">
      <label class="form-check-label small" for="f_proformy">Proformy bez faktury końcowej</label>
    </div>
  </div>
  <div class="col-auto">
    <label class="form-label small mb-1">Szukaj</label>
    <input type="text" name="q" class="form-control form-control-sm" value="<?= h($filter_q) ?>" placeholder="numer, kontrahent, tytuł…">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Szukaj</button>
  </div>
</form>

<?php if ($can_export): ?>
<form method="post" action="<?= APP_URL ?>/edok/preliminarz.php" id="edokExportForm"
      class="d-none align-items-center gap-2 mb-2 flex-wrap p-2 border rounded bg-light">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="export_przelewy">
  <input type="hidden" name="pakiet_ids" id="edokExportIds">
  <input type="hidden" name="rachunek_map" value="{}">
  <span class="small"><strong id="edokExportCount">0</strong> zaznaczonych</span>
  <select name="rachunek_zlecen" class="form-select form-select-sm" style="width:auto" title="Rachunek, z którego mają pójść przelewy" required>
    <?php foreach ($przelew_rachunki as $r): ?>
    <option value="<?= h($r['nrb']) ?>"><?= h($r['nazwa'] ?: $r['bank']) ?> (…<?= h(substr(preg_replace('/\D/', '', $r['nrb']), -4)) ?>)</option>
    <?php endforeach; ?>
  </select>
  <select name="format" class="form-select form-select-sm" style="width:auto" title="Format pliku przelewów">
    <?php foreach (EDOK_PRZELEWY_FORMATY as $k => $label): ?>
    <option value="<?= h($k) ?>"><?= h($label) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-bank"></i> Eksportuj przelewy</button>
</form>
<p class="text-muted small mb-2">Zaznaczyć do eksportu przelewów można zaakceptowane, jeszcze nieopłacone wydatki z prawidłowym 26-cyfrowym rachunkiem kontrahenta.</p>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr>
        <?php if ($can_export): ?>
        <th style="width:2rem"><input type="checkbox" class="form-check-input" id="edokExportAll" title="Zaznacz wszystkie do eksportu" aria-label="Zaznacz wszystkie do eksportu"></th>
        <?php endif; ?>
        <th>Numer</th>
        <th>Kierunek</th>
        <th>Kontrahent</th>
        <th>Tytuł</th>
        <th class="text-end">Kwota brutto</th>
        <th>Status</th>
        <?php $step_abbr = ['meryt' => 'M', 'formal' => 'F', 'rachunkowa' => 'R', 'dekretacja' => 'D', 'zatwierdza' => 'Z']; ?>
        <?php foreach (EDOK_STEPS as $sk => $sl): ?>
        <th class="text-center" title="<?= h($sl) ?>"><?= h($step_abbr[$sk] ?? '?') ?>.</th>
        <?php endforeach; ?>
        <th>Dodano</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$docs): ?>
      <tr><td colspan="<?= $can_export ? 14 : 13 ?>" class="text-center text-muted py-4">Brak dokumentów.</td></tr>
      <?php endif; ?>
      <?php foreach ($docs as $doc): ?>
      <tr>
        <?php if ($can_export): ?>
        <td><?php if (edok_przelew_exportable($doc)): ?><input type="checkbox" class="form-check-input edok-export-check" value="<?= (int)$doc['id'] ?>" aria-label="Zaznacz <?= h($doc['number']) ?> do eksportu"><?php endif; ?></td>
        <?php endif; ?>
        <td><code><?= h($doc['number']) ?></code><?php if (edok_is_test_number($doc['number'])): ?> <span class="badge bg-warning text-dark">TEST</span><?php endif; ?>
          <?php if (edok_zaplata_do_zwrotu($doc)): ?> <span class="badge bg-warning text-dark" title="<?= h(edok_zaplata_opis($doc)) ?>"><i class="bi bi-person-check"></i> do zwrotu</span>
          <?php elseif (!empty($doc['zaplacono_przed'])): ?> <span class="badge bg-success-subtle text-success-emphasis" title="Zapłacona przed akceptacją: <?= h(edok_zaplata_opis($doc)) ?>"><i class="bi bi-cash-coin"></i> zapłacona</span><?php endif; ?>
          <?php if ($doc['typ_dokumentu'] === 'proforma'): ?> <span class="badge bg-info-subtle text-info-emphasis" title="<?= isset($proforma_z_faktura[$doc['id']]) ? 'Rozliczona fakturą końcową' : 'Czeka na fakturę końcową' ?>">proforma<?= isset($proforma_z_faktura[$doc['id']]) ? ' ✓' : ' · bez faktury' ?></span><?php endif; ?></td>
        <td><?= ($doc['kierunek'] ?? 'wydatek') === 'przychod' ? '<span class="badge bg-info text-dark">Przychód</span>' : '<span class="badge bg-secondary">Wydatek</span>' ?></td>
        <td><?= h($doc['kontrahent_nazwa']) ?></td>
        <td><?= h($doc['title']) ?></td>
        <td class="text-end font-monospace"><?= h($doc['kwota_brutto']) ?> <?= h($doc['waluta']) ?></td>
        <td><?= edok_status_badge($doc['status'], $doc) ?></td>
        <?php foreach (array_keys(EDOK_STEPS) as $sk): ?>
        <td class="text-center">
          <?php $st = $doc['steps'][$sk] ?? null; ?>
          <?php if ($st === 'ok'): ?>
          <i class="bi bi-check-circle-fill text-success" title="Zatwierdzone"></i>
          <?php elseif ($st === 'uwagi'): ?>
          <i class="bi bi-exclamation-circle-fill text-warning" title="Z uwagami"></i>
          <?php elseif ($st === 'odrzucono'): ?>
          <i class="bi bi-x-circle-fill text-danger" title="Odrzucono"></i>
          <?php else: ?>
          <i class="bi bi-circle text-muted" title="Oczekuje"></i>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
        <td><?= date_pl($doc['created_at']) ?></td>
        <td class="text-nowrap">
          <a href="<?= APP_URL ?>/edok/view.php?id=<?= $doc['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($can_export): ?>
<script>
(function () {
  var boxes = Array.from(document.querySelectorAll('.edok-export-check'));
  var all   = document.getElementById('edokExportAll');
  var form  = document.getElementById('edokExportForm');
  function sync() {
    var ids = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
    document.getElementById('edokExportIds').value = ids.join(',');
    document.getElementById('edokExportCount').textContent = ids.length;
    form.classList.toggle('d-none', !ids.length);
    form.classList.toggle('d-flex', ids.length > 0);
    all.checked = boxes.length > 0 && ids.length === boxes.length;
    all.indeterminate = ids.length > 0 && ids.length < boxes.length;
  }
  all.disabled = !boxes.length;
  all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); sync(); });
  boxes.forEach(function (b) { b.addEventListener('change', sync); });
  sync();
})();
</script>
<?php endif; ?>

<?php if ((is_admin() || edok_has_role('upload')) && org_setting('kdok_ksef_enabled') === '1'): ?>
<div class="modal fade" id="salesSyncModal" tabindex="-1" aria-labelledby="salesSyncLabel" aria-hidden="true">
  <div class="modal-dialog"><form method="post" action="<?= APP_URL ?>/edok/ksef_sync.php" class="modal-content">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="sync_sales">
    <input type="hidden" name="return" value="index">
    <div class="modal-header"><h5 class="modal-title" id="salesSyncLabel">Pobierz faktury sprzedaży z KSeF</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <p class="small text-muted">Faktury wystawione przez organizację (sprzedawca) i ich korekty. Powstają jako dokumenty przychodowe EODoK; już pobrane są pomijane.</p>
      <div class="row g-2">
        <div class="col-6"><label class="form-label small mb-1" for="ss_from">Od</label><input type="date" id="ss_from" name="sales_from" class="form-control" value="<?= date('Y-m-01') ?>"></div>
        <div class="col-6"><label class="form-label small mb-1" for="ss_to">Do</label><input type="date" id="ss_to" name="sales_to" class="form-control" value="<?= date('Y-m-d') ?>"></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary"><i class="bi bi-cloud-download"></i> Pobierz</button></div>
  </form></div>
</div>
<?php endif; ?>
<?php if (is_admin() || edok_has_role('upload')): ?>
<div class="modal fade" id="salesXmlModal" tabindex="-1" aria-labelledby="salesXmlLabel" aria-hidden="true">
  <div class="modal-dialog"><form method="post" action="<?= APP_URL ?>/edok/ksef_sync.php" enctype="multipart/form-data" class="modal-content">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="sales_xml"><input type="hidden" name="return" value="index">
    <div class="modal-header"><h5 class="modal-title" id="salesXmlLabel">Import sprzedaży z plików XML</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <p class="small text-muted">Faktury sprzedaży i korekty wystawione przez organizację (FA z KSeF lub z systemu fakturowego). Powstają jako dokumenty przychodowe; faktury o tym samym numerze są pomijane.</p>
      <label class="form-label small mb-1" for="sx_files">Pliki XML</label>
      <input type="file" id="sx_files" name="xml_files[]" class="form-control" accept=".xml" multiple required>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary"><i class="bi bi-upload"></i> Importuj</button></div>
  </form></div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
