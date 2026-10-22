<?php
/**
 * panel/noclegi.php — zakładka „Noclegi” w panelu współpracownika (zleceniobiorca / wolontariusz).
 *
 * Self-service modułu Noclegi (modules/noclegi): zgłoszenie noclegu, status, pokój i instrukcja zameldowania
 * (kod do drzwi widoczny N dni przed przyjazdem). Dostęp tylko z zaznaczonym „Dop. noclegi” na umowie (noclEligible).
 * Zarządzanie (rooming, budżety, faktury, role) jest w module: /modules/noclegi/router.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/noclegi/logic/noclegi.php';

require_login();
$PAGE_TITLE = 'Noclegi';
$user = current_user();
$uid  = (int)$user['id'];
$_is_volunteer_only = panel_volunteer_shell($user);
$eligible = noclEligible($uid);
$errors = []; $form = [];

if ($eligible && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['_op'] ?? '') === 'cancel') {
        $r = noclRequestGet((int)($_POST['id'] ?? 0));
        $err = $r && noclCanView($r, $uid) ? noclTransition((int)$r['id'], 'anulowane', $uid) : 'Nie znaleziono zgłoszenia.';
        flash_set($err ? 'warning' : 'success', $err ?? 'Zgłoszenie zostało anulowane.');
        header('Location: noclegi.php'); exit;
    }
    [$d, $errors] = noclRequestValidate($_POST);
    if (!$errors) {
        noclRequestCreate($d, $uid, $uid, 'form');
        flash_set('success', 'Zgłoszenie zapisane (status: Wniosek). Dostaniesz potwierdzenie z pokojem i instrukcją zameldowania.');
        header('Location: noclegi.php'); exit;
    }
    $form = $_POST;
    flash_set('warning', 'Popraw zaznaczone pola.');
}
if (!$form) $form = ['person_name' => (string)($user['name'] ?? ''), 'person_email' => (string)($user['email'] ?? ''), 'person_role' => 'wolontariusz'];

noclAutoComplete();
$rows = $eligible ? noclRequestList($uid, 'own') : [];
$d = fn($x) => date('d.m.Y', strtotime($x));
$cards = [];
foreach ($rows as $r) {
    if (in_array($r['status'], ['potwierdzone', 'zrealizowane'], true) && $r['date_to'] >= date('Y-m-d') && ($ins = noclInstruction($r, noclCodesVisible($r, $uid)))) $cards[$r['id']] = $ins;
}
$_ncl_css = dirname(__DIR__) . '/modules/noclegi/view/noclegi.css';

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
    require_once __DIR__ . '/includes/pv_ui.php'; pv_ui_styles();
}
?>
<link rel="stylesheet" href="<?= h(APP_URL) ?>/modules/noclegi/view/noclegi.css?v=<?= is_file($_ncl_css) ? (int)filemtime($_ncl_css) : 1 ?>">
<div class="pv-wrap">
<main class="ncl" id="main-content">
  <p><a href="<?= h(APP_URL) ?>/panel/index.php">&larr; Mój panel</a></p>
  <h1>Noclegi</h1>
  <?= flash_html() ?>
  <?php if (!$eligible): ?>
    <div class="alert alert-info" role="alert">Moduł Noclegi jest dostępny dla osób, których umowa ma zaznaczone „Dop. noclegi”. Jeśli potrzebujesz noclegu, poproś opiekuna umowy o zaznaczenie tej opcji.</div>
  <?php else: ?>
    <p class="ncl-lead">Zgłoś nocleg na wydarzenie lub szkolenie. Koordynator Projektu akceptuje koszt, Logistyk przydziela pokój i potwierdza rezerwację. Po potwierdzeniu zobaczysz tu instrukcję zameldowania, a kod do drzwi lub skrytki — <?= (int)noclRevealDays() ?> dni przed przyjazdem.
    <?php if (noclIsLogistyk($uid) || noclIsKoordynator($uid)): ?> Zarządzanie: <a href="<?= h(APP_URL) ?>/modules/noclegi/router.php">moduł Noclegi</a>.<?php endif; ?></p>

    <?php foreach ($cards as $rid => $ins): ?>
    <section class="ncl-card" aria-labelledby="ins-<?= (int)$rid ?>">
      <h2 id="ins-<?= (int)$rid ?>" class="mt-0">Instrukcja zameldowania: <?= h($ins['facility']['name']) ?>, <?= h($d($ins['arrive'])) ?></h2>
      <dl class="ncl-kv">
        <dt>Adres</dt><dd><?= h($ins['address']) ?><?php if ($ins['map']): ?> — <a href="<?= h($ins['map']) ?>" target="_blank" rel="noopener">mapa (nowa karta)</a><?php endif; ?></dd>
        <dt>Termin</dt><dd><?= h($d($ins['arrive'])) ?> – <?= h($d($ins['leave'])) ?></dd>
        <dt>Zameldowanie</dt><dd><?= h($ins['checkin']) ?>, wymeldowanie do <?= h($ins['checkout']) ?></dd>
        <dt>Pokój</dt><dd><?= h($ins['room']) ?> (<?= h($ins['room_type']) ?>)<?= $ins['accessible'] ? ' — pokój dostosowany dla osób z niepełnosprawnościami' : '' ?></dd>
        <?php if ($ins['phone']): ?><dt>Kontakt</dt><dd><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $ins['phone'])) ?>"><?= h($ins['phone']) ?></a></dd><?php endif; ?>
        <?php if ($ins['codes']): ?>
          <?php if ($ins['door_code'] !== ''): ?><dt>Kod do drzwi</dt><dd><span class="ncl-code"><?= h($ins['door_code']) ?></span></dd><?php endif; ?>
          <?php if ($ins['lockbox'] !== ''): ?><dt>Skrytka na klucz</dt><dd><span class="ncl-code"><?= h($ins['lockbox']) ?></span></dd><?php endif; ?>
        <?php else: ?><dt>Kod do drzwi</dt><dd class="ncl-muted">pojawi się tu od <?= h($d($ins['reveal_from'])) ?></dd><?php endif; ?>
      </dl>
      <?php if ($ins['access_info']): ?><p class="mt-2 mb-1"><strong>Jak wejść:</strong><br><?= nl2br(h($ins['access_info'])) ?></p><?php endif; ?>
      <?php if ($ins['rules']): ?><p class="mb-0"><strong>Zasady pobytu:</strong><br><?= nl2br(h($ins['rules'])) ?></p><?php endif; ?>
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
          <form method="post" onsubmit="return confirm('Anulować to zgłoszenie?');"><?= csrf_field() ?><input type="hidden" name="_op" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">Anuluj<span class="visually-hidden"> zgłoszenie z <?= h($d($r['date_from'])) ?></span></button></form><?php endif; ?></td>
      </tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>

    <h2>Nowe zgłoszenie</h2>
    <?php if ($errors): ?><div class="alert alert-danger" role="alert">Formularz zawiera błędy — popraw zaznaczone pola.</div><?php endif; ?>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <?php $f = $form; $budgets = []; $showOther = false; include dirname(__DIR__) . '/modules/noclegi/view/_requestFields.php'; ?>
      <button class="btn btn-primary" type="submit">Wyślij zgłoszenie</button>
    </form>
  <?php endif; ?>
</main>
</div><!-- /.pv-wrap -->
<?php
if ($_is_volunteer_only) include __DIR__ . '/includes/footer_panel.php';
else include dirname(__DIR__) . '/includes/footer.php';
