<?php
/** @var array $app @var array $tpl @var string $kind @var string $css @var string $body @var string $pdfUrl
 *  @var string $otherUrl @var string $otherLabel — podgląd do wydruku (A4): treść wniosku albo potwierdzenie złożenia. */
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($kind === 'receipt' ? 'Potwierdzenie ' : $tpl['code'] . ' — ') . appDocsNumber($app, $tpl)) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
<?= $css ?>
html{background:#f2f2f2}
body{margin:0;font-family:"Segoe UI",system-ui,Arial,sans-serif}
.ad-bar{position:sticky;top:0;display:flex;align-items:stretch;gap:0;background:#1d1d1d;color:#fff;min-height:48px;z-index:2}
.ad-bar-t{flex:1;display:flex;align-items:center;padding:0 1rem;font-weight:300;font-size:1.05rem;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ad-bar-t small{font-size:.62rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;opacity:.7;margin-right:.75rem}
.ad-bar a,.ad-bar button{display:inline-flex;align-items:center;gap:.45rem;padding:0 1rem;border:0;background:transparent;color:#fff;font:600 .88rem "Segoe UI",system-ui,sans-serif;text-decoration:none;cursor:pointer}
.ad-bar a:hover,.ad-bar button:hover{background:rgba(255,255,255,.12)}
.ad-bar .is-acc{background:#0050ef}.ad-bar .is-acc:hover{background:#003bb0}
.ad-bar :focus-visible{outline:3px solid #fbbf24;outline-offset:-3px}
.ad-page{background:#fff;max-width:210mm;min-height:270mm;margin:1.5rem auto;padding:20mm 18mm;box-sizing:border-box;font-family:"DejaVu Sans","Segoe UI",Arial,sans-serif}
@media (max-width:820px){.ad-page{margin:0;padding:1.25rem;min-height:0}}
@media print{html{background:#fff}.ad-bar{display:none}.ad-page{margin:0;padding:0;max-width:none;min-height:0}@page{size:A4;margin:18mm}}
</style>
</head>
<body>
<nav class="ad-bar" aria-label="Polecenia dokumentu">
  <a href="javascript:history.back()"><i class="bi bi-arrow-left" aria-hidden="true"></i>Wróć</a>
  <div class="ad-bar-t"><small><?= $kind === 'receipt' ? 'Potwierdzenie' : h($tpl['code']) ?></small><?= h(appDocsNumber($app, $tpl)) ?></div>
  <?php if ($otherUrl !== ''): ?><a href="<?= h($otherUrl) ?>"><i class="bi <?= $kind === 'receipt' ? 'bi-file-earmark-text' : 'bi-patch-check' ?>" aria-hidden="true"></i><?= h($otherLabel) ?></a><?php endif; ?>
  <button type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i>Drukuj</button>
  <a class="is-acc" href="<?= h($pdfUrl) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>PDF</a>
</nav>
<main class="ad-page">
<?= $body ?>
</main>
</body>
</html>
