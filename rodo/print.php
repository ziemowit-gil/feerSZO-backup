<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
require_login();
require_role('admin', 'editor');

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? db_one("SELECT * FROM rodo_authorizations WHERE id=?", [$id]) : null;
if (!$row) { http_response_code(404); die('Brak danych.'); }

$scope    = json_decode($row['scope_items'] ?? '[]', true) ?: [];
$org_city = org_setting('org_miejscowosc') ?: (org_setting('org_name') ? '' : '_______________');
$org_krs  = org_setting('org_krs') ?: '';

$today           = date('d.m.Y');
$signed_date     = $row['signed_at']     ? date('d.m.Y', strtotime($row['signed_at']))     : $today;
$vol_signed_date = $row['vol_signed_at'] ? date('d.m.Y', strtotime($row['vol_signed_at'])) : $today;
$from_date       = $row['authorized_from']  ? date('d.m.Y', strtotime($row['authorized_from']))  : $today;
$until_date      = $row['authorized_until'] ? date('d.m.Y', strtotime($row['authorized_until'])) : '';
$contract_date_fmt = $row['contract_date'] ? date('d.m.Y', strtotime($row['contract_date'])) : '';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Upoważnienie RODO — <?= h($row['number']) ?></title>
<style>
/* ════════════════════════════════════════════════════════
   URZĘDOWY DOKUMENT RODO  ·  druk / PDF  ·  format A4
   ════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { font-size: 10.5pt; }

body {
  font-family: 'Times New Roman', Times, serif;
  font-size: 1rem;
  color: #000;
  background: #fff;
  width: 210mm;
  min-height: 297mm;
  margin: 0 auto;
  padding: 16mm 18mm 14mm 25mm;
  line-height: 1.45;
}

/* ── Pasek akcji (tylko ekran) ─────────────────────────── */
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
  background: #1e3a5f; color: #fff;
  padding: .6rem 1.5rem;
  display: flex; align-items: center; gap: 1rem;
  font-family: system-ui, sans-serif; font-size: .88rem;
}
.toolbar button {
  background: #fff; color: #1e3a5f; border: none;
  padding: .4rem 1.2rem; border-radius: 4px;
  font-weight: 700; cursor: pointer; font-size: .88rem;
}
.toolbar a { color: rgba(255,255,255,.78); font-size: .83rem; text-decoration: none; }
.toolbar a:hover { color: #fff; }

/* ── Nagłówek organizacji ─────────────────────────────── */
.org-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding-bottom: .55rem;
  border-bottom: 2px solid #000;
  margin-bottom: .6rem;
}
.org-name  { font-size: 1.1rem; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; }
.org-meta  { font-size: .8rem; color: #333; margin-top: .15rem; line-height: 1.4; }
.doc-ref   { text-align: right; }
.doc-ref-num {
  font-size: .82rem; font-weight: bold; letter-spacing: .03em;
  border: 1.5px solid #000; padding: .15rem .5rem;
  display: inline-block; margin-bottom: .2rem;
}
.doc-ref-date { font-size: .75rem; color: #444; }

/* ── Tytuł dokumentu ──────────────────────────────────── */
.doc-title-wrap {
  text-align: center;
  margin: .7rem 0 .65rem;
  padding: .5rem 0;
  border-top: 1px solid #888;
  border-bottom: 1px solid #888;
}
.doc-title {
  font-size: 1.2rem;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: .08em;
}
.doc-subtitle { font-size: .75rem; color: #555; margin-top: .2rem; }

/* ── Strony umowy — tabela ────────────────────────────── */
.parties-table {
  width: 100%; border-collapse: collapse; margin-bottom: .7rem;
  font-size: .9rem;
}
.parties-table td {
  vertical-align: top;
  padding: .3rem .5rem;
}
.parties-table td:first-child {
  width: 50%;
  border-right: 1px solid #bbb;
  padding-right: .75rem;
}
.parties-table td:last-child { padding-left: .75rem; }
.party-head {
  font-size: .68rem; font-weight: bold; text-transform: uppercase;
  letter-spacing: .08em; color: #555;
  border-bottom: 1px solid #bbb; padding-bottom: .15rem; margin-bottom: .25rem;
}
.party-val { font-weight: bold; font-size: .98rem; }
.party-sub { font-size: .83rem; color: #222; line-height: 1.4; }

/* ── Paragrafy ────────────────────────────────────────── */
.section { margin-bottom: .6rem; }
.section-title {
  font-weight: bold; font-size: .9rem;
  display: flex; align-items: baseline; gap: .5rem;
  margin-bottom: .2rem;
}
.section-title .para-num {
  font-size: 1rem; min-width: 1.6em; text-align: center;
  border: 1px solid #000; padding: 0 .2rem; border-radius: 2px;
  display: inline-block;
}
.section-body { font-size: .9rem; text-align: justify; line-height: 1.47; }
.section-body p { margin-bottom: .2rem; }

/* Lista zakresów § 2 */
.scope-list {
  margin: .25rem 0 0 1.2rem; padding: 0;
  list-style: none;
  columns: 2; column-gap: 1.2rem;
  font-size: .88rem; line-height: 1.45;
}
.scope-list li::before { content: '•'; margin-right: .35rem; font-weight: bold; }
.scope-list li { break-inside: avoid; margin-bottom: .1rem; }

/* Lista zobowiązań § 3 */
.oblig-list {
  margin: .2rem 0 0 1.4rem; padding: 0;
  font-size: .88rem; line-height: 1.45;
}
.oblig-list li { margin-bottom: .1rem; text-align: justify; }

/* ── Separator ────────────────────────────────────────── */
.hr-thin { border: none; border-top: 1px solid #bbb; margin: .6rem 0; }

/* ── Podpisy ──────────────────────────────────────────── */
.sign-row {
  display: flex;
  gap: 2rem;
  margin-top: .5rem;
}
.sign-block { flex: 1; }
.sign-city  { font-size: .85rem; margin-bottom: .8rem; text-align: right; }
.sign-line  {
  border-bottom: 1px solid #000;
  margin-bottom: .2rem;
  height: 2.2rem;
}
.sign-label { font-size: .72rem; text-align: center; color: #333; line-height: 1.35; }

/* ── Oświadczenie wolontariusza ───────────────────────── */
.statement-box {
  border: 1px solid #888;
  padding: .45rem .6rem;
  margin-top: .55rem;
  font-size: .88rem;
}
.statement-title {
  font-weight: bold; font-size: .82rem;
  text-transform: uppercase; letter-spacing: .06em;
  margin-bottom: .3rem;
}
.statement-body { text-align: justify; line-height: 1.45; }

/* ── Stopka ───────────────────────────────────────────── */
.doc-footer {
  margin-top: .7rem;
  padding-top: .4rem;
  border-top: 1px solid #ccc;
  font-size: .7rem;
  color: #777;
  display: flex; justify-content: space-between;
}

/* ── DRUK ─────────────────────────────────────────────── */
@media screen {
  body { margin-top: 3rem; box-shadow: 0 0 20px rgba(0,0,0,.18); }
}
@media print {
  body { margin: 0; padding: 12mm 16mm 10mm 22mm; box-shadow: none; }
  .toolbar { display: none !important; }
  @page { size: A4 portrait; margin: 0; }
}
@media (prefers-color-scheme: dark) {
  body { background: #fff; color: #000; }
}
</style>
</head>
<body>

<!-- Toolbar (tylko ekran) -->
<div class="toolbar" aria-hidden="true">
  <button onclick="window.print()">🖨 Drukuj / PDF</button>
  <a href="view.php?id=<?= $id ?>">← Wróć do zarządzania</a>
  <span style="margin-left:auto;opacity:.7">Nr <?= h($row['number']) ?></span>
</div>

<!-- ═══ DOKUMENT ═══════════════════════════════════════════════════════════════ -->

<!-- Nagłówek organizacji -->
<div class="org-header">
  <div>
    <div class="org-name"><?= h($row['org_name']) ?></div>
    <div class="org-meta">
      <?= h($row['org_address']) ?><br>
      NIP: <?= h($row['org_nip']) ?>
      <?php if ($org_krs): ?> &nbsp;·&nbsp; KRS: <?= h($org_krs) ?><?php endif; ?>
    </div>
  </div>
  <div class="doc-ref">
    <div class="doc-ref-num">Nr <?= h($row['number']) ?></div>
    <div class="doc-ref-date">
      <?= h($org_city) ?>, dnia <?= $signed_date ?>
    </div>
  </div>
</div>

<!-- Tytuł -->
<div class="doc-title-wrap">
  <div class="doc-title">Upoważnienie do przetwarzania danych osobowych</div>
  <div class="doc-subtitle">wydane na podstawie art. 29 oraz art. 32 ust. 4 Rozporządzenia (UE) 2016/679 (RODO)</div>
</div>

<!-- Strony -->
<table class="parties-table">
  <tr>
    <td>
      <div class="party-head">Administrator danych osobowych</div>
      <div class="party-val"><?= h($row['org_name']) ?></div>
      <div class="party-sub">
        z siedzibą: <?= h($row['org_address']) ?><br>
        NIP: <strong><?= h($row['org_nip']) ?></strong>
        <?php if ($org_krs): ?> &nbsp;·&nbsp; KRS: <?= h($org_krs) ?><?php endif; ?>
      </div>
    </td>
    <td>
      <div class="party-head">Osoba upoważniana</div>
      <div class="party-val"><?= h($row['person_name']) ?></div>
      <div class="party-sub">
        <?php if ($row['person_pesel']): ?>PESEL: <strong><?= h($row['person_pesel']) ?></strong><br><?php endif; ?>
        <?php if ($row['contract_number']): ?>Porozumienie nr: <strong><?= h($row['contract_number']) ?></strong><?php if ($contract_date_fmt): ?> z dnia <?= $contract_date_fmt ?><?php endif; ?><?php endif; ?>
      </div>
    </td>
  </tr>
</table>

<!-- § 1 -->
<div class="section">
  <div class="section-title"><span class="para-num">§ 1</span></div>
  <div class="section-body">
    Z dniem <strong><?= $from_date ?></strong> upoważniam Pana/Panią <strong><?= h($row['person_name']) ?></strong>
    do przetwarzania danych osobowych w zbiorach prowadzonych przez <em><?= h($row['org_name']) ?></em>
    w zakresie niezbędnym do realizacji zadań wynikających z porozumienia o wolontariacie
    <?php if ($row['contract_number']): ?>nr <strong><?= h($row['contract_number']) ?></strong><?php endif; ?>
    <?php if ($contract_date_fmt): ?>z dnia <?= $contract_date_fmt ?><?php endif ?>.<?php
    if ($until_date): ?> Upoważnienie obowiązuje do dnia <strong><?= $until_date ?></strong>.<?php endif; ?>
  </div>
</div>

<!-- § 2 -->
<div class="section">
  <div class="section-title"><span class="para-num">§ 2</span></div>
  <div class="section-body">
    Zakres upoważnienia obejmuje przetwarzanie danych osobowych w systemach informatycznych
    oraz w formie papierowej, w celu:
    <ul class="scope-list">
      <?php foreach ($scope as $k): ?>
      <li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li>
      <?php endforeach; ?>
      <?php if ($row['scope_custom']): ?>
      <li><?= h($row['scope_custom']) ?></li>
      <?php endif; ?>
    </ul>
  </div>
</div>

<!-- § 3 -->
<div class="section">
  <div class="section-title"><span class="para-num">§ 3</span></div>
  <div class="section-body">
    Osoba upoważniona jest zobowiązana do:
    <ol class="oblig-list">
      <li>Przetwarzania danych osobowych wyłącznie w zakresie i celu określonym w niniejszym upoważnieniu.</li>
      <li>Zachowania w pełnej poufności przetwarzanych danych osobowych oraz sposobów ich zabezpieczenia, również po ustaniu stosunku wolontariatu.</li>
      <li>Stosowania się do przepisów Rozporządzenia (UE) 2016/679 (RODO) oraz wewnętrznej polityki bezpieczeństwa <?= h($row['org_name']) ?>.</li>
      <li>Niezwłocznego zgłaszania Administratorowi każdego przypadku naruszenia ochrony danych lub podejrzenia takiego naruszenia.</li>
    </ol>
  </div>
</div>

<!-- § 4 -->
<div class="section">
  <div class="section-title"><span class="para-num">§ 4</span></div>
  <div class="section-body">
    Upoważnienie wygasa z chwilą rozwiązania lub wygaśnięcia porozumienia o wolontariacie<?php
    if ($until_date): ?>, nie później jednak niż w dniu <?= $until_date ?>,<?php endif; ?>
    lub w przypadku cofnięcia upoważnienia przez Administratora danych.
  </div>
</div>

<!-- Podpis Administratora -->
<hr class="hr-thin">
<div class="sign-row">
  <div class="sign-block" style="flex:1.3">
    <div class="sign-city" style="text-align:left">
      <?= h($org_city) ?>, dnia <?= $signed_date ?>
    </div>
    <div class="sign-line"></div>
    <div class="sign-label">
      (podpis Administratora danych / osoby reprezentującej organizację)
      <?php if ($row['signed_by_name']): ?><br><strong><?= h($row['signed_by_name']) ?></strong><?php endif; ?>
    </div>
  </div>
  <div style="flex:.4"></div><!-- odstęp -->
</div>

<!-- Oświadczenie wolontariusza -->
<div class="statement-box">
  <div class="statement-title">Oświadczenie osoby upoważnionej</div>
  <div class="statement-body">
    Oświadczam, że zapoznałem/am się z przepisami dotyczącymi ochrony danych osobowych
    oraz wewnętrznymi procedurami bezpieczeństwa obowiązującymi w <?= h($row['org_name']) ?>.
    Zobowiązuję się do ich przestrzegania i zachowania w tajemnicy wszelkich danych osobowych,
    do których uzyskam dostęp w ramach niniejszego upoważnienia.
  </div>
  <div class="sign-row" style="margin-top:.5rem">
    <div class="sign-block" style="flex:1.3">
      <div class="sign-city" style="text-align:left;font-size:.82rem">
        <?= h($org_city) ?>, dnia <?= $vol_signed_date ?>
      </div>
      <div class="sign-line" style="height:1.8rem"></div>
      <div class="sign-label">
        (podpis osoby upoważnionej — <?= h($row['person_name']) ?>)
      </div>
    </div>
    <div style="flex:.4"></div>
  </div>
</div>

<!-- Stopka dokumentu -->
<div class="doc-footer">
  <span>Rejestr upoważnień RODO · <?= h($row['org_name']) ?></span>
  <span><?= h($row['number']) ?> · wygenerowano <?= date('d.m.Y') ?></span>
</div>

</body>
</html>
