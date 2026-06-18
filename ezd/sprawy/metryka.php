<?php
/**
 * Metryka sprawy (wg art. 66a KPA) — chronologiczny rejestr czynności w sprawie,
 * budowany z dziennika operacji (ezd_log). Drukowalny.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$id     = (int)($_GET['id'] ?? 0);
$sprawa = ezd_sprawa_get($id);
if (!$sprawa) { flash_set('error','Sprawa nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }

// Pełen log sprawy w kolejności chronologicznej
$log = db_all(
    "SELECT l.*, u.name AS user_name FROM ezd_log l
     LEFT JOIN users u ON u.id=l.user_id
     WHERE l.sprawa_id=? ORDER BY l.created_at ASC, l.id ASC", [$id]
);

// Słownik czynności
$ACTIONS = [
    'sprawa_create'    => 'Wszczęcie sprawy',
    'sprawa_update'    => 'Aktualizacja danych sprawy',
    'pismo_create'     => 'Rejestracja pisma',
    'pismo_update'     => 'Modyfikacja pisma',
    'umowa_create'     => 'Dodanie umowy / dokumentu',
    'umowa_update'     => 'Modyfikacja umowy / dokumentu',
    'dekretacja_create'=> 'Dekretacja (przekazanie do realizacji)',
    'dekretacja_done'  => 'Zakończenie dekretacji',
    'upload'           => 'Załączenie dokumentu do akt',
    'del_attachment'   => 'Usunięcie załącznika',
    'rpw_assign'       => 'Włączenie przesyłki z dziennika podawczego',
    'dokument_create'  => 'Sporządzenie dokumentu wewnętrznego',
    'dokument_update'  => 'Modyfikacja dokumentu wewnętrznego',
    'dokument_delete'  => 'Usunięcie dokumentu wewnętrznego',
    'notatka_create'   => 'Dodanie notatki',
    'notatka_update'   => 'Modyfikacja notatki',
    'notatka_delete'   => 'Usunięcie notatki',
];

$org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$PAGE_TITLE = 'Metryka — ' . $sprawa['znak_sprawy'];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.mtr-meta{font-size:.85rem;color:#475569}
.mtr-table{font-size:.82rem}
.mtr-table th{background:#f1f5f9;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}
@media print{
  .no-print{display:none!important}
  .breadcrumb{display:none}
  body{background:#fff}
  .mtr-print-area{box-shadow:none!important;border:none!important}
}
</style>

<nav aria-label="breadcrumb" class="mb-3 no-print"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">Kancelaria</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Metryka sprawy</li>
</ol></nav>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-check text-primary me-2"></i>Metryka sprawy</h4>
  <button onclick="window.print()" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i>Drukuj / PDF</button>
</div>

<div class="card shadow-sm mtr-print-area"><div class="card-body p-4">
  <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
      <?php if($org_name): ?><div class="fw-bold" style="font-size:1.05rem"><?= h($org_name) ?></div><?php endif; ?>
      <div class="mtr-meta">Metryka sprawy (art. 66a KPA)</div>
    </div>
    <div class="text-end mtr-meta">
      <div><strong>Znak sprawy:</strong> <span class="font-monospace"><?= h($sprawa['znak_sprawy']) ?></span></div>
      <div><strong>Teczka:</strong> <span class="font-monospace"><?= h($sprawa['teczka_symbol']) ?></span></div>
    </div>
  </div>
  <div class="mb-3">
    <div style="font-size:1.1rem;font-weight:700"><?= h($sprawa['title']) ?></div>
    <div class="mtr-meta">
      Prowadzący: <?= h($sprawa['owner_name'] ?: '—') ?>
      · Data wszczęcia: <?= $sprawa['created_at'] ? date('d.m.Y', strtotime($sprawa['created_at'])) : '—' ?>
      <?php if($sprawa['closed_at']): ?> · Data zakończenia: <?= date('d.m.Y', strtotime($sprawa['closed_at'])) ?><?php endif; ?>
    </div>
  </div>

  <table class="table table-bordered mtr-table">
    <thead>
      <tr>
        <th style="width:48px">Lp.</th>
        <th style="width:140px">Data i godzina</th>
        <th style="width:180px">Osoba</th>
        <th>Określenie podejmowanej czynności</th>
      </tr>
    </thead>
    <tbody>
      <?php $lp=0; foreach($log as $l): $lp++; ?>
      <tr>
        <td class="text-center"><?= $lp ?></td>
        <td class="text-nowrap"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
        <td><?= h($l['user_name'] ?: '—') ?></td>
        <td>
          <strong><?= h($ACTIONS[$l['action']] ?? $l['action']) ?></strong>
          <?php if($l['details']): ?><div class="text-muted" style="font-size:.76rem"><?= h($l['details']) ?></div><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$log): ?>
      <tr><td colspan="4" class="text-center text-muted py-3">Brak zarejestrowanych czynności.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="mtr-meta mt-3">Liczba czynności: <strong><?= count($log) ?></strong> · Wygenerowano: <?= date('d.m.Y H:i') ?></div>
</div></div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
