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
$old = ['name'=>'','email'=>'','phone'=>'','street'=>'','building'=>'','post_code'=>'','city'=>'','size'=>'small','service'=>'inpost_locker_standard','target_point'=>'','r_name'=>'','r_email'=>'','r_phone'=>'','reference'=>''];
$done = null;
$enabled = inpostSetting('public_enabled', '0') === '1' && inpostConfigured();
$allowCustom = inpostSetting('allow_locker_custom', '1') === '1';

if ($enabled && $keyOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $_) $old[$k] = trim((string)($_POST[$k] ?? $old[$k]));
    $old['phone'] = preg_replace('/[\s-]/', '', preg_replace('/^\+48/', '', $old['phone']));
    $old['r_phone'] = preg_replace('/[\s-]/', '', preg_replace('/^\+48/', '', $old['r_phone']));
    $custom = $allowCustom && $old['service'] === 'locker_custom';
    if ($custom) $old['service'] = 'inpost_locker_standard'; else $old['service'] = $old['service'] === 'locker_custom' ? 'inpost_locker_standard' : $old['service'];
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

    $point = $custom ? strtoupper($old['target_point']) : strtoupper(inpostSetting('receiver_point')); // domyślnie odbiorca z ustawień admina
    if ($custom) {
        if (mb_strlen($old['r_name']) < 3 || !str_contains($old['r_name'], ' ')) $errors['r_name'] = 'Podaj imię i nazwisko odbiorcy.';
        if (!filter_var($old['r_email'], FILTER_VALIDATE_EMAIL)) $errors['r_email'] = 'Podaj poprawny e-mail odbiorcy.';
        if (!preg_match('/^[1-9]\d{8}$/', $old['r_phone'])) $errors['r_phone'] = 'Podaj 9-cyfrowy telefon odbiorcy.';
    }
    if ($old['service'] === 'inpost_locker_standard') {
        if ($custom && !preg_match('/^[A-Z]{3}\d{2,3}[A-Z]{0,2}$/', $point)) $errors['target_point'] = 'Podaj kod Paczkomatu odbiorcy, np. KRA010.';
        elseif (!preg_match('/^[A-Z]{3}\d{2,3}[A-Z]{0,2}$/', $point)) $errors['_'] = 'Formularz nie jest skonfigurowany (brak Paczkomatu odbiorcy). Skontaktuj się z organizacją.';
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
                'receiver' => ['name'=>$custom ? $old['r_name'] : inpostSetting('receiver_name', ORG_NAME ?? 'Odbiorca'),'email'=>$custom ? $old['r_email'] : inpostSetting('receiver_email'),'phone'=>$custom ? $old['r_phone'] : inpostSetting('receiver_phone'),
                    'target_point'=>$point,'street'=>inpostSetting('receiver_street'),'building'=>inpostSetting('receiver_building'),
                    'city'=>inpostSetting('receiver_city'),'post_code'=>inpostSetting('receiver_post_code')],
            ]);
            $done = db_one("SELECT * FROM inpost_shipments WHERE id=?", [$id]);
            // SMS do nadawcy z numerem nadania (błąd SMS nie przerywa — numer i tak widać na stronie)
            if ($done['tracking_number'] !== '') {
                try {
                    require_once $_root . '/includes/sms.php';
                    $txt = $done['service'] === 'inpost_locker_standard'
                        ? 'Przesylka zamowiona. Nadasz ja w Paczkomacie, numer nadania: ' . $done['tracking_number'] . '. Oplate pokryla Fundacja.'
                        : 'Przesylka zamowiona. Kurier odbierze paczke, numer przesylki: ' . $done['tracking_number'] . '. Oplate pokryla Fundacja.';
                    sms_send($old['phone'], $txt, 'inpost_shipment');
                    db()->prepare("UPDATE inpost_shipments SET error=NULL WHERE id=?")->execute([$id]);
                } catch (Throwable $e) {
                    db()->prepare("UPDATE inpost_shipments SET error=? WHERE id=?")->execute(['SMS: ' . mb_substr($e->getMessage(), 0, 300), $id]);
                }
            }
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
:root{--c:#00758f;--cd:#005a6e;--ink:#1d1d1d;--bd:#6b6b6b}
*{border-radius:0!important}
body{font:16px/1.5 "Segoe UI","Helvetica Neue",Arial,sans-serif;margin:0;background:#fff;color:var(--ink)}
header.top{background:var(--c);color:#fff;padding:28px 16px 20px}
header.top div{max-width:640px;margin:0 auto}
header.top h1{font-size:2.4rem;font-weight:300;margin:0;letter-spacing:-.5px}
header.top p{margin:4px 0 0;font-weight:300;opacity:.95}
main{max-width:640px;margin:0 auto;padding:16px}
.card{background:#f3f3f3;border:0;border-left:8px solid var(--c);padding:20px;margin-bottom:16px}
fieldset{border:0;border-top:2px solid var(--c);margin:0 0 20px;padding:12px 0 0}
legend{font-size:1.25rem;font-weight:300;color:var(--c);padding:0 8px 0 0}
.f{margin-bottom:12px} label{display:block;font-weight:600;margin-bottom:4px}
input,select{width:100%;box-sizing:border-box;padding:10px;font:inherit;border:2px solid var(--bd);background:#fff;color:var(--ink)}
input:hover,select:hover{border-color:var(--c)}
input:focus,select:focus,button:focus,a:focus{outline:3px solid #000;outline-offset:2px;border-color:var(--c)}
[aria-invalid=true]{border-color:#a4001c}.err{color:#a4001c;font-weight:600;margin-top:4px}
button,.btn{display:inline-block;background:var(--c);color:#fff;border:0;padding:12px 28px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
button:hover,.btn:hover{background:var(--cd)}
.sum{border-left:8px solid #a4001c;background:#fbe9ec;padding:12px 16px;margin-bottom:16px}
.ok{background:var(--c);color:#fff;border-left:0;padding:24px}.ok h2{font-weight:300;font-size:1.8rem}
.code{font:700 2.2rem/1.2 "Segoe UI",ui-monospace,monospace;word-break:break-all;margin:8px 0}
.hp{position:absolute;left:-9999px}.sb{background:#ffd9a0;color:#1d1d1d;border-left:8px solid #c25400;padding:8px 12px;margin-bottom:12px}
.ok .sb{margin-top:12px}
</style></head><body><header class="top"><div><h1>Nadaj przesyłkę InPost</h1><p>Wygeneruj numer nadania do Paczkomatu</p></div></header>
<main>
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
        <option value="inpost_locker_standard"<?= ($old['service']==='inpost_locker_standard' && empty($custom))?' selected':'' ?>>Paczkomat (nadanie w Paczkomacie)</option>
        <?php if ($allowCustom): ?><option value="locker_custom"<?= !empty($custom) ? ' selected' : '' ?>>Paczkomat → Paczkomat (wskażę odbiorcę i Paczkomat)</option><?php endif; ?>
        <option value="inpost_courier_standard"<?= $old['service']==='inpost_courier_standard'?' selected':'' ?>>Kurier (odbiór spod adresu)</option></select><?= $err('service') ?></div>
      <div class="f"><label for="size">Rozmiar paczki</label><select id="size" name="size"<?= $aria('size') ?>>
        <?php foreach ($sizes as $k => $l): ?><option value="<?= h($k) ?>"<?= $old['size']===$k?' selected':'' ?>><?= h($l) ?></option><?php endforeach; ?></select><?= $err('size') ?></div>
    </fieldset>
    <?php if ($allowCustom): ?><fieldset id="rcpt"><legend>Odbiorca (Paczkomat → Paczkomat)</legend>
      <?= $field('r_name', 'Imię i nazwisko odbiorcy', 'autocomplete="off"') ?>
      <?= $field('r_email', 'E-mail odbiorcy', 'type="email" autocomplete="off"') ?>
      <?= $field('r_phone', 'Telefon odbiorcy (9 cyfr)', 'type="tel" inputmode="numeric" autocomplete="off"') ?>
      <?= $field('target_point', 'Kod Paczkomatu odbiorcy (np. KRA010)', 'autocomplete="off"') ?>
    </fieldset>
    <script>(function(){var s=document.getElementById('service'),f=document.getElementById('rcpt');function u(){var on=s.value==='locker_custom';f.hidden=!on;f.querySelectorAll('input').forEach(function(i){i.required=on;i.disabled=!on})}s.addEventListener('change',u);u()})()</script><?php endif; ?>
    <button type="submit">Utwórz przesyłkę</button>
  </form>
<?php endif; ?>
</main></body></html>
