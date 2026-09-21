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

if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="EODoK_dokumenty_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Numer', 'Kierunek', 'Kontrahent', 'NIP', 'Tytuł', 'Netto', 'VAT', 'Brutto', 'Waluta', 'Status', 'Klasyfikacja', 'Dodano'], ';');
    foreach ($docs as $d) {
        fputcsv($out, [
            $d['number'], ($d['kierunek'] ?? 'wydatek') === 'przychod' ? 'Przychód' : 'Wydatek', $d['kontrahent_nazwa'], $d['kontrahent_nip'], $d['title'],
            $d['kwota_netto'], $d['kwota_vat'], $d['kwota_brutto'], $d['waluta'] ?: 'PLN',
            EDOK_STATUSES[$d['status']]['label'] ?? $d['status'],
            edok_transfer_label_klasyfikacja($d['rodzaj_dzialalnosci'], $d['projekt']),
            $d['created_at'],
        ], ';');
    }
    fclose($out);
    exit;
}

$PAGE_TITLE = 'EODoK — Elektroniczny Obieg Dokumentów Księgowych';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-journal-check"></i> EODoK — Elektroniczny Obieg Dokumentów Księgowych</h4>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/edok/index.php?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline-success btn-sm">
      <i class="bi bi-filetype-csv"></i> Eksport CSV
    </a>
    <a href="<?= APP_URL ?>/edok/transfers.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left-right"></i> Przelewy własne</a>
    <a href="<?= APP_URL ?>/edok/ustaw_pin.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-shield-lock"></i> Twój PIN</a>
    <?php if (is_admin() || edok_has_role('ksiegowy')): ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Przeliczyć tytuły przelewów wszystkich niezaakceptowanych dokumentów do aktualnego formatu?');">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="update_titles">
      <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat"></i> Aktualizuj tytuły</button>
    </form>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <form method="post" class="d-inline" onsubmit="return confirm('Usunąć wszystkie dokumenty testowe (numer EODoK-TEST/…) wraz z etapami, audytem i wygenerowanymi PDF-ami? Tej operacji nie można cofnąć.');">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="delete_test_docs">
      <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3"></i> Usuń testowe</button>
    </form>
    <?php endif; ?>
    <?php if (is_admin() || edok_has_role('upload')): ?>
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
    <label class="form-label small mb-1">Szukaj</label>
    <input type="text" name="q" class="form-control form-control-sm" value="<?= h($filter_q) ?>" placeholder="numer, kontrahent, tytuł…">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i> Szukaj</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr>
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
      <tr><td colspan="13" class="text-center text-muted py-4">Brak dokumentów.</td></tr>
      <?php endif; ?>
      <?php foreach ($docs as $doc): ?>
      <tr>
        <td><code><?= h($doc['number']) ?></code><?php if (edok_is_test_number($doc['number'])): ?> <span class="badge bg-warning text-dark">TEST</span><?php endif; ?></td>
        <td><?= ($doc['kierunek'] ?? 'wydatek') === 'przychod' ? '<span class="badge bg-info text-dark">Przychód</span>' : '<span class="badge bg-secondary">Wydatek</span>' ?></td>
        <td><?= h($doc['kontrahent_nazwa']) ?></td>
        <td><?= h($doc['title']) ?></td>
        <td class="text-end font-monospace"><?= h($doc['kwota_brutto']) ?> <?= h($doc['waluta']) ?></td>
        <td><?= edok_status_badge($doc['status']) ?></td>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
