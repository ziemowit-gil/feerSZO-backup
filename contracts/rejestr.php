<?php
/**
 * Rejestr Umów (RU) — przeglądanie. Lista wszystkich umów (wszystkich typów)
 * z przydzielonym numerem rejestru, w kolejności rejestru, z filtrami
 * (typ / status / rok rejestru / opiekun / data zawarcia / szukajka).
 * Ręczna zmiana numeru, „Nowy numer” (przerejestrowanie: kolejny wolny numer porządkowy) i przenumerowanie
 * powtarzających się numerów (najstarsza umowa zachowuje numer) — registry_* w includes/functions.php, audit_log.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $uid  = (int)(current_user()['id'] ?? 0);
    $op   = (string)($_POST['_op'] ?? '');
    $slug = (string)($_POST['type'] ?? '');
    $cid  = (int)($_POST['id'] ?? 0);
    if ($op === 'set_nr') {
        $err = registry_set_number($slug, $cid, (string)($_POST['nr'] ?? ''), $uid);
        flash_set($err === '' ? 'success' : 'danger', $err === '' ? 'Zapisano numer rejestru.' : $err);
    } elseif ($op === 'renumber') {
        $nr = registry_renumber($slug, $cid, $uid);
        flash_set($nr !== '' ? 'success' : 'danger', $nr !== '' ? 'Nadano nowy numer: ' . $nr . '.' : 'Nie udało się nadać numeru.');
    } elseif ($op === 'renumber_dups') {
        $ch = registry_renumber_duplicates($uid);
        flash_set('success', $ch ? 'Przenumerowano ' . count($ch) . ' umów: ' . implode('; ', array_map(fn($c) => $c['from'] . ' → ' . $c['to'] . ' (' . $c['type'] . ($c['strona'] !== '' ? ', ' . $c['strona'] : '') . ')', $ch)) . '.' : 'Brak powtarzających się numerów.');
    }
    $back = (string)($_POST['back'] ?? '');
    header('Location: ' . APP_URL . '/contracts/rejestr.php' . (preg_match('/^[A-Za-z0-9_=&%.\-+]*$/', $back) && $back !== '' ? '?' . $back : '')); exit;
}

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
$dups     = registry_duplicates($all);                       // nr => wpisy (najstarszy pierwszy)
$dupKeys  = array_fill_keys(array_keys($dups), true);
$nextSeq  = registry_next_seq();

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

<?= flash_html() ?>
<?php if ($dups): $dupN = array_sum(array_map(fn($g) => count($g) - 1, $dups)); ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="status">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <span><strong><?= count($dups) ?></strong> <?= count($dups) === 1 ? 'numer się powtarza' : 'numery się powtarzają' ?> (<?= h(implode(', ', array_slice(array_keys($dups), 0, 6))) ?><?= count($dups) > 6 ? '…' : '' ?>).
    Przenumerowanie zostawi numer najstarszej umowie w grupie, a <?= $dupN ?> pozostałym nada kolejne wolne numery (od RU/<?= sprintf('%04d', $nextSeq) ?>).</span>
  <form method="post" class="ms-auto" onsubmit="return confirm('Przenumerować <?= $dupN ?> umów z powtarzającym się numerem? Zmiany zapiszą się w dzienniku zdarzeń.')">
    <?= csrf_field() ?><input type="hidden" name="_op" value="renumber_dups"><input type="hidden" name="back" value="<?= h($print_qs) ?>">
    <button class="btn btn-sm btn-warning"><i class="bi bi-sort-numeric-down me-1" aria-hidden="true"></i>Przenumeruj powtórzenia</button>
  </form>
</div>
<?php endif; ?>

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
            <?php foreach (CONTRACT_TYPES + array_map(fn($x) => $x['label'], CONTRACT_REGISTRY_EXTRA) as $k => $l): ?>
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
            <td class="text-nowrap">
              <code style="font-size:.82rem"><?= h($r['nr_rejestru']) ?></code>
              <?php if (isset($dupKeys[mb_strtoupper(trim((string)$r['nr_rejestru']))])): ?><span class="badge text-bg-warning ms-1" title="Ten numer ma więcej niż jedna umowa">powtórzony</span><?php endif; ?>
              <details class="d-inline-block ms-1 ru-edit">
                <summary class="btn btn-sm btn-link p-0 align-baseline" title="Zmień numer rejestru" aria-label="Zmień numer rejestru <?= h($r['nr_rejestru']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></summary>
                <div class="border rounded bg-white shadow-sm p-2 mt-1" style="position:absolute;z-index:5;min-width:280px">
                  <form method="post" class="d-flex gap-1 mb-1">
                    <?= csrf_field() ?><input type="hidden" name="_op" value="set_nr"><input type="hidden" name="type" value="<?= h($r['contract_type']) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h($print_qs) ?>">
                    <label class="visually-hidden" for="ru-<?= h($r['contract_type'] . $r['id']) ?>">Nowy numer rejestru</label>
                    <input class="form-control form-control-sm font-monospace" id="ru-<?= h($r['contract_type'] . $r['id']) ?>" name="nr" value="<?= h($r['nr_rejestru']) ?>" required pattern="RU/\d{1,6}/\d{4}/[A-Za-z0-9\-]{1,10}" title="RU/0001/2026/AB">
                    <button class="btn btn-sm btn-primary">Zapisz</button>
                  </form>
                  <form method="post" onsubmit="return confirm('Nadać umowie kolejny wolny numer (RU/<?= sprintf('%04d', $nextSeq) ?>/…)? Dotychczasowy numer przestanie ją oznaczać.')">
                    <?= csrf_field() ?><input type="hidden" name="_op" value="renumber"><input type="hidden" name="type" value="<?= h($r['contract_type']) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= h($print_qs) ?>">
                    <button class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Nowy numer (przerejestruj)</button>
                  </form>
                </div>
              </details>
            </td>
            <td><i class="bi <?= h($_contract_icons[$r['contract_type']] ?? 'bi-file-text') ?> me-1 text-muted"></i><?= h($r['contract_type_label']) ?></td>
            <td><?= h($r['strona'] ?: '—') ?></td>
            <td><?= h($r['opiekun'] ?: '—') ?></td>
            <td><?= h($r['numer_umowy']) ?></td>
            <td><?= $r['data_zawarcia'] ? date('d.m.Y', strtotime($r['data_zawarcia'])) : '—' ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="text-end">
              <a href="<?= h($r['url'] ?? (APP_URL . '/contracts/' . $r['contract_type'] . '/view.php?id=' . (int)$r['id'])) ?>" class="btn btn-xs btn-outline-secondary btn-sm" title="Podgląd umowy"><i class="bi bi-eye"></i></a>
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
