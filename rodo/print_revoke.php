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

// Ostatnie cofnięcie
$rev = db_one(
    "SELECT * FROM rodo_revocations WHERE authorization_id=? ORDER BY revoked_at DESC LIMIT 1",
    [$id]
);

$org_city = org_setting('org_miejscowosc') ?: '_______________';
$org_krs  = org_setting('org_krs') ?: '';

$revoked_date    = $rev ? date('d.m.Y', strtotime($rev['revoked_at'])) : date('d.m.Y');
$revoked_by_name = $rev ? ($rev['revoked_by_name'] ?: '') : ($row['signed_by_name'] ?: '');
$reason          = $rev ? ($rev['reason'] ?? '') : '';

// Numer dokumentu cofnięcia: [nr_upoważnienia]/COF
$cof_number = $row['number'] . '/COF';

$contract_date_fmt = $row['contract_date'] ? date('d.m.Y', strtotime($row['contract_date'])) : '';
$auth_from_fmt     = $row['authorized_from'] ? date('d.m.Y', strtotime($row['authorized_from'])) : '_______________';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Cofnięcie upoważnienia RODO — <?= h($cof_number) ?></title>
<style>
/* ════════════════════════════════════════════════════════
   COFNIĘCIE UPOWAŻNIENIA RODO  ·  druk / PDF  ·  A4
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

/* ── Toolbar (tylko ekran) ────────────────────────────── */
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
  background: #7f1d1d; color: #fff;
  padding: .6rem 1.5rem;
  display: flex; align-items: center; gap: 1rem;
  font-family: system-ui, sans-serif; font-size: .88rem;
}
.toolbar button {
  background: #fff; color: #7f1d1d; border: none;
  padding: .4rem 1.2rem; border-radius: 4px;
  font-weight: 700; cursor: pointer; font-size: .88rem;
}
.toolbar a { color: rgba(255,255,255,.78); font-size: .83rem; text-decoration: none; }
.toolbar a:hover { color: #fff; }

/* ── Nagłówek org ─────────────────────────────────────── */
.org-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding-bottom: .55rem; border-bottom: 2px solid #000; margin-bottom: .6rem;
}
.org-name { font-size: 1.1rem; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; }
.org-meta  { font-size: .8rem; color: #333; margin-top: .15rem; line-height: 1.4; }
.doc-ref   { text-align: right; }
.doc-ref-num {
  font-size: .82rem; font-weight: bold; letter-spacing: .03em;
  border: 1.5px solid #7f1d1d; padding: .15rem .5rem;
  display: inline-block; margin-bottom: .2rem; color: #7f1d1d;
}
.doc-ref-date { font-size: .75rem; color: #444; }

/* ── Tytuł ────────────────────────────────────────────── */
.doc-title-wrap {
  text-align: center; margin: .7rem 0 .65rem;
  padding: .5rem 0;
  border-top: 1px solid #888; border-bottom: 1px solid #888;
}
.doc-title {
  font-size: 1.2rem; font-weight: bold;
  text-transform: uppercase; letter-spacing: .08em;
}
.doc-subtitle { font-size: .75rem; color: #555; margin-top: .2rem; }
.doc-ref-orig {
  display: inline-block; margin-top: .25rem;
  font-size: .82rem; color: #555; font-style: italic;
}

/* ── Strony ───────────────────────────────────────────── */
.parties-table {
  width: 100%; border-collapse: collapse; margin-bottom: .7rem; font-size: .9rem;
}
.parties-table td { vertical-align: top; padding: .3rem .5rem; }
.parties-table td:first-child { width: 50%; border-right: 1px solid #bbb; padding-right: .75rem; }
.parties-table td:last-child  { padding-left: .75rem; }
.party-head {
  font-size: .68rem; font-weight: bold; text-transform: uppercase;
  letter-spacing: .08em; color: #555;
  border-bottom: 1px solid #bbb; padding-bottom: .15rem; margin-bottom: .25rem;
}
.party-val { font-weight: bold; font-size: .98rem; }
.party-sub { font-size: .83rem; color: #222; line-height: 1.4; }

/* ── Paragrafy ────────────────────────────────────────── */
.section { margin-bottom: .6rem; }
.section-title { font-weight: bold; font-size: .9rem; display: flex; align-items: baseline; gap: .5rem; margin-bottom: .2rem; }
.section-title .para-num {
  font-size: 1rem; min-width: 1.6em; text-align: center;
  border: 1px solid #000; padding: 0 .2rem; border-radius: 2px; display: inline-block;
}
.section-body { font-size: .9rem; text-align: justify; line-height: 1.47; }

/* Lista § 3 */
.oblig-list { margin: .2rem 0 0 1.4rem; padding: 0; font-size: .88rem; line-height: 1.45; }
.oblig-list li { margin-bottom: .1rem; text-align: justify; }

/* ── Separator ────────────────────────────────────────── */
.hr-thin { border: none; border-top: 1px solid #bbb; margin: .6rem 0; }

/* ── Podpisy ──────────────────────────────────────────── */
.sign-row { display: flex; gap: 2rem; margin-top: .5rem; }
.sign-block { flex: 1; }
.sign-line  { border-bottom: 1px solid #000; margin-bottom: .2rem; height: 2.2rem; }
.sign-label { font-size: .72rem; text-align: center; color: #333; line-height: 1.35; }

/* ── Potwierdzenie odbioru ────────────────────────────── */
.receipt-box { border: 1px solid #888; padding: .45rem .6rem; margin-top: .55rem; font-size: .88rem; }
.receipt-title {
  font-weight: bold; font-size: .82rem; text-transform: uppercase;
  letter-spacing: .06em; margin-bottom: .3rem;
}
.receipt-body { text-align: justify; line-height: 1.45; }

/* ── Stopka ───────────────────────────────────────────── */
.doc-footer {
  margin-top: .7rem; padding-top: .4rem; border-top: 1px solid #ccc;
  font-size: .7rem; color: #777; display: flex; justify-content: space-between;
}

/* ── Druk ─────────────────────────────────────────────── */
@media screen {
  body { margin-top: 3rem; box-shadow: 0 0 20px rgba(0,0,0,.18); }
}
@media print {
  body { margin: 0; padding: 12mm 16mm 10mm 22mm; box-shadow: none; }
  .toolbar { display: none !important; }
  @page { size: A4 portrait; margin: 0; }
}
</style>
</head>
<body>

<!-- Toolbar -->
<div class="toolbar" aria-hidden="true">
  <button onclick="window.print()">🖨 Drukuj / PDF</button>
  <a href="view.php?id=<?= $id ?>">← Wróć do zarządzania</a>
  <span style="margin-left:auto;opacity:.7">Nr <?= h($cof_number) ?></span>
</div>

<!-- ═══ DOKUMENT ═══════════════════════════════════════════════════════════════ -->

<!-- Nagłówek org -->
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
    <div class="doc-ref-num">Nr <?= h($cof_number) ?></div>
    <div class="doc-ref-date">
      <?= h($org_city) ?>, dnia <?= $revoked_date ?>
    </div>
  </div>
</div>

<!-- Tytuł -->
<div class="doc-title-wrap">
  <div class="doc-title">Cofnięcie upoważnienia do przetwarzania danych osobowych</div>
  <div class="doc-subtitle">wydane na podstawie art. 29 Rozporządzenia (UE) 2016/679 (RODO)</div>
  <div class="doc-ref-orig">
    dotyczy upoważnienia nr <strong><?= h($row['number']) ?></strong>
    z dnia <?= $auth_from_fmt ?>
  </div>
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
      <div class="party-head">Osoba, której cofnięto upoważnienie</div>
      <div class="party-val"><?= h($row['person_name']) ?></div>
      <div class="party-sub">
        <?php if ($row['person_pesel']): ?>PESEL: <strong><?= h($row['person_pesel']) ?></strong><br><?php endif; ?>
        <?php if ($row['contract_number']): ?>Porozumienie nr: <strong><?= h($row['contract_number']) ?></strong><?php if ($contract_date_fmt): ?> z dnia <?= $contract_date_fmt ?><?php endif; ?><?php endif; ?>
      </div>
    </td>
  </tr>
</table>

<!-- § 1 — Cofnięcie -->
<div class="section">
  <div class="section-title"><span class="para-num">§ 1</span></div>
  <div class="section-body">
    Z dniem <strong><?= $revoked_date ?></strong> cofam udzielone Panu/Pani
    <strong><?= h($row['person_name']) ?></strong>
    upoważnienie do przetwarzania danych osobowych nr <strong><?= h($row['number']) ?></strong>
    z dnia <?= $auth_from_fmt ?>,
    wydane przez <?= h($row['org_name']) ?>.
    Z chwilą cofnięcia niniejszego upoważnienia osoba traci prawo do przetwarzania danych osobowych
    we wszystkich zbiorach prowadzonych przez Administratora.
  </div>
</div>

<!-- § 2 — Podstawa / powód -->
<div class="section">
  <div class="section-title"><span class="para-num">§ 2</span></div>
  <div class="section-body">
    <?php if ($reason): ?>
    Cofnięcie upoważnienia następuje z powodu: <strong><?= h($reason) ?></strong>.
    <?php else: ?>
    Cofnięcie upoważnienia następuje z powodu ustania stosunku wolontariatu lub zakończenia
    porozumienia o wolontariacie<?php if ($row['contract_number']): ?> nr&nbsp;<strong><?= h($row['contract_number']) ?></strong><?php endif; ?>,
    w związku z którym zostało ono wydane.
    <?php endif; ?>
  </div>
</div>

<!-- § 3 — Obowiązki po cofnięciu -->
<div class="section">
  <div class="section-title"><span class="para-num">§ 3</span></div>
  <div class="section-body">
    Osoba, której cofnięto upoważnienie, jest zobowiązana do:
    <ol class="oblig-list">
      <li>Niezwłocznego zaprzestania przetwarzania danych osobowych we wszystkich zbiorach
          objętych cofniętym upoważnieniem.</li>
      <li>Usunięcia lub zwrotu wszelkich danych osobowych pobranych ze zbiorów Administratora,
          w tym kopii papierowych i elektronicznych.</li>
      <li>Zachowania w pełnej poufności informacji uzyskanych w trakcie obowiązywania upoważnienia,
          zgodnie z przepisami Rozporządzenia (UE) 2016/679 (RODO).</li>
      <li>Wydania Administratorowi wszelkich nośników i dokumentów zawierających dane osobowe
          w terminie niezwłocznym, nie dłuższym niż 3 dni robocze od dnia cofnięcia upoważnienia.</li>
    </ol>
  </div>
</div>

<!-- § 4 — Wejście w życie -->
<div class="section">
  <div class="section-title"><span class="para-num">§ 4</span></div>
  <div class="section-body">
    Niniejsze cofnięcie upoważnienia wchodzi w życie z dniem <strong><?= $revoked_date ?></strong>
    i podlega odnotowaniu w Rejestrze osób upoważnionych do przetwarzania danych osobowych
    prowadzonym przez <?= h($row['org_name']) ?> zgodnie z art. 5 ust. 2 RODO.
  </div>
</div>

<!-- Podpis Administratora -->
<hr class="hr-thin">
<div class="sign-row">
  <div class="sign-block" style="flex:1.3">
    <div style="font-size:.82rem;margin-bottom:.8rem">
      <?= h($org_city) ?>, dnia <?= $revoked_date ?>
    </div>
    <div class="sign-line"></div>
    <div class="sign-label">
      (podpis Administratora danych / osoby reprezentującej organizację)
      <?php if ($revoked_by_name): ?><br><strong><?= h($revoked_by_name) ?></strong><?php endif; ?>
    </div>
  </div>
  <div style="flex:.4"></div>
</div>

<!-- Potwierdzenie odbioru -->
<div class="receipt-box">
  <div class="receipt-title">Potwierdzenie odbioru i zapoznania się z treścią cofnięcia</div>
  <div class="receipt-body">
    Oświadczam, że w dniu <strong>_______________</strong> otrzymałem/am niniejszy dokument i zapoznałem/am się
    z jego treścią. Zobowiązuję się do niezwłocznego zaprzestania przetwarzania danych osobowych
    oraz usunięcia wszelkich kopii danych uzyskanych w ramach cofniętego upoważnienia.
  </div>
  <div class="sign-row" style="margin-top:.5rem">
    <div class="sign-block" style="flex:1.3">
      <div style="font-size:.82rem;margin-bottom:.8rem">
        <?= h($org_city) ?>, dnia _______________
      </div>
      <div class="sign-line" style="height:1.8rem"></div>
      <div class="sign-label">
        (podpis osoby, której cofnięto upoważnienie — <?= h($row['person_name']) ?>)
      </div>
    </div>
    <div style="flex:.4"></div>
  </div>
</div>

<!-- Stopka -->
<div class="doc-footer">
  <span>Rejestr upoważnień RODO · <?= h($row['org_name']) ?></span>
  <span><?= h($cof_number) ?> · wygenerowano <?= date('d.m.Y') ?></span>
</div>

</body>
</html>
