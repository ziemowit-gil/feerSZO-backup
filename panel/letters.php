<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/amendments.php';
require_once dirname(__DIR__) . '/includes/letters.php';

require_login();
panel_require_enabled('pisma', 'Pisma');
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

<div class="pv-wrap">

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-envelope-open" aria-hidden="true"></i> Pisma</h1>
    <p class="pv-page-sub">Korespondencja przychodząca i wychodząca</p>
  </div>
</div>

<?= flash_html() ?>

<?php if (!$contracts): ?>

<div class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-envelope-x me-2" aria-hidden="true"></i>Brak umów</div>
  <div class="tz-card__bd">
    <div class="tz-empty">
      <div class="tz-empty__icon"><i class="bi bi-envelope-x" aria-hidden="true"></i></div>
      <p class="tz-empty__text">Nie znaleziono umów powiązanych z Twoim kontem.<br>
        Poproś administratora o powiązanie umowy z Twoim kontem Microsoft 365.
      </p>
    </div>
  </div>
</div>

<?php else: ?>

<!-- ── Filtry ─────────────────────────────────────────────────────────────── -->
<div class="tz-card mb-4">
  <div class="tz-card__hd"><i class="bi bi-funnel me-2" aria-hidden="true"></i>Filtry</div>
  <div class="tz-card__bd">
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
      <a href="<?= APP_URL ?>/panel/letters.php" class="tz-btn tz-btn--ghost">
        <i class="bi bi-x-lg" aria-hidden="true"></i> Wyczyść
      </a>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- ── Lista pism ─────────────────────────────────────────────────────────── -->
<?php if (!$all_letters): ?>

<div class="tz-card">
  <div class="tz-card__hd"><i class="bi bi-envelope-open me-2" aria-hidden="true"></i>Pisma</div>
  <div class="tz-card__bd">
    <div class="tz-empty">
      <div class="tz-empty__icon"><i class="bi bi-envelope-open" aria-hidden="true"></i></div>
      <p class="tz-empty__text">Brak pism<?= $filter_kierunek || $filter_typ ? ' dla wybranych filtrów' : '' ?>.</p>
    </div>
  </div>
</div>

<?php elseif ($_is_volunteer_only): ?>

<div class="tz-card">
  <div class="tz-card__hd">
    <i class="bi bi-envelope-paper me-2" aria-hidden="true"></i>Lista pism
    <span class="tz-badge ms-auto"><?= $total_count ?></span>
  </div>
  <?php foreach ($all_letters as $l):
      $dir_meta = LETTER_DIRECTIONS[$l['kierunek']] ?? ['label' => $l['kierunek'], 'class' => 'secondary', 'icon' => 'bi-arrow-right'];
  ?>
  <div class="vol-activity-row">
    <div class="vol-activity-icon">
      <i class="bi bi-envelope-fill" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold text-truncate" title="<?= h($l['tytul']) ?>"><?= h($l['tytul']) ?></span>
        <?= letter_direction_badge($l['kierunek']) ?>
      </div>
      <div class="text-muted small">
        <?= h($l['_contract_nr']) ?>
        <?php if ($l['typ_pisma']): ?> · <?= letter_type_badge($l['typ_pisma']) ?><?php endif; ?>
      </div>
    </div>
    <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
      <span class="text-muted text-nowrap small"><?= h($l['data_pisma']) ?></span>
      <div class="d-flex gap-1">
        <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $l['id'] ?>"
           class="tz-btn tz-btn--ghost tz-btn--sm"
           aria-label="Podgląd pisma: <?= h($l['tytul']) ?>"
           target="_blank">
          <i class="bi bi-eye" aria-hidden="true"></i>
        </a>
        <?php if ($l['plik']): ?>
        <a href="<?= h(letter_file_url($l['plik'])) ?>"
           class="tz-btn tz-btn--ghost tz-btn--sm"
           aria-label="Pobierz plik: <?= h($l['tytul']) ?>"
           download target="_blank">
          <i class="bi bi-paperclip" aria-hidden="true"></i>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php else: /* admin/editor — widok tabeli */ ?>

<div class="tz-card">
  <div class="pv-table-wrap">
    <table class="pv-table">
      <thead>
        <tr>
          <th>Kierunek</th>
          <th>Typ</th>
          <th>Tytuł</th>
          <th>Data</th>
          <th>Umowa</th>
          <th>Strona</th>
          <th>Akcje</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($all_letters as $l):
          $dir_meta   = LETTER_DIRECTIONS[$l['kierunek']] ?? ['label' => $l['kierunek'], 'class' => 'secondary', 'icon' => 'bi-arrow-right'];
          $side_label = ($l['kierunek'] === 'wychodzące') ? $l['odbiorca'] : $l['nadawca'];
      ?>
        <tr>
          <td><?= letter_direction_badge($l['kierunek']) ?></td>
          <td><?= letter_type_badge($l['typ_pisma']) ?></td>
          <td class="fw-semibold">
            <span class="text-truncate d-block" title="<?= h($l['tytul']) ?>"><?= h($l['tytul']) ?></span>
          </td>
          <td class="text-muted small text-nowrap"><?= h($l['data_pisma']) ?></td>
          <td class="text-nowrap">
            <a href="<?= APP_URL ?>/contracts/<?= h($l['contract_type']) ?>/view.php?id=<?= $l['contract_id'] ?>"
               class="text-decoration-none small fw-semibold">
              <?= h($l['_contract_nr']) ?>
            </a>
          </td>
          <td class="text-muted small">
            <?php if ($side_label): ?>
            <span class="text-truncate d-block" title="<?= h($side_label) ?>"><?= h($side_label) ?></span>
            <?php else: ?>&mdash;<?php endif; ?>
          </td>
          <td>
            <div class="d-flex justify-content-end gap-1">
              <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $l['id'] ?>"
                 class="tz-btn tz-btn--ghost tz-btn--sm"
                 aria-label="Podgląd pisma: <?= h($l['tytul']) ?>"
                 target="_blank">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </a>
              <?php if ($l['plik']): ?>
              <a href="<?= h(letter_file_url($l['plik'])) ?>"
                 class="tz-btn tz-btn--ghost tz-btn--sm"
                 aria-label="Pobierz plik: <?= h($l['tytul']) ?>"
                 download target="_blank">
                <i class="bi bi-paperclip" aria-hidden="true"></i>
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

<?php endif; /* !$all_letters / volunteer / admin */ ?>
<?php endif; /* !$contracts */ ?>

</div><!-- /pv-wrap -->

<?php if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
} ?>
