<?php
/**
 * modules/zlecenie_oswiadczenie/view/_head.php — wspólny początek stron zleceniobiorcy (panel i link z tokenem):
 * samodzielny układ bez menu SZO, czytelny na telefonie, kontrast WCAG AA, widoczny fokus.
 * Wymaga: $title; opcjonalnie $back (adres powrotu do panelu), $flash ([typ, treść]).
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
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f1f5f9;color:#0f172a;font-size:16px;line-height:1.55}
a{color:#1d4ed8}
:focus-visible{outline:3px solid #2563eb;outline-offset:2px}
.skip{position:absolute;left:-999px;top:0;background:#fff;padding:8px 12px;z-index:9}
.skip:focus{left:8px}
.top{background:#0f172a;color:#e2e8f0}
.top-in{max-width:820px;margin:0 auto;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}
.top a{color:#e2e8f0}
.wrap{max-width:820px;margin:0 auto;padding:22px 16px 48px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px 22px;margin-bottom:16px}
h1{font-size:1.55rem;line-height:1.25;margin:0 0 6px}
h2{font-size:1.2rem;margin:0 0 12px}
.muted{color:#475569}
.small{font-size:.9rem}
.steps{display:flex;flex-wrap:wrap;gap:6px;list-style:none;padding:0;margin:14px 0 0;counter-reset:s}
.steps li{flex:1 1 110px;font-size:.82rem;color:#475569;border-top:4px solid #cbd5e1;padding-top:6px}
.steps li.done{border-color:#16a34a;color:#14532d}
.steps li[aria-current]{border-color:#2563eb;color:#0f172a;font-weight:700}
.steps a{color:inherit}
.fld{margin-bottom:16px}
label,.lbl{display:block;font-weight:600;margin-bottom:4px}
.req{color:#b91c1c}
.hint{display:block;font-size:.88rem;color:#475569;margin:2px 0 6px}
input[type=text],input[type=date],input[type=email],select{width:100%;max-width:100%;padding:10px 12px;border:1px solid #64748b;border-radius:8px;font-size:1rem;font-family:inherit;background:#fff;color:#0f172a}
input[aria-invalid=true],select[aria-invalid=true]{border:2px solid #b91c1c}
.err{display:block;color:#b91c1c;font-weight:600;font-size:.92rem;margin-top:4px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}
.grid3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:0 16px}
fieldset{border:1px solid #cbd5e1;border-radius:10px;padding:12px 16px 4px;margin:0 0 16px}
legend{font-weight:700;padding:0 6px}
.opt{display:flex;gap:10px;align-items:flex-start;margin-bottom:10px;font-weight:400}
.opt input{width:22px;height:22px;margin-top:2px;flex-shrink:0}
.sub{margin:4px 0 10px 32px}
.btns{display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;margin-top:8px}
.btn{display:inline-flex;align-items:center;gap:8px;background:#1d4ed8;color:#fff;border:0;padding:12px 20px;border-radius:9px;text-decoration:none;font-weight:700;font-size:1rem;cursor:pointer;font-family:inherit}
.btn:hover{background:#1e40af}
.btn-sec{background:#fff;color:#1d4ed8;border:2px solid #93c5fd}
.btn-sec:hover{background:#eff6ff}
.btn-sm{padding:8px 14px;font-size:.92rem}
.note{border-radius:10px;padding:12px 16px;margin-bottom:16px}
.note-success{background:#f0fdf4;border:1px solid #86efac}
.note-info{background:#eff6ff;border:1px solid #93c5fd}
.note-warning{background:#fffbeb;border:1px solid #fcd34d}
.note-danger{background:#fef2f2;border:1px solid #fca5a5}
.badge{display:inline-block;font-size:.8rem;font-weight:700;padding:3px 10px;border-radius:999px}
.b-secondary{background:#e2e8f0;color:#1e293b}.b-info{background:#dbeafe;color:#1e3a8a}.b-warning{background:#fef3c7;color:#78350f}
.b-success{background:#dcfce7;color:#14532d}.b-danger{background:#fee2e2;color:#7f1d1d}
dl.sum{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,3fr);gap:6px 16px;margin:0}
dl.sum dt{color:#475569}
dl.sum dd{margin:0;font-weight:600;overflow-wrap:anywhere}
.doc{border:1px solid #cbd5e1;border-radius:12px;padding:16px 18px;margin-bottom:14px}
.doc h3{font-size:1.08rem;margin:0 0 6px}
.drop{border:2px dashed #93c5fd;border-radius:10px;padding:12px;margin:10px 0;background:#f8fbff}
.foot{color:#64748b;font-size:.82rem;text-align:center;margin-top:12px}
@media (max-width:640px){.grid,.grid3{grid-template-columns:1fr}dl.sum{grid-template-columns:1fr}dl.sum dd{margin-bottom:6px}h1{font-size:1.3rem}}
</style>
</head>
<body>
<a class="skip" href="#tresc">Przejdź do treści</a>
<header class="top"><div class="top-in">
  <span><?= h($_oswOrg) ?></span>
  <?php if (!empty($back)): ?><a href="<?= h($back) ?>">← Wróć do panelu</a><?php endif; ?>
</div></header>
<main class="wrap" id="tresc">
<?php if (!empty($flash)): [$_ft, $_fm] = $flash; ?>
<div class="note note-<?= h($_ft) ?>" role="<?= $_ft === 'danger' ? 'alert' : 'status' ?>"><?= h($_fm) ?></div>
<?php endif; ?>
