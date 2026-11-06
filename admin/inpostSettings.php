<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/inpost_shipx/logic/inpostShipx.php';

require_role('admin');
inpostMigrate();
$PAGE_TITLE = 'InPost ShipX — ustawienia';
$test = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['_action'] ?? '') === 'save') {
        foreach (['org_id','receiver_name','receiver_email','receiver_phone','receiver_point','receiver_street','receiver_building',
                  'receiver_city','receiver_post_code','public_key','limit_ip_hour','limit_day'] as $k) {
            inpostSettingSave($k, trim((string)($_POST[$k] ?? '')));
        }
        // Token: pusty = bez zmian (nie wyświetlamy zapisanego tokenu)
        if (trim((string)($_POST['token'] ?? '')) !== '') inpostSettingSave('token', trim($_POST['token']));
        foreach (['sandbox','public_enabled'] as $k) inpostSettingSave($k, isset($_POST[$k]) ? '1' : '0');
        flash_set('success', 'Ustawienia InPost zapisane.');
        header('Location: ' . APP_URL . '/admin/inpostSettings.php'); exit;
    }
    if (($_POST['_action'] ?? '') === 'test') {
        try {
            [$c, $r] = inpostRequest('GET', '/v1/organizations/' . rawurlencode(inpostSetting('org_id')));
            $test = $c === 200 ? ['ok' => true, 'msg' => 'Połączono: ' . ($r['name'] ?? 'organizacja') . ' (' . (inpostSetting('sandbox','1')==='1'?'sandbox':'produkcja') . ')']
                               : ['ok' => false, 'msg' => inpostErrorText($c, $r)];
        } catch (Throwable $e) { $test = ['ok' => false, 'msg' => $e->getMessage()]; }
    }
}
$v = fn($k, $d = '') => h(inpostSetting($k, $d));
$key = inpostSetting('public_key');
$link = APP_URL . '/extforms/inpostNadanie/' . ($key !== '' ? '?k=' . rawurlencode($key) : '');
include dirname(__DIR__) . '/includes/header.php';
$in = fn($k, $l, $t = 'text') => '<div class="mb-3"><label class="form-label" for="i_' . $k . '">' . $l . '</label><input class="form-control" id="i_' . $k . '" name="' . $k . '" type="' . $t . '" value="' . h(inpostSetting($k)) . '"></div>';
?>
<div class="container py-3" style="max-width:760px">
  <h1 class="h4 fw-bold mb-3">InPost ShipX — ustawienia</h1>
  <?php if ($test): ?><div class="alert alert-<?= $test['ok'] ? 'success' : 'danger' ?>" role="alert"><?= h($test['msg']) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="save">
    <h2 class="h6">Połączenie</h2>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="i_sandbox" name="sandbox" <?= inpostSetting('sandbox','1')==='1'?'checked':'' ?>><label class="form-check-label" for="i_sandbox">Tryb testowy (sandbox) — bez prawdziwych przesyłek</label></div>
    <div class="mb-3"><label class="form-label" for="i_token">Token API organizacji <?= inpostSetting('token')!==''?'(zapisany — zostaw puste, aby nie zmieniać)':'' ?></label><input class="form-control" id="i_token" name="token" type="password" autocomplete="off"></div>
    <?= $in('org_id', 'ID organizacji', 'text') ?>
    <h2 class="h6 mt-4">Odbiorca przesyłek</h2>
    <?= $in('receiver_name','Nazwa / imię i nazwisko') ?><?= $in('receiver_email','E-mail','email') ?><?= $in('receiver_phone','Telefon (9 cyfr)','tel') ?>
    <?= $in('receiver_point','Paczkomat docelowy (np. KRA010)') ?>
    <p class="small text-muted">Adres poniżej służy tylko dla usługi kurierskiej.</p>
    <?= $in('receiver_street','Ulica') ?><?= $in('receiver_building','Nr budynku') ?><?= $in('receiver_post_code','Kod pocztowy') ?><?= $in('receiver_city','Miejscowość') ?>
    <h2 class="h6 mt-4">Formularz publiczny</h2>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="i_pe" name="public_enabled" <?= inpostSetting('public_enabled')==='1'?'checked':'' ?>><label class="form-check-label" for="i_pe">Włącz formularz publiczny</label></div>
    <?= $in('public_key','Klucz w linku (puste = link bez klucza)') ?>
    <?= $in('limit_ip_hour','Limit nadań z jednego IP na godzinę (domyślnie 5)','number') ?>
    <?= $in('limit_day','Limit nadań na dobę łącznie (domyślnie 100)','number') ?>
    <p>Link publiczny: <a href="<?= h($link) ?>"><?= h($link) ?></a></p>
    <button class="btn btn-primary">Zapisz</button>
  </form>
  <form method="post" class="mt-3"><?= csrf_field() ?><input type="hidden" name="_action" value="test"><button class="btn btn-outline-secondary">Testuj połączenie</button></form>
  <?php $rows = db_all("SELECT * FROM inpost_shipments ORDER BY id DESC LIMIT 30"); ?>
  <h2 class="h6 mt-4">Ostatnie przesyłki</h2>
  <div class="table-responsive"><table class="table table-sm"><caption class="visually-hidden">Ostatnie przesyłki InPost</caption>
    <thead><tr><th scope="col">Data</th><th scope="col">Nadawca</th><th scope="col">Numer</th><th scope="col">Status</th><th scope="col">Test</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= h($r['created_at']) ?></td><td><?= h($r['sender_name']) ?></td><td><?= h($r['tracking_number']) ?></td><td><?= h($r['status']) ?></td><td><?= $r['sandbox'] ? 'tak' : 'nie' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php';
