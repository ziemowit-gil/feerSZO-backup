<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
rodo_auto_expire();
require_login();
require_role('admin', 'editor');

$f_status   = $_GET['status']   ?? '';
$f_q        = trim($_GET['q']   ?? '');
$f_type     = $_GET['type']     ?? '';
$f_training = $_GET['training'] ?? '';

$where  = ['1=1'];
$params = [];

if ($f_status && in_array($f_status, ['aktywne','cofnięte','wygasłe'], true)) {
    $where[]  = 'status = ?'; $params[] = $f_status;
}
if ($f_q) {
    $where[]  = '(person_name LIKE ? OR number LIKE ? OR contract_number LIKE ?)';
    $like     = '%' . $f_q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($f_type) {
    $where[]  = 'contract_type = ?'; $params[] = $f_type;
}
if ($f_training === '0') {
    $where[] = 'training_done = 0';
} elseif ($f_training === '1') {
    $where[] = 'training_done = 1';
}

$where_sql = implode(' AND ', $where);
$rows = db_all(
    "SELECT r.*,
        (SELECT COUNT(*) FROM rodo_trainings t WHERE t.authorization_id = r.id) AS training_count
     FROM rodo_authorizations r
     WHERE {$where_sql}
     ORDER BY r.created_at DESC",
    $params
);

// Liczniki
$cnt_all    = (int)(db_one("SELECT COUNT(*) AS c FROM rodo_authorizations")['c'] ?? 0);
$cnt_active = (int)(db_one("SELECT COUNT(*) AS c FROM rodo_authorizations WHERE status='aktywne'")['c'] ?? 0);
$cnt_no_trn = (int)(db_one("SELECT COUNT(*) AS c FROM rodo_authorizations WHERE training_done=0 AND status='aktywne'")['c'] ?? 0);

$PAGE_TITLE = 'Rejestr upoważnień RODO';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-shield-lock text-primary me-2"></i>Rejestr upoważnień RODO</h4>
    <div class="text-muted small">art. 5 ust. 2 RODO — zasada rozliczalności</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/rodo/new.php" class="btn btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowe upoważnienie
    </a>
    <a href="<?= APP_URL ?>/admin/rodo_settings.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-gear me-1"></i>Ustawienia RODO
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- Statystyki -->
<div class="row g-2 mb-3">
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm text-center py-2">
      <div class="fw-bold fs-4"><?= $cnt_all ?></div>
      <div class="small text-muted">Łącznie w rejestrze</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm text-center py-2">
      <div class="fw-bold fs-4 text-success"><?= $cnt_active ?></div>
      <div class="small text-muted">Aktywnych</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card border-0 shadow-sm text-center py-2 <?= $cnt_no_trn ? 'border-warning border-opacity-50' : '' ?>">
      <div class="fw-bold fs-4 <?= $cnt_no_trn ? 'text-warning' : 'text-success' ?>"><?= $cnt_no_trn ?></div>
      <div class="small text-muted">Bez szkolenia</div>
    </div>
  </div>
</div>

<!-- Filtry -->
<form method="get" class="row g-2 mb-3">
  <div class="col-sm-4 col-md-3">
    <input name="q" class="form-control form-control-sm" placeholder="Szukaj osoby, numeru…" value="<?= h($f_q) ?>">
  </div>
  <div class="col-sm-3 col-md-2">
    <select name="status" class="form-select form-select-sm">
      <option value="">Wszystkie statusy</option>
      <option value="aktywne"  <?= $f_status==='aktywne'  ?'selected':'' ?>>Aktywne</option>
      <option value="cofnięte" <?= $f_status==='cofnięte' ?'selected':'' ?>>Cofnięte</option>
      <option value="wygasłe"  <?= $f_status==='wygasłe'  ?'selected':'' ?>>Wygasłe</option>
    </select>
  </div>
  <div class="col-sm-3 col-md-2">
    <select name="type" class="form-select form-select-sm">
      <option value="">Wszystkie typy</option>
      <option value="wolontariat" <?= $f_type==='wolontariat'?'selected':'' ?>>Wolontariat</option>
      <option value="zlecenie"    <?= $f_type==='zlecenie'   ?'selected':'' ?>>Zlecenie</option>
      <option value="praca"       <?= $f_type==='praca'      ?'selected':'' ?>>Praca</option>
    </select>
  </div>
  <div class="col-sm-3 col-md-2">
    <select name="training" class="form-select form-select-sm">
      <option value="">Szkolenie: wszystkie</option>
      <option value="0" <?= $f_training==='0'?'selected':'' ?>>Brak szkolenia</option>
      <option value="1" <?= $f_training==='1'?'selected':'' ?>>Po szkoleniu</option>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel me-1"></i>Filtruj</button>
    <?php if ($f_q||$f_status||$f_type||$f_training): ?>
    <a href="?" class="btn btn-sm btn-link text-muted">Wyczyść</a>
    <?php endif; ?>
  </div>
</form>

<!-- Tabela -->
<?php if (!$rows): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-shield-check" style="font-size:2.5rem"></i>
  <div class="mt-2">Brak upoważnień pasujących do filtrów</div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.86rem">
      <thead class="table-light">
        <tr>
          <th>Numer</th>
          <th>Osoba upoważniona</th>
          <th>PESEL</th>
          <th>Typ</th>
          <th>Umowa</th>
          <th>Od</th>
          <th>Status</th>
          <th>Szkolenie</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['status'] !== 'aktywne' ? 'text-muted' : '' ?>">
          <td class="font-monospace" style="font-size:.8rem;white-space:nowrap"><?= h($r['number']) ?></td>
          <td class="fw-semibold"><?= h($r['person_name']) ?></td>
          <td class="font-monospace small text-muted">
            <?= $r['person_pesel'] ? substr($r['person_pesel'],0,2).'·····'.substr($r['person_pesel'],7) : '—' ?>
          </td>
          <td class="small">
            <span class="badge bg-light text-dark border"><?= h($r['contract_type']) ?></span>
          </td>
          <td class="small text-muted"><?= h($r['contract_number'] ?: '—') ?></td>
          <td class="small text-nowrap"><?= $r['authorized_from'] ? date('d.m.Y', strtotime($r['authorized_from'])) : '—' ?></td>
          <td><?= rodo_status_badge($r['status']) ?></td>
          <td class="text-center">
            <?php if ($r['training_done']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle">
              <i class="bi bi-check-circle me-1"></i>Tak
            </span>
            <?php else: ?>
            <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
              <i class="bi bi-exclamation-circle me-1"></i>Brak
            </span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a href="<?= APP_URL ?>/rodo/view.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 me-1">
              <i class="bi bi-eye"></i>
            </a>
            <a href="<?= APP_URL ?>/rodo/print.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
              <i class="bi bi-printer"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="text-muted small mt-2 text-end"><?= count($rows) ?> rekordów</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
