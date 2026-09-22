<?php
/**
 * Generowanie dokumentu pełnomocnictwa/odwołania 1:1 wg papierowego wzoru organizacji
 * (prosty list, Times New Roman, boilerplate + numerowany zakres, podpis).
 * Miejsca wymagające ręcznej odmiany (Pani/Panu, forma rzeczownika mocodawcy)
 * są edytowalne na ekranie przed wydrukiem — nie są automatycznie odmieniane.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/pelnomocnictwa.php';

require_login();
require_module_enabled('pelnomocnictwa_enabled', 'Rejestr pełnomocnictw');
if (!can_edit()) { flash_set('error', 'Brak uprawnień do rejestru pełnomocnictw.'); header('Location:'.APP_URL.'/index.php'); exit; }

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? pelnomocnictwo_get($id) : null;
if (!$row) { http_response_code(404); die('Nie znaleziono wpisu.'); }

$typ = ($_GET['typ'] ?? '') === 'odwolanie' ? 'odwolanie' : 'pelnomocnictwo';
$org = pelnomocnictwa_org_ident();

$dzis            = date('Y-m-d');
$data_dok        = $typ === 'odwolanie' ? ($row['data_odwolania'] ?: $dzis) : ($row['data_udzielenia'] ?: $dzis);
$data_dok_slow   = pelnomocnictwo_data_slownie($data_dok);
$data_udz_slow   = pelnomocnictwo_data_slownie($row['data_udzielenia'] ?: null);
$zakres_items    = pelnomocnictwo_zakres_items($row);
$miejscowosc     = $org['miejscowosc'] ?: '_______________';
$is_kor          = ($row['rodzaj'] ?? 'ogolne') === 'korespondencja';
$kor_opis        = $is_kor ? pelnomocnictwo_kor_opis($row) : '';
$zwrot_set       = in_array($row['zwrot'] ?? '', ['pan','pani'], true);
$zwrot_c         = pelnomocnictwo_zwrot_celownik($row['zwrot'] ?? '');
// Zwrot wybrany na etapie wpisu → tekst stały; brak wyboru → pole do ręcznej odmiany.
$zwrot_html      = $zwrot_set ? h($zwrot_c) : '<span class="edit-field" contenteditable="true">'.h($zwrot_c).'</span>';

$_tytul = $is_kor
    ? ($typ === 'odwolanie' ? 'Odwołanie pełnomocnictwa do odbioru korespondencji' : 'Pełnomocnictwo do odbioru korespondencji')
    : ($typ === 'odwolanie' ? 'Odwołanie pełnomocnictwa' : 'Pełnomocnictwo');
$PAGE_TITLE = $_tytul . ' — ' . $row['numer'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title><?= h($PAGE_TITLE) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 11pt; }
body {
  font-family: 'Times New Roman', Times, serif;
  font-size: 1rem;
  color: #000;
  background: #fff;
  width: 210mm;
  min-height: 297mm;
  margin: 0 auto;
  padding: 22mm 25mm 20mm 25mm;
  line-height: 1.5;
}
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
  background: #1e293b; color: #fff;
  padding: .6rem 1.5rem;
  display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
  font-family: system-ui, sans-serif; font-size: .85rem;
}
.toolbar button { background: #fff; color: #1e293b; border: none; padding: .4rem 1.1rem; border-radius: 4px; font-weight: 700; cursor: pointer; font-size: .85rem; }
.toolbar a { color: rgba(255,255,255,.8); font-size: .82rem; text-decoration: none; }
.toolbar a:hover { color: #fff; }
.toolbar .switch a { padding: .25rem .6rem; border: 1px solid rgba(255,255,255,.4); border-radius: 4px; }
.toolbar .switch a.on { background: #fff; color: #1e293b; font-weight: 700; }
.toolbar .hint { margin-left: auto; opacity: .75; font-size: .76rem; max-width: 320px; }

.doc-date { text-align: right; margin-bottom: 1.4rem; }
.doc-title { text-align: center; font-weight: bold; margin-bottom: 1.2rem; text-transform: uppercase; }
.doc-body p { text-align: justify; margin-bottom: .7rem; }
.doc-list { margin: .3rem 0 .9rem 1.6rem; }
.doc-list li { text-align: justify; margin-bottom: .25rem; }
.edit-field {
  border-bottom: 1px dashed #94a3b8;
  padding: 0 .1rem;
  cursor: text;
}
.doc-sign { margin-top: 3.5rem; text-align: right; }
.doc-sign .line { display: inline-block; border-top: 1px solid #000; width: 220px; padding-top: .25rem; font-weight: bold; }

@media screen { body { margin-top: 3.2rem; box-shadow: 0 0 20px rgba(0,0,0,.18); } }
@media print {
  body { margin: 0; padding: 20mm 25mm 18mm 25mm; box-shadow: none; }
  .toolbar { display: none !important; }
  .edit-field { border-bottom: none; }
  @page { size: A4 portrait; margin: 0; }
}
</style>
</head>
<body>

<div class="toolbar" aria-hidden="true">
  <button onclick="window.print()">🖨 Drukuj / PDF</button>
  <a href="<?= APP_URL ?>/pelnomocnictwa/index.php?edit=<?= $id ?>">← Wróć do rejestru</a>
  <span class="switch">
    <a class="<?= $typ==='pelnomocnictwo'?'on':'' ?>" href="?id=<?= $id ?>&typ=pelnomocnictwo">Pełnomocnictwo</a>
    <a class="<?= $typ==='odwolanie'?'on':'' ?>" href="?id=<?= $id ?>&typ=odwolanie">Odwołanie</a>
  </span>
  <span class="hint">Podkreślone pola (Pani/Panu, forma mocodawcy) popraw ręcznie przed drukiem — odmiana nie jest generowana automatycznie.</span>
</div>

<div class="doc-date"><?= h($miejscowosc) ?>, dnia <?= h($data_dok_slow ?: date('d.m.Y')) ?></div>

<div class="doc-title"><?= h($_tytul) ?></div>

<div class="doc-body">
<p>
ja, niżej podpisany/a <strong><?= h($row['podpisujacy'] ?: '_______________') ?></strong>,
jako <?= h($row['podpisujacy_funkcja'] ?: '_______________') ?>
<strong><?= h($org['nazwa']) ?></strong>
z siedzibą przy <?= h($org['adres'] ?: '_______________') ?>,
wpisanej do rejestru stowarzyszeń Krajowego Rejestru Sądowego,
<?= h($org['sad'] ?: '_______________') ?> pod nr: <?= h($org['krs'] ?: '_______________') ?>,
posiadającej NIP: <?= h($org['nip'] ?: '_______________') ?>, uprawniony do jednoosobowej reprezentacji;
</p>

<?php if ($typ === 'odwolanie'): ?>
<p>odwołuje pełnomocnictwo udzielone <?= $zwrot_html ?></p>
<p>1) <span class="edit-field" contenteditable="true"><?= h($row['pelnomocnik']) ?></span><?php if ($row['pelnomocnik_pesel']): ?> (PESEL: <?= h($row['pelnomocnik_pesel']) ?>)<?php endif; ?><?php if ($data_udz_slow): ?> w dniu <?= h($data_udz_slow) ?>r.<?php endif; ?></p>
<?php else: ?>
<p>udzielam pełnomocnictwa <?= $zwrot_html ?></p>
<p>1) <span class="edit-field" contenteditable="true"><?= h($row['pelnomocnik']) ?></span><?php if ($row['pelnomocnik_pesel']): ?> (PESEL: <?= h($row['pelnomocnik_pesel']) ?>)<?php endif; ?></p>
<?php endif; ?>

<?php if ($is_kor): ?>
<p>do odbioru w imieniu <span class="edit-field" contenteditable="true">Fundacji</span> <?= h($kor_opis) ?>.</p>
<?php else: ?>
<p>do działania w imieniu <span class="edit-field" contenteditable="true">Fundacji</span>, w sprawach:</p>
<ol class="doc-list">
  <?php foreach ($zakres_items as $it): ?>
  <li><?= h($it) ?></li>
  <?php endforeach; ?>
  <?php if (!$zakres_items): ?>
  <li class="edit-field" contenteditable="true">(uzupełnij zakres pełnomocnictwa)</li>
  <?php endif; ?>
</ol>
<?php endif; ?>

<?php if ($typ === 'pelnomocnictwo'): ?>
<p>
<?php if ($row['data_waznosci']): ?>
Pełnomocnictwo obowiązuje do dnia <?= h(pelnomocnictwo_data_slownie($row['data_waznosci'])) ?>r.
<?php else: ?>
Pełnomocnictwo ma charakter bezterminowy, do czasu jego odwołania.
<?php endif; ?>
</p>
<?php endif; ?>
</div>

<div class="doc-sign">
  <span class="line"><?= h($row['podpisujacy'] ?: '_______________') ?></span>
</div>

</body>
</html>
