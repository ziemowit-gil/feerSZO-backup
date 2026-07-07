<?php
/**
 * Rejestr Umów (RU) — przeglądanie. Lista wszystkich umów (wszystkich typów)
 * z przydzielonym numerem rejestru, w kolejności rejestru, z filtrami
 * (typ / status / rok rejestru / opiekun / data zawarcia / szukajka).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }

$filters = [
    'typ'     => trim($_GET['typ']     ?? ''),
    'status'  => trim($_GET['status']  ?? ''),
    'rok'     => trim($_GET['rok']     ?? ''),
    'opiekun' => trim($_GET['opiekun'] ?? ''),
    'data_od' => trim($_GET['data_od'] ?? ''),
    'data_do' => trim($_GET['data_do'] ?? ''),
    'q'       => trim($_GET['q']       ?? ''),
];
$any_filter = implode('', $filters) !== '';

$all     = all_contracts_registry();
$rejestr = contracts_registry_filter($all, $filters);

// Opcje do dropdownów budowane z realnych danych rejestru (nie pokazuj pustych opcji)
$years    = array_values(array_unique(array_filter(array_map(fn($r) => registry_year((string)$r['nr_rejestru']), $all))));
rsort($years);
$statuses = array_values(array_unique(array_column($all, 'status')));
sort($statuses);

$print_qs = http_build_query(array_filter($filters, fn($v) => $v !== ''));

$PAGE_TITLE = 'Rejestr umów (RU)';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-journal-text text-primary"></i> Rejestr umów (RU)
    <span class="badge bg-secondary ms-1"><?= count($rejestr) ?></span>
  </h4>
  <a href="<?= APP_URL ?>/contracts/rejestr_print.php?<?= h($print_qs) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-printer me-1"></i>Drukuj rejestr
  </a>
</div>

<!-- Filtry -->
<div class="card shadow-sm mb-3">
  <div class="card-body p-2">
    <form method="get">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md">
          <label class="form-label small mb-1">Szukaj</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input type="text" name="q" class="form-control" value="<?= h($filters['q']) ?>"
                   placeholder="Nr rejestru, nr umowy, strona umowy, opiekun…" autocomplete="off">
          </div>
        </div>
        <div class="col-6 col-md-auto">
          <label class="form-label small mb-1">Typ umowy</label>
          <select name="typ" class="form-select form-select-sm">
            <option value="">— wszystkie —</option>
            <?php foreach (CONTRACT_TYPES as $k => $l): ?>
            <option value="<?= h($k) ?>" <?= $filters['typ'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-auto">
          <label class="form-label small mb-1">Status</label>
          <select name="status" class="form-select form-select-sm">
            <option value="">— wszystkie —</option>
            <?php foreach ($statuses as $s): ?>
            <option value="<?= h($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= h(STATUS_LABELS[$s]['label'] ?? $s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-auto">
          <label class="form-label small mb-1">Rok rejestru</label>
          <select name="rok" class="form-select form-select-sm">
            <option value="">— wszystkie —</option>
            <?php foreach ($years as $y): ?>
            <option value="<?= h($y) ?>" <?= $filters['rok'] === $y ? 'selected' : '' ?>><?= h($y) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-auto">
          <label class="form-label small mb-1">Opiekun</label>
          <input type="text" name="opiekun" class="form-control form-control-sm" style="max-width:11rem"
                 value="<?= h($filters['opiekun']) ?>" placeholder="Nazwisko…">
        </div>
        <div class="col-12 col-md-auto">
          <label class="form-label small mb-1">Data zawarcia</label>
          <div class="input-group input-group-sm">
            <span class="input-group-text">od</span>
            <input type="date" name="data_od" class="form-control" value="<?= h($filters['data_od']) ?>">
            <span class="input-group-text">do</span>
            <input type="date" name="data_do" class="form-control" value="<?= h($filters['data_do']) ?>">
          </div>
        </div>
        <div class="col-12 col-md-auto">
          <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtruj</button>
          <?php if ($any_filter): ?>
          <a href="<?= APP_URL ?>/contracts/rejestr.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i> Wyczyść</a>
          <?php endif; ?>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:3rem">Lp.</th>
            <th>Nr rejestru</th>
            <th>Typ umowy</th>
            <th>Strona umowy</th>
            <th>Opiekun</th>
            <th>Nr umowy</th>
            <th>Data zawarcia</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rejestr): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">
            Brak umów w rejestrze<?= $any_filter ? ' spełniających kryteria' : '' ?>.
          </td></tr>
          <?php endif; ?>
          <?php foreach ($rejestr as $i => $r): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td><code style="font-size:.82rem"><?= h($r['nr_rejestru']) ?></code></td>
            <td><i class="bi <?= h($_contract_icons[$r['contract_type']] ?? 'bi-file-text') ?> me-1 text-muted"></i><?= h($r['contract_type_label']) ?></td>
            <td><?= h($r['strona'] ?: '—') ?></td>
            <td><?= h($r['opiekun'] ?: '—') ?></td>
            <td><?= h($r['numer_umowy']) ?></td>
            <td><?= $r['data_zawarcia'] ? date('d.m.Y', strtotime($r['data_zawarcia'])) : '—' ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/contracts/<?= h($r['contract_type']) ?>/view.php?id=<?= (int)$r['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm" title="Podgląd umowy"><i class="bi bi-eye"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-footer py-2" style="background:#FAFAFA">
    <small class="text-muted">Znaleziono: <strong><?= count($rejestr) ?></strong> z <?= count($all) ?> umów w rejestrze<?= $any_filter ? ' — filtrowanie aktywne' : '' ?>.</small>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
