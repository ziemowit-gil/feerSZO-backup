<?php
/**
 * contracts/print.php — Urzędowy wydruk umowy / porozumienia.
 *
 * GET: type (wolontariat|zlecenie|uslugi|dzielo|praca|inne), id
 *
 * Strona jest czystym HTML bez layoutu aplikacji.
 * Otwiera się w nowej karcie; przeglądarka drukuje po załadowaniu.
 */
if (!defined('APP_INSTALLED')) require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

$type_map = [
    'wolontariat' => ['table' => 'umowy_wolontariat', 'label' => 'Porozumienie wolontariackie',   'short' => 'POROZUMIENIE WOLONTARIACKIE'],
    'zlecenie'    => ['table' => 'umowy_zlecenie',    'label' => 'Umowa zlecenie',                 'short' => 'UMOWA ZLECENIE'],
    'uslugi'      => ['table' => 'umowy_uslugi',      'label' => 'Umowa o świadczenie usług',      'short' => 'UMOWA O ŚWIADCZENIE USŁUG'],
    'dzielo'      => ['table' => 'umowy_dzielo',      'label' => 'Umowa o dzieło',                 'short' => 'UMOWA O DZIEŁO'],
    'praca'       => ['table' => 'umowy_praca',       'label' => 'Umowa o pracę',                  'short' => 'UMOWA O PRACĘ'],
    'inne'        => ['table' => 'umowy_inne',        'label' => 'Umowa',                          'short' => 'UMOWA'],
];

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

if (!isset($type_map[$type]) || $id <= 0) {
    http_response_code(400); exit('Nieprawidłowe żądanie.');
}

$cfg = $type_map[$type];
$row = db_one("SELECT * FROM {$cfg['table']} WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); exit('Nie znaleziono umowy.'); }

// ── Dane organizacji ──────────────────────────────────────────────────────────
$org_name     = org_setting('org_name')        ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_krs      = org_setting('org_krs')         ?: '';
$org_nip      = org_setting('org_nip')         ?: '';
$org_regon    = org_setting('org_regon')       ?: '';
$org_adres    = org_setting('org_adres')       ?: '';
$org_miasto   = org_setting('org_miejscowosc') ?: '';
$org_logo_key = org_setting('org_logo')        ?: '';
$org_logo_url = $org_logo_key ? APP_URL . '/uploads/' . $org_logo_key : '';

// ── Dane umowy (wspólne i specyficzne) ────────────────────────────────────────
$numer        = $row['numer_umowy']        ?? '';
$data_zawarcia   = $row['data_zawarcia']  ?? '';
$data_start      = $row['data_rozpoczecia'] ?? ($row['data_od'] ?? '');
$data_end        = $row['data_zakonczenia'] ?? ($row['data_do'] ?? '');
$bezterminowa    = !empty($row['bezterminowa']);
$miejsce         = $row['miejsce_wolontariatu'] ?? ($row['miejsce'] ?? $org_miasto);

// Strona 2 (osoba)
$osoba_name   = $row['imie_nazwisko']  ?? ($row['nazwa_wykonawcy'] ?? '');
$osoba_pesel  = $row['pesel']          ?? '';
$osoba_adres  = $row['adres']          ?? '';
$osoba_email  = $row['email']          ?? '';
$osoba_tel    = $row['telefon']        ?? '';
$osoba_doc    = $row['id_document_type'] ? ($row['id_document_number'] ?? '') : '';

// Przedmiot / zakres
$przedmiot    = $row['zakres_czynnosci']    ?? ($row['przedmiot_porozumienia']
             ?? ($row['przedmiot_zlecenia'] ?? ($row['przedmiot_umowy']
             ?? ($row['tytul_stanowisko']   ?? ($row['tytul'] ?? '')))));

// Wynagrodzenie
$kwota        = isset($row['wynagrodzenie_brutto']) ? (float)$row['wynagrodzenie_brutto'] : null;
if ($kwota === null) $kwota = isset($row['kwota']) ? (float)$row['kwota'] : null;
$waluta       = $row['waluta'] ?? 'PLN';

// Specyficzne: wolontariat
$ubezpieczenie  = !empty($row['ubezpieczenie_nnw']);
$numer_polisy   = $row['numer_polisy_nnw'] ?? '';
$bhp_ok         = !empty($row['szkolenie_bhp']);
$bhp_data       = $row['data_szkolenia_bhp'] ?? '';
$zwrot_kosztow  = !empty($row['zwrot_kosztow']);
$opiekun        = $row['opiekun'] ?? '';
$projekt        = $row['projekt_program'] ?? '';
$godzin_tyg     = $row['godzin_tygodniowo'] ?? '';

// Forma podpisania
$forma_podpisania = $row['forma_podpisania'] ?? 'tradycyjna';
$is_electronic    = in_array($forma_podpisania, ['elektroniczna', 'epodpis_kwalifikowany'], true);

// Pomocnicze
function pf(string $v): string { return $v !== '' ? h($v) : '<span class="puste">—</span>'; }
function dpl(?string $d): string {
    if (!$d) return '<span class="puste">—</span>';
    $t = strtotime($d);
    if (!$t) return h($d);
    $mies = ['stycznia','lutego','marca','kwietnia','maja','czerwca',
             'lipca','sierpnia','września','października','listopada','grudnia'];
    return (int)date('d',$t) . ' ' . $mies[(int)date('m',$t)-1] . ' ' . date('Y',$t) . ' r.';
}

$data_zawarcia_fmt = dpl($data_zawarcia);
$data_start_fmt    = dpl($data_start);
$data_end_fmt      = $bezterminowa ? 'czas nieokreślony' : dpl($data_end);
$podpisano_w       = $org_miasto ?: 'miejscowość';

?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($cfg['label']) ?> nr <?= h($numer) ?></title>
<style>
/* ── Reset i podstawy ───────────────────────────────────────── */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;font-size:11pt;color:#111;
     background:#fff;line-height:1.55}
/* ── Układ strony ───────────────────────────────────────────── */
.page{width:210mm;min-height:297mm;margin:0 auto;padding:20mm 20mm 18mm 25mm;
      position:relative}
/* ── Nagłówek organizacji ───────────────────────────────────── */
.org-header{display:flex;justify-content:space-between;align-items:flex-start;
            border-bottom:2.5pt solid #000;padding-bottom:6pt;margin-bottom:12pt}
.org-logo img{max-height:52pt;max-width:100pt}
.org-logo .no-logo{font-size:22pt;font-weight:700;letter-spacing:-1px}
.org-details{text-align:right;font-size:8.5pt;line-height:1.7}
.org-name{font-weight:700;font-size:10.5pt;text-align:right}
/* ── Tytuł dokumentu ────────────────────────────────────────── */
.doc-title-wrap{text-align:center;margin:18pt 0 6pt}
.doc-label{font-size:7.5pt;letter-spacing:2px;text-transform:uppercase;color:#555}
.doc-type{font-size:17pt;font-weight:700;letter-spacing:1px;text-transform:uppercase;
          margin:3pt 0}
.doc-number{font-size:10.5pt;font-weight:700;border:1pt solid #000;
            display:inline-block;padding:3pt 12pt;margin-top:4pt}
.doc-place{font-size:9pt;margin-top:6pt;color:#333}
/* ── Sekcje ─────────────────────────────────────────────────── */
.section{margin-top:14pt}
.section-title{font-size:9pt;font-weight:700;letter-spacing:.5px;
               text-transform:uppercase;border-bottom:1pt solid #000;
               padding-bottom:2pt;margin-bottom:8pt}
/* ── Strony umowy ───────────────────────────────────────────── */
.parties{display:grid;grid-template-columns:1fr 1fr;gap:14pt;margin-top:4pt}
.party{border:1pt solid #ccc;padding:8pt 10pt;background:#fafafa}
.party-role{font-size:8pt;font-weight:700;letter-spacing:1px;text-transform:uppercase;
            color:#555;margin-bottom:5pt;padding-bottom:3pt;border-bottom:1pt dashed #ccc}
.party-name{font-size:11pt;font-weight:700;margin-bottom:4pt}
.party-row{font-size:8.5pt;margin-bottom:2pt;display:flex;gap:6pt}
.party-row .lbl{color:#555;min-width:52pt;flex-shrink:0}
/* ── Tabela danych ───────────────────────────────────────────── */
table.data{width:100%;border-collapse:collapse;font-size:9.5pt;margin-top:4pt}
table.data tr td{padding:4pt 6pt;vertical-align:top}
table.data tr td:first-child{font-weight:700;width:42%;color:#333;white-space:nowrap}
table.data tr:nth-child(even) td{background:#f7f7f7}
table.data tr td{border-bottom:1pt solid #eee}
/* ── Przedmiot ───────────────────────────────────────────────── */
.przedmiot-box{border:1pt solid #bbb;padding:8pt 10pt;min-height:52pt;
               font-size:9.5pt;line-height:1.65;background:#fafafa;margin-top:4pt;
               white-space:pre-wrap}
/* ── Klauzule ────────────────────────────────────────────────── */
.klauzula{font-size:8.5pt;line-height:1.65;margin-bottom:6pt;text-align:justify}
.klauzula strong{font-size:9pt}
/* ── Podpisy ─────────────────────────────────────────────────── */
.signatures{display:grid;grid-template-columns:1fr 1fr;gap:30pt;margin-top:30pt}
.sig-block{text-align:center}
.sig-line{border-bottom:1pt solid #000;margin-bottom:4pt;height:36pt}
.sig-label{font-size:8.5pt;color:#333}
.sig-sublabel{font-size:7.5pt;color:#555;margin-top:2pt}
/* ── Podpis elektroniczny ────────────────────────────────────── */
.esig-box{border:1.5pt solid #2563eb;border-radius:5pt;padding:10pt 12pt;background:#eff6ff;margin-top:8pt}
.esig-title{font-size:9.5pt;font-weight:700;color:#1d4ed8;margin-bottom:5pt;letter-spacing:.3px}
.esig-row{font-size:8.5pt;margin-bottom:3pt;display:flex;gap:6pt}
.esig-row .lbl{color:#1e40af;font-weight:600;min-width:80pt;flex-shrink:0}
.esig-badge{display:inline-block;background:#dbeafe;border:1pt solid #93c5fd;border-radius:3pt;
            padding:1pt 6pt;font-size:7.5pt;color:#1e40af;font-weight:700;margin-bottom:5pt}
/* ── Stopka strony ───────────────────────────────────────────── */
.page-footer{position:absolute;bottom:10mm;left:25mm;right:20mm;
             border-top:1pt solid #ccc;padding-top:4pt;
             display:flex;justify-content:space-between;font-size:7pt;color:#888}
/* ── Pieczęć ─────────────────────────────────────────────────── */
.stamp-area{border:1.5pt dashed #aaa;width:88pt;height:70pt;display:inline-flex;
            align-items:center;justify-content:center;font-size:7.5pt;color:#aaa;
            margin-top:8pt;text-align:center;line-height:1.4}
/* ── Pomocnicze ─────────────────────────────────────────────── */
.puste{color:#bbb}
.text-center{text-align:center}
.mt2{margin-top:8pt}
.check{font-family:Arial,sans-serif}
/* ── Druk ────────────────────────────────────────────────────── */
@media print{
  body{font-size:10.5pt}
  .page{padding:15mm 18mm 16mm 22mm;width:100%}
  .no-print{display:none!important}
  @page{size:A4;margin:0}
  .page-break{page-break-before:always}
}
/* ── Pasek akcji (tylko ekran) ──────────────────────────────── */
.action-bar{position:fixed;top:0;left:0;right:0;background:#1e293b;color:#fff;
            padding:6px 16px;display:flex;align-items:center;gap:10px;z-index:999;
            font-family:system-ui,sans-serif;font-size:.85rem}
.action-bar button,.action-bar a{padding:4px 14px;border-radius:4px;font-size:.82rem;cursor:pointer;border:none}
.btn-print{background:#3b82f6;color:#fff}
.btn-teczka{background:#7c3aed;color:#fff;text-decoration:none;display:inline-block}
.btn-close-bar{background:#475569;color:#fff;margin-left:auto;text-decoration:none;display:inline-block}
@media print{.action-bar{display:none}}
</style>
</head>
<body>

<!-- ── Pasek akcji (tylko ekran) ─────────────────────────────────── -->
<div class="action-bar no-print">
  <button class="btn-print" onclick="window.print()">⎙ Drukuj</button>
  <a class="btn-teczka"
     href="<?= APP_URL ?>/contracts/teczka.php?type=<?= h($type) ?>&id=<?= $id ?>"
     target="_blank">⬜ Ozdobnik na teczkę</a>
  <span style="color:#94a3b8"><?= h($cfg['label']) ?> · <?= h($numer) ?></span>
  <a class="btn-close-bar" href="javascript:window.close()">✕ Zamknij</a>
</div>
<div style="height:34px" class="no-print"></div>

<!-- ══ STRONA DOKUMENTU ══════════════════════════════════════════════ -->
<div class="page">

  <!-- ── Nagłówek organizacji ─────────────────────────────────────── -->
  <div class="org-header">
    <div class="org-logo">
      <?php if ($org_logo_url): ?>
      <img src="<?= h($org_logo_url) ?>" alt="Logo">
      <?php else: ?>
      <span class="no-logo"><?= mb_strtoupper(mb_substr($org_name, 0, 2)) ?></span>
      <?php endif; ?>
    </div>
    <div class="org-details">
      <div class="org-name"><?= h($org_name) ?></div>
      <?php if ($org_krs):   ?><div>KRS: <?= h($org_krs) ?></div><?php endif; ?>
      <?php if ($org_nip):   ?><div>NIP: <?= h($org_nip) ?></div><?php endif; ?>
      <?php if ($org_regon): ?><div>REGON: <?= h($org_regon) ?></div><?php endif; ?>
      <?php if ($org_adres): ?><div><?= h($org_adres) ?><?= $org_miasto ? ', ' . h($org_miasto) : '' ?></div><?php endif; ?>
    </div>
  </div>

  <!-- ── Tytuł dokumentu ──────────────────────────────────────────── -->
  <div class="doc-title-wrap">
    <div class="doc-label">Dokument</div>
    <div class="doc-type"><?= h($cfg['short']) ?></div>
    <div class="doc-number">Nr <?= pf($numer) ?></div>
    <div class="doc-place">
      Zawarte w <?= h($podpisano_w) ?>, dnia <?= $data_zawarcia_fmt ?>
    </div>
  </div>

  <!-- ── Strony umowy ──────────────────────────────────────────────── -->
  <div class="section">
    <div class="section-title">Strony umowy</div>
    <div class="parties">

      <!-- Organizacja -->
      <div class="party">
        <div class="party-role">Zamawiający / Organizacja</div>
        <div class="party-name"><?= pf($org_name) ?></div>
        <?php if ($org_krs):   ?><div class="party-row"><span class="lbl">KRS:</span><span><?= h($org_krs) ?></span></div><?php endif; ?>
        <?php if ($org_nip):   ?><div class="party-row"><span class="lbl">NIP:</span><span><?= h($org_nip) ?></span></div><?php endif; ?>
        <?php if ($org_regon): ?><div class="party-row"><span class="lbl">REGON:</span><span><?= h($org_regon) ?></span></div><?php endif; ?>
        <?php if ($org_adres): ?><div class="party-row"><span class="lbl">Adres:</span><span><?= h($org_adres) ?><?= $org_miasto ? ', ' . h($org_miasto) : '' ?></span></div><?php endif; ?>
        <?php if ($opiekun):   ?><div class="party-row"><span class="lbl">Opiekun:</span><span><?= h($opiekun) ?></span></div><?php endif; ?>
      </div>

      <!-- Druga strona -->
      <div class="party">
        <div class="party-role"><?= $type === 'wolontariat' ? 'Wolontariusz' : ($type === 'uslugi' ? 'Wykonawca' : ($type === 'praca' ? 'Pracownik' : 'Zleceniobiorca / Wykonawca')) ?></div>
        <div class="party-name"><?= pf($osoba_name) ?></div>
        <?php if ($osoba_pesel):  ?><div class="party-row"><span class="lbl">PESEL:</span><span><?= h($osoba_pesel) ?></span></div><?php endif; ?>
        <?php if ($osoba_adres):  ?><div class="party-row"><span class="lbl">Adres:</span><span><?= h($osoba_adres) ?></span></div><?php endif; ?>
        <?php if ($osoba_email):  ?><div class="party-row"><span class="lbl">E-mail:</span><span><?= h($osoba_email) ?></span></div><?php endif; ?>
        <?php if ($osoba_tel):    ?><div class="party-row"><span class="lbl">Tel.:</span><span><?= h($osoba_tel) ?></span></div><?php endif; ?>
        <?php if ($osoba_doc):    ?><div class="party-row"><span class="lbl">Dok.:</span><span><?= h($osoba_doc) ?></span></div><?php endif; ?>
      </div>

    </div>
  </div>

  <!-- ── Dane umowy ────────────────────────────────────────────────── -->
  <div class="section">
    <div class="section-title">Dane umowy</div>
    <table class="data">
      <tr><td>Data zawarcia</td><td><?= $data_zawarcia_fmt ?></td></tr>
      <?php if ($data_start): ?><tr><td>Data rozpoczęcia</td><td><?= $data_start_fmt ?></td></tr><?php endif; ?>
      <tr><td>Data zakończenia / Czas trwania</td><td><?= $data_end_fmt ?></td></tr>
      <?php if ($miejsce): ?><tr><td>Miejsce wykonywania</td><td><?= pf($miejsce) ?></td></tr><?php endif; ?>
      <?php if ($kwota !== null): ?><tr><td>Wynagrodzenie / Wartość</td><td><strong><?= money($kwota, $waluta) ?></strong></td></tr><?php endif; ?>
      <?php if ($projekt): ?><tr><td>Projekt / Program</td><td><?= pf($projekt) ?></td></tr><?php endif; ?>
      <?php if ($godzin_tyg): ?><tr><td>Wymiar czasu</td><td><?= pf((string)$godzin_tyg) ?> godz./tydzień</td></tr><?php endif; ?>
      <?php
      // Typ-specyficzne pola
      if ($type === 'zlecenie') {
          if (!empty($row['sposob_rozliczenia'])) echo '<tr><td>Sposób rozliczenia</td><td>' . pf($row['sposob_rozliczenia']) . '</td></tr>';
          if (!empty($row['termin_platnosci'])) echo '<tr><td>Termin płatności</td><td>' . pf($row['termin_platnosci']) . '</td></tr>';
          if (!empty($row['kup'])) echo '<tr><td>Koszty uzysk. przych.</td><td>' . pf($row['kup']) . '</td></tr>';
      }
      if ($type === 'praca') {
          if (!empty($row['stanowisko'])) echo '<tr><td>Stanowisko</td><td>' . pf($row['stanowisko']) . '</td></tr>';
          if (!empty($row['wymiar_etatu'])) echo '<tr><td>Wymiar etatu</td><td>' . pf($row['wymiar_etatu']) . '</td></tr>';
      }
      ?>
      <tr><td>Numer rejestru</td><td><?= pf($row['nr_rejestru'] ?? '') ?></td></tr>
      <tr><td>Status</td><td><?= pf($row['status'] ?? '') ?></td></tr>
    </table>
  </div>

  <!-- ── Przedmiot / Zakres czynności ─────────────────────────────── -->
  <?php if ($przedmiot): ?>
  <div class="section">
    <div class="section-title">Przedmiot / Zakres czynności</div>
    <div class="przedmiot-box"><?= nl2br(h($przedmiot)) ?></div>
  </div>
  <?php endif; ?>

  <!-- ── Klauzule (wolontariat) ────────────────────────────────────── -->
  <?php if ($type === 'wolontariat'): ?>
  <div class="section">
    <div class="section-title">Oświadczenia i klauzule</div>
    <p class="klauzula">
      <strong>1. Ubezpieczenie NNW:</strong>
      <?php if ($ubezpieczenie && $numer_polisy): ?>
      Wolontariusz objęty ubezpieczeniem NNW, nr polisy: <strong><?= h($numer_polisy) ?></strong>.
      <?php elseif ($ubezpieczenie): ?>
      Wolontariusz objęty ubezpieczeniem NNW.
      <?php else: ?>
      Wolontariusz nie jest objęty ubezpieczeniem NNW w ramach niniejszego porozumienia.
      <?php endif; ?>
    </p>
    <p class="klauzula">
      <strong>2. Szkolenie BHP:</strong>
      <?php if ($bhp_ok && $bhp_data): ?>
      Wolontariusz odbył szkolenie BHP w dniu <?= dpl($bhp_data) ?>.
      <?php elseif ($bhp_ok): ?>
      Wolontariusz odbył wymagane szkolenie BHP.
      <?php else: ?>
      Szkolenie BHP do odbycia przed przystąpieniem do wolontariatu.
      <?php endif; ?>
    </p>
    <p class="klauzula">
      <strong>3. Zwrot kosztów:</strong>
      <?php if ($zwrot_kosztow): ?>
      Organizacja zobowiązuje się do zwrotu uzasadnionych kosztów poniesionych przez wolontariusza.
      <?php if (!empty($row['zwrot_kosztow_opis'])): ?> <?= h($row['zwrot_kosztow_opis']) ?><?php endif; ?>
      <?php else: ?>
      Wolontariuszowi nie przysługuje zwrot kosztów z tytułu niniejszego porozumienia.
      <?php endif; ?>
    </p>
    <p class="klauzula">
      <strong>4. Klauzula informacyjna RODO:</strong>
      Administratorem danych osobowych wolontariusza jest <?= h($org_name) ?>.
      Dane przetwarzane są wyłącznie w celu realizacji wolontariatu.
      Wolontariusz ma prawo dostępu, sprostowania i usunięcia swoich danych.
    </p>
  </div>
  <?php endif; ?>

  <!-- ── Podpisy ────────────────────────────────────────────────────── -->
  <div class="section page-break-avoid" style="margin-top:24pt">
    <?php if ($is_electronic): ?>
    <div class="section-title">Zawarcie umowy drogą elektroniczną</div>
    <div style="font-size:8.5pt;color:#555;margin-bottom:10pt">
      Niniejsza umowa została zawarta i podpisana w formie elektronicznej — odręczne podpisy stron nie są wymagane.
    </div>
    <div class="esig-box">
      <div class="esig-badge">&#9679; PODPIS ELEKTRONICZNY</div>
      <div class="esig-title">
        <?= $forma_podpisania === 'epodpis_kwalifikowany' ? '&#128274; Kwalifikowany podpis elektroniczny (ePodpis)' : '&#9993; Podpis elektroniczny' ?>
      </div>
      <?php
      $platform = '';
      if (!empty($row['m365_user_id'])) {
          $platform = 'Microsoft 365';
      } elseif (!empty($row['portal_account_id'])) {
          $platform = 'Portal wolontariuszy';
      }
      ?>
      <?php if ($platform): ?>
      <div class="esig-row"><span class="lbl">Platforma:</span><span><?= h($platform) ?></span></div>
      <?php endif; ?>
      <div class="esig-row"><span class="lbl">Zlecający:</span><span><?= pf($org_name) ?></span></div>
      <div class="esig-row"><span class="lbl">Wykonawca:</span><span><?= pf($osoba_name) ?></span></div>
      <div class="esig-row"><span class="lbl">Data zawarcia:</span><span><?= $data_zawarcia_fmt ?></span></div>
      <?php if (!empty($row['numer_umowy'])): ?>
      <div class="esig-row"><span class="lbl">Nr umowy:</span><span><?= h($row['numer_umowy']) ?></span></div>
      <?php endif; ?>
      <div style="font-size:7.5pt;color:#1e40af;margin-top:8pt;font-style:italic">
        <?= $forma_podpisania === 'epodpis_kwalifikowany'
            ? 'Umowa podpisana kwalifikowanym podpisem elektronicznym zgodnie z rozporządzeniem eIDAS. Podpis ma moc prawną równoważną podpisowi odręcznemu.'
            : 'Umowa zawarta w formie elektronicznej. Strony wyraziły zgodę na zawarcie umowy bez własnoręcznych podpisów.' ?>
      </div>
    </div>
    <?php else: ?>
    <div class="section-title">Podpisy stron</div>
    <div style="font-size:8.5pt;color:#555;margin-bottom:12pt">
      Strony potwierdzają zawarcie umowy podpisami złożonymi poniżej.
    </div>
    <div class="signatures">
      <div class="sig-block">
        <div class="text-center" style="margin-bottom:6pt">
          <span class="stamp-area">Pieczęć<br>organizacji</span>
        </div>
        <div class="sig-line"></div>
        <div class="sig-label"><?= h($org_name) ?></div>
        <div class="sig-sublabel">Reprezentant organizacji / Podpis i pieczęć</div>
      </div>
      <div class="sig-block">
        <div class="sig-line" style="margin-top:76pt"></div>
        <div class="sig-label"><?= pf($osoba_name) ?></div>
        <div class="sig-sublabel"><?= $type === 'wolontariat' ? 'Wolontariusz — podpis' : 'Zleceniobiorca / Wykonawca — podpis' ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Stopka ─────────────────────────────────────────────────────── -->
  <div class="page-footer">
    <span><?= h($org_name) ?></span>
    <span>Dok. nr: <?= h($numer) ?> · Wygenerowano: <?= date('d.m.Y H:i') ?></span>
  </div>

</div><!-- /page -->

<script>
// Auto-drukuj po załadowaniu jeśli w nowej karcie (bez przycisków)
if (window.opener) {
    // Otwarte z innej strony — czekaj na kliknięcie
} else {
    // window.print(); // Odkomentuj aby drukować automatycznie
}
</script>
</body>
</html>
