<?php
/**
 * Rejestr Umów (RU) — przeglądanie. Lista wszystkich umów (wszystkich typów)
 * z przydzielonym numerem rejestru, w kolejności rejestru.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }

$f_q   = trim($_GET['q']   ?? '');
$f_typ = trim($_GET['typ'] ?? '');

$rejestr = all_contracts_registry();

if ($f_typ !== '') {
    $rejestr = array_values(array_filter($rejestr, fn($r) => $r['contract_type'] === $f_typ));
}
if ($f_q !== '') {
    $needle = mb_strtolower($f_q);
    $rejestr = array_values(array_filter($rejestr, function ($r) use ($needle) {
        return str_contains(mb_strtolower((string)$r['nr_rejestru']), $needle)
            || str_contains(mb_strtolower((string)$r['numer_umowy']), $needle)
            || str_contains(mb_strtolower((string)($r['strona'] ?? '')), $needle)
            || str_contains(mb_strtolower((string)($r['opiekun'] ?? '')), $needle);
    }));
}

$print_qs = http_build_query(['q' => $f_q, 'typ' => $f_typ]);

$PAGE_TITLE = 'Rejestr umów (RU)';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-journal-text text-primary"></i> Rejestr umów (RU)</h4>
  <a href="<?= APP_URL ?>/contracts/rejestr_print.php?<?= h($print_qs) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-printer me-1"></i>Drukuj rejestr
  </a>
</div>

<!-- Filtry -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Typ umowy</label>
        <select name="typ" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (CONTRACT_TYPES as $k => $l): ?>
          <option value="<?= h($k) ?>" <?= $f_typ === $k ? 'selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1">Szukaj</label>
        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($f_q) ?>" placeholder="Nr rejestru, nr umowy, strona, opiekun…">
      </div>
      <div class="col-sm-auto">
        <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Filtruj</button>
        <?php if ($f_q !== '' || $f_typ !== ''): ?>
        <a href="<?= APP_URL ?>/contracts/rejestr.php" class="btn btn-sm btn-outline-secondary">Wyczyść</a>
        <?php endif; ?>
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
          <tr><td colspan="9" class="text-center text-muted py-4">Brak umów w rejestrze.</td></tr>
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
</div>
<div class="text-muted mt-2" style="font-size:.8rem">Łącznie: <?= count($rejestr) ?> <?= count($rejestr) === 1 ? 'umowa' : 'umów' ?> w rejestrze.</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
