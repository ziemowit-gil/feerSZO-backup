<?php
/**
 * Partial: to, co prowadzący udostępnił kursantowi (moduł ti_dydaktyka). Wymaga: $rv_client_id (int), $rv_audience ('student'|'parent').
 *   student — materiały przypisane kursantowi + notatki oznaczone „dla kursanta”,
 *   parent  — wyłącznie notatki oznaczone „dla kursanta” (opiekun małoletniego; materiałów nie pokazujemy).
 * Notatki wewnętrzne (staff), profil tyflo, sprzęt i statusy modułów NIE są stąd czytane — to dane tylko dla prowadzących.
 * Używany w panelu kursanta (zakładka „Dydaktyka / eLearning”) i w panelu rodzica (zakładka „Uwagi prowadzącego”).
 */
require_once dirname(__DIR__, 3) . '/modules/ti_dydaktyka/logic/tiDydaktykaManager.php';

$rv_audience  = $rv_audience ?? 'student';
$_pv_notes    = TiDydaktykaManager::studentNotes((int)$rv_client_id);
$_pv_mats     = $rv_audience === 'student' ? TiDydaktykaManager::studentMaterials((int)$rv_client_id) : [];
$_pv_kind     = ['progress' => ['graph-up-arrow', 'primary'], 'difficulty' => ['exclamation-triangle', 'warning'], 'goal' => ['bullseye', 'info'], 'other' => ['chat-left-text', 'secondary']];
$_pv_today    = date('Y-m-d');
?>
<?php if ($_pv_mats): ?>
<section class="mb-4" aria-labelledby="pv-mat-h">
  <h2 class="h6 fw-bold d-flex flex-wrap align-items-center gap-2 mb-2 pb-1 border-bottom" id="pv-mat-h">
    <i class="bi bi-person-check text-primary" aria-hidden="true"></i><span>Materiały od prowadzącego dla Ciebie</span>
    <span class="badge text-bg-secondary"><?= count($_pv_mats) ?></span>
  </h2>
  <div class="d-flex flex-column gap-2">
    <?php foreach ($_pv_mats as $_a):
      $m = $_a; $m['id'] = $_a['material_id']; $m['open_at'] = null; $m['close_at'] = null;   // id materiału (nie przypisania); przypisanie otwiera materiał niezależnie od okna grupy
      $_overdue = !empty($_a['due_at']) && $_a['due_at'] < $_pv_today && empty($_a['opened_at']); ?>
    <div>
      <?php include __DIR__ . '/_dyd_material.php'; ?>
      <?php if (!empty($_a['note']) || !empty($_a['due_at'])): ?>
      <div class="small text-body-secondary ms-2 mt-1">
        <?php if (!empty($_a['note'])): ?><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><?= h($_a['note']) ?><?php endif; ?>
        <?php if (!empty($_a['due_at'])): ?>
          <span class="<?= $_overdue ? 'text-danger fw-semibold' : '' ?> ms-2"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Termin: <time datetime="<?= h($_a['due_at']) ?>"><?= h(date('d.m.Y', strtotime($_a['due_at']))) ?></time><?= $_overdue ? ' (po terminie)' : '' ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($_pv_notes): ?>
<section class="mb-4" aria-labelledby="pv-notes-h">
  <?php if ($rv_audience === 'student'): ?>
  <h2 class="h6 fw-bold d-flex flex-wrap align-items-center gap-2 mb-2 pb-1 border-bottom" id="pv-notes-h">
    <i class="bi bi-chat-square-text text-primary" aria-hidden="true"></i><span>Uwagi prowadzącego</span>
    <span class="badge text-bg-secondary"><?= count($_pv_notes) ?></span>
  </h2>
  <?php else: ?>
  <h3 class="visually-hidden" id="pv-notes-h">Lista uwag prowadzącego</h3>
  <?php endif; ?>
  <ul class="list-unstyled d-flex flex-column gap-2 mb-0">
    <?php foreach ($_pv_notes as $_n): [$_ic, $_cl] = $_pv_kind[$_n['kind']] ?? ['chat-left-text', 'secondary']; ?>
    <li class="card">
      <div class="card-body py-2">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
          <span class="badge bg-<?= $_cl ?>-subtle text-<?= $_cl ?>-emphasis border border-<?= $_cl ?>-subtle"><i class="bi bi-<?= $_ic ?> me-1" aria-hidden="true"></i><?= h(TiDydaktykaManager::NOTE_KINDS[$_n['kind']] ?? $_n['kind']) ?></span>
          <?php if ($_n['topic'] !== ''): ?><strong><?= h($_n['topic']) ?></strong><?php endif; ?>
          <span class="small text-body-secondary ms-auto"><time datetime="<?= h((string)$_n['created_at']) ?>"><?= h(date('d.m.Y', strtotime((string)$_n['created_at']))) ?></time><?= !empty($_n['author']) ? ' · ' . h($_n['author']) : '' ?></span>
        </div>
        <p class="mb-0" style="white-space:pre-wrap"><?= h($_n['body']) ?></p>
        <?php if (!empty($_n['course_name']) || !empty($_n['lesson_date'])): ?>
        <div class="small text-body-secondary mt-1"><?= !empty($_n['course_name']) ? h($_n['course_name']) : '' ?><?= !empty($_n['lesson_date']) ? ' · lekcja z ' . h(date('d.m.Y', strtotime((string)$_n['lesson_date']))) : '' ?></div>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php elseif ($rv_audience === 'parent'): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Prowadzący nie udostępnił jeszcze żadnych uwag o dziecku.</div>
<?php endif; ?>
