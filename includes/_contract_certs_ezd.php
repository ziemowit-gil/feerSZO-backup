<?php
/**
 * Sekcja zaświadczeń EZD — wstawka dla widoków umów (card style).
 * Wymagane w zasięgu: $TYPE (string), $id (int)
 */
if (!function_exists('ezd_zas_for_contract')) {
    require_once __DIR__ . '/zaswiadczenia_ezd.php';
}

$_ce_certs  = ezd_zas_for_contract($TYPE, (int)$id);
$_ce_url    = APP_URL . '/ezd/zaswiadczenia/new.php'
            . '?prefill_type=' . urlencode($TYPE)
            . '&prefill_id=' . (int)$id;
$_ce_on     = module_enabled('ezd_enabled');
$_ce_can    = $_ce_on && (is_admin() || can_read('ezd') || can_write('ezd'));
?>
<div class="card shadow-sm mb-3 no-print">
  <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-award me-1"></i>Zaświadczenia</span>
    <?php if ($_ce_can): ?>
    <a href="<?= h($_ce_url) ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowe zaświadczenie
    </a>
    <?php elseif (!$_ce_on): ?>
    <span class="text-muted small">Moduł EZD nieaktywny</span>
    <?php endif; ?>
  </div>
  <?php if ($_ce_certs): ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0">
    <thead class="table-light">
      <tr><th>Wnioskodawca</th><th>Typ</th><th>Data</th><th>Status</th><th class="text-end">Akcje</th></tr>
    </thead>
    <tbody>
    <?php foreach ($_ce_certs as $_ce_z): ?>
    <tr>
      <td class="small"><?= h($_ce_z['wnioskodawca_name']) ?></td>
      <td class="small text-muted"><?= h($_ce_z['typ_nazwa']) ?></td>
      <td class="small text-nowrap"><?= date_pl($_ce_z['created_at']) ?></td>
      <td><?= ezd_zas_status_badge($_ce_z['status']) ?></td>
      <td class="text-end text-nowrap">
        <a href="<?= APP_URL ?>/ezd/zaswiadczenia/view.php?id=<?= $_ce_z['id'] ?>"
           class="btn btn-sm btn-outline-secondary" title="Otwórz w EZD">
          <i class="bi bi-eye"></i>
        </a>
        <?php if (!empty($_ce_z['tresc_html']) || !empty($_ce_z['plik_path'])): ?>
        <a href="<?= APP_URL ?>/ezd/zaswiadczenia/pdf.php?id=<?= $_ce_z['id'] ?>"
           target="_blank" class="btn btn-sm btn-outline-success" title="Podgląd PDF">
          <i class="bi bi-printer"></i>
        </a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div class="card-body text-muted small">Brak zaświadczeń EZD dla tej umowy.</div>
  <?php endif; ?>
</div>
