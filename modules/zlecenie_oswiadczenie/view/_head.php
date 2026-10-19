<?php
/**
 * modules/zlecenie_oswiadczenie/view/_head.php — wspólny początek stron zleceniobiorcy (panel i link z tokenem):
 * samodzielny układ bez menu SZO w szacie Metro UI (jak System Tożsamości), czytelny na telefonie, kontrast WCAG AA, widoczny fokus.
 * Wymaga: $title; opcjonalnie $back (adres powrotu), $backLabel, $flash ([typ, treść]).
 */
$_oswOrg = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : ''));
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> — <?= h($_oswOrg) ?></title>
<style>
/* Szata Metro UI (jak System Tożsamości i portal planu TI): Segoe UI, ciemny pasek #1d1d1d, płaskie powierzchnie,
   ZERO zaokrągleń, cieni i gradientów, kafle pełnym kolorem. Kolory Metro w odcieniach z kontrastem AA wobec bieli. */
:root{--acc:#0f6fa8;--acc-d:#0b5582;--acc-50:#e4eff6;--cyan:#1ba1e2;--dark:#1d1d1d;--light:#f2f2f2;--light-h:#e6e6e6;--line:#e1e1e1;--muted:#5f5f5f;
  --green:#007a00;--orange:#c25100;--red:#a20025;--indigo:#0050ef;--violet:#6a00ff;--teal:#00808a;--focus:#fbbf24}
*{box-sizing:border-box;border-radius:0!important}
body{margin:0;font:15px/1.55 "Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,Arial,sans-serif;background:#fff;color:var(--dark);-webkit-font-smoothing:antialiased}
a{color:var(--acc-d)}
:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
.skip{position:absolute;left:-999px;top:0;background:#fff;padding:8px 12px;z-index:9}
.skip:focus{left:8px}
/* Pasek aplikacji */
.top{background:var(--dark);color:#fff}
.top-in{max-width:900px;margin:0 auto;display:flex;align-items:stretch;justify-content:space-between;gap:0;min-height:76px}
.top .brand{padding:12px 20px;display:flex;flex-direction:column;justify-content:center}
.top .org{font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.7)}
.top .app{font:300 26px/1.15 "Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;letter-spacing:-.01em;color:#fff}
.top a.bk{display:flex;align-items:center;padding:0 20px;color:#fff;text-decoration:none;font-weight:600;font-size:13px}
.top a.bk:hover,.top a.bk:focus-visible{background:rgba(255,255,255,.12)}
.wrap{max-width:900px;margin:0 auto;padding:20px 16px 48px}
/* Powierzchnie */
.card{background:var(--light);border:0;padding:20px 22px;margin-bottom:6px}
h1{font:300 32px/1.15 "Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;letter-spacing:-.02em;margin:0 0 8px}
h2{font:300 22px/1.2 "Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;margin:0 0 12px}
h3{font-weight:600}
.mt-head{margin:26px 0 8px;padding:0 0 6px;border-bottom:2px solid var(--dark);font:300 22px/1.2 "Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif}
.muted{color:var(--muted)}
.small{font-size:.88rem}
/* Postęp kreatora — kwadraty jak pivot Metro */
.steps{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:4px;list-style:none;padding:0;margin:16px 0 0}
.steps li{background:var(--light-h);color:var(--muted);font-size:.8rem;padding:8px 10px;min-height:58px}
.steps li b{display:block;font:300 20px/1 "Segoe UI",system-ui,sans-serif;margin-bottom:4px}
.steps li.done{background:var(--green);color:#fff}
.steps li[aria-current]{background:var(--acc);color:#fff;font-weight:600}
.steps a{color:inherit;text-decoration:none}
.steps a:hover{text-decoration:underline}
/* Formularz */
.fld{margin-bottom:16px}
label,.lbl{display:block;font-weight:600;margin-bottom:4px}
.req{color:var(--red)}
.hint{display:block;font-size:.86rem;color:var(--muted);margin:2px 0 6px}
input[type=text],input[type=date],input[type=email],input[type=password],select{width:100%;max-width:100%;padding:8px 10px;border:2px solid #8a8a8a;background:#fff;font:inherit;color:var(--dark)}
input:focus,select:focus{border-color:var(--acc);outline:0}
input[type=file]{font:inherit;max-width:100%}
input[aria-invalid=true],select[aria-invalid=true]{border-color:var(--red)}
.err{display:block;color:var(--red);font-weight:600;font-size:.9rem;margin-top:4px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}
.grid3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:0 16px}
fieldset{border:0;border-left:4px solid var(--acc);background:#fff;padding:10px 16px 4px;margin:0 0 16px}
legend{font-weight:700;padding:0;margin-bottom:6px;float:left;width:100%}
legend+*{clear:left}
.opt{display:flex;gap:10px;align-items:flex-start;margin-bottom:10px;font-weight:400}
.opt input{width:20px;height:20px;margin-top:2px;flex-shrink:0;accent-color:var(--acc)}
.sub{margin:4px 0 10px 30px}
.btns{display:flex;flex-wrap:wrap;gap:6px;justify-content:space-between;margin-top:10px}
.btn{display:inline-flex;align-items:center;gap:8px;background:var(--acc);color:#fff;border:2px solid var(--acc);padding:10px 22px;text-decoration:none;font:600 15px "Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;cursor:pointer}
.btn:hover{background:var(--acc-d);border-color:var(--acc-d)}
.btn-sec{background:transparent;color:var(--dark);border-color:var(--dark)}
.btn-sec:hover{background:var(--dark);color:#fff;border-color:var(--dark)}
.btn-sm{padding:6px 14px;font-size:.9rem}
/* Komunikaty — pasek koloru stanu po lewej */
.note{padding:12px 16px;margin-bottom:6px;background:var(--light);border-left:6px solid var(--acc)}
.note-success{border-left-color:var(--green)}
.note-info{border-left-color:var(--acc)}
.note-warning{border-left-color:var(--orange)}
.note-danger{border-left-color:var(--red);background:#fbeaee}
.badge{display:inline-block;font-size:.78rem;font-weight:600;padding:3px 10px;color:#fff;letter-spacing:.02em}
.b-secondary{background:var(--muted)}.b-info{background:var(--indigo)}.b-warning{background:var(--orange)}.b-success{background:var(--green)}.b-danger{background:var(--red)}
dl.sum{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,3fr);gap:0;margin:0;background:#fff}
dl.sum dt,dl.sum dd{margin:0;padding:6px 10px;border-bottom:1px solid var(--line)}
dl.sum dt{color:var(--muted);font-size:.9rem}
dl.sum dd{font-weight:600;overflow-wrap:anywhere}
/* Kafle Metro */
.mt-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:6px;margin:0 0 6px}
.mt-tile{--c:var(--acc);position:relative;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between;min-height:130px;padding:12px 14px;background:var(--c);color:#fff!important;text-decoration:none;border:0;font:inherit;text-align:left;cursor:pointer}
.mt-tile:hover,.mt-tile:focus-visible{filter:brightness(1.12);outline:3px solid var(--dark);outline-offset:2px}
.mt-tile strong{font-size:18px;font-weight:600;line-height:1.2}
.mt-tile small{color:rgba(255,255,255,.9);font-size:12.5px}
.mt-tile .sym{position:absolute;right:10px;top:4px;font:300 46px/1 "Segoe UI",system-ui,sans-serif;opacity:.35}
.mt-tile.w2{grid-column:span 2}
/* Dokument (ekran po złożeniu): kafel stanu + akcje obok */
.doc{display:grid;grid-template-columns:210px minmax(0,1fr);gap:6px;margin-bottom:6px}
.doc .mt-tile{cursor:default}
.doc .mt-tile:hover{filter:none;outline:0}
.doc .body{background:var(--light);padding:14px 18px}
.doc h3{font-size:1.05rem;margin:0 0 8px}
.drop{border-top:1px solid var(--line);padding:12px 0 0;margin:12px 0 0}
.foot{color:var(--muted);font-size:.8rem;margin-top:18px;padding-top:10px;border-top:1px solid var(--line)}
@media (max-width:640px){.grid,.grid3,.doc{grid-template-columns:1fr}dl.sum{grid-template-columns:1fr}dl.sum dt{border-bottom:0;padding-bottom:0}
  h1{font-size:26px}.steps{grid-template-columns:repeat(3,minmax(0,1fr))}.mt-tile.w2{grid-column:span 1}.top .app{font-size:21px}}
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="top"><div class="top-in">
  <div class="brand"><span class="org"><?= h($_oswOrg) ?></span><span class="app">Oświadczenia zleceniobiorcy</span></div>
  <?php if (!empty($back)): ?><a class="bk" href="<?= h($back) ?>"><?= h($backLabel ?? '← Wróć do panelu') ?></a><?php endif; ?>
</div></header>
<main class="wrap" id="tresc">
<?php if (!empty($flash)): [$_ft, $_fm] = $flash; ?>
<div class="note note-<?= h($_ft) ?>" role="<?= $_ft === 'danger' ? 'alert' : 'status' ?>"><?= h($_fm) ?></div>
<?php endif; ?>
