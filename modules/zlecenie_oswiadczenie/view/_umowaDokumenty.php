<?php
/**
 * modules/zlecenie_oswiadczenie/view/_umowaDokumenty.php — „Dokumenty dodatkowe” w zakładce Dokumenty karty umowy
 * zlecenia (contracts/zlecenie/view.php): pliki z contract_extra_docs, w tym zweryfikowane skany oświadczeń ZUS/PIT
 * i ZLB (znacznik + link do pisma w koszulce EZD). Wymaga w scope: $id (umowa zlecenia).
 */
try {
    require_once dirname(__DIR__) . '/logic/OswService.php';
    OswService::init();
    $_cxd = db_all("SELECT e.*, d.kind AS osw_kind, d.ezd_sprawa_id, d.ezd_pismo_id, d.ezd_error, d.id AS osw_doc_id
                      FROM contract_extra_docs e LEFT JOIN zlec_osw_docs d ON d.extra_doc_id = e.id
                     WHERE e.contract_type = 'zlecenie' AND e.contract_id = ? ORDER BY e.uploaded_at DESC", [(int)$id]);
} catch (\Throwable $e) { $_cxd = []; error_log('[umowa dokumenty] ' . $e->getMessage()); }
?>
<div class="card mb-3" id="contract-extra-docs">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-files text-primary" aria-hidden="true"></i><strong>Dokumenty dodatkowe</strong>
    <span class="small text-body-secondary ms-auto">m.in. zweryfikowane skany oświadczeń</span>
  </div>
  <?php if ($_cxd): ?>
  <ul class="list-group list-group-flush small">
    <?php foreach ($_cxd as $_x): $_xn = $_x['label'] ?: basename((string)$_x['stored_path']); ?>
    <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
      <i class="bi bi-file-earmark<?= str_ends_with(strtolower((string)$_x['stored_path']), '.pdf') ? '-pdf text-danger' : '-image text-primary' ?>" aria-hidden="true"></i>
      <span><?= h($_xn) ?></span>
      <?php if ($_x['osw_kind']): ?><span class="badge text-bg-success">oświadczenie <?= $_x['osw_kind'] === 'zus' ? 'ZUS/PIT' : 'ZLB' ?></span><?php endif; ?>
      <?php if ($_x['ezd_pismo_id']): ?><a class="badge text-bg-primary text-decoration-none" href="<?= h(rtrim(APP_URL, '/') . '/ezd/sprawy/view.php?id=' . (int)$_x['ezd_sprawa_id']) ?>">w EZD<span class="visually-hidden"> — <?= h($_xn) ?></span></a>
      <?php elseif ($_x['ezd_error']): ?><span class="badge text-bg-warning" title="<?= h($_x['ezd_error']) ?>">EZD: nie dołączono</span><?php endif; ?>
      <span class="text-body-secondary"><?= h(date('d.m.Y', (int)strtotime((string)$_x['uploaded_at']))) ?></span>
      <span class="ms-auto d-flex gap-1">
        <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= h(upload_url((string)$_x['stored_path'])) ?>" target="_blank" rel="noopener" title="Podgląd"><i class="bi bi-eye" aria-hidden="true"></i><span class="visually-hidden">Podgląd — <?= h($_xn) ?></span></a>
        <a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= h(upload_url((string)$_x['stored_path'])) ?>" download title="Pobierz"><i class="bi bi-download" aria-hidden="true"></i><span class="visually-hidden">Pobierz — <?= h($_xn) ?></span></a>
      </span>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
  <div class="card-body text-body-secondary small">Brak dokumentów dodatkowych. Zweryfikowane skany oświadczeń (zakładka „Oświadczenie”) pojawią się tutaj automatycznie.</div>
  <?php endif; ?>
</div>
