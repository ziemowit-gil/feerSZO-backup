<?php
/**
 * crm/ooo.php — „Poza biurem”: własna nieobecność (każdy) oraz nieobecności wszystkich osób (administrator).
 * Logika: includes/user_availability.php. Nieobecnej osoby nie da się wskazać jako opiekuna rozmowy w Skrzynce CRM,
 * a rozmowy, w których odpisze nadawca, wracają do „bez opiekuna”.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/user_availability.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('inbox', 'read');
crm_migrate();
uav_migrate();
$PAGE_TITLE = 'Poza biurem';
$me = (int)(current_user()['id'] ?? 0);
$isAdmin = is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $target = $isAdmin ? ((int)($_POST['user_id'] ?? $me) ?: $me) : $me;   // zwykły użytkownik zmienia tylko siebie
    $away = !empty($_POST['away']);
    if (uav_set($target, $away, (string)($_POST['from_date'] ?? ''), (string)($_POST['to_date'] ?? ''), (string)($_POST['note'] ?? ''), $me)) {
        flash_set('success', $away ? 'Nieobecność ustawiona.' : 'Nieobecność wyłączona.');
    } else {
        flash_set('danger', 'Data końca nie może być wcześniejsza niż początek.');
    }
    header('Location: ' . APP_URL . '/crm/ooo.php'); exit;
}

$mine = uav_get($me);
$users = $isAdmin ? db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name") : [];
$all = [];
if ($isAdmin) foreach (db_all("SELECT * FROM user_availability WHERE away = 1") as $r) $all[(int)$r['user_id']] = $r;
$names = []; foreach ($users as $u) $names[(int)$u['id']] = $u['name'];
$recent = $isAdmin ? db_all("SELECT l.*, c.subject, u.name AS uname FROM crm_ooo_log l LEFT JOIN crm_communications c ON c.id = l.comm_id LEFT JOIN users u ON u.id = l.old_user ORDER BY l.id DESC LIMIT 15") : [];

include __DIR__ . '/includes/header_crm.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/crm/inbox.php" class="btn btn-sm btn-outline-secondary" aria-label="Wróć do Skrzynki CRM"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
  <h1 class="h4 mb-0"><i class="bi bi-airplane me-2" aria-hidden="true"></i>Poza biurem</h1>
</div>
<p class="small text-muted" style="max-width:780px">Gdy jesteś poza biurem, inni nie mogą przypisać Ci rozmów w Skrzynce CRM (na liście opiekunów widać „poza biurem”), a rozmowy, w których odpisze nadawca,
  wracają do „bez opiekuna” — żeby nikt nie czekał na kogoś, kogo nie ma. Nieobecność z datą końca wygasa sama.
  Dotyczy ręcznego przypisywania w Skrzynce CRM; API i reguły automatyczne mogą jej nie respektować.</p>

<div class="card shadow-sm mb-3" style="max-width:640px"><div class="card-body">
  <h2 class="h6 mb-2">Moja dostępność <?= uav_row_active($mine) ? '<span class="badge bg-warning text-dark ms-1">poza biurem — ' . h(uav_period_label($mine)) . '</span>' : '<span class="badge bg-success ms-1">w biurze</span>' ?></h2>
  <form method="post" class="row g-2">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="col-12"><div class="form-check"><input id="o-a" class="form-check-input" type="checkbox" name="away" value="1"<?= $mine && (int)$mine['away'] ? ' checked' : '' ?>>
      <label for="o-a" class="form-check-label">Jestem poza biurem</label></div></div>
    <div class="col-6"><label for="o-f" class="form-label small mb-1">Od (opcjonalnie)</label><input id="o-f" type="date" name="from_date" value="<?= h($mine['from_date'] ?? '') ?>" class="form-control form-control-sm"></div>
    <div class="col-6"><label for="o-t" class="form-label small mb-1">Do (opcjonalnie — po tej dacie wracam automatycznie)</label><input id="o-t" type="date" name="to_date" value="<?= h($mine['to_date'] ?? '') ?>" class="form-control form-control-sm"></div>
    <div class="col-12"><label for="o-n" class="form-label small mb-1">Notatka (widoczna dla zespołu w tej stronie, do 200 znaków)</label><input id="o-n" name="note" maxlength="200" value="<?= h($mine['note'] ?? '') ?>" class="form-control form-control-sm" placeholder="np. urlop, szkolenie"></div>
    <div class="col-12"><button class="btn btn-sm btn-primary">Zapisz</button></div>
  </form>
</div></div>

<?php if ($isAdmin): ?>
<div class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h6 mb-2">Nieobecności zespołu (administrator)</h2>
  <?php if (!$all): ?><p class="small text-muted mb-2">Nikt nie jest teraz oznaczony jako poza biurem.</p>
  <?php else: ?>
  <ul class="list-unstyled small mb-2">
    <?php foreach ($all as $uid => $r): $act = uav_row_active($r); ?>
    <li class="mb-1"><b><?= h($names[$uid] ?? ('#' . $uid)) ?></b> — <?= h(uav_period_label($r)) ?><?= $r['note'] !== '' ? ' · ' . h($r['note']) : '' ?>
      <?= $act ? '' : '<span class="badge bg-secondary">jeszcze nie trwa / minęła</span>' ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <form method="post" class="row g-2 align-items-end">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="col-12 col-md-4"><label for="oa-u" class="form-label small mb-1">Ustaw nieobecność osobie</label>
      <select id="oa-u" name="user_id" class="form-select form-select-sm"><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label for="oa-f" class="form-label small mb-1">Od</label><input id="oa-f" type="date" name="from_date" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label for="oa-t" class="form-label small mb-1">Do</label><input id="oa-t" type="date" name="to_date" class="form-control form-control-sm"></div>
    <div class="col-12 col-md-3"><label for="oa-n" class="form-label small mb-1">Notatka</label><input id="oa-n" name="note" maxlength="200" class="form-control form-control-sm"></div>
    <div class="col-auto"><div class="form-check mb-1"><input id="oa-a" class="form-check-input" type="checkbox" name="away" value="1" checked><label for="oa-a" class="form-check-label small">poza biurem</label></div></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Zapisz</button></div>
  </form>
  <p class="small text-muted mt-2 mb-0">Aby przywrócić osobę do biura, odznacz „poza biurem” i zapisz.</p>
</div></div>

<?php if ($recent): ?>
<div class="card shadow-sm"><div class="card-body">
  <h2 class="h6 mb-2">Ostatnio odpięte rozmowy</h2>
  <ul class="list-unstyled small mb-0">
    <?php foreach ($recent as $r): ?><li><?= h(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?> — „<?= h($r['subject'] ?: '(bez tematu)') ?>” odpięta od <b><?= h($r['uname'] ?: ('#' . $r['old_user'])) ?></b> (<?= h($r['reason']) ?>)</li><?php endforeach; ?>
  </ul>
</div></div>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer_crm.php'; ?>
