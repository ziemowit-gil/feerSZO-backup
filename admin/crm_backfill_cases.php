<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_role('admin');
crm_migrate();

$PAGE_TITLE = 'Generuj sprawy CRM dla istniejących umów';

$TYPE_MAP = [
    'wolontariat' => ['table' => 'umowy_wolontariat', 'label' => 'Porozumienia wolontariackie'],
    'zlecenie'    => ['table' => 'umowy_zlecenie',    'label' => 'Umowy zlecenie'],
    'dzielo'      => ['table' => 'umowy_dzielo',      'label' => 'Umowy o dzieło'],
    'praca'       => ['table' => 'umowy_praca',       'label' => 'Umowy o pracę'],
    'uslugi'      => ['table' => 'umowy_uslugi',      'label' => 'Umowy o usługi'],
    'inne'        => ['table' => 'umowy_inne',         'label' => 'Inne umowy'],
];

// Zlicz umowy bez spraw CRM
$counts = [];
$total_missing = 0;
foreach ($TYPE_MAP as $type => $info) {
    try {
        $row = db_one(
            "SELECT COUNT(*) AS cnt FROM {$info['table']} w
             WHERE NOT EXISTS (
                 SELECT 1 FROM crm_cases c
                 WHERE c.contract_type=? AND c.contract_id=w.id
             )",
            [$type]
        );
        $counts[$type] = (int)($row['cnt'] ?? 0);
        $total_missing += $counts[$type];
    } catch (\Throwable $e) {
        $counts[$type] = 0;
    }
}

// Obsługa POST — wykonaj backfill
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_backfill'])) {
    csrf_check();
    if (!module_enabled('crm_enabled')) {
        flash_set('danger', 'Moduł CRM jest wyłączony. Włącz go w ustawieniach CRM przed uruchomieniem backfill.');
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    $admin_id = (int)(current_user()['id'] ?? 0);
    $created  = 0;
    $errors   = 0;
    $log      = [];

    foreach ($TYPE_MAP as $type => $info) {
        try {
            $rows = db_all(
                "SELECT w.* FROM {$info['table']} w
                 WHERE NOT EXISTS (
                     SELECT 1 FROM crm_cases c
                     WHERE c.contract_type=? AND c.contract_id=w.id
                 )
                 ORDER BY w.id ASC",
                [$type]
            );
        } catch (\Throwable $e) {
            $log[] = "Błąd pobierania {$type}: " . $e->getMessage();
            $errors++;
            continue;
        }

        foreach ($rows as $row) {
            try {
                CrmManager::autoCreateContractCase(
                    $type,
                    (int)$row['id'],
                    $row['numer_umowy'] ?? '',
                    $row,
                    $admin_id
                );
                $created++;
            } catch (\Throwable $e) {
                $log[] = "Błąd [{$type} #{$row['id']}]: " . $e->getMessage();
                $errors++;
            }
        }
    }

    $result = ['created' => $created, 'errors' => $errors, 'log' => $log];
}

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-4" style="max-width:800px">
  <div class="d-flex align-items-center gap-3 mb-4">
    <div style="width:44px;height:44px;border-radius:10px;background:#e8f5e9;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#198754">
      <i class="bi bi-journal-plus"></i>
    </div>
    <div>
      <h1 class="h5 mb-0 fw-bold">Generuj sprawy CRM dla istniejących umów</h1>
      <p class="text-muted mb-0 small">Umowy bez przypisanej sprawy CRM otrzymają numer w formacie <code>CASE2026/001</code>.</p>
    </div>
    <a href="crm_database.php" class="btn btn-outline-secondary btn-sm ms-auto">
      <i class="bi bi-arrow-left me-1"></i>Wróć
    </a>
  </div>

  <?= flash_get() ?>

  <?php if ($result !== null): ?>
  <div class="alert alert-<?= $result['errors'] === 0 ? 'success' : ($result['created'] > 0 ? 'warning' : 'danger') ?> mb-4">
    <div class="fw-semibold mb-1">
      <?php if ($result['created'] > 0): ?>
        <i class="bi bi-check-circle-fill me-1"></i>Utworzono <strong><?= $result['created'] ?></strong> <?= $result['created'] === 1 ? 'sprawę' : ($result['created'] < 5 ? 'sprawy' : 'spraw') ?> CRM.
      <?php else: ?>
        <i class="bi bi-info-circle-fill me-1"></i>Nie utworzono nowych spraw — wszystkie umowy były już powiązane.
      <?php endif; ?>
      <?php if ($result['errors'] > 0): ?>
        <span class="text-danger ms-2">Błędów: <?= $result['errors'] ?></span>
      <?php endif; ?>
    </div>
    <?php if ($result['log']): ?>
    <details class="mt-2">
      <summary class="small" style="cursor:pointer">Szczegóły błędów (<?= count($result['log']) ?>)</summary>
      <ul class="small mb-0 mt-1">
        <?php foreach ($result['log'] as $line): ?>
        <li><?= h($line) ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!module_enabled('crm_enabled')): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div>Moduł CRM jest wyłączony. <a href="crm_settings.php" class="alert-link">Włącz go w ustawieniach CRM</a>, aby uruchomić backfill.</div>
  </div>
  <?php endif; ?>

  <!-- Podsumowanie brakujących spraw -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
      <h2 class="h6 fw-semibold mb-3">Umowy bez sprawy CRM</h2>
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Typ umowy</th>
            <th class="text-end">Brakujące sprawy</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($TYPE_MAP as $type => $info): ?>
          <tr>
            <td><?= h($info['label']) ?></td>
            <td class="text-end">
              <?php if ($counts[$type] > 0): ?>
              <span class="badge bg-warning text-dark"><?= $counts[$type] ?></span>
              <?php else: ?>
              <span class="text-success small"><i class="bi bi-check2"></i> wszystkie powiązane</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="fw-semibold">
            <td>Łącznie</td>
            <td class="text-end">
              <?php if ($total_missing > 0): ?>
              <span class="badge bg-warning text-dark"><?= $total_missing ?></span>
              <?php else: ?>
              <span class="text-success small"><i class="bi bi-check2-all"></i> brak</span>
              <?php endif; ?>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <?php if ($total_missing > 0 && module_enabled('crm_enabled')): ?>
  <!-- Formularz potwierdzenia -->
  <div class="card border-warning border-0 shadow-sm mb-4" style="border-left:4px solid #fd7e14 !important">
    <div class="card-body">
      <div class="d-flex align-items-start gap-3">
        <i class="bi bi-exclamation-triangle-fill text-warning mt-1" style="font-size:1.3rem"></i>
        <div>
          <h3 class="h6 fw-semibold mb-1">Potwierdzenie generowania spraw</h3>
          <p class="mb-3 small text-muted">
            Operacja utworzy <strong><?= $total_missing ?></strong> nowych spraw CRM — po jednej dla każdej umowy bez sprawy.
            Każda sprawa otrzyma unikalny numer w formacie <code>CASE<?= date('Y') ?>/NNN</code>.
            Istniejące sprawy nie zostaną zmienione.
          </p>
          <form method="post" action="">
            <?= csrf_field() ?>
            <button type="submit" name="run_backfill" value="1"
                    class="btn btn-warning fw-semibold"
                    onclick="return confirm('Wygenerować <?= $total_missing ?> sprawy CRM dla istniejących umów?')">
              <i class="bi bi-journal-plus me-1"></i>Generuj sprawy CRM (<?= $total_missing ?>)
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php elseif ($total_missing === 0): ?>
  <div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-check-circle-fill"></i>
    <div>Wszystkie umowy mają już powiązane sprawy CRM. Backfill nie jest potrzebny.</div>
  </div>
  <?php endif; ?>

</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
