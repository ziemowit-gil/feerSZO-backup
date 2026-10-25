<?php
/**
 * karty30/ti/dydaktyk/_widget_zalegle_wpisy.php — widżet „Moje zaległe wpisy” na pulpicie prowadzącego.
 *
 * Własne lekcje z Kontroli Lekcji i Wpisów: braki po terminie, zwrócone do poprawy i zakwestionowane
 * (z uwagą audytora). Dane: tiLcInstructorPending() z modules/ti_lesson_control. Kierownik widzi widżet
 * tylko wtedy, gdy sam ma takie lekcje; prowadzący — zawsze (z potwierdzeniem, że wszystko jest w porządku).
 */
require_once dirname(__DIR__, 3) . '/modules/ti_lesson_control/logic/lessonControl.php';

$_lcw_uid   = (int)((function_exists('dyd_current') ? dyd_current() : null)['user_id'] ?? 0);
$_lcw_items = tiLcInstructorPending($_lcw_uid, 6);
$_lcw_total = $_lcw_items ? tiLcInstructorPendingCount($_lcw_uid) : 0;
if ($_lcw_items || !(function_exists('dyd_is_staff') && dyd_is_staff())):
?>
<div class="card dyd-card-accent mb-3 <?= $_lcw_total ? 'border-danger' : '' ?>">
  <div class="card-header d-flex align-items-center gap-2">
    <h2 class="h6 mb-0 fw-semibold"><i class="bi bi-journal-x me-1" style="color:#a20025" aria-hidden="true"></i>Moje zaległe wpisy</h2>
    <span class="badge <?= $_lcw_total ? 'text-bg-danger' : 'text-bg-success' ?> ms-auto"><?= $_lcw_total ?></span>
  </div>
  <?php if (!$_lcw_items): ?>
  <div class="card-body small text-body-secondary"><i class="bi bi-check2-circle me-1 text-success" aria-hidden="true"></i>Wszystkie wpisy z zajęć są uzupełnione w terminie.</div>
  <?php else: ?>
  <ul class="list-group list-group-flush small">
    <?php foreach ($_lcw_items as $_lcw):
        $_lcw_st = in_array($_lcw['review_status'], [TiLcStatus::DO_POPRAWY, TiLcStatus::ZAKWESTIONOWANY], true) ? $_lcw['review_status'] : $_lcw['auto_status'];
        $_lcw_ms = implode(', ', array_map(fn($x) => (string)($x['label'] ?? ''), json_decode((string)$_lcw['missing_json'], true) ?: [])); ?>
    <li class="list-group-item d-flex gap-2 align-items-start">
      <span class="badge text-bg-<?= h(TiLcStatus::color($_lcw_st)) ?> mt-1"><?= h(TiLcStatus::label($_lcw_st)) ?></span>
      <div class="flex-grow-1">
        <a href="kontrolaWpisow.php?s=<?= (int)$_lcw['session_id'] ?>#lc-detail" class="fw-semibold"><?= h(date('d.m', strtotime((string)$_lcw['lesson_date']))) ?> <?= h(substr((string)$_lcw['time_from'], 0, 5)) ?> · <?= h($_lcw['course_name']) ?></a>
        <?php if ($_lcw['review_comment'] !== '' && $_lcw_st !== $_lcw['auto_status']): ?><div class="text-body-secondary">Uwaga: „<?= h(mb_strimwidth((string)$_lcw['review_comment'], 0, 120, '…')) ?>”</div>
        <?php elseif ($_lcw_ms !== ''): ?><div class="text-body-secondary">Brakuje: <?= h($_lcw_ms) ?></div><?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <div class="card-footer small"><a href="kontrolaWpisow.php">Wszystkie moje wpisy<?= $_lcw_total > count($_lcw_items) ? ' (' . $_lcw_total . ')' : '' ?> →</a></div>
  <?php endif; ?>
</div>
<?php endif; ?>
