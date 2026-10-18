<?php
/**
 * modules/panel_metro/view/sideFeed.php — okienko boczne pulpitu: Komunikaty + Zadania.
 * Wejście z panelMetroSideFeed(): $ann (lista ogłoszeń), $tasks (lista zadań albo null = moduł wyłączony).
 */
$_sfToday = date('Y-m-d');
?>
<style>
.pvm .sf-box{background:var(--pvm-light);padding:1rem;margin-bottom:8px}
.pvm .sf-h{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin:0 0 .6rem}
.pvm .sf-h h2{margin:0;font-size:1.05rem;font-weight:600;color:var(--pvm-ink);display:flex;align-items:center;gap:.45rem}
.pvm .sf-h h2 .sq{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;background:var(--c);color:#fff;font-size:.95rem}
.pvm .sf-h a{font-size:.78rem;font-weight:600;color:var(--pvm-ink);text-decoration:none;border-bottom:2px solid currentColor}
.pvm .sf-list{list-style:none;margin:0;padding:0;display:grid;gap:3px}
.pvm .sf-list a{display:block;padding:.5rem .6rem;background:var(--pvm-bg);color:var(--pvm-ink);text-decoration:none;border-left:4px solid transparent}
.pvm .sf-list a:hover{background:var(--pvm-light-h)}
.pvm .sf-list a.is-new{border-left-color:var(--m-violet)}
.pvm .sf-list a.is-late{border-left-color:var(--m-red)}
.pvm .sf-list b{display:block;font-size:.86rem;font-weight:600;line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pvm .sf-list small{display:block;font-size:.72rem;color:var(--pvm-muted);margin-top:.1rem}
.pvm .sf-list small .late{color:var(--m-red);font-weight:700}
.pvm .sf-empty{font-size:.82rem;color:var(--pvm-muted);margin:0;padding:.4rem 0}
:root[data-theme="hc"] .pvm .sf-list a{border:2px solid #000;border-left-width:4px}
</style>
<section class="sf-box" aria-labelledby="sf-ann-h">
  <div class="sf-h">
    <h2 id="sf-ann-h"><span class="sq" style="--c:var(--m-violet)" aria-hidden="true"><i class="bi bi-megaphone"></i></span>Komunikaty</h2>
    <a href="<?= APP_URL ?>/komunikaty/index.php">Wszystkie<span class="visually-hidden"> komunikaty</span></a>
  </div>
  <?php if (!$ann): ?>
  <p class="sf-empty">Brak aktualnych komunikatów.</p>
  <?php else: ?>
  <ul class="sf-list">
    <?php foreach ($ann as $_a): $_new = empty($_a['is_read_by_me']); ?>
    <li><a href="<?= APP_URL ?>/komunikaty/announcement.php?id=<?= (int)$_a['id'] ?>"<?= $_new ? ' class="is-new"' : '' ?>>
      <b><?= !empty($_a['is_pinned']) ? '<i class="bi bi-pin-angle-fill me-1" aria-hidden="true"></i>' : '' ?><?= h($_a['title'] ?? '') ?></b>
      <small><?= $_new ? '<strong>nowy</strong> · ' : '' ?><?= h(!empty($_a['created_at']) ? date('d.m.Y', strtotime($_a['created_at'])) : '') ?><?= !empty($_a['author_name']) ? ' · ' . h($_a['author_name']) : '' ?></small>
    </a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php if ($tasks !== null): ?>
<section class="sf-box" aria-labelledby="sf-task-h">
  <div class="sf-h">
    <h2 id="sf-task-h"><span class="sq" style="--c:var(--m-orange)" aria-hidden="true"><i class="bi bi-check2-square"></i></span>Zadania</h2>
    <a href="<?= APP_URL ?>/tasks/index.php">Wszystkie<span class="visually-hidden"> zadania</span></a>
  </div>
  <?php if (!$tasks): ?>
  <p class="sf-empty"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Nie masz aktywnych zadań.</p>
  <?php else: ?>
  <ul class="sf-list">
    <?php foreach ($tasks as $_t): $_due = (string)($_t['due_date'] ?? ''); $_late = $_due !== '' && substr($_due, 0, 10) < $_sfToday; ?>
    <li><a href="<?= APP_URL ?>/tasks/index.php?task=<?= (int)$_t['id'] ?>"<?= $_late ? ' class="is-late"' : '' ?>>
      <b><?= h($_t['title'] ?? '') ?></b>
      <small><?= $_due !== '' ? ($_late ? '<span class="late">po terminie</span> · ' : 'termin ') . h(date('d.m.Y', strtotime($_due))) : 'bez terminu' ?></small>
    </a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
