<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
require_login();
require_role('admin', 'editor');

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? db_one("SELECT * FROM rodo_authorizations WHERE id=?", [$id]) : null;
if (!$row) { http_response_code(404); die('Upoważnienie nie istnieje.'); }

// ── Akcje ─────────────────────────────────────────────────────────────────────

// Zapis szkolenia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'add_training') {
    csrf_check();
    $date    = trim($_POST['training_date'] ?? '');
    $topics  = array_filter((array)($_POST['topics'] ?? []));
    $trainer = trim($_POST['trainer_name'] ?? '');
    $notes   = trim($_POST['training_notes'] ?? '');
    if ($date) {
        $u = current_user();
        db_insert('rodo_trainings', [
            'authorization_id' => $id,
            'training_date'    => $date,
            'topics'           => json_encode(array_values($topics), JSON_UNESCAPED_UNICODE),
            'trainer_id'       => (int)$u['id'],
            'trainer_name'     => $trainer ?: ($u['name'] ?? ''),
            'notes'            => $notes ?: null,
        ]);
        db()->prepare("UPDATE rodo_authorizations SET training_done=1, updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $id]);
        flash_set('success', 'Szkolenie RODO zostało odnotowane.');
    }
    header('Location: view.php?id=' . $id); exit;
}

// Zmiana statusu / cofnięcie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'set_status') {
    csrf_check();
    $new_status = $_POST['status'] ?? '';
    if (in_array($new_status, ['aktywne','cofnięte','wygasłe'], true)) {
        db()->prepare("UPDATE rodo_authorizations SET status=?, updated_at=? WHERE id=?")
            ->execute([$new_status, date('Y-m-d H:i:s'), $id]);
        if ($new_status === 'cofnięte') {
            $u = current_user();
            $reason = trim($_POST['reason'] ?? '');
            db_insert('rodo_revocations', [
                'authorization_id' => $id,
                'revoked_by_id'    => (int)$u['id'],
                'revoked_by_name'  => $u['name'] ?? '',
                'reason'           => $reason ?: null,
            ]);
        }
        flash_set('success', 'Status upoważnienia zmieniony.');
    }
    header('Location: view.php?id=' . $id); exit;
}

// Oznacz podpis wolontariusza
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'vol_signed') {
    csrf_check();
    $dt = trim($_POST['vol_signed_at'] ?? date('Y-m-d'));
    db()->prepare("UPDATE rodo_authorizations SET vol_signed_at=?, updated_at=? WHERE id=?")
        ->execute([$dt, date('Y-m-d H:i:s'), $id]);
    flash_set('success', 'Podpis wolontariusza odnotowany.');
    header('Location: view.php?id=' . $id); exit;
}

// Usuń
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'delete' && is_admin()) {
    csrf_check();
    db()->prepare("DELETE FROM rodo_authorizations WHERE id=?")->execute([$id]);
    flash_set('success', 'Upoważnienie usunięte z rejestru.');
    header('Location: ' . APP_URL . '/rodo/index.php'); exit;
}

// ── Dane ─────────────────────────────────────────────────────────────────────
$row       = db_one("SELECT * FROM rodo_authorizations WHERE id=?", [$id]);
$trainings = db_all("SELECT * FROM rodo_trainings WHERE authorization_id=? ORDER BY training_date DESC", [$id]);
$revocs    = db_all("SELECT * FROM rodo_revocations WHERE authorization_id=? ORDER BY revoked_at DESC", [$id]);
$scope     = json_decode($row['scope_items'] ?? '[]', true) ?: [];

$PAGE_TITLE = 'Upoważnienie ' . $row['number'];
include dirname(__DIR__) . '/includes/header.php';
?>

<!-- Nagłówek -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/rodo/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div class="flex-grow-1">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="font-monospace fw-bold text-muted"><?= h($row['number']) ?></span>
      <?= rodo_status_badge($row['status']) ?>
      <?php if (!$row['training_done']): ?>
      <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-circle me-1"></i>Brak szkolenia</span>
      <?php endif; ?>
    </div>
    <h4 class="mb-0 mt-1 fw-bold"><?= h($row['person_name']) ?></h4>
    <div class="text-muted small">
      Upoważnienie od <?= date('d.m.Y', strtotime($row['authorized_from'])) ?>
      <?= $row['authorized_until'] ? ' do ' . date('d.m.Y', strtotime($row['authorized_until'])) : ' (do wygaśnięcia umowy)' ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/rodo/print.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-printer me-1"></i>Drukuj / PDF
    </a>
    <a href="<?= APP_URL ?>/rodo/new.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-plus me-1"></i>Nowe
    </a>
  </div>
</div>

<?= flash_html() ?>

<div class="row g-3">

<!-- ── Kolumna główna ─────────────────────────────────────────────────────── -->
<div class="col-lg-8">

  <!-- Szczegóły -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-shield-check me-1 text-primary"></i>Treść upoważnienia
    </div>
    <div class="card-body" style="font-size:.88rem">
      <dl class="row mb-0">
        <dt class="col-sm-4 text-muted">Administrator danych</dt>
        <dd class="col-sm-8"><?= h($row['org_name']) ?> · NIP <?= h($row['org_nip']) ?></dd>
        <dt class="col-sm-4 text-muted">Siedziba</dt>
        <dd class="col-sm-8"><?= h($row['org_address']) ?></dd>
        <dt class="col-sm-4 text-muted">Osoba upoważniona</dt>
        <dd class="col-sm-8 fw-semibold"><?= h($row['person_name']) ?></dd>
        <dt class="col-sm-4 text-muted">PESEL</dt>
        <dd class="col-sm-8 font-monospace"><?= h($row['person_pesel'] ?: '—') ?></dd>
        <dt class="col-sm-4 text-muted">Nr porozumienia</dt>
        <dd class="col-sm-8"><?= h($row['contract_number'] ?: '—') ?></dd>
        <dt class="col-sm-4 text-muted">Data porozumienia</dt>
        <dd class="col-sm-8"><?= $row['contract_date'] ? date('d.m.Y', strtotime($row['contract_date'])) : '—' ?></dd>
        <dt class="col-sm-4 text-muted fw-semibold">§ 2 Zakres</dt>
        <dd class="col-sm-8">
          <ul class="mb-0 ps-3">
            <?php foreach ($scope as $k): ?>
            <li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li>
            <?php endforeach; ?>
            <?php if ($row['scope_custom']): ?><li><?= h($row['scope_custom']) ?></li><?php endif; ?>
          </ul>
        </dd>
      </dl>
    </div>
  </div>

  <!-- Szkolenia -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold d-flex align-items-center" style="font-size:.85rem">
      <i class="bi bi-mortarboard me-1 text-success"></i>Szkolenie RODO
      <?php if ($row['training_done']): ?>
      <span class="badge bg-success ms-2">Zrealizowane</span>
      <?php else: ?>
      <span class="badge bg-warning text-dark ms-2">Wymagane</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($trainings): ?>
      <div class="table-responsive mb-3">
        <table class="table table-sm align-middle" style="font-size:.84rem">
          <thead class="table-light">
            <tr><th>Data</th><th>Prowadzący</th><th>Tematy</th><th>Uwagi</th></tr>
          </thead>
          <tbody>
            <?php foreach ($trainings as $t): ?>
            <?php $topics = json_decode($t['topics'] ?? '[]', true) ?: []; ?>
            <tr>
              <td class="text-nowrap"><?= date('d.m.Y', strtotime($t['training_date'])) ?></td>
              <td><?= h($t['trainer_name']) ?></td>
              <td>
                <?php foreach ($topics as $tp): ?>
                <span class="badge bg-light text-dark border me-1" style="font-size:.75rem">
                  <?= h(RODO_TRAINING_TOPICS[$tp] ?? $tp) ?>
                </span>
                <?php endforeach; ?>
              </td>
              <td class="text-muted small"><?= h($t['notes'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <!-- Dodaj szkolenie -->
      <?php if ($row['status'] === 'aktywne'): ?>
      <details <?= !$trainings ? 'open' : '' ?>>
        <summary class="btn btn-sm btn-outline-success mb-2" style="list-style:none">
          <i class="bi bi-plus-circle me-1"></i>Odnotuj szkolenie
        </summary>
        <form method="post" class="p-3 bg-light rounded mt-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="add_training">
          <div class="row g-2">
            <div class="col-md-5">
              <label class="form-label small fw-semibold">Data szkolenia</label>
              <input name="training_date" type="date" class="form-control form-control-sm"
                     value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-7">
              <label class="form-label small fw-semibold">Prowadzący</label>
              <input name="trainer_name" class="form-control form-control-sm"
                     value="<?= h(current_user()['name'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Tematy (wybierz)</label>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach (RODO_TRAINING_TOPICS as $k => $v): ?>
                <label class="badge fw-normal border text-dark d-flex align-items-center gap-1"
                       style="cursor:pointer;padding:.35em .7em;background:#f8fafc">
                  <input type="checkbox" name="topics[]" value="<?= h($k) ?>"
                         class="form-check-input mt-0" style="width:13px;height:13px">
                  <?= h($v) ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Uwagi</label>
              <textarea name="training_notes" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-sm btn-success">
                <i class="bi bi-check-lg me-1"></i>Zapisz szkolenie
              </button>
            </div>
          </div>
        </form>
      </details>
      <?php endif; ?>
    </div>
  </div>

  <!-- Historia cofnięć -->
  <?php if ($revocs): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold text-danger" style="font-size:.85rem">
      <i class="bi bi-x-circle me-1"></i>Historia cofnięcia upoważnienia
    </div>
    <div class="card-body">
      <?php foreach ($revocs as $rv): ?>
      <div class="d-flex gap-2 mb-2">
        <div class="text-muted small text-nowrap"><?= date('d.m.Y H:i', strtotime($rv['revoked_at'])) ?></div>
        <div>
          <span class="fw-semibold small"><?= h($rv['revoked_by_name']) ?></span>
          <?php if ($rv['reason']): ?><span class="text-muted small"> — <?= h($rv['reason']) ?></span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /col-8 -->

<!-- ── Sidebar ──────────────────────────────────────────────────────────────── -->
<div class="col-lg-4">

  <!-- Status -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-arrow-repeat me-1"></i>Status upoważnienia
    </div>
    <div class="card-body">
      <div class="mb-2"><?= rodo_status_badge($row['status']) ?></div>
      <?php if ($row['status'] === 'aktywne'): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="set_status">
        <input type="hidden" name="status" value="cofnięte">
        <div class="mb-2">
          <label class="form-label small">Powód cofnięcia</label>
          <input name="reason" class="form-control form-control-sm" placeholder="opcjonalnie">
        </div>
        <button type="submit" class="btn btn-sm btn-outline-danger w-100"
                onclick="return confirm('Cofnąć upoważnienie?')">
          <i class="bi bi-x-circle me-1"></i>Cofnij upoważnienie
        </button>
      </form>
      <?php elseif ($row['status'] === 'cofnięte'): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="set_status">
        <input type="hidden" name="status" value="aktywne">
        <button type="submit" class="btn btn-sm btn-outline-success w-100">
          <i class="bi bi-check-circle me-1"></i>Przywróć upoważnienie
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Podpis wolontariusza -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-pen me-1"></i>Oświadczenie wolontariusza
    </div>
    <div class="card-body">
      <?php if ($row['vol_signed_at']): ?>
      <div class="text-success small mb-2">
        <i class="bi bi-check-circle-fill me-1"></i>
        Podpisane <?= date('d.m.Y', strtotime($row['vol_signed_at'])) ?>
      </div>
      <?php else: ?>
      <p class="text-muted small mb-2">Wolontariusz jeszcze nie złożył oświadczenia.</p>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="vol_signed">
        <div class="d-flex gap-2">
          <input name="vol_signed_at" type="date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
          <button type="submit" class="btn btn-sm btn-outline-success flex-shrink-0">Oznacz</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Meta -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-info-circle me-1"></i>Metadane
    </div>
    <div class="card-body small text-muted">
      <div>Podpisał: <strong><?= h($row['signed_by_name'] ?: '—') ?></strong></div>
      <?php if ($row['signed_at']): ?><div>Data podpisania: <?= date('d.m.Y', strtotime($row['signed_at'])) ?></div><?php endif; ?>
      <div class="mt-1">Zarejestrowano: <?= date('d.m.Y H:i', strtotime($row['created_at'])) ?></div>
      <?php if ($row['notes']): ?><div class="mt-2 p-2 bg-light rounded"><?= nl2br(h($row['notes'])) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- Umowa powiązana -->
  <?php if ($row['contract_id'] && $row['contract_type']): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <a href="<?= APP_URL ?>/contracts/<?= h($row['contract_type']) ?>/view.php?id=<?= $row['contract_id'] ?>&tab=rodo"
         class="btn btn-sm btn-outline-secondary w-100">
        <i class="bi bi-file-text me-1"></i>Porozumienie <?= h($row['contract_number'] ?: '#' . $row['contract_id']) ?>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Usuń -->
  <?php if (is_admin()): ?>
  <div class="card border-danger border-opacity-25 border">
    <div class="card-body py-2">
      <form method="post" onsubmit="return confirm('Trwale usunąć upoważnienie z rejestru?')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="delete">
        <button type="submit" class="btn btn-sm btn-outline-danger w-100">
          <i class="bi bi-trash3 me-1"></i>Usuń z rejestru
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /col-4 -->
</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
