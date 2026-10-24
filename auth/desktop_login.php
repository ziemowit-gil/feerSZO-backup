<?php
/**
 * Ekran zgody na logowanie aplikacji zewnętrznej (EZD Client, Wdrożenie MSUES…) (OAuth Authorization Code + PKCE, loopback).
 * Użytkownik loguje się standardowo (Microsoft 365), a tu tylko zatwierdza dostęp aplikacji.
 * Opis przepływu: includes/desktop_auth.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/includes/desktop_auth.php';

require_login();
desktop_auth_migrate();

$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$redirect  = (string)($src['redirect_uri'] ?? '');
$state     = (string)($src['state'] ?? '');
$challenge = (string)($src['code_challenge'] ?? '');
$device    = desktop_clean_device((string)($src['device'] ?? ''));

$scopeParam = desktop_parse_scope((string)($src['scope'] ?? ''));   // null = bez zawężenia, false = nieprawidłowy
$bad = $scopeParam === false
    || !desktop_valid_redirect($redirect)
    || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $state)
    || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge);
if ($bad) {
    http_response_code(400);
    exit('Nieprawidłowe żądanie logowania aplikacji. Uruchom logowanie ponownie z aplikacji.');
}

$knownApp = desktop_app_for_redirect($redirect);
if ($knownApp) {   // aplikacja z rejestru: tylko jej scope'y, nazwa z rejestru (nie z parametru device)
    $scopeParam = $scopeParam === null ? $knownApp['scopes'] : array_values(array_intersect($scopeParam, $knownApp['scopes']));
    $device = $knownApp['name'];
}
$user   = current_user();
$scopes = desktop_effective_scopes($user, $scopeParam);   // przecięcie z uprawnieniami konta
$back   = static fn(array $q) => $redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($q + ['state' => $state]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['decision'] ?? '') !== 'approve' || !$scopes) {
        header('Location: ' . $back(['error' => 'access_denied']));
        exit;
    }
    $code = bin2hex(random_bytes(32));
    db()->prepare("DELETE FROM desktop_auth_codes WHERE expires_at < ?")->execute([time() - 3600]);
    db()->prepare(
        "INSERT INTO desktop_auth_codes (code_hash, user_id, code_challenge, device, scope, expires_at) VALUES (?,?,?,?,?,?)"
    )->execute([hash('sha256', $code), (int)$user['id'], $challenge, $device, $scopeParam === null ? '' : implode(' ', $scopeParam), time() + DESKTOP_CODE_TTL_SEC]);
    header('Location: ' . $back(['code' => $code]));
    exit;
}
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

$app    = $device !== '' ? $device : 'EZD Client';
$host   = (string)parse_url($redirect, PHP_URL_HOST);
$port   = parse_url($redirect, PHP_URL_PORT);
$target = $host . ($port ? ':' . $port : '');
$limited = $scopeParam !== null;
$remote  = !in_array(strtolower($host), ['127.0.0.1', 'localhost', '[::1]'], true);   // adres lokalny to szczegół techniczny — pokazujemy tylko obcą stronę

/* Uprawnienia po polsku, pogrupowane; zapis wyróżniony osobno. Pokazujemy wyłącznie przyznawane scope'y. */
$groups = [
    'Dokumenty (EZD)' => ['ezd:read' => 'oglądanie spraw i pism', 'ezd:upload' => 'dodawanie plików i skanów do spraw',
                          'ezd:write' => 'zakładanie spraw i rejestrowanie pism'],
    'Kontakty (CRM)'   => ['crm:read' => 'oglądanie kontaktów', 'crm:write' => 'dodawanie i zmiana kontaktów oraz notatek'],
    'Wdrożenie MSUES' => ['msues:read' => 'oglądanie postępu wdrożenia', 'msues:write' => 'wpisywanie i zmiana danych wdrożenia'],
];
$readList = []; $writeList = [];
foreach ($groups as $gname => $items) {
    foreach ($items as $sc => $label) {
        if (!in_array($sc, $scopes, true)) continue;
        if (str_ends_with($sc, ':read')) $readList[] = [$gname, $label]; else $writeList[] = [$gname, $label];
    }
}
$hidden = '<input type="hidden" name="redirect_uri" value="' . h($redirect) . '"><input type="hidden" name="state" value="' . h($state) . '">'
        . '<input type="hidden" name="code_challenge" value="' . h($challenge) . '"><input type="hidden" name="device" value="' . h($device) . '">'
        . '<input type="hidden" name="scope" value="' . h($scopeParam === null ? '' : implode(' ', $scopeParam)) . '">';
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wejście do „<?= h($app) ?>” — logowanie</title>
<style>
 /* Metro UI: płaskie kafle, 0 zaokrągleń, Segoe UI; kontrasty ≥ 4,5:1 (biały na #006ba6 = 5,9:1, #111 na #f2f2f2 = 16:1) */
 *{box-sizing:border-box;border-radius:0}
 body{font-family:"Segoe UI","Segoe UI Web",system-ui,-apple-system,Roboto,Arial,sans-serif;font-size:1.125rem;background:#f2f2f2;color:#111;margin:0;min-height:100vh;display:flex;flex-direction:column}
 .dl-skip{position:absolute;left:1rem;top:-4rem;background:#111;color:#fff;padding:.7rem 1.1rem;font-weight:600;z-index:10}
 .dl-skip:focus{top:1rem}
 .dl-bar{background:#1d1d1d;color:#fff;padding:.9rem 1.5rem;font-size:1rem;letter-spacing:.02em}
 .dl-bar strong{font-weight:600}
 .dl-wrap{flex:1;display:flex;justify-content:center;padding:2rem 1rem}
 main{width:100%;max-width:640px}
 h1{font-size:2.2rem;font-weight:300;line-height:1.2;margin:0 0 1.4rem;color:#111}
 h2{font-size:1.25rem;font-weight:600;margin:0 0 .6rem;color:#111}
 p{margin:.5rem 0;line-height:1.55} ul{margin:.3rem 0;padding-left:1.4rem;line-height:1.7}
 li+li{margin-top:.25rem}
 .lead{font-size:1.2rem}.small{font-size:1rem;color:#333;margin:1rem 0}.muted{color:#333}
 .tiles{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:6px}
 .tile{background:#1d1d1d;color:#fff;padding:1rem 1.1rem;min-height:92px}
 .tile span{display:block;font-size:.95rem;color:#e6e6e6}
 .tile b{display:block;font-size:1.2rem;font-weight:600;margin-top:.25rem;word-break:break-all}
 .tile.host{background:#006ba6}.tile.host span{color:#fff}
 .panel{background:#fff;padding:1.2rem 1.3rem;margin-top:6px;border-left:8px solid #006ba6}
 .panel.write{border-left-color:#a4262c;background:#fff4ce}
 .panel.info{border-left-color:#1d1d1d}
 .tag{display:inline-block;background:#a4262c;color:#fff;font-size:.85rem;font-weight:600;padding:.1rem .5rem;margin-left:.4rem;vertical-align:middle}
 .alert{background:#a4262c;color:#fff;padding:1rem 1.2rem;margin:6px 0;font-weight:600;line-height:1.5}
 .row{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}
 button{font:inherit;font-weight:600;min-height:60px;min-width:44px;padding:.6rem 1.4rem;border:0;background:#1d1d1d;color:#fff;cursor:pointer;flex:1 1 200px;text-align:left}
 button.primary{background:#006ba6;flex:2 1 240px;font-size:1.2rem}
 button:hover{background:#000} button.primary:hover{background:#005283}
 :focus-visible{outline:3px solid #c50f1f!important;outline-offset:3px!important}
 @media(max-width:520px){.tiles{grid-template-columns:1fr}h1{font-size:1.8rem}}
</style></head><body>
<a class="dl-skip" href="#tresc">Przejdź do treści</a>
<div class="dl-bar"><strong>FEER</strong> · Konto SZO · logowanie</div>
<div class="dl-wrap"><main id="tresc" aria-labelledby="h">
  <h1 id="h">Wejść do „<?= h($app) ?>” swoim kontem?</h1>
  <p class="lead">Zalogujemy Cię tam jako <strong><?= h($user['name']) ?></strong>.</p>
  <?php if (!$scopes): ?>
    <p class="alert" role="alert">Nie masz uprawnień do „<?= h($app) ?>”, więc nie możemy Cię tam wpuścić. Napisz do administratora.</p>
    <form method="post"><?= csrf_field() ?><?= $hidden ?>
      <div class="row"><button name="decision" value="deny">Wróć</button></div>
    </form>
  <?php else: ?>
    <div class="panel">
      <h2>Będziesz tam mieć dostęp do:</h2>
      <ul>
        <?php foreach ($readList as [$g, $l]) echo '<li>' . h($g) . ': ' . h($l) . '</li>'; ?>
        <?php foreach ($writeList as [$g, $l]) echo '<li>' . h($g) . ': ' . h($l) . ' <span class="tag">zmienia dane</span></li>'; ?>
      </ul>
    </div>
    <p class="small">Jeśli to nie Ty chcesz tam wejść, kliknij „Nie”. Dostęp cofniesz, wylogowując się.<?= $remote && !$knownApp ? ' Po zatwierdzeniu wrócisz na stronę <strong>' . h($host) . '</strong>.' : '' ?></p>
    <form method="post"><?= csrf_field() ?><?= $hidden ?>
      <div class="row">
        <button class="primary" name="decision" value="approve" autofocus>Tak, wchodzę</button>
        <button name="decision" value="deny">Nie</button>
      </div>
    </form>
  <?php endif; ?>
</main></div></body></html>
