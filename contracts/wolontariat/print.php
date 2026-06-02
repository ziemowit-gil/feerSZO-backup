<?php
/**
 * contracts/wolontariat/print.php — Wydruk porozumienia wolontariackiego.
 *
 * GET: id (int) — identyfikator porozumienia w tabeli umowy_wolontariat
 *      preview=1 — podgląd bez automatycznego druku
 *
 * Strona generuje czysty HTML A4 bez frameworków CSS.
 * Przeglądarka drukuje automatycznie po załadowaniu (chyba że ?preview=1).
 *
 * Podstawa prawna: ustawa z dnia 24 kwietnia 2003 r. o działalności pożytku
 * publicznego i o wolontariacie (Dz.U. 2003 nr 96 poz. 873 z późn. zm.).
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Nieprawidłowe żądanie.'); }

$row = db_one("SELECT * FROM umowy_wolontariat WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); exit('Nie znaleziono porozumienia.'); }

if (!viewer_owns_contract('wolontariat', $row)) {
    header('Location: ' . APP_URL . '/panel/index.php');
    exit;
}

$is_preview = isset($_GET['preview']);

// ── Dane organizacji ──────────────────────────────────────────────────────────
$org_name   = org_setting('org_name')        ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres  = org_setting('org_adres')       ?: '';
$org_miasto = org_setting('org_miejscowosc') ?: '';
$org_nip    = org_setting('org_nip')         ?: '';
$org_krs    = org_setting('org_krs')         ?: '';
$org_regon  = org_setting('org_regon')       ?: '';
$org_logo   = org_setting('org_logo')        ?: '';
$org_logo_url = $org_logo ? APP_URL . '/uploads/' . $org_logo : '';

// ── Dane porozumienia ─────────────────────────────────────────────────────────
$numer          = $row['numer_umowy']         ?? '';
$data_zawarcia  = $row['data_zawarcia']       ?? '';
$data_start     = $row['data_rozpoczecia']    ?? '';
$data_end       = $row['data_zakonczenia']    ?? '';
$bezterminowa   = !empty($row['bezterminowa']);
$miejsce        = $row['miejsce_wolontariatu'] ?? '';
$przedmiot      = $row['przedmiot_porozumienia'] ?? '';
$opiekun        = $row['opiekun']             ?? '';
$projekt        = $row['projekt_program']     ?? '';
$godzin_tyg     = $row['godzin_tygodniowo']   ?? '';
$forma          = $row['forma_podpisania']    ?? 'tradycyjna';
$nr_rejestru    = $row['nr_rejestru']         ?? '';
$uwagi          = $row['uwagi']               ?? '';

// Wolontariusz
$w_imie         = $row['imie_nazwisko']  ?? '';
$w_pesel        = $row['pesel']          ?? '';
$w_adres        = $row['adres']          ?? '';
$w_email        = $row['email']          ?? '';
$w_tel          = $row['telefon']        ?? '';

// BHP / ubezpieczenia
$bhp_ok         = !empty($row['szkolenie_bhp']);
$bhp_data       = $row['data_szkolenia_bhp']  ?? '';
$nnw_ok         = !empty($row['ubezpieczenie_nnw']);
$nnw_polisa     = $row['numer_polisy_nnw']    ?? '';
$oc_ok          = !empty($row['ubezpieczenie_oc']);
$zwrot_ok       = !empty($row['zwrot_kosztow']);
$zwrot_opis     = $row['zwrot_kosztow_opis']  ?? '';

$is_electronic  = in_array($forma, ['elektroniczna', 'epodpis_kwalifikowany'], true);

// ── Pomocnicze funkcje formatowania ──────────────────────────────────────────
function pf_w(mixed $v): string {
    $s = (string)($v ?? '');
    return $s !== '' ? htmlspecialchars($s, ENT_QUOTES) : '<span class="puste">—</span>';
}

function dpl_w(?string $d): string {
    if (!$d) return '<span class="puste">—</span>';
    $t = strtotime($d);
    if (!$t) return htmlspecialchars($d, ENT_QUOTES);
    $mies = ['stycznia','lutego','marca','kwietnia','maja','czerwca',
             'lipca','sierpnia','września','października','listopada','grudnia'];
    return (int)date('d', $t) . ' ' . $mies[(int)date('m', $t) - 1] . ' ' . date('Y', $t) . ' r.';
}

$data_zawarcia_fmt = dpl_w($data_zawarcia);
$data_start_fmt    = dpl_w($data_start);
$data_end_fmt      = $bezterminowa ? 'czas nieokreślony' : dpl_w($data_end);
$podpisano_w       = $org_miasto ?: 'miejscowość';

?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Porozumienie wolontariackie nr <?= htmlspecialchars($numer, ENT_QUOTES) ?></title>
<style>
/* ── Reset ─────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 11pt; }
body { font-family: 'Times New Roman', Times, serif; color: #111; background: #fff; line-height: 1.6; }

/* ── Układ A4 ──────────────────────────────────────────────── */
.page {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 22mm 22mm 20mm 25mm;
    position: relative;
    background: #fff;
}

/* ── Nagłówek organizacji ──────────────────────────────────── */
.org-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2.5pt solid #000;
    padding-bottom: 7pt;
    margin-bottom: 14pt;
}
.org-logo img { max-height: 54pt; max-width: 108pt; }
.org-logo .no-logo {
    font-size: 24pt; font-weight: 700; letter-spacing: -1px; color: #1a1a1a;
}
.org-details { text-align: right; font-size: 8.5pt; line-height: 1.75; }
.org-details .name { font-weight: 700; font-size: 10pt; }

/* ── Tytuł dokumentu ───────────────────────────────────────── */
.doc-title {
    text-align: center;
    margin: 16pt 0 8pt;
}
.doc-title .label {
    font-size: 7.5pt; letter-spacing: 2.5px; text-transform: uppercase; color: #666;
}
.doc-title .type {
    font-size: 18pt; font-weight: 700; letter-spacing: 1.5px;
    text-transform: uppercase; margin: 4pt 0;
}
.doc-title .number {
    display: inline-block;
    font-size: 10.5pt; font-weight: 700;
    border: 1pt solid #111;
    padding: 3pt 16pt; margin-top: 4pt;
}
.doc-title .place { font-size: 9pt; color: #444; margin-top: 6pt; }

/* ── Podstawa prawna ───────────────────────────────────────── */
.legal-basis {
    font-size: 8pt; color: #555; font-style: italic;
    text-align: center; margin-bottom: 12pt; line-height: 1.5;
}

/* ── Sekcje ────────────────────────────────────────────────── */
.section { margin-top: 14pt; }
.section-title {
    font-size: 9pt; font-weight: 700; letter-spacing: .6px;
    text-transform: uppercase;
    border-bottom: 1pt solid #111;
    padding-bottom: 2.5pt; margin-bottom: 9pt;
}

/* ── Strony porozumienia ───────────────────────────────────── */
.parties { display: grid; grid-template-columns: 1fr 1fr; gap: 12pt; margin-top: 4pt; }
.party { border: 1pt solid #ccc; padding: 9pt 11pt; background: #fafafa; }
.party-role {
    font-size: 7.5pt; font-weight: 700; letter-spacing: 1.2px;
    text-transform: uppercase; color: #555;
    margin-bottom: 5pt; padding-bottom: 3pt;
    border-bottom: 1pt dashed #ccc;
}
.party-name { font-size: 11pt; font-weight: 700; margin-bottom: 5pt; }
.party-row { font-size: 8.5pt; margin-bottom: 2.5pt; display: flex; gap: 6pt; }
.party-row .lbl { color: #555; min-width: 50pt; flex-shrink: 0; }

/* ── Tabela danych ─────────────────────────────────────────── */
table.data { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-top: 4pt; }
table.data td { padding: 4pt 6pt; vertical-align: top; border-bottom: 1pt solid #eee; }
table.data td:first-child {
    font-weight: 700; width: 44%; color: #333; white-space: nowrap;
}
table.data tr:nth-child(even) td { background: #f7f7f7; }

/* ── Przedmiot porozumienia ────────────────────────────────── */
.przedmiot-box {
    border: 1pt solid #bbb; padding: 9pt 11pt;
    min-height: 56pt; font-size: 9.5pt; line-height: 1.7;
    background: #fafafa; margin-top: 4pt; white-space: pre-wrap;
}

/* ── Klauzule i paragrafy ──────────────────────────────────── */
.paragraf { margin-bottom: 10pt; font-size: 9.5pt; line-height: 1.65; text-align: justify; }
.paragraf .par-nr {
    font-weight: 700; font-size: 9pt; display: block; margin-bottom: 3pt;
}
.klauzula { font-size: 9pt; line-height: 1.65; margin-bottom: 6pt; text-align: justify; }
.klauzula strong { font-size: 9.5pt; }
.check-row { font-size: 9pt; margin-bottom: 4pt; display: flex; align-items: center; gap: 6pt; }
.check-box {
    display: inline-block; width: 11pt; height: 11pt; border: 1pt solid #555;
    text-align: center; line-height: 11pt; font-size: 8pt; flex-shrink: 0;
}
.check-yes { background: #000; color: #fff; }
.check-no  { color: #aaa; }

/* ── Uwagi ─────────────────────────────────────────────────── */
.uwagi-box {
    border: 1pt dashed #aaa; padding: 7pt 10pt; font-size: 9pt;
    line-height: 1.65; color: #333; margin-top: 4pt; min-height: 32pt;
}

/* ── Podpisy ───────────────────────────────────────────────── */
.signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 32pt; margin-top: 32pt; }
.sig-block { text-align: center; }
.sig-stamp {
    display: inline-flex; align-items: center; justify-content: center;
    border: 1.5pt dashed #aaa; width: 90pt; height: 72pt;
    font-size: 7.5pt; color: #aaa; text-align: center; line-height: 1.5;
    margin-bottom: 0;
}
.sig-line { border-bottom: 1pt solid #000; margin-bottom: 4pt; height: 36pt; }
.sig-label { font-size: 9pt; font-weight: 700; }
.sig-sublabel { font-size: 7.5pt; color: #555; margin-top: 2pt; }

/* ── Podpis elektroniczny ──────────────────────────────────── */
.esig-box {
    border: 1.5pt solid #2563eb; border-radius: 4pt;
    padding: 11pt 13pt; background: #eff6ff; margin-top: 8pt;
}
.esig-badge {
    display: inline-block; background: #dbeafe; border: 1pt solid #93c5fd;
    border-radius: 3pt; padding: 1pt 7pt; font-size: 7.5pt;
    color: #1e40af; font-weight: 700; margin-bottom: 6pt;
}
.esig-title { font-size: 9.5pt; font-weight: 700; color: #1d4ed8; margin-bottom: 6pt; }
.esig-row { font-size: 8.5pt; margin-bottom: 3pt; display: flex; gap: 6pt; }
.esig-row .lbl { color: #1e40af; font-weight: 600; min-width: 82pt; flex-shrink: 0; }

/* ── Nr rejestru ───────────────────────────────────────────── */
.nr-rejestru-box {
    display: inline-block; border: 1pt solid #ccc;
    padding: 3pt 10pt; font-size: 8.5pt; color: #555;
    font-family: monospace; margin-top: 8pt;
}

/* ── Stopka strony ─────────────────────────────────────────── */
.page-footer {
    position: absolute; bottom: 10mm; left: 25mm; right: 22mm;
    border-top: 1pt solid #ccc; padding-top: 4pt;
    display: flex; justify-content: space-between;
    font-size: 7pt; color: #888;
}

/* ── Pomocnicze ────────────────────────────────────────────── */
.puste { color: #bbb; }
.text-center { text-align: center; }
.mt-sm { margin-top: 6pt; }

/* ── Pasek akcji (tylko ekran) ─────────────────────────────── */
.action-bar {
    position: fixed; top: 0; left: 0; right: 0;
    background: #1e293b; color: #fff;
    padding: 6px 16px;
    display: flex; align-items: center; gap: 10px;
    z-index: 999;
    font-family: system-ui, sans-serif; font-size: .85rem;
}
.action-bar button, .action-bar a {
    padding: 4px 14px; border-radius: 4px;
    font-size: .82rem; cursor: pointer; border: none;
    text-decoration: none;
}
.btn-print  { background: #3b82f6; color: #fff; }
.btn-close  { background: #475569; color: #fff; margin-left: auto; }
.action-bar .info { color: #94a3b8; font-size: .8rem; }

/* ── Media print ───────────────────────────────────────────── */
@media print {
    body { font-size: 10.5pt; }
    .page { width: 100%; padding: 0; }
    .no-print { display: none !important; }
    .action-bar { display: none !important; }
    @page { size: A4; margin: 20mm 20mm 18mm 25mm; }
    .page-break { page-break-before: always; }
    .page-break-avoid { page-break-inside: avoid; }
    .page-footer { position: fixed; bottom: 0; }
}
</style>
</head>
<body>

<!-- ── Pasek akcji (tylko ekran) ─────────────────────────────────────────── -->
<div class="action-bar no-print">
  <button class="btn-print" onclick="window.print()">&#9113; Drukuj / Zapisz PDF</button>
  <?php if ($is_preview): ?>
    <a class="action-bar" style="background:#7c3aed;color:#fff;border-radius:4px;padding:4px 14px;font-size:.82rem;text-decoration:none;border:none"
       href="print.php?id=<?= $id ?>">&#9654; Drukuj automatycznie</a>
  <?php endif; ?>
  <span class="info">
    Porozumienie wolontariackie
    <?php if ($numer): ?>&nbsp;&#183;&nbsp;Nr <?= htmlspecialchars($numer, ENT_QUOTES) ?><?php endif; ?>
    <?php if ($w_imie): ?>&nbsp;&#183;&nbsp;<?= htmlspecialchars($w_imie, ENT_QUOTES) ?><?php endif; ?>
  </span>
  <a class="btn-close" href="javascript:window.close()">&#10005; Zamknij</a>
</div>
<div style="height:36px" class="no-print"></div>

<!-- ══ DOKUMENT A4 ══════════════════════════════════════════════════════════ -->
<div class="page">

  <!-- ── Nagłówek organizacji ──────────────────────────────────────────── -->
  <div class="org-header">
    <div class="org-logo">
      <?php if ($org_logo_url): ?>
        <img src="<?= htmlspecialchars($org_logo_url, ENT_QUOTES) ?>" alt="Logo <?= htmlspecialchars($org_name, ENT_QUOTES) ?>">
      <?php else: ?>
        <span class="no-logo"><?= htmlspecialchars(mb_strtoupper(mb_substr($org_name, 0, 2)), ENT_QUOTES) ?></span>
      <?php endif; ?>
    </div>
    <div class="org-details">
      <div class="name"><?= htmlspecialchars($org_name, ENT_QUOTES) ?></div>
      <?php if ($org_krs):   ?><div>KRS: <?= htmlspecialchars($org_krs, ENT_QUOTES) ?></div><?php endif; ?>
      <?php if ($org_nip):   ?><div>NIP: <?= htmlspecialchars($org_nip, ENT_QUOTES) ?></div><?php endif; ?>
      <?php if ($org_regon): ?><div>REGON: <?= htmlspecialchars($org_regon, ENT_QUOTES) ?></div><?php endif; ?>
      <?php if ($org_adres):
            $adres_full = $org_adres . ($org_miasto ? ', ' . $org_miasto : '');
      ?><div><?= htmlspecialchars($adres_full, ENT_QUOTES) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- ── Tytuł dokumentu ───────────────────────────────────────────────── -->
  <div class="doc-title">
    <div class="label">Dokument</div>
    <div class="type">Porozumienie wolontariackie</div>
    <?php if ($numer): ?>
      <div class="number">Nr <?= htmlspecialchars($numer, ENT_QUOTES) ?></div>
    <?php endif; ?>
    <div class="place">
      Zawarte w <?= htmlspecialchars($podpisano_w, ENT_QUOTES) ?>, dnia <?= $data_zawarcia_fmt ?>
    </div>
  </div>

  <p class="legal-basis">
    Zawarte na podstawie ustawy z dnia 24 kwietnia 2003 r. o działalności pożytku publicznego
    i&nbsp;o&nbsp;wolontariacie (t.j. Dz.U. 2023 poz. 571 z&nbsp;późn.&nbsp;zm.).
  </p>

  <!-- ── Strony porozumienia ───────────────────────────────────────────── -->
  <div class="section">
    <div class="section-title">Strony porozumienia</div>
    <div class="parties">

      <!-- Korzystający -->
      <div class="party">
        <div class="party-role">Korzystający (organizacja)</div>
        <div class="party-name"><?= pf_w($org_name) ?></div>
        <?php if ($org_krs):   ?><div class="party-row"><span class="lbl">KRS:</span><span><?= htmlspecialchars($org_krs, ENT_QUOTES) ?></span></div><?php endif; ?>
        <?php if ($org_nip):   ?><div class="party-row"><span class="lbl">NIP:</span><span><?= htmlspecialchars($org_nip, ENT_QUOTES) ?></span></div><?php endif; ?>
        <?php if ($org_regon): ?><div class="party-row"><span class="lbl">REGON:</span><span><?= htmlspecialchars($org_regon, ENT_QUOTES) ?></span></div><?php endif; ?>
        <?php if ($org_adres): ?><div class="party-row"><span class="lbl">Adres:</span><span><?= htmlspecialchars($adres_full ?? $org_adres, ENT_QUOTES) ?></span></div><?php endif; ?>
        <?php if ($opiekun):   ?><div class="party-row"><span class="lbl">Opiekun:</span><span><?= htmlspecialchars($opiekun, ENT_QUOTES) ?></span></div><?php endif; ?>
      </div>

      <!-- Wolontariusz -->
      <div class="party">
        <div class="party-role">Wolontariusz</div>
        <div class="party-name"><?= pf_w($w_imie) ?></div>
        <?php if ($w_pesel): ?><div class="party-row"><span class="lbl">PESEL:</span><span><?= htmlspecialchars($w_pesel, ENT_QUOTES) ?></span></div><?php endif; ?>
        <?php if ($w_adres): ?><div class="party-row"><span class="lbl">Adres:</span><span><?= htmlspecialchars($w_adres, ENT_QUOTES) ?></span></div><?php endif; ?>
        <?php if ($w_email): ?><div class="party-row"><span class="lbl">E-mail:</span><span><?= htmlspecialchars($w_email, ENT_QUOTES) ?></span></div><?php endif; ?>
        <?php if ($w_tel):   ?><div class="party-row"><span class="lbl">Tel.:</span><span><?= htmlspecialchars($w_tel, ENT_QUOTES) ?></span></div><?php endif; ?>
      </div>

    </div>
  </div>

  <!-- ── § 1. Zakres i warunki wolontariatu ────────────────────────────── -->
  <div class="section">
    <div class="section-title">§ 1. Zakres i warunki wolontariatu</div>
    <table class="data">
      <tr>
        <td>Data zawarcia porozumienia</td>
        <td><?= $data_zawarcia_fmt ?></td>
      </tr>
      <?php if ($data_start): ?>
      <tr>
        <td>Data rozpoczęcia wolontariatu</td>
        <td><?= $data_start_fmt ?></td>
      </tr>
      <?php endif; ?>
      <tr>
        <td>Czas trwania</td>
        <td><?= $data_end_fmt ?></td>
      </tr>
      <?php if ($miejsce): ?>
      <tr>
        <td>Miejsce wykonywania wolontariatu</td>
        <td><?= pf_w($miejsce) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($godzin_tyg): ?>
      <tr>
        <td>Wymiar zaangażowania</td>
        <td><?= htmlspecialchars((string)$godzin_tyg, ENT_QUOTES) ?> godz./tydzień</td>
      </tr>
      <?php endif; ?>
      <?php if ($projekt): ?>
      <tr>
        <td>Projekt / Program</td>
        <td><?= pf_w($projekt) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($nr_rejestru): ?>
      <tr>
        <td>Numer rejestru</td>
        <td><span style="font-family:monospace"><?= htmlspecialchars($nr_rejestru, ENT_QUOTES) ?></span></td>
      </tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- ── § 2. Przedmiot porozumienia ──────────────────────────────────── -->
  <div class="section">
    <div class="section-title">§ 2. Przedmiot porozumienia i zakres czynności</div>
    <?php if ($przedmiot): ?>
      <div class="przedmiot-box"><?= nl2br(htmlspecialchars($przedmiot, ENT_QUOTES)) ?></div>
    <?php else: ?>
      <div class="przedmiot-box" style="color:#aaa;font-style:italic">
        Zakres czynności wolontariusza nie został sprecyzowany.
      </div>
    <?php endif; ?>
  </div>

  <!-- ── § 3. Prawa i obowiązki stron ─────────────────────────────────── -->
  <div class="section">
    <div class="section-title">§ 3. Prawa i obowiązki stron</div>

    <div class="paragraf">
      <span class="par-nr">1. Obowiązki Korzystającego:</span>
      Korzystający zobowiązuje się do: informowania wolontariusza o ryzyku dla zdrowia i bezpieczeństwa,
      zapewnienia wolontariuszowi bezpiecznych i higienicznych warunków wykonywania świadczeń,
      zapewnienia wolontariuszowi środków ochrony indywidualnej niezbędnych do wykonywanych zadań
      oraz&nbsp;pokrycia kosztów podróży służbowych i diet, zgodnie z&nbsp;przepisami Kodeksu pracy
      — o&nbsp;ile porozumienie nie stanowi inaczej.
    </div>

    <div class="paragraf">
      <span class="par-nr">2. Obowiązki Wolontariusza:</span>
      Wolontariusz zobowiązuje się do: wykonywania powierzonych zadań sumiennie i&nbsp;zgodnie z&nbsp;zakresem
      niniejszego porozumienia, przestrzegania zasad BHP oraz wewnętrznych regulaminów Korzystającego,
      zachowania w&nbsp;tajemnicy wszelkich informacji poufnych uzyskanych w&nbsp;toku wolontariatu,
      a&nbsp;także informowania Korzystającego o&nbsp;nieobecności z&nbsp;co najmniej jednodniowym
      wyprzedzeniem.
    </div>
  </div>

  <!-- ── § 4. Szkolenia i ubezpieczenia ───────────────────────────────── -->
  <div class="section">
    <div class="section-title">§ 4. Szkolenia i ubezpieczenia</div>

    <div class="check-row">
      <span class="check-box <?= $bhp_ok ? 'check-yes' : 'check-no' ?>"><?= $bhp_ok ? '&#10003;' : '&#10007;' ?></span>
      <span>
        <strong>Szkolenie BHP:</strong>
        <?php if ($bhp_ok && $bhp_data): ?>
          Wolontariusz odbył szkolenie BHP w dniu <?= dpl_w($bhp_data) ?>.
        <?php elseif ($bhp_ok): ?>
          Wolontariusz odbył wymagane szkolenie BHP.
        <?php else: ?>
          Szkolenie BHP zostanie przeprowadzone przez Korzystającego przed przystąpieniem
          do wolontariatu, zgodnie z&nbsp;art.&nbsp;45 ust.&nbsp;1 pkt&nbsp;1 ustawy o&nbsp;działalności
          pożytku publicznego i&nbsp;o&nbsp;wolontariacie.
        <?php endif; ?>
      </span>
    </div>

    <div class="check-row mt-sm">
      <span class="check-box <?= $nnw_ok ? 'check-yes' : 'check-no' ?>"><?= $nnw_ok ? '&#10003;' : '&#10007;' ?></span>
      <span>
        <strong>Ubezpieczenie NNW:</strong>
        <?php if ($nnw_ok && $nnw_polisa): ?>
          Wolontariusz jest objęty ubezpieczeniem od następstw nieszczęśliwych wypadków,
          nr polisy: <strong><?= htmlspecialchars($nnw_polisa, ENT_QUOTES) ?></strong>.
        <?php elseif ($nnw_ok): ?>
          Wolontariusz jest objęty ubezpieczeniem od następstw nieszczęśliwych wypadków (NNW).
        <?php else: ?>
          Wolontariusz nie jest objęty ubezpieczeniem NNW w&nbsp;ramach niniejszego porozumienia.
          Korzystający informuje, że&nbsp;ubezpieczenie NNW przysługuje wolontariuszom
          z&nbsp;mocy prawa po upływie 30&nbsp;dni od daty zawarcia porozumienia (ZUS).
        <?php endif; ?>
      </span>
    </div>

    <div class="check-row mt-sm">
      <span class="check-box <?= $oc_ok ? 'check-yes' : 'check-no' ?>"><?= $oc_ok ? '&#10003;' : '&#10007;' ?></span>
      <span>
        <strong>Ubezpieczenie OC:</strong>
        <?php if ($oc_ok): ?>
          Wolontariusz objęty jest ubezpieczeniem od odpowiedzialności cywilnej w&nbsp;związku
          z&nbsp;wykonywanym wolontariatem.
        <?php else: ?>
          Wolontariusz nie jest objęty ubezpieczeniem OC w&nbsp;ramach niniejszego porozumienia.
        <?php endif; ?>
      </span>
    </div>
  </div>

  <!-- ── § 5. Zwrot kosztów ────────────────────────────────────────────── -->
  <div class="section">
    <div class="section-title">§ 5. Zwrot kosztów</div>
    <div class="klauzula">
      <?php if ($zwrot_ok): ?>
        Korzystający zobowiązuje się do zwrotu uzasadnionych kosztów poniesionych przez wolontariusza
        w&nbsp;związku z&nbsp;wykonywaniem wolontariatu, w&nbsp;tym kosztów podróży i&nbsp;diet,
        na&nbsp;zasadach określonych w&nbsp;przepisach dotyczących należności przysługujących
        pracownikom zatrudnionym w&nbsp;państwowej lub samorządowej jednostce sfery budżetowej
        z&nbsp;tytułu podróży służbowej na obszarze kraju.
        <?php if ($zwrot_opis): ?>
          <br><strong>Szczegóły:</strong> <?= htmlspecialchars($zwrot_opis, ENT_QUOTES) ?>
        <?php endif; ?>
      <?php else: ?>
        Wolontariuszowi nie przysługuje zwrot kosztów podróży i&nbsp;diet z&nbsp;tytułu niniejszego
        porozumienia. Wolontariusz zrzeka się prawa do zwrotu kosztów.
      <?php endif; ?>
    </div>
  </div>

  <!-- ── § 6. Ochrona danych osobowych (RODO) ─────────────────────────── -->
  <div class="section">
    <div class="section-title">§ 6. Klauzula informacyjna RODO</div>
    <div class="klauzula">
      Administratorem danych osobowych wolontariusza jest
      <strong><?= htmlspecialchars($org_name, ENT_QUOTES) ?></strong><?php
      if ($org_adres) echo ', ' . htmlspecialchars($adres_full ?? $org_adres, ENT_QUOTES);
      ?>.
      Dane osobowe przetwarzane są na&nbsp;podstawie art.&nbsp;6 ust.&nbsp;1 lit.&nbsp;b RODO
      (wykonanie umowy) oraz art.&nbsp;9 ust.&nbsp;2 lit.&nbsp;b RODO w&nbsp;zakresie wymaganym
      przepisami prawa pracy i&nbsp;ubezpieczeniowego, w&nbsp;celu zawarcia i&nbsp;realizacji
      niniejszego porozumienia wolontariackiego.
      Dane będą przechowywane przez okres realizacji wolontariatu oraz przez czas wynikający
      z&nbsp;przepisów prawa (dokumentacja wolontariacka). Wolontariuszowi przysługuje prawo
      dostępu do&nbsp;danych, ich sprostowania, usunięcia lub ograniczenia przetwarzania,
      a&nbsp;także prawo wniesienia skargi do&nbsp;Prezesa Urzędu Ochrony Danych Osobowych.
    </div>
  </div>

  <!-- ── § 7. Rozwiązanie porozumienia ────────────────────────────────── -->
  <div class="section">
    <div class="section-title">§ 7. Rozwiązanie porozumienia</div>
    <div class="klauzula">
      <?php if ($bezterminowa): ?>
        Porozumienie zawarto na czas nieokreślony. Każda ze stron może wypowiedzieć porozumienie
        ze skutkiem natychmiastowym lub z&nbsp;zachowaniem uzgodnionego okresu wypowiedzenia.
      <?php else: ?>
        Porozumienie wygasa z&nbsp;upływem czasu, na jaki zostało zawarte, tj. w&nbsp;dniu <?= $data_end_fmt ?>.
        Każda ze stron może rozwiązać porozumienie przed tym terminem za porozumieniem stron
        lub ze skutkiem natychmiastowym w&nbsp;przypadku rażącego naruszenia jego postanowień.
      <?php endif; ?>
      Wolontariuszowi przysługuje zaświadczenie o&nbsp;wykonanym wolontariacie na&nbsp;jego wniosek
      (art.&nbsp;44 ustawy o&nbsp;działalności pożytku publicznego i&nbsp;o&nbsp;wolontariacie).
    </div>
  </div>

  <!-- ── § 8. Postanowienia końcowe ───────────────────────────────────── -->
  <div class="section">
    <div class="section-title">§ 8. Postanowienia końcowe</div>
    <div class="klauzula">
      Wszelkie zmiany niniejszego porozumienia wymagają formy pisemnej pod&nbsp;rygorem nieważności.
      W sprawach nieuregulowanych niniejszym porozumieniem zastosowanie mają przepisy ustawy
      z&nbsp;dnia 24&nbsp;kwietnia 2003&nbsp;r. o&nbsp;działalności pożytku publicznego
      i&nbsp;o&nbsp;wolontariacie oraz Kodeksu cywilnego.
      Porozumienie sporządzono w&nbsp;dwóch jednobrzmiących egzemplarzach, po&nbsp;jednym
      dla&nbsp;każdej ze&nbsp;stron.
    </div>
    <?php if ($uwagi): ?>
    <div class="uwagi-box"><?= nl2br(htmlspecialchars($uwagi, ENT_QUOTES)) ?></div>
    <?php endif; ?>
  </div>

  <!-- ── Podpisy stron ─────────────────────────────────────────────────── -->
  <div class="section page-break-avoid" style="margin-top: 28pt">
    <?php if ($is_electronic): ?>
      <div class="section-title">Zawarcie porozumienia drogą elektroniczną</div>
      <p style="font-size:8.5pt;color:#555;margin-bottom:10pt">
        Niniejsze porozumienie zostało zawarte i&nbsp;podpisane w&nbsp;formie elektronicznej —
        odręczne podpisy stron nie są wymagane.
      </p>
      <div class="esig-box">
        <div class="esig-badge">&#9679; PODPIS ELEKTRONICZNY</div>
        <div class="esig-title">
          <?= $forma === 'epodpis_kwalifikowany'
              ? '&#128274; Kwalifikowany podpis elektroniczny (ePodpis)'
              : '&#9993; Podpis elektroniczny' ?>
        </div>
        <?php
        $platform = '';
        if (!empty($row['m365_user_id'])) $platform = 'Microsoft 365';
        elseif (!empty($row['portal_account_id'])) $platform = 'Portal wolontariuszy';
        if ($platform): ?>
        <div class="esig-row"><span class="lbl">Platforma:</span><span><?= htmlspecialchars($platform, ENT_QUOTES) ?></span></div>
        <?php endif; ?>
        <div class="esig-row"><span class="lbl">Korzystający:</span><span><?= pf_w($org_name) ?></span></div>
        <div class="esig-row"><span class="lbl">Wolontariusz:</span><span><?= pf_w($w_imie) ?></span></div>
        <div class="esig-row"><span class="lbl">Data zawarcia:</span><span><?= $data_zawarcia_fmt ?></span></div>
        <?php if ($numer): ?>
        <div class="esig-row"><span class="lbl">Nr porozumienia:</span><span><?= htmlspecialchars($numer, ENT_QUOTES) ?></span></div>
        <?php endif; ?>
        <p style="font-size:7.5pt;color:#1e40af;margin-top:8pt;font-style:italic">
          <?= $forma === 'epodpis_kwalifikowany'
              ? 'Porozumienie podpisane kwalifikowanym podpisem elektronicznym zgodnie z rozporządzeniem eIDAS. Podpis ma moc prawną równoważną podpisowi odręcznemu.'
              : 'Porozumienie zawarte w formie elektronicznej. Strony wyraziły zgodę na zawarcie porozumienia bez własnoręcznych podpisów.' ?>
        </p>
      </div>
    <?php else: ?>
      <div class="section-title">Podpisy stron</div>
      <p style="font-size:8.5pt;color:#555;margin-bottom:14pt">
        Strony potwierdzają zawarcie porozumienia podpisami złożonymi poniżej.
        Porozumienie sporządzono w&nbsp;dwóch jednobrzmiących egzemplarzach.
      </p>
      <div class="signatures">

        <div class="sig-block">
          <div class="text-center" style="margin-bottom:6pt">
            <span class="sig-stamp">Pieczęć<br>organizacji</span>
          </div>
          <div class="sig-line"></div>
          <div class="sig-label"><?= htmlspecialchars($org_name, ENT_QUOTES) ?></div>
          <div class="sig-sublabel">Korzystający — reprezentant / Podpis i pieczęć</div>
          <?php if ($opiekun): ?>
          <div style="font-size:8pt;color:#555;margin-top:3pt"><?= htmlspecialchars($opiekun, ENT_QUOTES) ?></div>
          <?php endif; ?>
        </div>

        <div class="sig-block">
          <div class="sig-line" style="margin-top:78pt"></div>
          <div class="sig-label"><?= pf_w($w_imie) ?></div>
          <div class="sig-sublabel">Wolontariusz — podpis własnoręczny</div>
          <?php if ($w_adres): ?>
          <div style="font-size:8pt;color:#555;margin-top:3pt"><?= htmlspecialchars($w_adres, ENT_QUOTES) ?></div>
          <?php endif; ?>
        </div>

      </div>
    <?php endif; ?>
  </div>

  <!-- ── Stopka ─────────────────────────────────────────────────────────── -->
  <div class="page-footer">
    <span><?= htmlspecialchars($org_name, ENT_QUOTES) ?></span>
    <span>
      Porozumienie wolontariackie
      <?php if ($numer): ?>· Nr <?= htmlspecialchars($numer, ENT_QUOTES) ?><?php endif; ?>
      · Wygenerowano: <?= date('d.m.Y H:i') ?>
    </span>
  </div>

</div><!-- /page -->

<script>
<?php if (!$is_preview): ?>
// Automatyczny wydruk po załadowaniu strony
setTimeout(function() {
    window.print();
}, 500);
<?php endif; ?>
</script>
</body>
</html>
