<?php
/**
 * espis/index.php — PUBLICZNY eSpis koszulki EZD (QR ze stopki dokumentów): lista dokumentów koszulki z numerami, kodami, rewizją i stanem.
 * Dostęp po tokenie HMAC (?t=E{id}.{mac}); bez logowania; BEZ danych osobowych (tytuł koszulki i nazwiska nie są pokazywane).
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

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (isset($_GET['login'])) require_login();   // przekierowanie do logowania SZO i powrót na ten eSpis
$sid = Espis::parseToken((string)($_GET['t'] ?? ''));
$sprawa = null; $docs = []; $canPreview = false; $loggedIn = false;
if ($sid) {
    try {
        require_once dirname(__DIR__) . '/includes/ezd.php';
        $sprawa = ezd_sprawa_get($sid); $docs = $sprawa ? Espis::documents($sid) : [];
        // Podgląd wersji elektronicznej: dokumenty zawierają dane osobowe — tylko zalogowani z dostępem do tej koszulki EZD (ta sama reguła co w EZD)
        $u = current_user(); $loggedIn = (bool)$u;
        $canPreview = $sprawa && $u && ezd_sprawa_access($sprawa, (int)$u['id']) !== null;
    } catch (\Throwable $ex) { $sprawa = null; }
}
if (!$sid || !$sprawa) {
    http_response_code(404);
    $title = 'Nie znaleziono eSpisu'; $msg = 'Nie znaleziono spisu o tym kodzie. Sprawdź, czy kod QR jest czytelny.';
} else {
    $title = 'eSpis dokumentów — koszulka ' . $sprawa['znak_sprawy'];
    $msg = '';
}
$org = defined('ORG_NAME') ? ORG_NAME : '';
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> — <?= $e($org) ?></title>
<style>
  :root{--bg:#f4f4f4;--card:#fff;--ink:#1d1d1d;--line:#d6d6d6;--blue:#0050ef;--green:#008a00;--red:#a20025;--steel:#647687}
  @media (prefers-color-scheme: dark){:root{--bg:#1d1d1d;--card:#2a2a2a;--ink:#f2f2f2;--line:#444;--steel:#9fb0c0}}
  body{margin:0;font-family:"Segoe UI","Open Sans",system-ui,sans-serif;background:var(--bg);color:var(--ink)}
  header{background:#1d1d1d;color:#fff;padding:.8rem 1rem}
  main{max-width:60rem;margin:0 auto;padding:1rem}
  .box{background:var(--card);border:1px solid var(--line);padding:1rem;margin-bottom:1rem}
  .hero{background:var(--blue);color:#fff;padding:1rem 1.2rem;font-size:1.1rem}
  table{width:100%;border-collapse:collapse;font-size:.92rem}th,td{text-align:left;padding:.45rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}
  th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:var(--steel)}
  .chip{display:inline-block;color:#fff;padding:.05rem .5rem;font-size:.78rem;font-weight:600}
  .void td{color:var(--steel)} .void td:first-child{text-decoration:line-through}
  code{font-size:.85rem} .small{font-size:.85rem;color:var(--steel)}
  a.btn{display:inline-block;background:var(--blue);color:#fff;padding:.15rem .6rem;text-decoration:none;font-size:.82rem;font-weight:600}
  iframe.pv{width:100%;height:78vh;border:1px solid var(--line);background:#fff}
  .lock{background:var(--card);border:1px dashed var(--steel);padding:.7rem 1rem}
</style></head><body>
<header><b><?= $e($org) ?></b> · eSpis</header>
<main>
<?php if ($msg !== ''): ?>
  <div class="hero" style="background:var(--red)" role="alert"><?= $e($msg) ?></div>
<?php else: ?>
  <div class="hero" role="status">eSpis koszulki EZD <b><?= $e($sprawa['znak_sprawy']) ?></b> — <?= $e(Espis::LABELS[$sprawa['ref_type'] ?? ''] ?? 'Dokumenty') ?>. Stan na <?= $e(date('d.m.Y H:i')) ?>.</div>
  <div class="box">
    <table>
      <thead><tr><th scope="col">Dokument</th><th scope="col">Numer</th><th scope="col">Kod</th><th scope="col">Rewizja</th><th scope="col">Stan</th><th scope="col">Data</th><th scope="col">Wersja elektroniczna</th></tr></thead>
      <tbody>
      <?php if (!$docs): ?><tr><td colspan="7" class="small">Koszulka nie zawiera jeszcze zarejestrowanych dokumentów.</td></tr><?php endif; ?>
      <?php foreach ($docs as $d): $v = $d['status'] === 'void'; ?>
        <tr class="<?= $v ? 'void' : '' ?>"><td><?= $e($d['label']) ?></td><td><?= $e($d['doc_number']) ?></td><td><code><?= $e($d['code']) ?></code></td><td>R<?= (int)$d['revision'] ?></td>
          <td><span class="chip" style="background:<?= $v ? 'var(--red)' : 'var(--green)' ?>"><?= $v ? 'UNIEWAŻNIONY' : 'Obowiązuje' ?></span></td>
          <td><?= $e(substr((string)$d['created_at'], 0, 10)) ?></td>
          <td><?php if ($canPreview && !empty($d['zalacznik_id'])): ?><a class="btn" href="<?= $e(rtrim(APP_URL, '/') . '/ezd/serve.php?id=' . (int)$d['zalacznik_id']) ?>" target="pv" rel="noopener">Podgląd</a><?php elseif (!$canPreview): ?><span class="small">po zalogowaniu</span><?php else: ?><span class="small">brak pliku</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small">Druk z niższą rewizją niż obowiązująca albo oznaczony „UNIEWAŻNIONY” jest nieaktualny. Spis nie zawiera danych osobowych.</p>
  </div>
  <div class="box" id="podglad">
    <h2 style="margin:0 0 .6rem;font-size:1.05rem;font-weight:600">Podgląd wersji elektronicznej</h2>
    <?php if ($canPreview): $first = null; foreach ($docs as $d) if ($d['status'] === 'valid' && !empty($d['zalacznik_id'])) { $first = $d; break; } ?>
      <?php if ($first): ?><iframe class="pv" name="pv" title="Podgląd wybranego dokumentu" src="<?= $e(rtrim(APP_URL, '/') . '/ezd/serve.php?id=' . (int)$first['zalacznik_id']) ?>"></iframe>
        <p class="small">Wyświetlono: <?= $e($first['label']) ?> (<?= $e($first['doc_number']) ?>, R<?= (int)$first['revision'] ?>). Wybierz „Podgląd” w tabeli, aby zobaczyć inny dokument.</p>
      <?php else: ?><p class="small">Brak dokumentów z plikiem do podglądu.</p><?php endif; ?>
    <?php else: ?>
      <div class="lock" role="note">Wersja elektroniczna dokumentów zawiera dane osobowe, dlatego podgląd jest dostępny po zalogowaniu osobom z dostępem do tej koszulki EZD.
        <?php if (!$loggedIn): ?><br><a class="btn" style="margin-top:.5rem" href="<?= $e('?t=' . urlencode((string)($_GET['t'] ?? '')) . '&login=1') ?>">Zaloguj się</a>
        <?php else: ?><br><span class="small">Zalogowano, ale bez dostępu do tej koszulki.</span><?php endif; ?></div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</main></body></html>
