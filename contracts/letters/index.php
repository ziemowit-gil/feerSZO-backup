<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';

require_login();
if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }
require_module_enabled('letters_enabled', 'Moduł pism');

$f_kierunek = $_GET['kierunek']      ?? '';
$f_typ      = $_GET['typ']           ?? '';
$f_ctype    = $_GET['contract_type'] ?? '';

$letters = get_all_letters($f_kierunek, $f_typ, $f_ctype);

// Pobierz numery umów (cache)
$nr_cache = [];
function letter_contract_nr(string $type, int $id): string {
    global $nr_cache;
    $k = "{$type}:{$id}";
    if (!isset($nr_cache[$k])) {
        try {
            $r = db_one("SELECT numer_umowy FROM " . table_for_type($type) . " WHERE id=?", [$id]);
            $nr_cache[$k] = $r['numer_umowy'] ?? "#{$id}";
        } catch (\Exception $e) { $nr_cache[$k] = "#{$id}"; }
    }
    return $nr_cache[$k];
}

$PAGE_TITLE = 'Pisma umów';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-envelope-paper text-primary"></i> Pisma umów</h4>
</div>

<!-- Filtry -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Kierunek</label>
        <select name="kierunek" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (LETTER_DIRECTIONS as $k => $d): ?>
          <option value="<?= h($k) ?>" <?= $f_kierunek === $k ? 'selected' : '' ?>><?= h($d['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Typ pisma</label>
        <select name="typ" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (LETTER_TYPES as $k => $t): ?>
          <option value="<?= h($k) ?>" <?= $f_typ === $k ? 'selected' : '' ?>><?= h($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Typ umowy</label>
        <select name="contract_type" class="form-select form-select-sm">
          <option value="">Wszystkie</option>
          <?php foreach (CONTRACT_TYPES as $k => $l): ?>
          <option value="<?= h($k) ?>" <?= $f_ctype === $k ? 'selected' : '' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-auto">
        <button type="submit" class="btn btn-sm btn-primary">
          <i class="bi bi-funnel"></i> Filtruj
        </button>
        <a href="<?= APP_URL ?>/contracts/letters/index.php" class="btn btn-sm btn-outline-secondary ms-1">Resetuj</a>
      </div>
    </form>
  </div>
</div>

<?php if (!$letters): ?>
<div class="card shadow-sm">
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-envelope-slash fs-1 d-block mb-2 opacity-25"></i>
    Brak pism spełniających kryteria filtra.
  </div>
</div>
<?php else: ?>

<div class="card shadow-sm">
  <div class="d-flex align-items-center px-3 py-2 border-bottom">
    <span class="text-muted small">Łącznie: <strong><?= count($letters) ?></strong> pism</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Kierunek</th>
          <th>Typ</th>
          <th>Tytuł</th>
          <th>Umowa</th>
          <th>Data pisma</th>
          <th>Nadawca / Odbiorca</th>
          <th>Dodał</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($letters as $l): ?>
      <tr>
        <td><?= letter_direction_badge($l['kierunek']) ?></td>
        <td><?= letter_type_badge($l['typ_pisma']) ?></td>
        <td>
          <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $l['id'] ?>"
             class="text-decoration-none fw-semibold">
            <?= h($l['tytul']) ?>
          </a>
          <?php if ($l['email_sent']): ?>
          <i class="bi bi-envelope-check text-success ms-1" title="E-mail wysłany"></i>
          <?php endif; ?>
          <?php if ($l['plik']): ?>
          <i class="bi bi-paperclip text-muted ms-1" title="Plik załączony"></i>
          <?php endif; ?>
        </td>
        <td>
          <a href="<?= h(contract_url($l['contract_type'], $l['contract_id'])) ?>"
             class="text-decoration-none small">
            <?= h(letter_contract_nr($l['contract_type'], $l['contract_id'])) ?>
          </a>
          <div class="text-muted" style="font-size:.75rem"><?= h(CONTRACT_TYPES[$l['contract_type']] ?? $l['contract_type']) ?></div>
        </td>
        <td class="text-nowrap small"><?= date_pl($l['data_pisma']) ?></td>
        <td class="small">
          <?php if ($l['kierunek'] === 'wychodzące'): ?>
          <span class="text-muted">Do:</span> <?= h($l['odbiorca'] ?: '—') ?>
          <?php elseif ($l['kierunek'] === 'przychodzące'): ?>
          <span class="text-muted">Od:</span> <?= h($l['nadawca'] ?: '—') ?>
          <?php else: ?>
          <?= h($l['nadawca'] ?: $l['odbiorca'] ?: '—') ?>
          <?php endif; ?>
        </td>
        <td class="small text-muted"><?= h($l['created_by_name'] ?? '—') ?></td>
        <td class="text-end text-nowrap">
          <a href="<?= APP_URL ?>/contracts/letters/view.php?id=<?= $l['id'] ?>"
             class="btn btn-sm btn-outline-secondary" title="Podgląd / druk">
            <i class="bi bi-eye"></i>
          </a>
          <?php if ($l['plik']): ?>
          <a href="<?= h(letter_file_url($l['plik'])) ?>" download
             class="btn btn-sm btn-outline-primary" title="Pobierz plik">
            <i class="bi bi-download"></i>
          </a>
          <?php endif; ?>
          <?php if (is_admin() || (can_edit() && (int)($l['created_by'] ?? 0) === (int)current_user()['id'])): ?>
          <?= delete_btn('contract_letters', (int)$l['id'], $l['tytul'] ?? '#'.$l['id']) ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
