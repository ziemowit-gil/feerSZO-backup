<?php
/**
 * karty30/ti/dydaktyk/konsultanciDostep.php — Dostęp konsultantów (kierownik / administrator, sesja pełna):
 *  1. dziennik logowań panelu (ti_panel_logins — dyd_login_log): kto, kiedy, którą drogą, adres IP, przeglądarka;
 *  2. konta konsultantów zewnętrznych z ostatnim logowaniem, oznaczenie nieużywanych > 90 dni, wyłączenie jednym
 *     kliknięciem (od razu kończy sesje i „zapamiętaj mnie”).
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultant_access/logic/consultantAccess.php';
if (!$staff || !empty($me['alt_konsultacje'])) { flash_set('warning', 'Dostęp konsultantów przegląda kierownik.'); header('Location: konsultacjeStart.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'ext_off' || $op === 'ext_on') {
        TiConsultantAccess::externalSetActive((int)($_POST['user_id'] ?? 0), $op === 'ext_on', $uid);
        flash_set('success', $op === 'ext_on' ? 'Konto włączone.' : 'Konto wyłączone — trwające sesje zakończą się przy następnym kliknięciu.');
    }
    header('Location: konsultanciDostep.php'); exit;
}

$ext = TiConsultantAccess::externalAccounts();   // zakłada też tabelę dziennika
$METHODS = ['ms365' => 'Microsoft 365', 'email' => 'E-mail + kod', 'numer' => 'Numer zastępczy', 'wznowienie' => 'Wznowienie („zapamiętaj mnie”)', 'impersonacja' => 'Impersonacja', 'inne' => 'Inne'];
$fUser = (int)($_GET['osoba'] ?? 0);
$fMeth = isset($METHODS[$_GET['droga'] ?? '']) ? (string)$_GET['droga'] : '';
$fFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['od'] ?? '')) ? (string)$_GET['od'] : date('Y-m-d', strtotime('-30 days'));
$where = ['l.created_at >= ?']; $par = [$fFrom . ' 00:00:00'];
if ($fUser) { $where[] = 'l.user_id = ?'; $par[] = $fUser; }
if ($fMeth !== '') { $where[] = 'l.method = ?'; $par[] = $fMeth; }
$log = db_all("SELECT l.*, u.name, u.email FROM ti_panel_logins l LEFT JOIN users u ON u.id = l.user_id WHERE " . implode(' AND ', $where) . " ORDER BY l.id DESC LIMIT 500", $par);
$who = db_all("SELECT DISTINCT u.id, u.name FROM ti_panel_logins l JOIN users u ON u.id = l.user_id ORDER BY u.name");
$ua = function (string $s): string {
    $b = preg_match('/Edg\//', $s) ? 'Edge' : (preg_match('/Chrome\//', $s) ? 'Chrome' : (preg_match('/Firefox\//', $s) ? 'Firefox' : (preg_match('/Safari\//', $s) ? 'Safari' : 'inna')));
    $os = preg_match('/Windows/', $s) ? 'Windows' : (preg_match('/Mac OS X/', $s) ? 'macOS' : (preg_match('/Android/', $s) ? 'Android' : (preg_match('/iPhone|iPad/', $s) ? 'iOS' : (preg_match('/Linux/', $s) ? 'Linux' : ''))));
    return $s === '' ? '—' : $b . ($os ? ' · ' . $os : '');
};
$RZ_TITLE = 'Dostęp konsultantów'; $RZ_CUR = 'konsultanciDostep.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-shield-lock text-primary me-2" aria-hidden="true"></i>Dostęp konsultantów</h1>
<?= flash_html() ?>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h2 class="h6 fw-bold">Konsultanci zewnętrzni</h2>
  <?php if (!$ext): ?><p class="small text-body-secondary mb-0">Brak kont z zaproszeń.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th scope="col">Osoba</th><th scope="col">Ostatnie logowanie</th><th scope="col">Stan</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
    <tbody>
    <?php foreach ($ext as $r): ?>
      <tr<?= $r['stale'] ? ' class="table-warning"' : '' ?>>
        <td><?= h($r['name']) ?><div class="small text-body-secondary"><?= h($r['email']) ?></div></td>
        <td class="small"><?= $r['last_at'] ? h(date('d.m.Y H:i', strtotime((string)$r['last_at']))) : 'nigdy' ?>
          <?php if ($r['stale']): ?><span class="badge text-bg-warning ms-1">nieużywane &gt; <?= TiConsultantAccess::STALE_DAYS ?> dni</span><?php endif; ?></td>
        <td><?= (int)$r['is_active'] ? 'aktywne' : '<span class="text-body-secondary">wyłączone</span>' ?></td>
        <td class="text-end"><form method="post"<?= (int)$r['is_active'] ? ' onsubmit="return confirm(\'Wyłączyć konto ' . h(addslashes((string)$r['name'])) . '? Sesje zakończą się od razu.\')"' : '' ?>>
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="_op" value="<?= (int)$r['is_active'] ? 'ext_off' : 'ext_on' ?>">
          <button class="btn btn-sm <?= (int)$r['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?> py-0"><?= (int)$r['is_active'] ? 'Wyłącz' : 'Włącz' ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div></div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h2 class="h6 fw-bold">Dziennik logowań do panelu</h2>
  <form method="get" class="row g-2 align-items-end mb-2">
    <div class="col-md-4"><label class="form-label small mb-0" for="dl-o">Osoba</label>
      <select class="form-select form-select-sm" id="dl-o" name="osoba"><option value="">wszyscy</option>
        <?php foreach ($who as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $fUser === (int)$w['id'] ? ' selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0" for="dl-d">Droga</label>
      <select class="form-select form-select-sm" id="dl-d" name="droga"><option value="">wszystkie</option>
        <?php foreach ($METHODS as $k => $l): ?><option value="<?= h($k) ?>"<?= $fMeth === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0" for="dl-od">Od dnia</label><input class="form-control form-control-sm" type="date" id="dl-od" name="od" value="<?= h($fFrom) ?>"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Pokaż</button></div>
  </form>
  <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0 small">
    <thead><tr><th scope="col">Kiedy</th><th scope="col">Osoba</th><th scope="col">Droga</th><th scope="col">Adres IP</th><th scope="col">Przeglądarka</th></tr></thead>
    <tbody>
    <?php if (!$log): ?><tr><td colspan="5" class="text-body-secondary">Brak logowań w wybranym okresie.</td></tr><?php endif; ?>
    <?php foreach ($log as $l): ?>
      <tr><td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$l['created_at']))) ?></td>
        <td><?= h($l['name'] ?? ('#' . $l['user_id'])) ?></td>
        <td><?= h($METHODS[$l['method']] ?? $l['method']) ?></td>
        <td class="font-monospace"><?= h((string)$l['ip']) ?></td>
        <td title="<?= h((string)$l['user_agent']) ?>"><?= h($ua((string)$l['user_agent'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="small text-body-secondary mt-2 mb-0">Do 500 ostatnich wpisów. Dziennik zbiera logowania od wdrożenia tej funkcji.</p>
</div></div>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
