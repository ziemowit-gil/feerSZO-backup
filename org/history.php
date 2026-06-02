<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org.php';
require_role('admin'); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$PAGE_TITLE = 'Historia zmian struktury';
$type_f     = $_GET['type'] ?? '';
$limit      = 150;

$where  = $type_f ? "WHERE entity_type=?" : "";
$params = $type_f ? [$type_f] : [];
$rows   = db_all(
    "SELECT h.*, u.name AS user_name FROM org_history h
     LEFT JOIN users u ON u.id=h.changed_by
     $where ORDER BY h.changed_at DESC LIMIT $limit",
    $params
);

$action_labels = [
    'create'        => ['Utworzono',     'success'],
    'update'        => ['Edytowano',     'primary'],
    'delete'        => ['Usunięto',      'danger'],
    'deactivate'    => ['Dezaktywowano', 'warning'],
    'add'           => ['Dodano osobę',  'success'],
    'remove'        => ['Usunięto osobę','danger'],
    'head_change'   => ['Zmiana kier.',  'warning'],
    'status_change' => ['Zmiana statusu','info'],
];

include dirname(__DIR__) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/index.php">Struktura</a></li>
  <li class="breadcrumb-item active">Historia zmian</li>
</ol></nav>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>Historia zmian struktury</h4>
</div>

<!-- Filtry -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach(['' => 'Wszystkie', 'unit' => 'Jednostki', 'member' => 'Przypisania', 'position' => 'Stanowiska'] as $tv => $tl): ?>
  <a href="?type=<?= $tv ?>" class="btn btn-sm <?= $type_f === $tv ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= h($tl) ?></a>
  <?php endforeach; ?>
</div>

<div class="card shadow-sm">
  <div class="card-header fw-semibold" style="font-size:.88rem">
    <i class="bi bi-journal-text me-1 text-primary"></i>Zdarzenia (<?= count($rows) ?>)
  </div>
  <?php if($rows): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
    <thead class="table-light">
      <tr><th>Data</th><th>Typ</th><th>Akcja</th><th>Szczegóły</th><th>Wykonał</th></tr>
    </thead>
    <tbody>
    <?php foreach($rows as $r):
      [$al, $ac] = $action_labels[$r['action']] ?? [$r['action'], 'secondary'];
      $new = json_decode($r['new_data'] ?? '{}', true) ?: [];
      $old = json_decode($r['old_data'] ?? '{}', true) ?: [];
    ?>
    <tr>
      <td class="text-muted text-nowrap"><?= date('d.m.Y H:i', strtotime($r['changed_at'])) ?></td>
      <td><span class="badge bg-light text-dark border"><?= h($r['entity_type']) ?> #<?= $r['entity_id'] ?></span></td>
      <td><span class="badge bg-<?= $ac ?>"><?= h($al) ?></span></td>
      <td>
        <div style="max-width:340px"><?= h($r['note']) ?></div>
        <?php if($r['old_data'] && $r['old_data'] !== '{}'): ?>
        <details style="font-size:.7rem;margin-top:.25rem">
          <summary class="text-muted" style="cursor:pointer">Dane przed / po</summary>
          <div class="mt-1 d-flex gap-2">
            <?php if($old): ?><div style="flex:1"><strong class="text-danger">Przed:</strong><pre style="font-size:.65rem;white-space:pre-wrap;background:#fff5f5;padding:4px;border-radius:4px;max-height:80px;overflow:auto"><?= h(json_encode($old, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre></div><?php endif; ?>
            <?php if($new): ?><div style="flex:1"><strong class="text-success">Po:</strong><pre style="font-size:.65rem;white-space:pre-wrap;background:#f0fff4;padding:4px;border-radius:4px;max-height:80px;overflow:auto"><?= h(json_encode($new, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre></div><?php endif; ?>
          </div>
        </details>
        <?php endif; ?>
      </td>
      <td class="text-muted"><?= h($r['user_name'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="text-center py-5 text-muted"><i class="bi bi-clock-history" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.25"></i>Brak historii.</div>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
