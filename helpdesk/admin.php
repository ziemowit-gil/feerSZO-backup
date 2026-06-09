<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpdesk.php';
helpdesk_migrate();
require_login();
require_role('admin');

// ── Akcje ─────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    // Toggle helpdesk_operator
    if ($op === 'toggle_operator') {
        $target = (int)($_POST['user_id'] ?? 0);
        if ($target) {
            $cur = db_one("SELECT helpdesk_operator FROM users WHERE id=?", [$target]);
            if ($cur !== null) {
                $new = $cur['helpdesk_operator'] ? 0 : 1;
                db()->prepare("UPDATE users SET helpdesk_operator=? WHERE id=?")->execute([$new, $target]);
                flash_set('success', $new ? 'Uprawnienia operatora nadane.' : 'Uprawnienia operatora cofnięte.');
            }
        }
    }

    // Włącz/wyłącz moduł
    if ($op === 'toggle_module') {
        $cur = db_one("SELECT value FROM settings WHERE key_='helpdesk_enabled'");
        $new = ($cur['value'] ?? '1') === '1' ? '0' : '1';
        if ($cur) {
            db()->prepare("UPDATE settings SET value=? WHERE key_='helpdesk_enabled'")->execute([$new]);
        } else {
            db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['helpdesk_enabled', $new]);
        }
        flash_set('success', $new === '1' ? 'Helpdesk włączony.' : 'Helpdesk wyłączony.');
    }

    // Włącz/wyłącz formularz zgłoszenia błędu
    if ($op === 'toggle_bug_report') {
        $cur = db_one("SELECT value FROM settings WHERE key_='bug_report_enabled'");
        $new = ($cur['value'] ?? '1') === '1' ? '0' : '1';
        if ($cur) {
            db()->prepare("UPDATE settings SET value=? WHERE key_='bug_report_enabled'")->execute([$new]);
        } else {
            db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['bug_report_enabled', $new]);
        }
        flash_set('success', $new === '1' ? 'Formularz zgłoszeń błędów włączony.' : 'Formularz zgłoszeń błędów wyłączony.');
    }

    header('Location: admin.php'); exit;
}

// ── Dane ─────────────────────────────────────────────────────────────────────
$hd_enabled         = (db_one("SELECT value FROM settings WHERE key_='helpdesk_enabled'")['value'] ?? '1') === '1';
$bug_report_enabled = (db_one("SELECT value FROM settings WHERE key_='bug_report_enabled'")['value'] ?? '1') === '1';
$operators   = db_all("SELECT id, name, email, role FROM users WHERE helpdesk_operator=1 AND is_active=1 ORDER BY name");
$all_users   = db_all("SELECT id, name, email, role FROM users WHERE is_active=1 ORDER BY name");

// Statystyki
$stats = [];
foreach (HD_STATUSES as $k => $s) {
    $row = db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE status=?", [$k]);
    $stats[$k] = (int)($row['c'] ?? 0);
}
$total = array_sum($stats);

$PAGE_TITLE = 'Helpdesk — Ustawienia';
include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-headset text-primary me-2"></i>Helpdesk IT — Admin</h4>
  </div>
  <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Lista zgłoszeń
  </a>
</div>

<?= flash_html() ?>

<div class="row g-3">

<!-- Statystyki -->
<div class="col-12">
  <div class="row g-2">
    <?php foreach (HD_STATUSES as $k => $s): ?>
    <div class="col-sm-4 col-md-2">
      <div class="card border-0 shadow-sm text-center py-2">
        <div class="fw-bold fs-4"><?= $stats[$k] ?></div>
        <div class="small"><?= hd_status_badge($k) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="col-sm-4 col-md-2">
      <div class="card border-0 shadow-sm text-center py-2 bg-light">
        <div class="fw-bold fs-4"><?= $total ?></div>
        <div class="small text-muted">Łącznie</div>
      </div>
    </div>
  </div>
</div>

<!-- Włącz/wyłącz moduł -->
<div class="col-md-6">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-power me-1"></i>Moduł Helpdesk
    </div>
    <div class="card-body">
      <div class="d-flex align-items-center gap-3">
        <div>
          Status:
          <span class="badge bg-<?= $hd_enabled ? 'success' : 'secondary' ?>">
            <?= $hd_enabled ? 'Włączony' : 'Wyłączony' ?>
          </span>
        </div>
        <form method="post" class="ms-auto">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="toggle_module">
          <button class="btn btn-sm btn-<?= $hd_enabled ? 'outline-danger' : 'success' ?>">
            <?= $hd_enabled ? '<i class="bi bi-pause-circle me-1"></i>Wyłącz' : '<i class="bi bi-play-circle me-1"></i>Włącz' ?>
          </button>
        </form>
      </div>
      <p class="text-muted small mt-2 mb-0">
        Wyłączenie modułu ukrywa link w menu — istniejące zgłoszenia pozostają w bazie.
      </p>
    </div>
  </div>
</div>

<!-- Formularz zgłoszeń błędów -->
<div class="col-md-6">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-bug-fill text-danger me-1"></i>Formularz zgłoszeń błędów
    </div>
    <div class="card-body">
      <div class="d-flex align-items-center gap-3">
        <div>
          Status:
          <span class="badge bg-<?= $bug_report_enabled ? 'success' : 'secondary' ?>">
            <?= $bug_report_enabled ? 'Włączony' : 'Wyłączony' ?>
          </span>
        </div>
        <form method="post" class="ms-auto">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="toggle_bug_report">
          <button class="btn btn-sm btn-<?= $bug_report_enabled ? 'outline-danger' : 'success' ?>">
            <?= $bug_report_enabled ? '<i class="bi bi-pause-circle me-1"></i>Wyłącz' : '<i class="bi bi-play-circle me-1"></i>Włącz' ?>
          </button>
        </form>
      </div>
      <p class="text-muted small mt-2 mb-0">
        Przycisk <i class="bi bi-bug-fill text-danger"></i>&nbsp;<em>Zgłoś błąd</em> widoczny jest w nagłówku każdej strony.
        Wyłączenie usuwa go ze wszystkich widoków.
      </p>
    </div>
  </div>
</div>

<!-- Operatorzy Helpdesk -->
<div class="col-md-6">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold d-flex align-items-center" style="font-size:.85rem">
      <i class="bi bi-person-badge me-1"></i>Operatorzy Helpdesk
      <span class="badge bg-secondary ms-2"><?= count($operators) ?></span>
    </div>
    <div class="card-body" style="max-height:250px;overflow-y:auto">
      <?php if (!$operators): ?>
      <p class="text-muted small">Brak operatorów. Dodaj poniżej.</p>
      <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($operators as $op): ?>
        <li class="list-group-item d-flex align-items-center gap-2 py-2 px-0">
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= h($op['name']) ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= h($op['email']) ?> · <?= h($op['role']) ?></div>
          </div>
          <form method="post">
            <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
            <input type="hidden" name="_op"      value="toggle_operator">
            <input type="hidden" name="user_id"  value="<?= $op['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Cofnij uprawnienia">
              <i class="bi bi-x"></i>
            </button>
          </form>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Dodaj operatora -->
<div class="col-12">
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-person-plus me-1"></i>Dodaj / Zarządzaj operatorami
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Operator Helpdesk to <strong>dodatkowe uprawnienie</strong> nałożone na istniejącą rolę użytkownika.
        Użytkownik zachowuje swoją rolę, a dodatkowo może obsługiwać zgłoszenia IT.
      </p>
      <div class="table-responsive">
        <table class="table table-sm align-middle" style="font-size:.85rem">
          <thead class="table-light">
            <tr><th>Użytkownik</th><th>E-mail</th><th>Rola</th><th>Operator HD</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($all_users as $u_row): ?>
            <tr>
              <td class="fw-semibold"><?= h($u_row['name']) ?></td>
              <td class="text-muted small"><?= h($u_row['email']) ?></td>
              <td><span class="badge bg-light text-dark border"><?= h($u_row['role']) ?></span></td>
              <td>
                <?php $is_op_row = in_array($u_row['id'], array_column($operators, 'id')); ?>
                <?php if ($is_op_row): ?>
                <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Operator</span>
                <?php else: ?>
                <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
                  <input type="hidden" name="_op"     value="toggle_operator">
                  <input type="hidden" name="user_id" value="<?= $u_row['id'] ?>">
                  <button class="btn btn-sm <?= $is_op_row ? 'btn-outline-danger' : 'btn-outline-success' ?> py-0 px-2">
                    <?= $is_op_row ? '<i class="bi bi-person-dash"></i> Cofnij' : '<i class="bi bi-person-check"></i> Nadaj' ?>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

</div><!-- /row -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
