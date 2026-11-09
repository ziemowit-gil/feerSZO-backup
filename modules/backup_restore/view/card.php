<?php
/**
 * modules/backup_restore/view/card.php — karta „Przywróć bazę SQL z wczoraj” w admin/backups.php.
 * Sam wybór kopii (GET) — przywracanie, porównanie tabel i potwierdzenie są na stronie modułu (router.php).
 */
require_once dirname(__DIR__) . '/logic/backupRestore.php';

$_brCands = backupRestoreCandidates();
$_brDef   = backupRestoreDefault($_brCands);
$_brUrl   = APP_URL . '/modules/backup_restore/router.php';
?>
<div class="card shadow-sm mb-3 border-warning">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-database-fill-gear me-1 text-warning" aria-hidden="true"></i>Przywróć bazę SQL z wczoraj</div>
  <div class="card-body small">
    <?php if (!$_brCands): ?>
      <div class="text-body-secondary">Brak lokalnych kopii bazy do przywrócenia.</div>
    <?php else: ?>
      <p class="mb-2">Odzyskanie danych z kopii bazy: najpierw porównasz liczbę wierszy w tabelach (teraz / w kopii), potem przywrócisz
        <strong>tylko wybrane tabele</strong> (np. dokumenty EODoK) albo całą bazę. Przed przywróceniem system sam robi kopię bieżącej bazy.</p>
      <?php if (!$_brDef || !$_brDef['isYesterday']): ?>
      <div class="text-body-secondary mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Brak kopii z wczoraj — domyślnie wybrana najnowsza wcześniejsza.</div>
      <?php endif; ?>
      <form method="get" action="<?= h($_brUrl) ?>" class="d-flex flex-wrap gap-2 align-items-end">
        <div class="flex-grow-1" style="max-width:720px">
          <label class="form-label mb-1" for="brCardFile">Kopia bazy</label>
          <select id="brCardFile" name="file" class="form-select form-select-sm">
            <?php foreach ($_brCands as $c): ?>
            <option value="<?= h($c['rel']) ?>"<?= $_brDef && $_brDef['rel'] === $c['rel'] ? ' selected' : '' ?>><?= h(backupRestoreLabel($c)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-sm btn-warning"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Przywróć bazę SQL z wczoraj…</button>
      </form>
    <?php endif; ?>
  </div>
</div>
