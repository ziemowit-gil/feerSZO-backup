<?php
/**
 * karty30/ti/kursant/zgody.php — „Zgody” w panelu kursanta i w panelu rodzica (?as=parent): zgody i akceptacje pogrupowane
 * (zapis na zajęcia, umowa, regulaminy, dokumenty do akceptacji), przy każdej status: Udzielona / Brak zgody / Odmowa / Czeka.
 * Tylko odczyt. Logika: modules/ti_consents/logic/tiConsents.php.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consents/logic/tiConsents.php';
require_once __DIR__ . '/auth.php';

// Kto patrzy: opiekun (panel rodzica) albo kursant — jak w dokumenty.php
$asParent = ($_GET['as'] ?? '') === 'parent';
if ($asParent || !student_current()) {
    $parent = parent_current();
    if (!$parent) { header('Location: ' . ($asParent ? 'parent.php' : 'login.php')); exit; }
    $kind = 'parent';
    $aid  = (int)$parent['student_id'];
} else {
    $student = student_require();
    $kind = 'student';
    $aid  = (int)$student['id'];
}
$acc = db_one("SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$aid]) ?: [];
$groups = TiConsents::forClient((int)($acc['client_id'] ?? 0), $aid);
$back = $kind === 'parent' ? 'parent.php' : 'index.php';
$msgUrl = $kind === 'parent' ? 'parent.php' : 'index.php?tab=wiadomosci';
$total = 0; $granted = 0; $missing = 0;
foreach ($groups as $g) foreach ($g['items'] as $it) { $total++; if ($it['state'] === 'granted') $granted++; elseif ($it['state'] === 'none') $missing++; }

$KP_TITLE  = 'Zgody — ' . ($kind === 'parent' ? 'panel rodzica' : 'panel kursanta');
$KP_TOPBAR = ['brand' => defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta', 'icon' => 'pc-display',
              'user' => $kind === 'parent' ? ('Opiekun: ' . ($acc['client_name'] ?? '')) : (string)($student['login'] ?? ''),
              'logout' => $kind === 'parent' ? 'parent.php?logout=1' : 'index.php?logout=1'];
include __DIR__ . '/_layout_head.php';
$ST = ['granted' => ['check-circle-fill', 'text-success'], 'none' => ['dash-circle', 'text-secondary'], 'declined' => ['x-circle-fill', 'text-danger'], 'pending' => ['hourglass-split', 'text-warning']];
?>
<style>
  .zg-card { border-radius:1rem; border:1px solid var(--bs-border-color) }
  .zg-ico { width:2.6rem; height:2.6rem; border-radius:.75rem; display:inline-flex; align-items:center; justify-content:center; background:var(--bs-primary-bg-subtle); color:var(--bs-primary); font-size:1.15rem; flex:0 0 auto }
  .zg-row { display:flex; align-items:center; gap:.85rem; padding:.7rem 0; border-top:1px solid var(--bs-border-color-translucent) }
  .zg-row:first-child { border-top:0 }
  .zg-row .zg-st { font-size:1.15rem; flex:0 0 auto }
  .zg-text { flex:1 1 auto; font-size:.92rem; color:var(--bs-body-color) }
  .zg-text small { display:block; color:var(--bs-secondary-color) }
  .zg-label { flex:0 0 auto; text-align:right; font-weight:700; font-size:.9rem; min-width:7.5rem }
  .zg-label small { display:block; font-weight:400; color:var(--bs-secondary-color); font-size:.75rem }
  .zg-granted { color:var(--bs-success-text-emphasis) } .zg-none { color:var(--bs-secondary-color) } .zg-declined { color:var(--bs-danger-text-emphasis) } .zg-pending { color:var(--bs-warning-text-emphasis) }
  @media (max-width:575px) { .zg-row { align-items:flex-start } .zg-label { min-width:5.5rem } }
</style>
<main id="main" class="container-xl px-3 py-4" style="max-width:1000px">
  <p class="mb-2"><a href="<?= h($back) ?>" class="small"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do panelu</a></p>
  <h1 class="h3 fw-bold mb-1">Zgody</h1>
  <p class="text-body-secondary mb-3">Zgody udzielone w umowach i zapisach oraz zaakceptowane regulaminy<?= $kind === 'parent' ? ' — kursant: <b>' . h($acc['client_name'] ?? '') . '</b>' : '' ?>.</p>

  <?php if ($total): ?>
  <p class="small mb-3" role="status"><span class="badge text-bg-success"><?= $granted ?> udzielonych</span>
    <?php if ($missing): ?><span class="badge text-bg-secondary ms-1"><?= $missing ?> bez zgody</span><?php endif; ?>
    <span class="text-body-secondary ms-1">z <?= $total ?> pozycji</span></p>
  <?php endif; ?>

  <?php if (!$groups): ?>
  <div class="alert alert-info" role="status">Nie ma jeszcze zapisanych zgód ani akceptacji. Pojawią się tutaj po zapisie na zajęcia, zawarciu umowy lub akceptacji regulaminu.</div>
  <?php endif; ?>

  <?php foreach ($groups as $gi => $g): ?>
  <section class="card zg-card shadow-sm mb-3" aria-labelledby="zg-t-<?= $gi ?>">
    <div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-2">
        <span class="zg-ico" aria-hidden="true"><i class="bi bi-<?= h($g['icon']) ?>"></i></span>
        <div><h2 class="h5 fw-bold mb-0" id="zg-t-<?= $gi ?>"><?= h($g['title']) ?></h2>
          <?php if ($g['subtitle'] !== ''): ?><div class="text-body-secondary small"><?= h($g['subtitle']) ?></div><?php endif; ?></div>
      </div>
      <ul class="list-unstyled mb-0">
        <?php foreach ($g['items'] as $it): [$ic, $cl] = $ST[$it['state']] ?? $ST['none']; ?>
        <li class="zg-row">
          <i class="bi bi-<?= $ic ?> zg-st <?= $cl ?>" aria-hidden="true"></i>
          <div class="zg-text"><?= h($it['text']) ?><?php if ($it['hint'] !== ''): ?><small><?= h($it['hint']) ?></small><?php endif; ?></div>
          <div class="zg-label zg-<?= h($it['state']) ?>"><?= h(TiConsents::STATE_LABELS[$it['state']] ?? $it['state']) ?>
            <?php if ($it['date'] !== '' || $it['by'] !== ''): ?><small><?= h(trim($it['date'] . ($it['by'] !== '' ? ' · ' . $it['by'] : ''), ' ·')) ?></small><?php endif; ?></div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
  <?php endforeach; ?>

  <p class="small text-body-secondary">Chcesz zmienić albo wycofać zgodę (np. na wizerunek lub informacje o zajęciach)? <a href="<?= h($msgUrl) ?>">Napisz do organizatora</a> — cofnięcie zgody nie wpływa na zgodność z prawem przetwarzania sprzed jej cofnięcia.</p>
</main>
<?php include __DIR__ . '/_layout_foot.php'; ?>
