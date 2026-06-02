<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();
if (defined('CRM_STANDALONE') && CRM_STANDALONE) { header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }

$PAGE_TITLE = 'Dashboard';

// Statystyki
$stats = [];
foreach (CONTRACT_TYPES as $slug => $label) {
    $table = table_for_type($slug);
    $row = db_one("SELECT COUNT(*) AS total FROM {$table}");
    $active = db_one("SELECT COUNT(*) AS cnt FROM {$table} WHERE status IN ('podpisana','w realizacji','obowiązująca')");
    $stats[$slug] = [
        'label'  => $label,
        'total'  => $row['total'] ?? 0,
        'active' => $active['cnt'] ?? 0,
    ];
}

// Ostatnie 10 umów (union across tables)
$recent = [];
foreach (CONTRACT_TYPES as $slug => $label) {
    $table = table_for_type($slug);
    $rows = db_all("SELECT id, numer_umowy, status, created_at, '{$slug}' AS type, '{$label}' AS type_label FROM {$table} ORDER BY created_at DESC LIMIT 5");
    $recent = array_merge($recent, $rows);
}
usort($recent, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));
$recent = array_slice($recent, 0, 10);

// Zbliżające się zakończenia (30 dni)
// Każdy typ umowy może mieć inną nazwę kolumny z datą końcową
$end_col_map = [
    'zlecenie'    => 'data_zakonczenia',
    'uslugi'      => 'data_zakonczenia',
    'wolontariat' => 'data_zakonczenia',
    'dzielo'      => 'termin_oddania',   // umowy_dzielo nie ma data_zakonczenia
    'praca'       => 'data_zakonczenia',
    'inne'        => 'data_zakonczenia',
];
$expiring = [];
foreach (CONTRACT_TYPES as $slug => $label) {
    $table = table_for_type($slug);
    $col   = $end_col_map[$slug] ?? 'data_zakonczenia';
    try {
        $rows = db_all(
            "SELECT id, numer_umowy, status, {$col} AS end_date, '{$slug}' AS type FROM {$table}
             WHERE {$col} BETWEEN DATE('now') AND DATE('now', '+30 days')
             AND status NOT IN ('zakończona','anulowana','wygasła') ORDER BY {$col}",
            []
        );
        foreach ($rows as $r) { $r['type_label'] = $label; $expiring[] = $r; }
    } catch (PDOException $e) {
        // kolumna nieznana w tej tabeli — pomiń
    }
}
usort($expiring, fn($a,$b) => strcmp($a['end_date'], $b['end_date']));

// Ostatnie wiadomości (dla kafelka dashboardu)
$dash_threads = [];
if (can_edit()) {
    try {
        require_once __DIR__ . '/includes/messages.php';
        $dash_threads = db_all("
            SELECT m.context_type, m.context_id, m.contract_type,
                   MAX(m.created_at) AS last_at,
                   SUM(CASE WHEN m.sender_type='user' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread_admin,
                   (SELECT sender_name FROM messages m2
                    WHERE m2.context_type=m.context_type AND m2.context_id=m.context_id
                    ORDER BY m2.created_at DESC LIMIT 1) AS last_sender,
                   (SELECT body FROM messages m2
                    WHERE m2.context_type=m.context_type AND m2.context_id=m.context_id
                    ORDER BY m2.created_at DESC LIMIT 1) AS last_body
            FROM messages m
            GROUP BY m.context_type, m.context_id
            ORDER BY last_at DESC LIMIT 5
        ");
        foreach ($dash_threads as &$_dt) {
            if ($_dt['context_type'] === 'contract') {
                $table = 'umowy_' . $_dt['contract_type'];
                try {
                    $r = db_one("SELECT numer_umowy, imie_nazwisko FROM {$table} WHERE id=?", [(int)$_dt['context_id']]);
                    $_dt['_label']    = $r['numer_umowy'] ?? '#'.$_dt['context_id'];
                    $_dt['_sublabel'] = $r['imie_nazwisko'] ?? '';
                    $_dt['_url']      = APP_URL . '/contracts/' . $_dt['contract_type'] . '/view.php?id=' . $_dt['context_id'];
                } catch (\Throwable $e) { $_dt['_label'] = '#'.$_dt['context_id']; $_dt['_sublabel'] = ''; $_dt['_url'] = '#'; }
            } elseif ($_dt['context_type'] === 'onboarding') {
                try {
                    $r = db_one("SELECT imie_nazwisko, email FROM onboarding_volunteers WHERE id=?", [(int)$_dt['context_id']]);
                    $_dt['_label']    = $r['imie_nazwisko'] ?? '#'.$_dt['context_id'];
                    $_dt['_sublabel'] = $r['email'] ?? '';
                    $_dt['_url']      = APP_URL . '/admin/onboarding_view.php?id=' . $_dt['context_id'];
                } catch (\Throwable $e) { $_dt['_label'] = '#'.$_dt['context_id']; $_dt['_sublabel'] = ''; $_dt['_url'] = '#'; }
            }
        }
        unset($_dt);
    } catch (\Throwable $e) {}
}

include __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-4">
  <?php foreach ($stats as $slug => $s):
    $icons = ['zlecenie'=>'person-lines-fill','uslugi'=>'building','wolontariat'=>'heart-fill','dzielo'=>'palette2','praca'=>'briefcase-fill','inne'=>'file-earmark-diff-fill'];
  ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card h-100 shadow-sm">
      <div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="text-muted small"><?= h($s['label']) ?></div>
            <div class="fs-3 fw-bold"><?= $s['total'] ?></div>
            <div class="text-success small"><?= $s['active'] ?> aktywnych</div>
          </div>
          <i class="bi bi-<?= $icons[$slug] ?> fs-3 text-primary opacity-50"></i>
        </div>
        <a href="<?= APP_URL ?>/contracts/<?= $slug ?>/list.php" class="stretched-link"></a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <!-- Ostatnie umowy -->
  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-clock-history"></i> Ostatnio dodane</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 contracts-table">
          <thead class="table-light">
            <tr><th>Numer</th><th>Typ</th><th>Status</th><th>Data</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= h($r['numer_umowy']) ?></td>
              <td><span class="badge bg-light text-dark"><?= h($r['type_label']) ?></span></td>
              <td><?= status_badge($r['status']) ?></td>
              <td><?= date_pl($r['created_at']) ?></td>
              <td><a href="<?= contract_url($r['type'], $r['id']) ?>" class="btn btn-xs btn-outline-primary btn-sm">Otwórz</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?>
            <tr><td colspan="5" class="text-muted text-center py-3">Brak umów</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Zbliżające się zakończenia -->
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold text-warning-emphasis bg-warning-subtle">
        <i class="bi bi-calendar-event"></i> Kończą się w ciągu 30 dni
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 contracts-table">
          <thead class="table-light"><tr><th>Numer</th><th>Koniec</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($expiring as $r): ?>
            <tr>
              <td><?= h($r['numer_umowy']) ?><br><small class="text-muted"><?= h($r['type_label']) ?></small></td>
              <td class="text-danger fw-semibold"><?= date_pl($r['end_date']) ?></td>
              <td><a href="<?= contract_url($r['type'], $r['id']) ?>" class="btn btn-sm btn-outline-secondary">Otwórz</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$expiring): ?>
            <tr><td colspan="3" class="text-muted text-center py-3"><i class="bi bi-check-circle text-success"></i> Brak</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if (can_edit() && $dash_threads): ?>
<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-chat-dots text-primary me-1"></i> Ostatnie wiadomości</span>
        <a href="<?= APP_URL ?>/admin/messages.php" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-list-ul me-1"></i>Wszystkie
        </a>
      </div>
      <div class="list-group list-group-flush">
        <?php foreach ($dash_threads as $_dt):
          $unread = (int)$_dt['unread_admin'];
          $preview = mb_substr(strip_tags($_dt['last_body'] ?? ''), 0, 90);
          $sender_short = $_dt['last_sender'] ? explode(' ', $_dt['last_sender'])[0] . ': ' : '';
          $is_onb = $_dt['context_type'] === 'onboarding';
        ?>
        <button type="button"
          class="list-group-item list-group-item-action py-2 px-3 d-flex align-items-center gap-3 text-start<?= $unread ? ' border-start border-primary border-3' : '' ?>"
          style="<?= $unread ? 'background:#eff6ff' : '' ?>"
          onclick="window.MsgWidget && window.MsgWidget.openThread(
            '<?= h($_dt['context_type']) ?>',
            <?= (int)$_dt['context_id'] ?>,
            '<?= h($_dt['contract_type']) ?>',
            '<?= h($_dt['_label'] ?? '') ?>',
            '<?= h($_dt['_url'] ?? '#') ?>'
          )">
          <div style="width:34px;height:34px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;background:<?= $is_onb ? '#fef9c3;color:#92400e' : '#e2e8f0;color:#475569' ?>">
            <?= strtoupper(mb_substr($_dt['_label'] ?? '?', 0, 3)) ?>
          </div>
          <div class="flex-grow-1 overflow-hidden">
            <div class="d-flex align-items-center gap-2">
              <?php if ($is_onb): ?>
              <span class="badge bg-warning text-dark" style="font-size:.65rem">Zgłoszenie</span>
              <?php else: ?>
              <span class="badge bg-light text-secondary border" style="font-size:.65rem"><?= h(CONTRACT_TYPES[$_dt['contract_type']] ?? '') ?></span>
              <?php endif; ?>
              <span class="fw-semibold small <?= $unread ? 'text-primary' : '' ?>"><?= h($_dt['_label'] ?? '') ?></span>
              <?php if ($_dt['_sublabel'] ?? ''): ?>
              <span class="text-muted" style="font-size:.76rem"><?= h($_dt['_sublabel']) ?></span>
              <?php endif; ?>
            </div>
            <div class="text-muted text-truncate" style="font-size:.77rem;max-width:100%">
              <?= h($sender_short . $preview) ?>
            </div>
          </div>
          <div class="text-end flex-shrink-0">
            <div class="text-muted" style="font-size:.68rem"><?= date_pl($_dt['last_at']) ?></div>
            <?php if ($unread): ?>
            <span class="badge bg-danger mt-1"><?= $unread ?></span>
            <?php endif; ?>
          </div>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
