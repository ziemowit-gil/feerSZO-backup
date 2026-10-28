<?php
/**
 * Książka nadawcza — seryjny wydruk etykiet adresowych na arkuszu A4.
 * Wejście: ?ids[]=ID… (zaznaczone w liście RPW-W). Ustawienia siatki i marginesów w GET,
 * podgląd = to, co pójdzie na drukarkę (@page A4 z marginesem, siatka CSS w mm).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$ids = $_GET['ids'] ?? [];
if (!is_array($ids)) $ids = explode(',', (string)$ids);
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
if (!$ids) { flash_set('error', 'Zaznacz w książce nadawczej przesyłki, dla których drukować etykiety.'); header('Location:' . APP_URL . '/ezd/rpwy/index.php'); exit; }

$clamp = fn($k, $d, $lo, $hi) => max($lo, min($hi, (int)($_GET[$k] ?? $d)));
$cols   = $clamp('cols', 2, 1, 4);
$rows_n = $clamp('rows', 7, 1, 14);
$margin = $clamp('margin', 15, 5, 30);          // mm — domyślnie spory margines (bezpieczny dla drukarek)
$gap    = $clamp('gap', 2, 0, 10);              // mm odstępu między etykietami
$fontPt = $clamp('font', 11, 7, 16);
$perPage = $cols * $rows_n;
$start  = $clamp('start', 1, 1, $perPage);      // pierwsze wolne pole na częściowo zużytym arkuszu
$showNr = !empty($_GET['nr']) || !isset($_GET['cols']);
$showSender = !empty($_GET['sender']) || !isset($_GET['cols']);
$border = !empty($_GET['border']);

$labels = [];
foreach ($ids as $id) {
    $r = ezd_rpwy_get($id);
    if (!$r) continue;
    $labels[] = $r;
}
if (!$labels) { flash_set('error', 'Nie znaleziono wybranych przesyłek.'); header('Location:' . APP_URL . '/ezd/rpwy/index.php'); exit; }

$sender = trim((string)(org_setting('org_name') ?: '') . ' · ' . (string)(org_setting('org_adres') ?: '') . ' ' . (string)(org_setting('org_miejscowosc') ?: ''), ' ·');

// Pola: puste (start-1) + etykiety → strony
$cells = array_merge(array_fill(0, $start - 1, null), $labels);
$pages = array_chunk($cells, $perPage);
$cellW = (210 - 2 * $margin - ($cols - 1) * $gap) / $cols;
$cellH = (297 - 2 * $margin - ($rows_n - 1) * $gap) / $rows_n;

$qs = fn(array $o = []) => http_build_query(array_merge(['ids' => $ids], $o));
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Etykiety adresowe — książka nadawcza</title>
<style>
@page { size: A4; margin: <?= $margin ?>mm; }
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;color:#111;background:#e9ecef}
.ctl{background:#fff;border-bottom:1px solid #c3c8cf;padding:.75rem 1rem;display:flex;flex-wrap:wrap;gap:.75rem 1rem;align-items:flex-end;font-size:.85rem}
.ctl label{display:flex;flex-direction:column;gap:.2rem;font-weight:600}
.ctl input[type=number]{width:5rem;padding:.3rem .4rem;border:1px solid #8a94a1;border-radius:.25rem;font-size:.9rem}
.ctl .chk{flex-direction:row;align-items:center;gap:.4rem;font-weight:400}
.ctl button,.ctl a.btn{padding:.45rem .9rem;border:1px solid #0f6fa8;background:#0f6fa8;color:#fff;border-radius:.25rem;font-size:.9rem;cursor:pointer;text-decoration:none}
.ctl a.btn{background:#fff;color:#0f6fa8}
.ctl :focus-visible{outline:3px solid #d90429;outline-offset:2px}
.info{padding:.5rem 1rem;font-size:.82rem;color:#374151}
.sheet{width:210mm;height:297mm;margin:1rem auto;background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.25);padding:<?= $margin ?>mm;page-break-after:always;break-after:page;overflow:hidden}
.grid{display:grid;grid-template-columns:repeat(<?= $cols ?>,<?= round($cellW, 2) ?>mm);grid-auto-rows:<?= round($cellH, 2) ?>mm;gap:<?= $gap ?>mm}
.lbl{overflow:hidden;padding:2.5mm 3mm;font-size:<?= $fontPt ?>pt;line-height:1.25;<?= $border ? 'border:.2mm dashed #888;' : '' ?>}
.lbl .snd{font-size:<?= max(6, $fontPt - 4) ?>pt;color:#444;border-bottom:.2mm solid #999;padding-bottom:.8mm;margin-bottom:1.4mm}
.lbl .nm{font-weight:700}
.lbl .nr{font-size:<?= max(6, $fontPt - 4) ?>pt;color:#444;margin-top:1mm;font-family:monospace}
.empty{<?= $border ? 'border:.2mm dashed #ddd' : '' ?>}
@media print{
  body{background:#fff}.ctl,.info{display:none!important}
  .sheet{margin:0;box-shadow:none;padding:0;width:auto;height:<?= 297 - 2 * $margin ?>mm}
  .sheet:last-child{page-break-after:auto;break-after:auto}
}
</style></head><body>
<form class="ctl" method="get" aria-label="Ustawienia arkusza etykiet">
  <?php foreach ($ids as $i): ?><input type="hidden" name="ids[]" value="<?= (int)$i ?>"><?php endforeach; ?>
  <label>Kolumny<input type="number" name="cols" min="1" max="4" value="<?= $cols ?>"></label>
  <label>Wiersze<input type="number" name="rows" min="1" max="14" value="<?= $rows_n ?>"></label>
  <label>Margines (mm)<input type="number" name="margin" min="5" max="30" value="<?= $margin ?>"></label>
  <label>Odstęp (mm)<input type="number" name="gap" min="0" max="10" value="<?= $gap ?>"></label>
  <label>Czcionka (pt)<input type="number" name="font" min="7" max="16" value="<?= $fontPt ?>"></label>
  <label>Pierwsze wolne pole<input type="number" name="start" min="1" max="<?= $perPage ?>" value="<?= $start ?>"></label>
  <label class="chk"><input type="checkbox" name="sender" value="1" <?= $showSender ? 'checked' : '' ?>>Nadawca</label>
  <label class="chk"><input type="checkbox" name="nr" value="1" <?= $showNr ? 'checked' : '' ?>>Nr RPW-W</label>
  <label class="chk"><input type="checkbox" name="border" value="1" <?= $border ? 'checked' : '' ?>>Ramki (do próby)</label>
  <button type="submit">Odśwież podgląd</button>
  <button type="button" onclick="window.print()">Drukuj</button>
  <a class="btn" href="<?= APP_URL ?>/ezd/rpwy/index.php">Wróć do książki</a>
</form>
<div class="info" role="status">
  Etykiet: <strong><?= count($labels) ?></strong> · arkuszy A4: <strong><?= count($pages) ?></strong> ·
  etykieta <?= round($cellW, 1) ?> × <?= round($cellH, 1) ?> mm · przy druku wybierz skalę 100% („Rzeczywisty rozmiar”), bez dopasowania do strony.
</div>
<?php foreach ($pages as $page): ?>
<section class="sheet" aria-label="Arkusz etykiet"><div class="grid">
  <?php for ($i = 0; $i < $perPage; $i++): $r = $page[$i] ?? null;
    if (!$r): ?><div class="lbl empty" aria-hidden="true"></div><?php continue; endif; ?>
  <div class="lbl">
    <?php if ($showSender && $sender !== ''): ?><div class="snd"><?= h($sender) ?></div><?php endif; ?>
    <div class="nm"><?= h($r['odbiorca'] ?: '—') ?></div>
    <div><?= nl2br(h(trim((string)($r['adres'] ?? '')))) ?></div>
    <?php if ($showNr): ?><div class="nr"><?= h(ezd_rpwy_label($r)) ?></div><?php endif; ?>
  </div>
  <?php endfor; ?>
</div></section>
<?php endforeach; ?>
</body></html>
