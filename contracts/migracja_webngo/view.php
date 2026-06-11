<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once __DIR__ . '/_table.php';

require_login();
migracja_webngo_ensure_table();

$id  = intval($_GET['id'] ?? 0);
$row = db_one("SELECT * FROM umowy_migracja_webngo WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); die('Nie znaleziono rekordu migracji.'); }

$PAGE_TITLE = 'Migracja ' . $row['numer_umowy'];

// ── Zmiana statusu ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_edit()) {
    csrf_check();
    if (isset($_POST['_set_status'])) {
        $ns = $_POST['status'] ?? '';
        if (array_key_exists($ns, MIGRACJA_STATUSY)) {
            db_update('umowy_migracja_webngo', [
                'status'     => $ns,
                'updated_at' => date('Y-m-d H:i:s'),
            ], $id);
            log_contract_action('migracja_webngo', $id, current_user()['id'], 'status_change',
                'Status: ' . $row['status'] . ' → ' . $ns);
        }
    }
    if (isset($_POST['_set_nowa_umowa'])) {
        db_update('umowy_migracja_webngo', [
            'nowy_typ_umowy'   => trim($_POST['nowy_typ_umowy'] ?? ''),
            'nowy_numer_umowy' => trim($_POST['nowy_numer_umowy'] ?? ''),
            'updated_at'       => date('Y-m-d H:i:s'),
        ], $id);
        flash_set('success', 'Dane nowej umowy zaktualizowane.');
    }
    header('Location: view.php?id=' . $id); exit;
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<?= flash_html() ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0">
      <i class="bi bi-arrow-left-right text-warning"></i>
      Migracja <?= h($row['numer_umowy']) ?>
    </h4>
    <small class="text-muted">
      webNGO: <strong><?= h($row['webngo_numer_umowy']) ?></strong>
      &nbsp;·&nbsp; <?= h(MIGRACJA_TYPY[$row['typ_umowy_zrodla']] ?? $row['typ_umowy_zrodla']) ?>
    </small>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="print.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary">
      <i class="bi bi-printer"></i> Drukuj potwierdzenie
    </a>
    <?php if (can_edit()): ?>
    <a href="add.php" class="btn btn-outline-warning btn-sm">
      <i class="bi bi-plus"></i> Nowa
    </a>
    <?php endif; ?>
    <a href="list.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> Lista
    </a>
  </div>
</div>

<!-- Status + zmiana -->
<div class="d-flex align-items-center gap-3 mb-3">
  <?= migracja_status_badge($row['status']) ?>
  <?php if (can_edit()): ?>
  <form method="post" class="d-flex gap-2 align-items-center">
    <?= csrf_field() ?>
    <input type="hidden" name="_set_status" value="1">
    <select name="status" class="form-select form-select-sm" style="width:auto">
      <?php foreach (MIGRACJA_STATUSY as $k => $d): ?>
      <option value="<?= h($k) ?>" <?= $row['status'] === $k ? 'selected' : '' ?>><?= h($d['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-secondary">Zmień</button>
  </form>
  <?php endif; ?>
</div>

<div class="row g-3">

  <!-- ── Umowa źródłowa ──────────────────────────────────────────────── -->
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-warning bg-opacity-10 fw-semibold">
        <i class="bi bi-database-down text-warning"></i> Umowa w webNGO
      </div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-5 text-muted">Typ umowy</dt>
          <dd class="col-7"><?= h(MIGRACJA_TYPY[$row['typ_umowy_zrodla']] ?? $row['typ_umowy_zrodla']) ?></dd>

          <dt class="col-5 text-muted">Numer w webNGO</dt>
          <dd class="col-7 fw-semibold font-monospace"><?= h($row['webngo_numer_umowy']) ?></dd>

          <?php if ($row['webngo_id']): ?>
          <dt class="col-5 text-muted">ID webNGO</dt>
          <dd class="col-7 font-monospace"><?= h($row['webngo_id']) ?></dd>
          <?php endif; ?>

          <?php if ($row['webngo_data_zawarcia']): ?>
          <dt class="col-5 text-muted">Data zawarcia</dt>
          <dd class="col-7"><?= date_pl($row['webngo_data_zawarcia']) ?></dd>
          <?php endif; ?>

          <dt class="col-5 text-muted">Imię i nazwisko</dt>
          <dd class="col-7"><?= h($row['imie_nazwisko'] ?? '—') ?></dd>

          <?php if ($row['pesel']): ?>
          <dt class="col-5 text-muted">PESEL</dt>
          <dd class="col-7 font-monospace"><?= h($row['pesel']) ?></dd>
          <?php endif; ?>

          <?php if ($row['email']): ?>
          <dt class="col-5 text-muted">E-mail</dt>
          <dd class="col-7"><?= h($row['email']) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

  <!-- ── Nowa umowa ──────────────────────────────────────────────────── -->
  <div class="col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header bg-success bg-opacity-10 fw-semibold">
        <i class="bi bi-database-up text-success"></i> Nowa umowa w FEER SZO
      </div>
      <div class="card-body">
        <?php if ($row['nowy_numer_umowy']): ?>
        <dl class="row mb-3 small">
          <dt class="col-5 text-muted">Typ</dt>
          <dd class="col-7"><?= h(MIGRACJA_TYPY[$row['nowy_typ_umowy']] ?? ($row['nowy_typ_umowy'] ?: '—')) ?></dd>
          <dt class="col-5 text-muted">Numer</dt>
          <dd class="col-7 fw-semibold">
            <?php if ($row['nowy_typ_umowy']): ?>
            <a href="<?= APP_URL ?>/contracts/<?= h($row['nowy_typ_umowy']) ?>/list.php">
              <?= h($row['nowy_numer_umowy']) ?>
            </a>
            <?php else: ?>
            <?= h($row['nowy_numer_umowy']) ?>
            <?php endif; ?>
          </dd>
        </dl>
        <?php else: ?>
        <p class="text-muted small mb-2">Nowa umowa nie została jeszcze wskazana.</p>
        <?php endif; ?>

        <?php if (can_edit()): ?>
        <form method="post" class="row g-2">
          <?= csrf_field() ?>
          <input type="hidden" name="_set_nowa_umowa" value="1">
          <div class="col-5">
            <select name="nowy_typ_umowy" class="form-select form-select-sm">
              <option value="">— typ —</option>
              <?php foreach (MIGRACJA_TYPY as $k => $v): ?>
              <option value="<?= h($k) ?>" <?= ($row['nowy_typ_umowy'] ?? '') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-5">
            <input type="text" name="nowy_numer_umowy" class="form-control form-control-sm"
                   value="<?= h($row['nowy_numer_umowy'] ?? '') ?>" placeholder="Numer umowy">
          </div>
          <div class="col-2">
            <button class="btn btn-sm btn-outline-success w-100"><i class="bi bi-check"></i></button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Uzasadnienie migracji ───────────────────────────────────────── -->
  <div class="col-12">
    <div class="card shadow-sm border-danger border-opacity-25">
      <div class="card-header bg-danger bg-opacity-10 fw-semibold">
        <i class="bi bi-exclamation-circle text-danger"></i> Uzasadnienie migracji
      </div>
      <div class="card-body">
        <div class="mb-2">
          <span class="badge bg-secondary">
            <?= h(MIGRACJA_POWODY[$row['powod_migracji']] ?? $row['powod_migracji']) ?>
          </span>
        </div>
        <blockquote class="blockquote-sm border-start border-3 border-danger ps-3 mb-0">
          <p class="mb-0" style="white-space:pre-wrap"><?= h($row['opis_powodu']) ?></p>
        </blockquote>
      </div>
    </div>
  </div>

  <!-- ── Dane operacyjne ────────────────────────────────────────────── -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold">
        <i class="bi bi-person-gear"></i> Dane operacyjne
      </div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-md-2 col-4 text-muted">Data migracji</dt>
          <dd class="col-md-4 col-8"><?= date_pl($row['data_migracji']) ?></dd>

          <dt class="col-md-2 col-4 text-muted">Wykonał/a</dt>
          <dd class="col-md-4 col-8"><?= h($row['osoba_migrujaca'] ?? '—') ?></dd>

          <dt class="col-md-2 col-4 text-muted">Nr migracji</dt>
          <dd class="col-md-4 col-8 font-monospace"><?= h($row['numer_umowy']) ?></dd>

          <dt class="col-md-2 col-4 text-muted">Data zapisu</dt>
          <dd class="col-md-4 col-8"><?= h($row['created_at'] ?? '—') ?></dd>

          <?php if ($row['uwagi']): ?>
          <dt class="col-md-2 col-4 text-muted">Uwagi</dt>
          <dd class="col-md-10 col-8"><?= nl2br(h($row['uwagi'])) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
