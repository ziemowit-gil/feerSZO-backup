<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/amendments.php';
require_once dirname(__DIR__) . '/includes/letters.php';

require_login();
$PAGE_TITLE = 'Moje pisma';
$user = current_user();

$_db_user = db_one("SELECT microsoft_id FROM users WHERE id = ?", [$user['id']]);
$user['microsoft_id'] = $_db_user['microsoft_id'] ?? '';

// ── Pobierz umowy użytkownika (ta sama logika co panel/index.php) ─────────────
function panel_contracts_letters(array $user): array {
    $email = $user['email'] ?? '';
    $ms_id = $user['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return [];
    $results = [];
    $tables = [
        ['zlecenie',    'imie_nazwisko', ['m365_user_id', 'm365_login'],           'data_zakonczenia'],
        ['wolontariat', 'imie_nazwisko', ['m365_user_id', 'm365_login', 'email'],  'data_zakonczenia'],
        ['dzielo',      'imie_nazwisko', ['m365_user_id', 'm365_login'],           'termin_oddania'],
        ['praca',       'imie_nazwisko', ['email_login'],                          'data_zakonczenia'],
    ];
    foreach ($tables as [$type, $name_col, $fields, $end_col]) {
        $conds = []; $params = [];
        foreach ($fields as $f) {
            if ($f === 'm365_user_id' && !$ms_id) continue;
            if (($f === 'email' || $f === 'm365_login') && !$email) continue;
            $conds[]  = "{$f} = ?";
            $params[] = ($f === 'm365_user_id') ? $ms_id : $email;
        }
        if (!$conds) continue;
        $rows = db_all(
            "SELECT id, '{$type}' AS contract_type, numer_umowy, status
             FROM   umowy_{$type} WHERE (" . implode(' OR ', $conds) . ")",
            $params
        );
        $results = array_merge($results, $rows);
    }
    $seen = [];
    return array_values(array_filter($results, function ($r) use (&$seen) {
        $k = $r['contract_type'] . ':' . $r['id'];
        if (isset($seen[$k])) return false;
        return $seen[$k] = true;
    }));
}

$contracts = panel_contracts_letters($user);

// ── Pobierz wszystkie pisma dla umów użytkownika ──────────────────────────────
$filter_kierunek = $_GET['kierunek'] ?? '';
$filter_typ      = $_GET['typ'] ?? '';

$all_letters = [];
foreach ($contracts as $c) {
    $letters = get_contract_letters($c['contract_type'], $c['id']);
    foreach ($letters as $l) {
        $l['_contract_nr']   = $c['numer_umowy'];
        $l['_contract_status'] = $c['status'];
        $all_letters[] = $l;
    }
}

// Deduplicate by letter id (a letter can only belong to one contract)
$seen_ids = [];
$all_letters = array_values(array_filter($all_letters, function ($l) use (&$seen_ids) {
    if (isset($seen_ids[$l['id']])) return false;
    return $seen_ids[$l['id']] = true;
}));

// Sort: newest first
usort($all_letters, fn($a, $b) =>
    strcmp($b['data_pisma'] . $b['id'], $a['data_pisma'] . $a['id'])
);

// Apply filters
if ($filter_kierunek) {
    $all_letters = array_values(array_filter($all_letters, fn($l) => $l['kierunek'] === $filter_kierunek));
}
if ($filter_typ) {
    $all_letters = array_values(array_filter($all_letters, fn($l) => $l['typ_pisma'] === $filter_typ));
}

// Counts for filter tabs
$total_count = count($all_letters);

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>

<div class="pv-page-header d-flex gap-2 flex-wrap">
  <h1 class="pv-page-title"><i class="bi bi-archive me-2" aria-hidden="true"></i>Moje pisma</h1>
  <p class="pv-page-sub">Historia pism i wniosków</p>
</div>

<?= flash_html() ?>

<?php if (!$contracts): ?>
<div class="vol-detail-card">
  <div class="vol-detail-header"><i class="bi bi-envelope-x me-2"></i>Brak umów</div>
  <div class="vol-detail-body text-center py-4 text-muted">
    <i class="bi bi-envelope-x fs-1 d-block mb-2 opacity-25"></i>
    <p class="mb-1">Nie znaleziono umów powiązanych z Twoim kontem.</p>
    <p class="small mb-0">Poproś administratora o powiązanie umowy z Twoim kontem Microsoft 365.</p>
  </div>
</div>
<?php else: ?>

<div class="vol-detail-card mb-4">
  <div class="vol-detail-header"><i class="bi bi-funnel me-2"></i>Filtry</div>
  <div class="vol-detail-body">
    <form method="get" class="d-flex align-items-end gap-3 flex-wrap">
      <div>
        <label class="form-label small mb-1 fw-semibold">Typ pisma</label>
        <select name="typ" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">Wszystkie typy</option>
          <?php foreach (LETTER_TYPES as $k => $t): ?>
          <option value="<?= h($k) ?>"<?= $filter_typ === $k ? ' selected' : '' ?>><?= h($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($filter_kierunek || $filter_typ): ?>
      <a href="<?= APP_URL ?>/panel/letters.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-x-lg"></i> Wyczyść
      </a>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if (!$all_letters): ?>
<div class="vol-detail-card">
  <div class="vol-detail-header"><i class="bi bi-envelope-open me-2"></i>Pisma</div>
  <div class="vol-detail-body text-center py-4 text-muted">
    <i class="bi bi-envelope-open fs-1 d-block mb-2 opacity-25"></i>
    <p class="mb-0">Brak pism<?= $filter_kierunek || $filter_typ ? ' dla wybranych filtrów' : '' ?>.</p>
  </div>
</div>
<?php else: ?>

<div class="vol-detail-card">
  <div class="vol-detail-header">
    <i class="bi bi-envelope-paper me-2" aria-hidden="true"></i>Lista pism
    <span class="badge bg-secondary ms-auto"><?= $total_count ?></span>
  </div>
  <?php foreach ($all_letters as $l):
      $dir_meta   = LETTER_DIRECTIONS[$l['kierunek']] ?? ['label' => $l['kierunek'], 'class' => 'secondary', 'icon' => 'bi-arrow-right'];
  ?>
  <div class="vol-activity-row">
    <div class="vol-activity-icon" style="background:var(--vol-bg);color:var(--vol-color)">
      <i class="bi bi-envelope-fill" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold text-truncate" style="font-size:.85rem;max-width:200px" title="<?= h($l['tytul']) ?>"><?= h($l['tytul']) ?></span>
        <?= letter_direction_badge($l['kierunek']) ?>
      </div>
      <div class="text-muted" style="font-size:.78rem">
        <?= h($l['_contract_nr']) ?>
        <?php if ($l['typ_pisma']): ?> · <?= letter_type_badge($l['typ_pisma']) ?><?php endif; ?>
      </div>
    </div>
    <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
      <span class="text-muted text-nowrap" style="font-size:.77rem"><?= h($l['data_pisma']) ?></span>
      <div class="d-flex gap-1">
        <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $l['id'] ?>"
           class="btn btn-sm btn-outline-primary py-0 px-2" title="Podgląd" target="_blank">
          <i class="bi bi-eye" aria-hidden="true"></i>
        </a>
        <?php if ($l['plik']): ?>
        <a href="<?= h(letter_file_url($l['plik'])) ?>"
           class="btn btn-sm btn-outline-secondary py-0 px-2" title="Pobierz plik" download target="_blank">
          <i class="bi bi-paperclip" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>
<?php endif; ?>

<?php else: /* !$_is_volunteer_only — admin/editor layout */ ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;flex-shrink:0">
    <i class="bi bi-envelope-paper text-primary fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Moje pisma</h4>
    <div class="text-muted small">Korespondencja powiązana z Twoimi umowami</div>
  </div>
</div>

<?= flash_html() ?>

<?php if (!$contracts): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-envelope-x fs-1 d-block mb-2 opacity-25"></i>
    <p class="mb-1">Nie znaleziono umów powiązanych z Twoim kontem.</p>
    <p class="small">Poproś administratora o powiązanie umowy z Twoim kontem Microsoft 365.</p>
  </div>
</div>
<?php else: ?>

<!-- ── Filtry ─────────────────────────────────────────────────────────────── -->
<form method="get" class="card shadow-sm mb-4">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small mb-1 fw-semibold">Typ pisma</label>
        <select name="typ" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">Wszystkie typy</option>
          <?php foreach (LETTER_TYPES as $k => $t): ?>
          <option value="<?= h($k) ?>"<?= $filter_typ === $k ? ' selected' : '' ?>>
            <?= h($t['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4 d-flex align-items-end gap-2">
        <?php if ($filter_kierunek || $filter_typ): ?>
        <a href="<?= APP_URL ?>/panel/letters.php" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-x-lg"></i> Wyczyść
        </a>
        <?php endif; ?>
        <span class="text-muted small ms-auto">
        </span>
      </div>
    </div>
  </div>
</form>

<!-- ── Lista pism ─────────────────────────────────────────────────────────── -->
<?php if (!$all_letters): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-envelope-open fs-1 d-block mb-2 opacity-25"></i>
    <p class="mb-0">Brak pism<?= $filter_kierunek || $filter_typ ? ' dla wybranych filtrów' : '' ?>.</p>
  </div>
</div>
<?php else: ?>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="px-3">Kierunek</th>
          <th>Typ</th>
          <th>Tytuł</th>
          <th>Data</th>
          <th>Umowa</th>
          <th>Strona</th>
          <th class="text-end px-3">Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($all_letters as $l):
          $dir_meta  = LETTER_DIRECTIONS[$l['kierunek']] ?? ['label' => $l['kierunek'], 'class' => 'secondary', 'icon' => 'bi-arrow-right'];
          $side_label = ($l['kierunek'] === 'wychodzące') ? $l['odbiorca'] : $l['nadawca'];
      ?>
        <tr>
          <td class="px-3"><?= letter_direction_badge($l['kierunek']) ?></td>
          <td><?= letter_type_badge($l['typ_pisma']) ?></td>
          <td class="fw-semibold" style="max-width:240px">
            <span class="text-truncate d-block" title="<?= h($l['tytul']) ?>"><?= h($l['tytul']) ?></span>
          </td>
          <td class="text-muted small text-nowrap"><?= h($l['data_pisma']) ?></td>
          <td class="text-nowrap">
            <a href="<?= APP_URL ?>/contracts/<?= h($l['contract_type']) ?>/view.php?id=<?= $l['contract_id'] ?>"
               class="text-decoration-none small fw-semibold">
              <?= h($l['_contract_nr']) ?>
            </a>
          </td>
          <td class="text-muted small" style="max-width:160px">
            <?php if ($side_label): ?>
            <span class="text-truncate d-block" title="<?= h($side_label) ?>"><?= h($side_label) ?></span>
            <?php else: ?>&mdash;<?php endif; ?>
          </td>
          <td class="text-end px-3">
            <div class="d-flex justify-content-end gap-1">
              <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $l['id'] ?>"
                 class="btn btn-sm btn-outline-primary" title="Podgląd" target="_blank">
                <i class="bi bi-eye"></i>
              </a>
              <?php if ($l['plik']): ?>
              <a href="<?= h(letter_file_url($l['plik'])) ?>"
                 class="btn btn-sm btn-outline-secondary" title="Pobierz plik"
                 download target="_blank">
                <i class="bi bi-paperclip"></i>
              </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>
<?php endif; ?>

<?php endif; /* $_is_volunteer_only */ ?>

<?php if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
} ?>
