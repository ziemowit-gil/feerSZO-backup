<?php
/**
 * panel/includes/pv_cert_history.php — „Historia wniosków o zaświadczenie".
 *
 * Markup Bootstrap renderowany serwerowo + filtr po statusie (chipy `.pv-hub-chip`)
 * wzbogacony waniliowym JS ze wspólnego pv_enhance.php. Gdy JS zawiedzie, lista
 * jest w pełni widoczna (chipy nic nie ukrywają).
 *
 * Wymaga w zasięgu: $_pv_certs (znormalizowana tablica, patrz panel/certificates.php),
 *   APP_URL, h().
 */
$_pv_certs = $_pv_certs ?? [];

// Status → etykieta + kolor (Bootstrap) + ikona. Spójne z CERTIFICATE_STATUSES.
$_cert_st = [
    'oczekuje'       => ['Oczekuje',            'warning', 'bi-clock-history'],
    'gotowe'         => ['Gotowe',              'info',    'bi-hourglass-split'],
    'esign_oczekuje' => ['Oczekuje na podpis',  'primary', 'bi-pen'],
    'wydane'         => ['Wydane',              'success', 'bi-award-fill'],
    'odrzucone'      => ['Odrzucone',           'danger',  'bi-x-circle'],
];

// Statusy obecne w danych (w ustalonej kolejności) — tylko one dostają chip.
$_present = [];
foreach (['oczekuje','esign_oczekuje','gotowe','wydane','odrzucone'] as $s) {
    foreach ($_pv_certs as $r) { if (($r['status'] ?? '') === $s) { $_present[] = $s; break; } }
}
?>
<div class="vol-detail-card" id="pvCertHistory"<?= count($_present) > 1 ? ' data-pv-filter' : '' ?>>
  <div class="vol-detail-header">
    <i class="bi bi-list-check me-2" aria-hidden="true"></i>Historia wniosków
    <?php if ($_pv_certs): ?>
    <span class="badge bg-secondary ms-auto"><?= count($_pv_certs) ?></span>
    <?php endif; ?>
  </div>

  <?php if (!$_pv_certs): ?>
  <div class="vol-detail-body text-center py-4 text-muted">
    <i class="bi bi-award" style="font-size:2.5rem;opacity:.2" aria-hidden="true"></i>
    <p class="mt-3 mb-0 small">Nie masz jeszcze żadnych wniosków o zaświadczenie.</p>
  </div>
  <?php else: ?>
  <?php if (count($_present) > 1): ?>
  <div class="pv-filter-chips" role="group" aria-label="Filtruj po statusie">
    <button type="button" class="pv-hub-chip" data-filter-val="all" aria-pressed="true">Wszystkie</button>
    <?php foreach ($_present as $s): ?>
    <button type="button" class="pv-hub-chip" data-filter-val="<?= h($s) ?>" aria-pressed="false"><?= h($_cert_st[$s][0]) ?></button>
    <?php endforeach; ?>
  </div>
  <div data-pv-filter-live data-plural="wniosek|wnioski|wniosków" class="visually-hidden" aria-live="polite"></div>
  <?php endif; ?>
  <?php foreach ($_pv_certs as $r):
    $m = $_cert_st[$r['status']] ?? [$r['status'], 'secondary', 'bi-dot'];
  ?>
  <div class="vol-activity-row" data-filter-item data-status="<?= h($r['status']) ?>">
    <div class="vol-activity-icon bg-<?= $m[1] ?> bg-opacity-15 text-<?= $m[1] ?>">
      <i class="bi <?= $m[2] ?>" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1" style="min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-semibold" style="font-size:.85rem"><?= h($r['type_label']) ?> · <?= h($r['nr']) ?></span>
        <span class="badge bg-<?= $m[1] ?>"><?= h($m[0]) ?></span>
      </div>
      <div class="text-muted" style="font-size:.78rem">Cel: <?= h($r['cel']) ?></div>
      <?php if ($r['rejection_note']): ?>
      <div class="text-danger" style="font-size:.78rem">
        <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><?= h($r['rejection_note']) ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
      <span class="text-muted" style="font-size:.77rem"><?= h($r['created_pl']) ?></span>
      <?php if ($r['status'] === 'wydane'): ?>
      <a href="<?= APP_URL ?>/certificates/print.php?id=<?= (int)$r['id'] ?>"
         target="_blank" class="btn btn-sm btn-success py-0 px-2" title="Pobierz / drukuj PDF"
         aria-label="Pobierz lub drukuj PDF zaświadczenia">
        <i class="bi bi-printer" aria-hidden="true"></i>
      </a>
      <a href="<?= APP_URL ?>/certificates/download_docx.php?id=<?= (int)$r['id'] ?>"
         class="btn btn-sm btn-outline-secondary py-0 px-2" title="Pobierz DOCX (Word)"
         aria-label="Pobierz zaświadczenie w formacie Word">
        <i class="bi bi-file-earmark-word" aria-hidden="true"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if (count($_present) > 1) require_once __DIR__ . '/pv_enhance.php'; ?>
