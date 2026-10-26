<?php
/**
 * espis/index.php — PUBLICZNA część eSpisu (bez logowania):
 *   /espis/             — „Sprawdź dokument”: wpisz kod ze stopki dokumentu (Code128) → stan: obowiązuje / unieważniony / nieaktualna rewizja,
 *   /espis/?c=KOD       — wynik weryfikacji kodu (bez danych osobowych; limit zapytań z IP),
 *   /espis/?t=TOKEN     — eSpis koszulki EZD (QR ze stopki): lista dokumentów z numerami, kodami, rewizją, stanem; podgląd wersji elektronicznej
 *                         tylko dla zalogowanych z dostępem do koszulki EZD (dane osobowe).
 * Nagłówki: noindex, no-referrer, DENY. Strona dostępna (skip-link, etykiety, kontrast, tryb ciemny, widok do druku).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/espis/lib/Espis.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
header('X-Frame-Options: DENY');
if (isset($_GET['login'])) require_login();   // przekierowanie do logowania SZO i powrót na ten eSpis

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$org = defined('ORG_NAME') ? ORG_NAME : '';
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$mode = isset($_GET['t']) ? 'espis' : (isset($_GET['c']) ? 'verify' : 'home');
$title = 'Sprawdź dokument'; $status = 200;
$sprawa = null; $docs = []; $canPreview = false; $loggedIn = false; $res = null; $codeIn = trim((string)($_GET['c'] ?? ''));

if ($mode === 'espis') {
    $sid = Espis::parseToken((string)$_GET['t']);
    try {
        require_once dirname(__DIR__) . '/includes/ezd.php';
        if ($sid) {
            $sprawa = ezd_sprawa_get($sid); $docs = $sprawa ? Espis::documents($sid) : [];
            // Podgląd wersji elektronicznej: dokumenty zawierają dane osobowe — tylko zalogowani z dostępem do tej koszulki EZD (reguła EZD)
            $u = current_user(); $loggedIn = (bool)$u;
            $canPreview = $sprawa && $u && ezd_sprawa_access($sprawa, (int)$u['id']) !== null;
        }
    } catch (\Throwable $ex) { $sprawa = null; }
    if (!$sprawa) { $status = 404; $title = 'Nie znaleziono eSpisu'; } else { $title = 'eSpis — koszulka ' . $sprawa['znak_sprawy']; }
} elseif ($mode === 'verify') {
    if ($codeIn === '') { $mode = 'home'; }
    elseif (Espis::throttled($ip)) { $status = 429; $title = 'Zbyt wiele zapytań'; $mode = 'throttled'; }
    else { $res = Espis::verify($codeIn); Espis::logLookup($ip, $res['found']); $title = 'Wynik weryfikacji'; }
}
http_response_code($status);
$nValid = count(array_filter($docs, fn($d) => $d['status'] === 'valid')); $nVoid = count($docs) - $nValid;
$stateInfo = ['valid' => ['OBOWIĄZUJE', '#008a00'], 'void' => ['NIEAKTUALNY / UNIEWAŻNIONY', '#a20025'], 'pending' => ['CZEKA NA PODPIS', '#b45309'], 'unknown' => ['NIEZNANY', '#475569']];
$form = function (string $val = '') use ($e) { ?>
  <form method="get" action="" role="search" aria-label="Sprawdź dokument po kodzie">
    <label for="kod"><b>Kod dokumentu</b> (spod kodu kreskowego w stopce, np. <code>LD000002/AM7-1/R2</code>, <code>KD000123/DOP1/45</code>, <code>AMSK-000012</code>)</label>
    <div class="row"><input id="kod" name="c" value="<?= $e($val) ?>" required maxlength="100" autocomplete="off" autocapitalize="characters" spellcheck="false" inputmode="text" aria-describedby="kodhelp">
      <button type="submit">Sprawdź</button></div>
    <p id="kodhelp" class="small">Kod z QR otwiera eSpis całej sprawy. Wynik nie zawiera danych osobowych.</p>
  </form>
<?php };
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> — <?= $e($org) ?></title>
<style>
  :root{--bg:#f4f4f4;--card:#fff;--ink:#1d1d1d;--line:#c9c9c9;--blue:#0050ef;--green:#007a00;--red:#a20025;--steel:#4b5b6b;--focus:#ff9800}
  @media (prefers-color-scheme: dark){:root{--bg:#1b1b1b;--card:#262626;--ink:#f2f2f2;--line:#4a4a4a;--steel:#aebccb;--blue:#6aa0ff;--green:#4ade80}}
  *{box-sizing:border-box} body{margin:0;font-family:"Segoe UI","Open Sans",system-ui,sans-serif;background:var(--bg);color:var(--ink);line-height:1.5}
  a{color:var(--blue)} :focus-visible{outline:3px solid var(--focus);outline-offset:2px}
  .skip{position:absolute;left:-999px;top:0;background:#fff;color:#000;padding:.5rem 1rem;z-index:10} .skip:focus{left:0}
  header.top{background:#1d1d1d;color:#fff;padding:.7rem 1rem;display:flex;gap:1rem;flex-wrap:wrap;align-items:center} header.top a{color:#fff}
  header.top .brand{font-weight:700;margin-right:auto}
  main{max-width:62rem;margin:0 auto;padding:1rem}
  h1{font-size:1.5rem;font-weight:300;margin:.4rem 0 1rem} h2{font-size:1.05rem;margin:0 0 .6rem}
  .box{background:var(--card);border:1px solid var(--line);padding:1rem 1.1rem;margin-bottom:1rem}
  .hero{color:#fff;padding:1rem 1.2rem;font-size:1.05rem;margin-bottom:1rem}
  .state{display:inline-block;color:#fff;font-weight:700;padding:.25rem .8rem;letter-spacing:.03em}
  form .row{display:flex;gap:.5rem;margin-top:.4rem} input{flex:1;font:inherit;padding:.55rem .7rem;border:2px solid var(--line);background:var(--card);color:var(--ink);min-width:0}
  button,a.btn{font:inherit;background:var(--blue);color:#fff;border:0;padding:.5rem 1rem;cursor:pointer;text-decoration:none;display:inline-block;font-weight:600} @media (prefers-color-scheme: dark){button,a.btn{color:#0b1220}}
  table{width:100%;border-collapse:collapse;font-size:.92rem}th,td{text-align:left;padding:.45rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}
  th{font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;color:var(--steel)} .tw{overflow-x:auto}
  .chip{display:inline-block;color:#fff;padding:.05rem .5rem;font-size:.78rem;font-weight:700}
  .void td{color:var(--steel)} .void td:first-child{text-decoration:line-through}
  code{font-size:.88rem;word-break:break-all} .small{font-size:.85rem;color:var(--steel)} dl{display:grid;grid-template-columns:max-content 1fr;gap:.3rem 1rem;margin:.6rem 0}
  dt{color:var(--steel)} dd{margin:0}
  iframe.pv{width:100%;height:78vh;border:1px solid var(--line);background:#fff} .lock{border:1px dashed var(--steel);padding:.7rem 1rem}
  footer{max-width:62rem;margin:0 auto;padding:0 1rem 2rem} @media print{header.top,form,.noprint,iframe,.skip{display:none!important} body{background:#fff;color:#000} .box,.hero{border:1px solid #000;color:#000;background:#fff}}
</style></head><body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="top"><span class="brand"><?= $e($org) ?> · eSpis</span><nav aria-label="Menu"><a href="<?= $e(strtok((string)($_SERVER['REQUEST_URI'] ?? '/espis/'), '?')) ?>">Sprawdź dokument</a></nav></header>
<main id="tresc">
<?php if ($mode === 'home'): ?>
  <h1>Sprawdź dokument</h1>
  <div class="box"><?php $form(''); ?></div>
  <div class="box"><h2>Jak to działa</h2>
    <p>Każdy dokument wystawiony przez <?= $e($org) ?> ma w stopce <b>kod kreskowy Code128</b> (a część dokumentów także <b>kod QR</b>). Wpisz kod powyżej, aby sprawdzić, czy dokument <b>obowiązuje</b>, czy został <b>unieważniony</b> lub zastąpiony nowszą wersją (rewizją).</p>
    <p class="small">Zeskanowanie kodu QR otwiera eSpis całej sprawy — listę jej dokumentów. Strona nie pokazuje danych osobowych; pełna treść dokumentów jest dostępna tylko osobom uprawnionym po zalogowaniu.</p></div>
<?php elseif ($mode === 'throttled'): ?>
  <div class="hero" style="background:var(--red)" role="alert">Wykonano zbyt wiele zapytań z tego adresu. Spróbuj ponownie za kilkanaście minut.</div>
<?php elseif ($mode === 'verify'): ?>
  <h1>Wynik weryfikacji</h1>
  <div class="box" aria-live="polite">
    <p>Kod: <code><?= $e($res['code']) ?></code></p>
    <?php if ($res['found']): [$lbl, $col] = $stateInfo[$res['state']] ?? $stateInfo['unknown']; ?>
      <p><span class="state" style="background:<?= $e($col) ?>"><?= $e($lbl) ?></span></p>
      <dl>
        <dt>Dokument</dt><dd><?= $e($res['label']) ?></dd>
        <?php if ($res['number'] !== ''): ?><dt>Numer sprawy / dokumentu</dt><dd><?= $e($res['number']) ?></dd><?php endif; ?>
        <?php if ($res['revision'] > 0): ?><dt>Rewizja druku</dt><dd>R<?= (int)$res['revision'] ?><?= $res['current'] > 0 && $res['current'] !== $res['revision'] ? ' — obecnie obowiązuje R' . (int)$res['current'] : '' ?></dd><?php endif; ?>
        <?php if ($res['date'] !== ''): ?><dt>Data wystawienia</dt><dd><?= $e(date('d.m.Y', strtotime($res['date']))) ?></dd><?php endif; ?>
      </dl>
      <?php if ($res['state'] === 'void'): ?><p role="alert"><b>Ten dokument jest nieaktualny.</b> Nie korzystaj z niego — poproś o aktualną wersję.</p><?php endif; ?>
      <?php if ($res['note'] !== ''): ?><p class="small"><?= $e($res['note']) ?></p><?php endif; ?>
      <?php if ($res['espis_url'] !== ''): ?><p><a class="btn" href="<?= $e($res['espis_url']) ?>">Otwórz eSpis sprawy</a></p><?php endif; ?>
    <?php else: ?>
      <p><span class="state" style="background:#475569">NIE ZNALEZIONO</span></p><p><?= $e($res['note']) ?></p>
    <?php endif; ?>
  </div>
  <div class="box noprint"><h2>Sprawdź inny kod</h2><?php $form(''); ?></div>
<?php elseif ($mode === 'espis' && !$sprawa): ?>
  <div class="hero" style="background:var(--red)" role="alert">Nie znaleziono spisu o tym kodzie. Sprawdź, czy kod QR jest czytelny, albo <a href="./" style="color:#fff">sprawdź dokument po kodzie</a>.</div>
<?php else: ?>
  <h1>eSpis koszulki EZD <?= $e($sprawa['znak_sprawy']) ?></h1>
  <div class="hero" style="background:var(--blue)" role="status"><?= $e(Espis::LABELS[$sprawa['ref_type'] ?? ''] ?? 'Dokumenty') ?> · obowiązujących dokumentów: <b><?= (int)$nValid ?></b><?= $nVoid ? ', unieważnionych: <b>' . (int)$nVoid . '</b>' : '' ?> · stan na <?= $e(date('d.m.Y H:i')) ?></div>
  <div class="box"><div class="tw">
    <table>
      <caption class="small" style="text-align:left;padding-bottom:.4rem">Dokumenty koszulki — obowiązujące najpierw</caption>
      <thead><tr><th scope="col">Dokument</th><th scope="col">Numer</th><th scope="col">Kod</th><th scope="col">Rewizja</th><th scope="col">Stan</th><th scope="col">Data</th><th scope="col" class="noprint">Wersja elektroniczna</th></tr></thead>
      <tbody>
      <?php if (!$docs): ?><tr><td colspan="7" class="small">Koszulka nie zawiera jeszcze zarejestrowanych dokumentów.</td></tr><?php endif; ?>
      <?php foreach ($docs as $d): $v = $d['status'] === 'void'; ?>
        <tr class="<?= $v ? 'void' : '' ?>"><td><?= $e($d['label']) ?></td><td><?= $e($d['doc_number']) ?></td><td><a href="?c=<?= $e(urlencode((string)$d['code'])) ?>"><code><?= $e($d['code']) ?></code></a></td><td>R<?= (int)$d['revision'] ?></td>
          <td><span class="chip" style="background:<?= $v ? 'var(--red)' : 'var(--green)' ?>"><?= $v ? 'UNIEWAŻNIONY' : 'Obowiązuje' ?></span></td>
          <td><?= $e(substr((string)$d['created_at'], 0, 10)) ?></td>
          <td class="noprint"><?php if ($canPreview && !empty($d['zalacznik_id'])): ?><a class="btn" href="<?= $e(rtrim(APP_URL, '/') . '/ezd/serve.php?id=' . (int)$d['zalacznik_id']) ?>" target="pv" rel="noopener">Podgląd<span style="position:absolute;left:-999px"> dokumentu <?= $e($d['doc_number']) ?></span></a><?php elseif (!$canPreview): ?><span class="small">po zalogowaniu</span><?php else: ?><span class="small">brak pliku</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="small">Druk z niższą rewizją niż obowiązująca albo oznaczony „UNIEWAŻNIONY” jest nieaktualny. Spis nie zawiera danych osobowych.</p>
  </div>
  <div class="box noprint" id="podglad"><h2>Podgląd wersji elektronicznej</h2>
    <?php if ($canPreview): $first = null; foreach ($docs as $d) if ($d['status'] === 'valid' && !empty($d['zalacznik_id'])) { $first = $d; break; } ?>
      <?php if ($first): ?><iframe class="pv" name="pv" title="Podgląd wybranego dokumentu" src="<?= $e(rtrim(APP_URL, '/') . '/ezd/serve.php?id=' . (int)$first['zalacznik_id']) ?>"></iframe>
        <p class="small">Wyświetlono: <?= $e($first['label']) ?> (<?= $e($first['doc_number']) ?>, R<?= (int)$first['revision'] ?>). Wybierz „Podgląd” w tabeli, aby zobaczyć inny dokument.</p>
      <?php else: ?><p class="small">Brak dokumentów z plikiem do podglądu.</p><?php endif; ?>
    <?php else: ?>
      <div class="lock" role="note">Wersja elektroniczna dokumentów zawiera dane osobowe, dlatego podgląd jest dostępny po zalogowaniu osobom z dostępem do tej koszulki EZD.
        <?php if (!$loggedIn): ?><p><a class="btn" href="<?= $e('?t=' . urlencode((string)($_GET['t'] ?? '')) . '&login=1') ?>">Zaloguj się</a></p>
        <?php else: ?><p class="small">Jesteś zalogowany(-a), ale bez dostępu do tej koszulki.</p><?php endif; ?></div>
    <?php endif; ?>
  </div>
  <div class="box noprint"><h2>Sprawdź kod dokumentu</h2><?php $form(''); ?></div>
<?php endif; ?>
</main>
<footer><p class="small"><?= $e($org) ?> · wersja elektroniczna spisu dokumentów. Strona nie przetwarza danych osobowych.</p></footer>
</body></html>
