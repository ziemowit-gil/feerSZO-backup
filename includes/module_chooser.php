<?php
/**
 * includes/module_chooser.php — Generyczny ekran wyboru modułu po zalogowaniu.
 *
 * Pokazywany, gdy użytkownik ma dostęp do więcej niż 2 modułów (panel
 * wolontariusza liczy się jako moduł). Dla kont zarządczych rolę „wyboru"
 * pełni pełny portal — ten ekran obsługuje wolontariuszy i konta zawężone.
 *
 * Wejście:
 *   $__choices — lista wpisów: ['label','desc','icon','grad','url','primary'(bool)]
 *                Pierwszy/„primary" wpis dostaje plakietkę „Twój moduł".
 *
 * Warstwa wizualna (2026-10-06): Metro UI (metroui.org.ua / Windows 8 Modern UI), spójnie
 * z Systemem Tożsamości — ciemny app-bar #1d1d1d, Segoe UI, ekran Start z kafli w jednolitych
 * kolorach (kolor kafla = ostatni kolor gradientu modułu z MODULE_REGISTRY), zero zaokrągleń
 * i cieni. Samodzielny CSS (bez Bootstrapa — tylko ikony). Skróty klawiszowe: 1–9.
 */
require_once __DIR__ . '/branding.php';

$_u     = current_user();
$_org   = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$_short = org_setting('org_short_name') ?: mb_substr($_org, 0, 28, 'UTF-8');
$_h     = (int)date('G');
$_greet = $_h < 5 ? 'Dobranoc' : ($_h < 12 ? 'Dzień dobry' : ($_h < 18 ? 'Witaj' : 'Dobry wieczór'));
$_fn    = explode(' ', trim($_u['first_name'] ?? $_u['name'] ?? $_u['email'] ?? 'Użytkowniku'))[0];
$_b     = branding_load();
$_name  = trim(($_u['first_name'] ?? '') . ' ' . ($_u['last_name'] ?? '')) ?: ($_u['name'] ?? '');
$_ini   = ''; foreach (preg_split('/\s+/', trim($_name)) as $w) $_ini .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
$_ini   = mb_substr($_ini, 0, 2, 'UTF-8') ?: '?';
$__choices = array_values(array_filter($__choices ?? []));
$_n = count($__choices);
$_dni = ['niedziela','poniedziałek','wtorek','środa','czwartek','piątek','sobota'];
$_mies = ['','stycznia','lutego','marca','kwietnia','maja','czerwca','lipca','sierpnia','września','października','listopada','grudnia'];
$_today = $_dni[(int)date('w')] . ', ' . (int)date('j') . ' ' . $_mies[(int)date('n')] . ' ' . date('Y');
// Jednolity kolor kafla z gradientu modułu (ostatni kolor); fallback: cyan Metro.
$_tile_color = function (string $grad): string {
    return preg_match_all('/#[0-9a-fA-F]{3,6}/', $grad, $m) ? end($m[0]) : '#1ba1e2';
};
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#1d1d1d">
<title><?= h($_org ?: 'System') ?> — wybór modułu</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
:root{--m-cyan:#1ba1e2;--m-cyan-d:#0e7bb3;--m-green:#008a00;--m-dark:#1d1d1d;--m-grey:#777;--m-light:#f2f2f2;--m-line:#e1e1e1}
*,*::before,*::after{box-sizing:border-box}
html,body{min-height:100vh;margin:0;font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif;background:#fff;color:#111;-webkit-font-smoothing:antialiased}
a{color:inherit}
.sr{position:absolute;left:-999px}.sr:focus{left:1rem;top:1rem;z-index:50;background:#fff;color:#111;padding:.5rem 1rem;font-weight:600;outline:3px solid #000}
/* app-bar */
.bar{height:56px;background:var(--m-dark);color:#fff;display:flex;align-items:center;position:sticky;top:0;z-index:40}
.bar .in{width:100%;max-width:1100px;margin:0 auto;padding:0 1rem;display:flex;align-items:center;gap:.75rem}
.brand{display:flex;align-items:center;gap:.65rem;text-decoration:none;color:#fff;min-width:0;flex:1}
.brand-img{height:30px;max-width:130px;object-fit:contain;background:#fff;padding:.15rem .4rem}
.brand-ic{width:34px;height:34px;background:var(--c,var(--m-cyan));color:#fff;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.brand b{display:block;font-weight:600;font-size:.95rem;line-height:1.05;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.brand small{display:block;font-size:.64rem;color:rgba(255,255,255,.65);font-weight:400}
.usr{display:inline-flex;align-items:center;gap:.6rem;padding:.2rem .4rem .2rem .2rem}
.ava{width:32px;height:32px;background:var(--c,var(--m-cyan));color:#fff;font-size:.74rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.usr .who{display:none;flex-direction:column;line-height:1.1}
.usr .who b{font-size:.78rem;font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.usr .who small{font-size:.64rem;color:rgba(255,255,255,.65);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media(min-width:640px){.usr .who{display:flex}}
.lnk{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .7rem;color:rgba(255,255,255,.85);text-decoration:none;font-size:.82rem;font-weight:500;white-space:nowrap;transition:background .12s,color .12s}
.lnk:hover{background:rgba(255,255,255,.12);color:#fff}
.lnk:focus-visible{outline:3px solid #fff;outline-offset:-3px}
.lnk--out:hover{background:#a20025}
/* nagłówek */
.head{max-width:1100px;margin:0 auto;padding:2rem 1rem 1.25rem;display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:.75rem 1.5rem}
.kicker{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.18em;color:#777;margin-bottom:.35rem}
.greet{font-size:clamp(1.9rem,4vw,2.6rem);font-weight:300;letter-spacing:-.02em;margin:0;line-height:1.05;color:#111}
.sub{font-size:.95rem;color:#555;margin:.6rem 0 0;font-weight:300}
.sub b{color:#111;font-weight:600}
.date{font-size:.8rem;color:#777;white-space:nowrap}
/* siatka kafli (Start) */
.wrap{max-width:1100px;margin:0 auto;padding:0 1rem 3rem}
.sec-h{font-size:1.3rem;font-weight:300;letter-spacing:-.01em;color:#111;margin:1.75rem 0 .75rem}
.sec-h:first-child{margin-top:0}
.sec-h b{font-weight:600}
.sec-h .hint{font-size:.78rem;color:#777;font-weight:400;margin-left:.75rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));grid-auto-rows:150px;gap:8px;grid-auto-flow:dense}
.tile{position:relative;display:flex;flex-direction:column;justify-content:space-between;padding:14px 16px;color:#fff;text-decoration:none;background:var(--t,var(--m-cyan));overflow:hidden;min-width:0;transition:transform .08s,filter .12s}
.tile:hover{filter:brightness(1.08);color:#fff}
.tile:active{transform:scale(.97)}
.tile:focus-visible{outline:3px solid #000;outline-offset:-3px}
.tile--w{grid-column:span 2}
.tile--l{grid-column:span 2;grid-row:span 2}
.tile .ic{font-size:2.1rem;line-height:1}
.tile--l .ic{font-size:3.6rem}
.tile .title{font-size:1rem;font-weight:600;line-height:1.2}
.tile--l .title{font-size:1.7rem;font-weight:300;letter-spacing:-.02em}
.tile .desc{font-size:.76rem;opacity:.88;margin-top:.2rem;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.tile--l .desc{font-size:.9rem;-webkit-line-clamp:3}
.tile .tag{position:absolute;top:10px;right:12px;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:rgba(0,0,0,.28);padding:2px 7px;display:inline-flex;align-items:center;gap:.3rem}
.tile .key{position:absolute;right:12px;bottom:12px;min-width:1.5rem;height:1.5rem;padding:0 .4rem;display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,.22);font:700 .72rem ui-monospace,SFMono-Regular,Menlo,monospace}
.tile .cta{font-size:.78rem;font-weight:600;display:inline-flex;align-items:center;gap:.3rem;margin-top:.5rem}
.tile:hover .cta i{transform:translateX(3px)}.tile .cta i{transition:transform .14s}
.note{background:var(--m-light);padding:.85rem 1rem;margin-top:1.75rem;font-size:.82rem;color:#444;border-left:6px solid var(--m-cyan)}
.foot{max-width:1100px;margin:0 auto;padding:1rem 1rem 2rem;font-size:.76rem;color:#777;display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;border-top:1px solid var(--m-line)}
.foot span:last-child{margin-left:auto}
@media(max-width:600px){.grid{grid-template-columns:repeat(2,1fr);grid-auto-rows:140px}.tile{padding:12px 13px}.tile .key{display:none}.tile--l .title{font-size:1.35rem}.d-sm,.who-txt{display:none}.bar .in{gap:.4rem}}
@media(prefers-reduced-motion:reduce){.tile,.tile .cta i{transition:none}}
</style>
</head>
<body>
<a href="#main" class="sr">Przejdź do treści</a>
<header class="bar">
  <div class="in">
    <a href="<?= APP_URL ?>/portal.php" class="brand">
      <?php if ($_b['logo_url']): ?>
      <img src="<?= h($_b['logo_url']) ?>" alt="<?= h($_org) ?>" class="brand-img">
      <?php else: ?>
      <span class="brand-ic" aria-hidden="true"><i class="bi bi-building-heart"></i></span>
      <?php endif; ?>
      <span><b><?= h($_short) ?></b><small>Strona startowa</small></span>
    </a>
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="lnk" title="System Tożsamości — konto, hasło, MFA"><i class="bi bi-person-vcard" aria-hidden="true"></i><span class="who-txt">Moje konto</span></a>
    <span class="usr" aria-label="Zalogowano jako <?= h($_name ?: $_fn) ?>">
      <span class="ava" aria-hidden="true"><?= h($_ini) ?></span>
      <span class="who" aria-hidden="true"><b><?= h($_name ?: $_fn) ?></b><small><?= h($_u['email'] ?? '') ?></small></span>
    </span>
    <a href="<?= APP_URL ?>/auth/logout.php" class="lnk lnk--out" onclick="return confirm('Wylogować się?')">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="d-sm">Wyloguj</span>
    </a>
  </div>
</header>

<header class="head" aria-labelledby="greet">
  <div>
    <div class="kicker">Start · <?= h($_short) ?></div>
    <h1 id="greet" class="greet"><?= h($_greet) ?>, <?= h($_fn) ?></h1>
    <p class="sub">Masz dostęp do <b><?= $_n ?> <?= $_n === 1 ? 'modułu' : 'modułów' ?></b>. Wybierz kafel, aby przejść dalej — na tę stronę wrócisz zawsze przez stronę startową.</p>
  </div>
  <span class="date"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= h($_today) ?></span>
</header>

<main id="main" class="wrap">
  <h2 class="sec-h">Twoje <b>moduły</b><span class="hint">klawisze 1–<?= min($_n, 9) ?> otwierają kolejne kafle</span></h2>
  <div class="grid" id="apps">
    <?php foreach ($__choices as $i => $c):
      $primary = !empty($c['primary']);
      $k = $i + 1;
    ?>
    <a href="<?= h($c['url']) ?>" class="tile<?= $primary ? ' tile--l' : ' tile--w' ?>" style="--t:<?= h($_tile_color((string)($c['grad'] ?? ''))) ?>" data-key="<?= $k <= 9 ? $k : '' ?>">
      <?php if ($primary): ?><span class="tag"><i class="bi bi-star-fill" aria-hidden="true"></i>Twój moduł</span><?php endif; ?>
      <i class="bi <?= h($c['icon']) ?> ic" aria-hidden="true"></i>
      <span>
        <span class="title"><?= h($c['label']) ?></span>
        <span class="desc"><?= h($c['desc'] ?? '') ?></span>
        <span class="cta">Otwórz <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
      </span>
      <?php if ($k <= 9): ?><kbd class="key" aria-label="skrót klawiszowy <?= $k ?>"><?= $k ?></kbd><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <h2 class="sec-h">Twoje <b>konto</b></h2>
  <div class="grid" style="grid-auto-rows:120px">
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="tile tile--w" style="--t:var(--m-cyan)">
      <i class="bi bi-person-vcard ic" aria-hidden="true"></i>
      <span><span class="title">System Tożsamości</span><span class="desc">Dane, hasło, telefon i metody weryfikacji</span></span>
    </a>
    <a href="<?= APP_URL ?>/tozsamosc/index.php#bezpieczenstwo" class="tile" style="--t:var(--m-green)">
      <i class="bi bi-shield-lock ic" aria-hidden="true"></i>
      <span><span class="title">Bezpieczeństwo</span><span class="desc">hasło · MFA · telefon</span></span>
    </a>
    <a href="<?= APP_URL ?>/auth/logout.php" class="tile" style="--t:var(--m-dark)" onclick="return confirm('Wylogować się?')">
      <i class="bi bi-box-arrow-right ic" aria-hidden="true"></i>
      <span><span class="title">Wyloguj</span><span class="desc"><?= h($_u['email'] ?? '') ?></span></span>
    </a>
  </div>

  <div class="note">
    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
    Moduł oznaczony gwiazdką to Twój moduł podstawowy; pozostałe zostały przyznane dodatkowo przez administratora.
  </div>
</main>
<footer class="foot">
  <span><i class="bi bi-shield-lock-fill" aria-hidden="true"></i> połączenie szyfrowane</span>
  <span>© <?= date('Y') ?> <?= h($_org) ?></span>
</footer>
<script>
  // Skróty klawiszowe 1–9 → kolejne moduły (poza polami formularzy).
  document.addEventListener('keydown', function (e) {
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    var t = e.target && e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return;
    if (!/^[1-9]$/.test(e.key)) return;
    var a = document.querySelector('#apps .tile[data-key="' + e.key + '"]');
    if (a) { a.focus(); window.location.href = a.getAttribute('href'); }
  });
</script>
</body>
</html>
