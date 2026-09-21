<?php
/**
 * edok/archiwum.php — Archiwum miesięczne EODoK (Uchwała 5/2026 §7): lista
 * wygenerowanych PDF-ów kart akceptacji i zestawień dokument→karta→akceptant,
 * pobieranie, ręczne wywołanie archiwizacji, potwierdzenie kompletności
 * (osoba odpowiedzialna za zamknięcie miesiąca — rola ksiegowy/admin).
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    csrf_check();
    $year  = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['month'] ?? 0);
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        flash_set('danger', 'Nieprawidłowy okres.');
    } else {
        $archive = edok_run_monthly_archive($year, $month, (int)$user['id']);
        flash_set($archive ? 'success' : 'warning', $archive
            ? "Archiwum za " . sprintf('%02d.%04d', $month, $year) . " wygenerowane ({$archive['doc_count']} dokument(ów))."
            : 'Brak dokumentów EODoK w tym miesiącu — nie ma czego archiwizować.');
    }
    header('Location: ' . APP_URL . '/edok/archiwum.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    csrf_check();
    if (!is_admin() && !edok_has_role('ksiegowy')) {
        flash_set('danger', 'Brak uprawnień do potwierdzenia kompletności.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        db_exec("UPDATE edok_monthly_archive SET verified_by=?, verified_name=?, verified_at=datetime('now') WHERE id=?",
            [(int)$user['id'], $user['name'] ?? '', $id]);
        flash_set('success', 'Potwierdzono kompletność archiwum.');
    }
    header('Location: ' . APP_URL . '/edok/archiwum.php');
    exit;
}

$archives = db_all("SELECT * FROM edok_monthly_archive ORDER BY year DESC, month DESC");

$PAGE_TITLE = 'Archiwum miesięczne — EODoK';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/edok/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0"><i class="bi bi-archive"></i> Archiwum miesięczne</h4>
</div>
<p class="text-muted small">Co miesiąc system automatycznie generuje PDF ze wszystkimi kartami akceptacji dodanymi w danym miesiącu oraz zestawienie powiązań dokument→karta akceptacji→akceptant (Uchwała 5/2026 §7). Poniżej można też wygenerować/przeliczyć archiwum ręcznie.</p>

<?php if (is_admin() || edok_has_role('ksiegowy')): ?>
<div class="card shadow-sm mb-3" style="max-width:420px">
  <div class="card-body py-2">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="generate">
      <div class="col-auto">
        <label class="form-label small mb-1">Miesiąc</label>
        <select name="month" class="form-select form-select-sm">
          <?php foreach (EDOK_MONTHS_PL as $m => $l): if ($m === 0) continue; ?>
          <option value="<?= $m ?>" <?= $m == (int)date('n') ? 'selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <label class="form-label small mb-1">Rok</label>
        <select name="year" class="form-select form-select-sm">
          <?php for ($r = (int)date('Y'); $r >= (int)date('Y') - 5; $r--): ?>
          <option value="<?= $r ?>" <?= $r == (int)date('Y') ? 'selected' : '' ?>><?= $r ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Generuj / przelicz</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>Okres</th>
        <th class="text-center">Dokumentów</th>
        <th>Wygenerowano</th>
        <th>Kompletność</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$archives): ?>
      <tr><td colspan="5" class="text-center text-muted py-4">Brak wygenerowanych archiwów.</td></tr>
      <?php endif; ?>
      <?php foreach ($archives as $a): ?>
      <tr>
        <td><strong><?= h(EDOK_MONTHS_PL[(int)$a['month']] ?? $a['month']) ?> <?= h($a['year']) ?></strong></td>
        <td class="text-center"><?= (int)$a['doc_count'] ?></td>
        <td class="small text-muted"><?= date_pl($a['generated_at']) ?> <?= date('H:i', strtotime($a['generated_at'])) ?><br><?= h($a['gen_name']) ?></td>
        <td class="small">
          <?php if ($a['verified_at']): ?>
          <span class="text-success"><i class="bi bi-check-circle-fill"></i> Potwierdzona</span><br>
          <span class="text-muted"><?= h($a['verified_name']) ?>, <?= date_pl($a['verified_at']) ?></span>
          <?php elseif (is_admin() || edok_has_role('ksiegowy')): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-check2"></i> Potwierdź kompletność</button>
          </form>
          <?php else: ?>
          <span class="text-muted">Oczekuje potwierdzenia</span>
          <?php endif; ?>
        </td>
        <td class="text-nowrap">
          <?php if ($a['pdf_path']): ?>
          <a href="<?= APP_URL ?>/edok/archiwum_file.php?id=<?= (int)$a['id'] ?>&type=pdf" target="_blank" class="btn btn-sm btn-outline-danger" title="Karty akceptacji (PDF)"><i class="bi bi-file-earmark-pdf"></i></a>
          <?php endif; ?>
          <?php if ($a['csv_path']): ?>
          <a href="<?= APP_URL ?>/edok/archiwum_file.php?id=<?= (int)$a['id'] ?>&type=csv" class="btn btn-sm btn-outline-success" title="Zestawienie powiązań (CSV)"><i class="bi bi-filetype-csv"></i></a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
