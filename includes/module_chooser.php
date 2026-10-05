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
 * Warstwa wizualna (2026-10-05): jasna i płaska, jak System Tożsamości (tozsamosc/_head.php) —
 * biały pasek, powitanie, białe karty modułów z paskiem gradientu modułu, akcent z brandingu
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
<meta name="theme-color" content="#ffffff">
<title><?= h($_org ?: 'System') ?> — wybór modułu</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php branding_css($_b); ?>
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{min-height:100vh;margin:0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;background:#f3f5f9;color:#0f172a;-webkit-font-smoothing:antialiased}
a{color:inherit}
.sr{position:absolute;left:-999px}.sr:focus{left:1rem;top:1rem;z-index:50;background:#fff;color:#0b1a3a;padding:.5rem 1rem;border-radius:8px;font-weight:600}
/* ── Pasek górny ── */
.bar{height:56px;background:#fff;color:#0f172a;display:flex;align-items:center;position:sticky;top:0;z-index:40;border-bottom:1px solid #e6e9f0}
.bar .in{width:100%;max-width:1100px;margin:0 auto;padding:0 1rem;display:flex;align-items:center;gap:.75rem}
.brand{display:flex;align-items:center;gap:.6rem;text-decoration:none;color:#0f172a;min-width:0;flex:1}
.brand-img{height:30px;max-width:130px;object-fit:contain}
.brand-ic{width:34px;height:34px;border-radius:10px;background:var(--c,#2563eb);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.brand b{display:block;font-weight:700;font-size:.95rem;line-height:1.05;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.brand small{display:block;font-size:.64rem;color:#64748b;font-weight:500}
.usr{display:inline-flex;align-items:center;gap:.55rem;padding:.25rem .5rem .25rem .25rem;border-radius:999px}
.ava{width:32px;height:32px;border-radius:50%;background:var(--c,#2563eb);color:#fff;font-size:.72rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.usr .who{display:none;flex-direction:column;line-height:1.1}
.usr .who b{font-size:.78rem;font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.usr .who small{font-size:.64rem;color:#64748b;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media(min-width:640px){.usr .who{display:flex}}
.lnk{display:inline-flex;align-items:center;gap:.4rem;padding:.42rem .7rem;border-radius:8px;color:#374151;text-decoration:none;font-size:.82rem;font-weight:500;white-space:nowrap;transition:background .12s,color .12s}
.lnk:hover{background:#f3f4f6;color:#111827}
.lnk:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.lnk--out:hover{background:#fef2f2;color:#b91c1c}
/* ── Nagłówek z powitaniem ── */
.head{max-width:1100px;margin:0 auto;padding:1.75rem 1rem .25rem;display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:.75rem}
.eyebrow{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8}
.greet{font-size:clamp(1.4rem,2.6vw,1.75rem);font-weight:800;letter-spacing:-.025em;margin:.15rem 0 0;line-height:1.15;color:#0f172a}
.sub{font-size:.86rem;color:#64748b;margin:.35rem 0 0}
.sub b{color:#0f172a;font-weight:700}
.date{display:inline-flex;align-items:center;gap:.45rem;font-size:.78rem;color:#64748b;background:#fff;border:1px solid #e6e9f0;border-radius:999px;padding:.35rem .8rem}
/* ── Treść ── */
.wrap{max-width:1100px;margin:0 auto;padding:0 1rem 3rem}
.sec-h{display:flex;align-items:center;gap:.5rem;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#7d8aa6;margin:1.75rem .15rem .8rem}
.sec-h:first-child{margin-top:1.25rem}
.sec-h .cnt{margin-left:auto;font-weight:700;letter-spacing:0;text-transform:none;font-size:.72rem;color:inherit;opacity:.85}
.apps{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:1rem}
.app{position:relative;display:flex;flex-direction:column;gap:.7rem;background:#fff;border:1px solid #e6e9f0;border-radius:16px;padding:1.1rem 1.15rem 1rem;text-decoration:none;color:#0f172a;min-height:168px;box-shadow:0 1px 2px rgba(16,24,40,.04);transition:box-shadow .14s,border-color .14s;overflow:hidden}
.app::before{content:'';position:absolute;left:0;right:0;top:0;height:4px;background:var(--g)}
.app:hover{border-color:#bdd2ff;box-shadow:0 0 0 3px rgba(37,99,235,.10)}
.app:focus-visible{outline:3px solid #fbbf24;outline-offset:3px}
.app-ic{width:46px;height:46px;border-radius:12px;background:var(--g);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.35rem}
.app-title{font-size:1.06rem;font-weight:800;letter-spacing:-.015em;line-height:1.2}
.app-desc{font-size:.8rem;color:#56637f;line-height:1.45;flex:1;margin:0}
.app-foot{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-top:.1rem}
.app-cta{display:inline-flex;align-items:center;gap:.3rem;font-size:.8rem;font-weight:700;color:var(--c,#1E6DFF)}
.app:hover .app-cta i{transform:translateX(3px)}.app-cta i{transition:transform .14s}
.key{display:inline-flex;align-items:center;justify-content:center;min-width:1.5rem;height:1.5rem;padding:0 .35rem;border-radius:6px;border:1px solid #cfd6e3;background:#f8fafc;color:#56637f;font:700 .68rem ui-monospace,SFMono-Regular,Menlo,monospace}
.app-badge{position:absolute;top:.95rem;right:.95rem;display:inline-flex;align-items:center;gap:.3rem;background:#eef4ff;color:#1d4ed8;border:1px solid #bdd2ff;font-size:.64rem;font-weight:700;padding:.18rem .55rem;border-radius:999px}
.app-badge i{color:#f59e0b}
.app--primary{border-color:var(--c,#2563eb)}
@media(min-width:720px){.app--primary{grid-column:span 2}.app--primary .app-desc{font-size:.84rem;max-width:46ch}}
/* ── Rząd „Konto" ── */
.row2{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.85rem}
.tile{display:flex;align-items:center;gap:.9rem;background:#fff;border:1px solid #e6e9f0;border-radius:16px;padding:.9rem 1rem;text-decoration:none;color:#0f172a;transition:border-color .13s,box-shadow .13s,transform .13s}
.tile:hover{border-color:#bdd2ff;box-shadow:0 0 0 3px rgba(37,99,235,.10)}
.tile:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.tile-ic{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;background:#eef4ff;color:#1656d6}
.tile b{display:block;font-size:.9rem;font-weight:700}
.tile small{display:block;font-size:.74rem;color:#56637f;margin-top:.1rem}
.tile .chev{margin-left:auto;color:#a9b4c9}
.note{display:flex;align-items:flex-start;gap:.6rem;background:#fff;border:1px dashed #cfd6e3;border-radius:14px;padding:.75rem 1rem;margin-top:1.5rem;font-size:.8rem;color:#56637f}
.note i{color:var(--c,#2563eb);margin-top:.1rem}
.foot{max-width:1100px;margin:0 auto;padding:1rem 1rem 2rem;font-size:.74rem;color:#7d8aa6;display:flex;flex-wrap:wrap;gap:.5rem 1.25rem;align-items:center;border-top:1px solid #e6e9f0}
.foot span:last-child{margin-left:auto}
@media(max-width:600px){.apps{grid-template-columns:1fr 1fr;gap:.7rem}.app{min-height:150px;padding:.95rem .95rem .85rem;border-radius:14px}.app-ic{width:40px;height:40px;font-size:1.15rem;border-radius:11px}.app-title{font-size:.95rem}.app-badge{top:.75rem;right:.75rem}.key{display:none}}
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

<header class="head" aria-labelledby="greet">
  <div>
    <div class="eyebrow">Strona startowa · <?= h($_short) ?></div>
    <h1 id="greet" class="greet"><?= h($_greet) ?>, <?= h($_fn) ?> 👋</h1>
    <p class="sub">Masz dostęp do <b><?= $_n ?> <?= $_n === 1 ? 'modułu' : 'modułów' ?></b>. Wybierz, gdzie chcesz przejść — na tę stronę wrócisz zawsze przez stronę startową.</p>
  </div>
  <span class="date"><i class="bi bi-calendar3" aria-hidden="true"></i><?= h($_today) ?></span>
</header>

<main id="main" class="wrap">
  <div class="sec-h"><i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i> Twoje moduły <span class="cnt"><kbd class="key">1</kbd>–<kbd class="key"><?= min($_n, 9) ?></kbd> na klawiaturze</span></div>
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
  <span><i class="bi bi-shield-lock-fill" aria-hidden="true" style="color:var(--c,#2563eb)"></i> połączenie szyfrowane</span>
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
