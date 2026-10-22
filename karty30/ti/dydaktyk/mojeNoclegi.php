<?php
/**
 * karty30/ti/dydaktyk/mojeNoclegi.php — zgłoszenia noclegów dydaktyka (self-service) w panelu dydaktyka.
 *
 * Wpina moduł Noclegi (modules/noclegi) w panel TI: dydaktyk zgłasza nocleg, widzi status, pokój i instrukcję zameldowania.
 * Dostęp tylko z zaznaczonym „Dop. noclegi” na umowie (noclEligible). Zarządzanie (rooming, budżety, faktury, role)
 * jest w module: /modules/noclegi/router.php. Osobne od karty „Noclegi kadry” (noclegi.php — flagi noc przed/po).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/noclegi/logic/noclegi.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
karty30_migrate();
$eligible = noclEligible($uid);
$errors = []; $form = [];

if ($eligible && $_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'cancel') {
        $r = noclRequestGet((int)($_POST['id'] ?? 0));
        $err = $r && noclCanView($r, $uid) ? noclTransition((int)$r['id'], 'anulowane', $uid) : 'Nie znaleziono zgłoszenia.';
        flash_set($err ? 'warning' : 'success', $err ?? 'Zgłoszenie anulowane.');
        header('Location: mojeNoclegi.php'); exit;
    }
    [$d, $errors] = noclRequestValidate($_POST);
    if (!$errors) {
        noclRequestCreate($d, $uid, $uid, 'form');
        flash_set('success', 'Zgłoszenie zapisane (status: Wniosek). Dostaniesz potwierdzenie z pokojem i instrukcją zameldowania.');
        header('Location: mojeNoclegi.php'); exit;
    }
    $form = $_POST;
    flash_set('warning', 'Popraw zaznaczone pola.');
}
if (!$form) $form = ['person_name' => (string)($me['name'] ?? ''), 'person_email' => (string)(db_one("SELECT email FROM users WHERE id = ?", [$uid])['email'] ?? ''), 'person_role' => 'dydaktyk'];

$rows = $eligible ? noclRequestList($uid, 'own') : [];
$d = fn($x) => date('d.m.Y', strtotime($x));
$cards = [];
foreach ($rows as $r) {
    if (in_array($r['status'], ['potwierdzone', 'zrealizowane'], true) && $r['date_to'] >= date('Y-m-d') && ($ins = noclInstruction($r, noclCodesVisible($r, $uid)))) $cards[$r['id']] = $ins;
}

$KP_TITLE  = 'Zgłoszenia noclegów — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
$_ncl_css = dirname(__DIR__, 3) . '/modules/noclegi/view/noclegi.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<link rel="stylesheet" href="<?= h(APP_URL) ?>/modules/noclegi/view/noclegi.css?v=<?= is_file($_ncl_css) ? (int)filemtime($_ncl_css) : 1 ?>">
<main class="ncl" id="main-content">
<h1>Zgłoszenia noclegów</h1>
<?= flash_html() ?>
<?php if (!$eligible): ?>
  <div class="alert alert-info" role="alert">Moduł Noclegi jest dostępny dla osób, których umowa ma zaznaczone „Dop. noclegi”. Jeśli potrzebujesz noclegu, poproś opiekuna umowy o zaznaczenie tej opcji.</div>
<?php else: ?>
  <p class="ncl-lead">Zgłoś nocleg na szkolenie lub wydarzenie. Koordynator Projektu akceptuje koszt, Logistyk przydziela pokój. Noc przed i po szkoleniu stacjonarnym zaznacza kierownik w karcie „Noclegi kadry” — trafia tu automatycznie po imporcie.
  <?php if (noclIsLogistyk($uid) || noclIsKoordynator($uid)): ?> Zarządzanie: <a href="<?= h(APP_URL) ?>/modules/noclegi/router.php">moduł Noclegi</a>.<?php endif; ?></p>

  <?php foreach ($cards as $rid => $ins): ?>
  <section class="ncl-card" aria-labelledby="ins-<?= (int)$rid ?>">
    <h2 id="ins-<?= (int)$rid ?>" class="mt-0">Instrukcja zameldowania: <?= h($ins['facility']['name']) ?>, <?= h($d($ins['arrive'])) ?></h2>
    <dl class="ncl-kv">
      <dt>Adres</dt><dd><?= h($ins['address']) ?><?php if ($ins['map']): ?> — <a href="<?= h($ins['map']) ?>" target="_blank" rel="noopener">mapa (nowa karta)</a><?php endif; ?></dd>
      <dt>Termin</dt><dd><?= h($d($ins['arrive'])) ?> – <?= h($d($ins['leave'])) ?></dd>
      <dt>Zameldowanie</dt><dd><?= h($ins['checkin']) ?>, wymeldowanie do <?= h($ins['checkout']) ?></dd>
      <dt>Pokój</dt><dd><?= h($ins['room']) ?></dd>
      <?php if ($ins['codes']): ?>
        <?php if ($ins['door_code'] !== ''): ?><dt>Kod do drzwi</dt><dd><span class="ncl-code"><?= h($ins['door_code']) ?></span></dd><?php endif; ?>
        <?php if ($ins['lockbox'] !== ''): ?><dt>Skrytka na klucz</dt><dd><span class="ncl-code"><?= h($ins['lockbox']) ?></span></dd><?php endif; ?>
      <?php else: ?><dt>Kod do drzwi</dt><dd class="ncl-muted">pojawi się tu od <?= h($d($ins['reveal_from'])) ?></dd><?php endif; ?>
    </dl>
    <?php if ($ins['access_info']): ?><p class="mt-2 mb-0"><strong>Jak wejść:</strong><br><?= nl2br(h($ins['access_info'])) ?></p><?php endif; ?>
  </section>
  <?php endforeach; ?>

  <h2>Moje zgłoszenia</h2>
  <?php if (!$rows): ?><p class="ncl-muted">Nie masz jeszcze zgłoszeń.</p><?php else: ?>
  <div class="ncl-scroll"><table class="ncl-table"><caption>Moje zgłoszenia noclegów</caption>
    <thead><tr><th scope="col">Termin</th><th scope="col">Cel</th><th scope="col">Obiekt / pokój</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr>
      <td><?= h($d($r['date_from'])) ?> – <?= h($d($r['date_to'])) ?></td><td><?= h($r['purpose']) ?></td>
      <td><?= $r['room_name'] ? h($r['facility_name'] . ', pok. ' . $r['room_name']) : '<span class="ncl-muted">jeszcze nie przydzielono</span>' ?></td>
      <td><span class="badge <?= h(noclStatusBadge($r['status'])) ?>"><?= h(noclStatusLabel($r['status'])) ?></span></td>
      <td><?php if (in_array($r['status'], ['wniosek', 'w_trakcie', 'potwierdzone'], true) && $r['date_from'] >= date('Y-m-d')): ?>
        <form method="post" onsubmit="return confirm('Anulować to zgłoszenie?');"><input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-outline-danger">Anuluj<span class="visually-hidden"> zgłoszenie z <?= h($d($r['date_from'])) ?></span></button></form><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>

  <h2>Nowe zgłoszenie</h2>
  <?php if ($errors): ?><div class="alert alert-danger" role="alert">Formularz zawiera błędy — popraw zaznaczone pola.</div><?php endif; ?>
  <form method="post" novalidate>
    <input type="hidden" name="_token" value="<?= dyd_token() ?>">
    <?php $f = $form; $budgets = []; $showOther = false; include dirname(__DIR__, 3) . '/modules/noclegi/view/_requestFields.php'; ?>
    <button class="btn btn-primary" type="submit">Wyślij zgłoszenie</button>
  </form>
<?php endif; ?>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
