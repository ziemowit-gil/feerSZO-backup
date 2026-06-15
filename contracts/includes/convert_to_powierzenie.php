<?php
/**
 * contracts/includes/convert_to_powierzenie.php
 * Pasek akcji „Konwertuj na umowę powierzenia zadania publicznego".
 * Dołączany w view.php typów źródłowych (inne, usługi). Wymaga ustawionych
 * $TYPE i $id oraz dołączonego functions.php/auth.php.
 */
if (can_edit() && module_enabled('contract_powierzenie') && in_array($TYPE ?? '', ['inne', 'uslugi'], true)):
?>
<div class="card shadow-sm mb-3 no-print" style="border-left:4px solid #0ea5e9">
  <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
    <span class="small text-muted">
      <i class="bi bi-bank text-info"></i>
      Tę umowę można skonwertować na <strong>umowę powierzenia zadania publicznego</strong> —
      utworzy nową umowę z przeniesionymi danymi; oryginał pozostanie bez zmian.
    </span>
    <form method="post" action="<?= APP_URL ?>/contracts/powierzenie/convert.php"
          onsubmit="return confirm('Utworzyć nową umowę powierzenia na podstawie tej umowy? Oryginał pozostanie bez zmian.');">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="source_type" value="<?= h($TYPE) ?>">
      <input type="hidden" name="source_id" value="<?= (int)$id ?>">
      <button type="submit" class="btn btn-sm btn-outline-info">
        <i class="bi bi-arrow-left-right"></i> Konwertuj na umowę powierzenia
      </button>
    </form>
  </div>
</div>
<?php endif; ?>
