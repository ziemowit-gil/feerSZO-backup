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
 * Warstwa wizualna (2026-10-05): ten sam język co System Tożsamości (tozsamosc/_head.php) —
 * granatowy pasek + band z powitaniem, karty modułów nachodzące na band, akcent z brandingu
 * organizacji (--c z branding_css). Samodzielny CSS (bez Bootstrapa — tylko ikony).
 * Skróty klawiszowe: cyfry 1–9 otwierają kolejne moduły.
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
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b1a3a">
<title><?= h($_org ?: 'System') ?> — wybór modułu</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{min-height:100vh;margin:0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;background:#f3f5f9;color:#0f172a;-webkit-font-smoothing:antialiased}
a{color:inherit}
.sr{position:absolute;left:-999px}.sr:focus{left:1rem;top:1rem;z-index:50;background:#fff;color:#0b1a3a;padding:.5rem 1rem;border-radius:8px;font-weight:600}
/* ── Pasek górny ── */
.bar{height:60px;background:linear-gradient(90deg,#0b1a3a 0%,#11275e 60%,#143070 100%);color:#fff;display:flex;align-items:center;position:sticky;top:0;z-index:40;box-shadow:0 6px 18px -10px rgba(2,6,23,.6)}
.bar .in{width:100%;max-width:1100px;margin:0 auto;padding:0 1rem;display:flex;align-items:center;gap:.75rem}
.brand{display:flex;align-items:center;gap:.6rem;text-decoration:none;color:#fff;min-width:0;flex:1}
.brand-img{height:30px;max-width:130px;object-fit:contain;background:#fff;border-radius:8px;padding:.15rem .4rem}
.brand-ic{width:36px;height:36px;border-radius:11px;background:linear-gradient(135deg,var(--c,#1E6DFF),#7c5cff);display:flex;align-items:center;justify-content:center;font-size:1.05rem;flex-shrink:0;box-shadow:0 6px 16px -4px rgba(30,109,255,.6)}
.brand b{display:block;font-weight:700;font-size:.95rem;line-height:1.05;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.brand small{display:block;font-size:.64rem;color:rgba(255,255,255,.62);font-weight:500}
.usr{display:inline-flex;align-items:center;gap:.55rem;padding:.25rem .5rem .25rem .25rem;border-radius:999px}
.ava{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--c,#1E6DFF),#7c5cff);color:#fff;font-size:.74rem;font-weight:700;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 2px rgba(255,255,255,.18)}
.usr .who{display:none;flex-direction:column;line-height:1.1}
.usr .who b{font-size:.78rem;font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.usr .who small{font-size:.64rem;color:rgba(255,255,255,.62);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media(min-width:640px){.usr .who{display:flex}}
.lnk{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem .7rem;border-radius:9px;color:rgba(255,255,255,.82);text-decoration:none;font-size:.83rem;font-weight:500;white-space:nowrap;transition:background .12s,color .12s}
.lnk:hover{background:rgba(255,255,255,.1);color:#fff}
.lnk:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.lnk--out:hover{background:rgba(239,68,68,.22)}
/* ── Band z powitaniem ── */
.band{background:radial-gradient(1200px 420px at 85% -20%,rgba(30,109,255,.45),transparent 60%),radial-gradient(800px 300px at 0% 120%,rgba(124,92,255,.35),transparent 60%),linear-gradient(135deg,#0b1a3a 0%,#11275e 55%,#143070 100%);color:#fff;position:relative;overflow:hidden}
.band::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.05) 1px,transparent 1px);background-size:36px 36px;-webkit-mask-image:linear-gradient(180deg,rgba(0,0,0,.7),transparent 85%);mask-image:linear-gradient(180deg,rgba(0,0,0,.7),transparent 85%);pointer-events:none}
.band .in{max-width:1100px;margin:0 auto;padding:2rem 1rem 5.25rem;position:relative;display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem}
.eyebrow{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.6)}
.greet{font-size:clamp(1.55rem,3.2vw,2.1rem);font-weight:800;letter-spacing:-.025em;margin:.2rem 0 0;line-height:1.15}
.sub{font-size:.9rem;color:rgba(255,255,255,.72);margin:.45rem 0 0}
.sub b{color:#fff;font-weight:700}
.date{display:inline-flex;align-items:center;gap:.45rem;font-size:.78rem;color:rgba(255,255,255,.75);background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:999px;padding:.4rem .8rem;-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)}
/* ── Treść nachodząca na band ── */
.wrap{max-width:1100px;margin:-3.75rem auto 0;padding:0 1rem 3rem;position:relative;z-index:1}
.sec-h{display:flex;align-items:center;gap:.5rem;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#7d8aa6;margin:1.75rem .15rem .8rem}
.sec-h:first-child{margin-top:0;color:rgba(255,255,255,.7)}
.sec-h .cnt{margin-left:auto;font-weight:700;letter-spacing:0;text-transform:none;font-size:.72rem;color:inherit;opacity:.85}
.apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:1rem}
.app{position:relative;display:flex;flex-direction:column;gap:.75rem;background:#fff;border:1px solid #e6e9f0;border-radius:20px;padding:1.2rem 1.2rem 1.05rem;text-decoration:none;color:#0f172a;min-height:176px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 14px 36px -24px rgba(16,24,40,.35);transition:transform .14s,box-shadow .14s,border-color .14s;overflow:hidden}
.app::before{content:'';position:absolute;left:0;right:0;top:0;height:5px;background:var(--g)}
.app:hover{transform:translateY(-3px);box-shadow:0 1px 2px rgba(16,24,40,.04),0 22px 44px -22px rgba(16,24,40,.45);border-color:#cfd6e3}
.app:focus-visible{outline:3px solid #fbbf24;outline-offset:3px}
.app-ic{width:50px;height:50px;border-radius:15px;background:var(--g);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.45rem;box-shadow:0 10px 20px -10px rgba(2,6,23,.5)}
.app-title{font-size:1.06rem;font-weight:800;letter-spacing:-.015em;line-height:1.2}
.app-desc{font-size:.8rem;color:#56637f;line-height:1.45;flex:1;margin:0}
.app-foot{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-top:.1rem}
.app-cta{display:inline-flex;align-items:center;gap:.3rem;font-size:.8rem;font-weight:700;color:var(--c,#1E6DFF)}
.app:hover .app-cta i{transform:translateX(3px)}.app-cta i{transition:transform .14s}
.key{display:inline-flex;align-items:center;justify-content:center;min-width:1.5rem;height:1.5rem;padding:0 .35rem;border-radius:6px;border:1px solid #cfd6e3;background:#f8fafc;color:#56637f;font:700 .68rem ui-monospace,SFMono-Regular,Menlo,monospace}
.app-badge{position:absolute;top:1rem;right:1rem;display:inline-flex;align-items:center;gap:.3rem;background:#0b1a3a;color:#fff;font-size:.64rem;font-weight:700;padding:.22rem .55rem;border-radius:999px;letter-spacing:.02em}
.app-badge i{color:#fbbf24}
.app--primary{border-color:transparent;box-shadow:0 0 0 2px var(--c,#1E6DFF),0 22px 44px -22px rgba(16,24,40,.45)}
@media(min-width:720px){.app--primary{grid-column:span 2}.app--primary .app-desc{font-size:.86rem;max-width:46ch}.app--primary .app-ic{width:56px;height:56px;font-size:1.65rem}}
/* ── Rząd „Konto" ── */
.row2{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.85rem}
.tile{display:flex;align-items:center;gap:.9rem;background:#fff;border:1px solid #e6e9f0;border-radius:16px;padding:.9rem 1rem;text-decoration:none;color:#0f172a;transition:border-color .13s,box-shadow .13s,transform .13s}
.tile:hover{border-color:#8fb3ff;box-shadow:0 0 0 3px rgba(30,109,255,.12);transform:translateY(-1px)}
.tile:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.tile-ic{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;background:#eef4ff;color:#1656d6}
.tile b{display:block;font-size:.9rem;font-weight:700}
.tile small{display:block;font-size:.74rem;color:#56637f;margin-top:.1rem}
.tile .chev{margin-left:auto;color:#a9b4c9}
.note{display:flex;align-items:flex-start;gap:.6rem;background:#fff;border:1px dashed #cfd6e3;border-radius:14px;padding:.75rem 1rem;margin-top:1.5rem;font-size:.8rem;color:#56637f}
.note i{color:#1E6DFF;margin-top:.1rem}
.foot{max-width:1100px;margin:0 auto;padding:1rem 1rem 2rem;font-size:.74rem;color:#7d8aa6;display:flex;flex-wrap:wrap;gap:.5rem 1.25rem;align-items:center;border-top:1px solid #e6e9f0}
.foot span:last-child{margin-left:auto}
@media(max-width:600px){.apps{grid-template-columns:1fr 1fr;gap:.7rem}.app{min-height:150px;padding:.95rem .95rem .85rem;border-radius:16px}.app-ic{width:42px;height:42px;font-size:1.2rem;border-radius:12px}.app-title{font-size:.95rem}.app-badge{top:.75rem;right:.75rem}.key{display:none}.band .in{padding-bottom:4.5rem}}
@media(max-width:380px){.apps{grid-template-columns:1fr}}
@media(max-width:520px){.d-sm,.who-txt{display:none}.bar .in{gap:.4rem}}
@media(prefers-reduced-motion:reduce){.app,.tile,.app-cta i{transition:none}}
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

<section class="band" aria-labelledby="greet">
  <div class="in">
    <div>
      <div class="eyebrow">Strona startowa · <?= h($_short) ?></div>
      <h1 id="greet" class="greet"><?= h($_greet) ?>, <?= h($_fn) ?> 👋</h1>
      <p class="sub">Masz dostęp do <b><?= $_n ?> <?= $_n === 1 ? 'modułu' : 'modułów' ?></b>. Wybierz, gdzie chcesz przejść — na tę stronę wrócisz zawsze przez stronę startową.</p>
    </div>
    <span class="date"><i class="bi bi-calendar3" aria-hidden="true"></i><?= h($_today) ?></span>
  </div>
</section>

<main id="main" class="wrap">
  <div class="sec-h"><i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i> Twoje moduły <span class="cnt"><kbd class="key" style="color:#fff;background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25)">1</kbd>–<kbd class="key" style="color:#fff;background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25)"><?= min($_n, 9) ?></kbd> na klawiaturze</span></div>
  <div class="apps" id="apps">
    <?php foreach ($__choices as $i => $c):
      $primary = !empty($c['primary']);
      $k = $i + 1;
    ?>
    <a href="<?= h($c['url']) ?>" class="app<?= $primary ? ' app--primary' : '' ?>" style="--g:<?= $c['grad'] ?>" data-key="<?= $k <= 9 ? $k : '' ?>">
      <?php if ($primary): ?><span class="app-badge"><i class="bi bi-star-fill" aria-hidden="true"></i>Twój moduł</span><?php endif; ?>
      <span class="app-ic" aria-hidden="true"><i class="bi <?= h($c['icon']) ?>"></i></span>
      <span class="app-title"><?= h($c['label']) ?></span>
      <p class="app-desc"><?= h($c['desc'] ?? '') ?></p>
      <span class="app-foot">
        <span class="app-cta">Otwórz <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
        <?php if ($k <= 9): ?><kbd class="key" aria-label="skrót klawiszowy <?= $k ?>"><?= $k ?></kbd><?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="sec-h"><i class="bi bi-person-gear" aria-hidden="true"></i> Konto</div>
  <div class="row2">
    <a href="<?= APP_URL ?>/tozsamosc/index.php" class="tile">
      <span class="tile-ic" aria-hidden="true"><i class="bi bi-person-vcard"></i></span>
      <span><b>System Tożsamości</b><small>Twoje dane, hasło, telefon i metody weryfikacji (MFA)</small></span>
      <i class="bi bi-chevron-right chev" aria-hidden="true"></i>
    </a>
    <a href="<?= APP_URL ?>/tozsamosc/index.php#bezpieczenstwo" class="tile">
      <span class="tile-ic" style="background:#ecfdf5;color:#047857" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
      <span><b>Bezpieczeństwo konta</b><small>Sprawdź, czy konto jest dobrze zabezpieczone</small></span>
      <i class="bi bi-chevron-right chev" aria-hidden="true"></i>
    </a>
  </div>

  <div class="note">
    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
    <div>Twoje konto ma dostęp do kilku modułów. Moduł oznaczony gwiazdką to Twój moduł podstawowy; pozostałe zostały przyznane dodatkowo przez administratora.</div>
  </div>
</main>
<footer class="foot">
  <span><i class="bi bi-shield-lock-fill" aria-hidden="true" style="color:#1E6DFF"></i> połączenie szyfrowane</span>
  <span>© <?= date('Y') ?> <?= h($_org) ?></span>
</footer>
<script>
  // Skróty klawiszowe 1–9 → kolejne moduły (poza polami formularzy).
  document.addEventListener('keydown', function (e) {
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    var t = e.target && e.target.tagName;
    if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return;
    if (!/^[1-9]$/.test(e.key)) return;
    var a = document.querySelector('#apps .app[data-key="' + e.key + '"]');
    if (a) { a.focus(); window.location.href = a.getAttribute('href'); }
  });
</script>
</body>
</html>
