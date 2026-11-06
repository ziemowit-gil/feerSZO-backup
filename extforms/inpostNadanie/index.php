<?php
/**
 * extforms/inpostNadanie/index.php — publiczny formularz nadania przesyłki InPost (ShipX).
 * Dostępny BEZ logowania (opcjonalnie z kluczem ?k=… z ustawień). Nadawca = osoba wypełniająca,
 * odbiorca = organizacja (z ustawień). Zabezpieczenia: klucz linku, honeypot, token czasu,
 * limity nadań na IP/godzinę i globalnie na dobę. Wynik: sam numer przesyłki (kod nadania do wpisania w Paczkomacie), bez etykiety.
 * WCAG: etykiety for/id, błędy powiązane aria-describedby, podsumowanie błędów z fokusem.
 */
$_root = dirname(__DIR__, 2);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/includes/functions.php';
require_once $_root . '/includes/auth.php';
require_once $_root . '/modules/inpost_shipx/logic/inpostShipx.php';

inpostMigrate();
auth_start();

$self = APP_URL . '/extforms/inpostNadanie/';
$key  = inpostSetting('public_key');
$keyQ = $key !== '' ? '?k=' . rawurlencode($key) : '';
$keyOk = $key === '' || hash_equals($key, (string)($_GET['k'] ?? $_POST['k'] ?? ''));

$sizes = inpostParcelSizes();
$errors = [];
$old = ['name'=>'','email'=>'','phone'=>'','street'=>'','building'=>'','post_code'=>'','city'=>'','size'=>'small','service'=>'inpost_locker_standard','target_point'=>'','reference'=>''];
$done = null;
$enabled = inpostSetting('public_enabled', '0') === '1' && inpostConfigured();

if ($enabled && $keyOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) $old[$k] = trim((string)($_POST[$k] ?? $old[$k]));
    $old['phone'] = preg_replace('/[\s-]/', '', preg_replace('/^\+48/', '', $old['phone']));
    $spam = trim((string)($_POST['website'] ?? '')) !== '' || (time() - (int)($_SESSION['inpost_ts'] ?? time())) < 3;
    if ($spam) { http_response_code(429); exit('Nie można przetworzyć formularza.'); }

    if (mb_strlen($old['name']) < 3 || !str_contains($old['name'], ' ')) $errors['name'] = 'Podaj imię i nazwisko.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Podaj poprawny adres e-mail.';
    if (!preg_match('/^[1-9]\d{8}$/', $old['phone'])) $errors['phone'] = 'Podaj 9-cyfrowy numer telefonu komórkowego, np. 600100200.';
    if ($old['street'] === '') $errors['street'] = 'Podaj ulicę.';
    if ($old['building'] === '') $errors['building'] = 'Podaj numer budynku.';
    if (!preg_match('/^\d{2}-\d{3}$/', $old['post_code'])) $errors['post_code'] = 'Podaj kod pocztowy w formacie 00-000.';
    if ($old['city'] === '') $errors['city'] = 'Podaj miejscowość.';
    if (!isset($sizes[$old['size']])) $errors['size'] = 'Wybierz rozmiar paczki.';
    if (!in_array($old['service'], ['inpost_locker_standard', 'inpost_courier_standard'], true)) $errors['service'] = 'Wybierz rodzaj usługi.';

    $point = strtoupper(inpostSetting('receiver_point')); // odbiorca definiowany wyłącznie przez admina
    if ($old['service'] === 'inpost_locker_standard') {
        if (!preg_match('/^[A-Z]{3}\d{2,3}[A-Z]{0,2}$/', $point)) $errors['_'] = 'Formularz nie jest skonfigurowany (brak Paczkomatu odbiorcy). Skontaktuj się z organizacją.';
        elseif (inpostPointExists($point) === false) $errors['target_point'] = 'Nie znaleziono Paczkomatu o kodzie ' . $point . '.';
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $perIp = (int)inpostSetting('limit_ip_hour', '5');
    $perDay = (int)inpostSetting('limit_day', '100');
    if (!$errors) {
        $c1 = (int)(db_one("SELECT COUNT(*) c FROM inpost_shipments WHERE client_ip=? AND created_at > datetime('now','localtime','-1 hour')", [$ip])['c'] ?? 0);
        $c2 = (int)(db_one("SELECT COUNT(*) c FROM inpost_shipments WHERE created_at > datetime('now','localtime','-1 day')")['c'] ?? 0);
        if ($c1 >= $perIp) $errors['_'] = 'Przekroczono limit nadań z tego adresu. Spróbuj ponownie za godzinę.';
        elseif ($c2 >= $perDay) $errors['_'] = 'Dzienny limit nadań został wyczerpany. Spróbuj jutro lub skontaktuj się z organizacją.';
    }
    if (!$errors) {
        try {
            $id = inpostCreateShipment([
                'service' => $old['service'], 'size' => $old['size'], 'client_ip' => $ip,
                'reference' => $old['reference'] ?: 'Formularz publiczny',
                'sender' => ['name'=>$old['name'],'email'=>$old['email'],'phone'=>$old['phone'],'street'=>$old['street'],'building'=>$old['building'],'city'=>$old['city'],'post_code'=>$old['post_code']],
                'receiver' => ['name'=>inpostSetting('receiver_name', ORG_NAME ?? 'Odbiorca'),'email'=>inpostSetting('receiver_email'),'phone'=>inpostSetting('receiver_phone'),
                    'target_point'=>$point,'street'=>inpostSetting('receiver_street'),'building'=>inpostSetting('receiver_building'),
                    'city'=>inpostSetting('receiver_city'),'post_code'=>inpostSetting('receiver_post_code')],
            ]);
            $done = db_one("SELECT * FROM inpost_shipments WHERE id=?", [$id]);
        } catch (Throwable $e) {
            $errors['_'] = $e->getMessage();
        }
    }
} elseif (empty($_SESSION['inpost_ts'])) {
    $_SESSION['inpost_ts'] = time();
}
$_SESSION['inpost_ts'] = $_SESSION['inpost_ts'] ?? time();

$err = fn(string $f) => isset($errors[$f]) ? '<div id="e_' . $f . '" class="err" role="alert">' . h($errors[$f]) . '</div>' : '';
$aria = fn(string $f) => isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="e_' . $f . '"' : '';
$field = function (string $f, string $label, string $extra = '') use ($old, $err, $aria) {
    return '<div class="f"><label for="' . $f . '">' . $label . '</label><input id="' . $f . '" name="' . $f . '" value="' . h($old[$f]) . '" required ' . $extra . $aria($f) . '>' . $err($f) . '</div>';
};
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nadaj przesyłkę InPost</title>
<style>
body{font:16px/1.5 system-ui,sans-serif;margin:0;background:#f4f6f8;color:#1a1a1a}
main{max-width:640px;margin:0 auto;padding:16px}
.card{background:#fff;border:1px solid #c9d1d9;border-radius:6px;padding:20px;margin-bottom:16px}
h1{font-size:1.5rem;margin:0 0 8px} fieldset{border:1px solid #c9d1d9;border-radius:6px;margin:0 0 16px;padding:12px 16px} legend{font-weight:600;padding:0 6px}
.f{margin-bottom:12px} label{display:block;font-weight:600;margin-bottom:4px}
input,select{width:100%;box-sizing:border-box;padding:8px;font:inherit;border:2px solid #6b7785;border-radius:4px;background:#fff;color:#1a1a1a}
input:focus,select:focus,button:focus,a:focus{outline:3px solid #0b5fff;outline-offset:2px}
[aria-invalid=true]{border-color:#b00020}.err{color:#b00020;font-weight:600;margin-top:4px}
button,.btn{display:inline-block;background:#0b5fff;color:#fff;border:0;border-radius:4px;padding:10px 20px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
.sum{border:2px solid #b00020;background:#fdecee;padding:12px;border-radius:6px;margin-bottom:16px}
.ok{border:2px solid #1b7a3a;background:#e8f6ec}.code{font:700 1.6rem/1.3 ui-monospace,monospace;word-break:break-all}
.hp{position:absolute;left:-9999px}.sb{background:#fff3cd;border:1px solid #c9a400;padding:6px 10px;border-radius:4px;margin-bottom:12px}
</style></head><body><main>
<h1>Nadaj przesyłkę InPost</h1>
<?php if (!$enabled || !$keyOk): ?>
  <div class="card" role="alert">Formularz jest obecnie niedostępny.</div>
<?php elseif ($done): ?>
  <section class="card ok" aria-labelledby="okh"><h2 id="okh" tabindex="-1" style="margin-top:0">Przesyłka została utworzona</h2>
    <p>Numer przesyłki (kod nadania):</p><p class="code"><?= $done['tracking_number'] !== '' ? h($done['tracking_number']) : 'nadawany — odśwież za chwilę' ?></p>
    <?php if ($done['service'] === 'inpost_locker_standard'): ?>
    <p>W Paczkomacie wybierz „Nadaj paczkę”, wpisz numer telefonu <strong><?= h($done['sender_phone']) ?></strong> oraz powyższy numer przesyłki, albo użyj aplikacji InPost Mobile.</p>
    <?php else: ?><p>Kurier odbierze paczkę z podanego adresu; numer przesyłki podaj kurierowi.</p><?php endif; ?>
    <?php if ($done['sandbox']): ?><p class="sb">Tryb testowy (sandbox) — przesyłka nie jest prawdziwa.</p><?php endif; ?>
  </section>
  <script>document.getElementById('okh').focus()</script>
<?php else: ?>
  <?php if (inpostSetting('sandbox','1')==='1'): ?><p class="sb">Tryb testowy (sandbox) — nie powstaną prawdziwe przesyłki.</p><?php endif; ?>
  <?php if ($errors): ?><div class="sum" role="alert" tabindex="-1" id="sum"><strong>Formularz zawiera błędy:</strong><ul>
    <?php foreach ($errors as $f => $m): ?><li><?= $f === '_' ? h($m) : '<a href="#' . h($f) . '">' . h($m) . '</a>' ?></li><?php endforeach; ?></ul></div>
    <script>document.getElementById('sum').focus()</script><?php endif; ?>
  <form method="post" action="<?= h($self . $keyQ) ?>" novalidate class="card">
    <?= csrf_field() ?><input type="hidden" name="k" value="<?= h($key !== '' ? (string)($_GET['k'] ?? $_POST['k'] ?? '') : '') ?>">
    <div class="hp" aria-hidden="true"><label>Strona www<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <fieldset><legend>Nadawca (Ty)</legend>
      <?= $field('name', 'Imię i nazwisko', 'autocomplete="name"') ?>
      <?= $field('email', 'Adres e-mail', 'type="email" autocomplete="email"') ?>
      <?= $field('phone', 'Telefon komórkowy (9 cyfr)', 'type="tel" inputmode="numeric" autocomplete="tel-national"') ?>
      <?= $field('street', 'Ulica', 'autocomplete="address-line1"') ?>
      <?= $field('building', 'Numer budynku / lokalu', '') ?>
      <?= $field('post_code', 'Kod pocztowy', 'inputmode="numeric" autocomplete="postal-code" placeholder="00-000"') ?>
      <?= $field('city', 'Miejscowość', 'autocomplete="address-level2"') ?>
    </fieldset>
    <fieldset><legend>Przesyłka</legend>
      <div class="f"><label for="service">Rodzaj usługi</label><select id="service" name="service"<?= $aria('service') ?>>
        <option value="inpost_locker_standard"<?= $old['service']==='inpost_locker_standard'?' selected':'' ?>>Paczkomat (nadanie w Paczkomacie)</option>
        <option value="inpost_courier_standard"<?= $old['service']==='inpost_courier_standard'?' selected':'' ?>>Kurier (odbiór spod adresu)</option></select><?= $err('service') ?></div>
      <div class="f"><label for="size">Rozmiar paczki</label><select id="size" name="size"<?= $aria('size') ?>>
        <?php foreach ($sizes as $k => $l): ?><option value="<?= h($k) ?>"<?= $old['size']===$k?' selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select><?= $err('size') ?></div>
    </fieldset>
    <button type="submit">Utwórz przesyłkę</button>
  </form>
<?php endif; ?>
</main></body></html>
