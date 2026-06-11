<?php
/**
 * contracts/migracja_webngo/print.php — Potwierdzenie migracji umowy z webNGO.
 *
 * GET: id (int)      — identyfikator rekordu migracji
 *      preview=1     — podgląd bez automatycznego druku
 *
 * Generuje oficjalny dokument (Protokół Migracji Technicznej) potwierdzający
 * przeniesienie danych umowy z systemu webNGO do FEER SZO.
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once __DIR__ . '/_table.php';

require_login();
migracja_webngo_ensure_table();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Nieprawidłowe żądanie.'); }

$row = db_one("SELECT * FROM umowy_migracja_webngo WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); exit('Nie znaleziono rekordu migracji.'); }

$is_preview = isset($_GET['preview']);

// ── Dane organizacji ──────────────────────────────────────────────────────────
$org_name    = org_setting('org_name')        ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres   = org_setting('org_adres')       ?: '';
$org_miasto  = org_setting('org_miejscowosc') ?: '';
$org_nip     = org_setting('org_nip')         ?: '';
$org_krs     = org_setting('org_krs')         ?: '';
$org_regon   = org_setting('org_regon')       ?: '';
$org_logo    = org_setting('org_logo')        ?: '';
$org_logo_url = $org_logo ? APP_URL . '/uploads/' . $org_logo : '';

// ── Dane migracji ─────────────────────────────────────────────────────────────
$typ_zrodla    = MIGRACJA_TYPY[$row['typ_umowy_zrodla']] ?? $row['typ_umowy_zrodla'];
$powod_label   = MIGRACJA_POWODY[$row['powod_migracji']]  ?? $row['powod_migracji'];
$data_migr     = $row['data_migracji'] ? date('d.m.Y', strtotime($row['data_migracji'])) : '—';
$data_druku    = date('d.m.Y');
$osoba         = $row['osoba_migrujaca'] ?: (current_user()['name'] ?? '');

function e(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function dat(string $s): string {
    if (!$s || !strtotime($s)) return '—';
    return date('d.m.Y', strtotime($s));
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Protokół migracji <?= e($row['numer_umowy']) ?></title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Times New Roman', Times, serif;
      font-size: 11pt;
      color: #000;
      background: #fff;
      padding: 0;
    }

    .page {
      width: 210mm;
      min-height: 297mm;
      margin: 0 auto;
      padding: 18mm 20mm 20mm;
    }

    /* ── Nagłówek organizacji ── */
    .org-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      border-bottom: 2px solid #000;
      padding-bottom: 8pt;
      margin-bottom: 14pt;
    }
    .org-logo img { max-height: 50pt; max-width: 100pt; }
    .org-data { text-align: right; font-size: 9pt; line-height: 1.5; }
    .org-data strong { font-size: 10pt; }

    /* ── Tytuł dokumentu ── */
    .doc-title {
      text-align: center;
      margin-bottom: 18pt;
    }
    .doc-title h1 {
      font-size: 14pt;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 1px;
      margin-bottom: 4pt;
    }
    .doc-title .doc-nr {
      font-size: 11pt;
      font-weight: normal;
    }
    .doc-title .doc-date {
      font-size: 10pt;
      color: #444;
      margin-top: 3pt;
    }

    /* ── Sekcje ── */
    .section {
      margin-bottom: 14pt;
    }
    .section-title {
      font-size: 10pt;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: 1px solid #666;
      padding-bottom: 3pt;
      margin-bottom: 8pt;
    }

    /* ── Tabela danych ── */
    table.data {
      width: 100%;
      border-collapse: collapse;
      font-size: 10pt;
    }
    table.data td {
      padding: 4pt 6pt;
      vertical-align: top;
    }
    table.data tr:nth-child(odd) td { background: #f9f9f9; }
    table.data td:first-child {
      width: 42%;
      font-weight: bold;
      color: #333;
    }

    /* ── Uzasadnienie ── */
    .uzasadnienie-box {
      border: 1.5px solid #c00;
      border-radius: 3pt;
      padding: 10pt 12pt;
      background: #fff9f9;
      margin-bottom: 0;
    }
    .uzasadnienie-box .powod-label {
      font-size: 9pt;
      font-weight: bold;
      text-transform: uppercase;
      color: #c00;
      margin-bottom: 5pt;
      border-bottom: 1px solid #f0c0c0;
      padding-bottom: 3pt;
    }
    .uzasadnienie-box p {
      font-size: 10.5pt;
      line-height: 1.6;
      white-space: pre-wrap;
    }

    /* ── Oświadczenie ── */
    .declaration {
      border: 1px solid #aaa;
      padding: 8pt 10pt;
      font-size: 10pt;
      line-height: 1.7;
      background: #fafafa;
    }

    /* ── Podpisy ── */
    .signatures {
      display: flex;
      justify-content: space-between;
      gap: 16pt;
      margin-top: 28pt;
    }
    .sig-block {
      flex: 1;
      text-align: center;
    }
    .sig-line {
      border-top: 1px solid #000;
      margin-top: 30pt;
      padding-top: 4pt;
      font-size: 9pt;
    }
    .sig-hint {
      font-size: 8.5pt;
      color: #555;
      margin-top: 2pt;
    }

    /* ── Stopka ── */
    .doc-footer {
      border-top: 1px solid #aaa;
      padding-top: 6pt;
      margin-top: 24pt;
      font-size: 8pt;
      color: #666;
      display: flex;
      justify-content: space-between;
    }

    /* ── Pieczęć QR ── */
    .stamp-area {
      border: 1px dashed #aaa;
      width: 70pt;
      height: 70pt;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 7pt;
      color: #aaa;
      text-align: center;
      padding: 4pt;
      margin-top: 10pt;
    }

    @media print {
      body { background: #fff; }
      .page { padding: 15mm 18mm; }
      .no-print { display: none !important; }
    }
  </style>
  <?php if (!$is_preview): ?>
  <script>window.addEventListener('load', () => window.print());</script>
  <?php endif; ?>
</head>
<body>
<div class="page">

  <?php if ($is_preview): ?>
  <div class="no-print" style="background:#e9ecef;padding:8px 12px;margin-bottom:12pt;display:flex;gap:8px;align-items:center;font-family:sans-serif;font-size:11px;border-radius:4px">
    <strong>Podgląd</strong> — dokument nie zostanie wydrukowany automatycznie.
    <button onclick="window.print()" style="margin-left:auto;padding:4px 10px;cursor:pointer">Drukuj</button>
    <a href="view.php?id=<?= $id ?>" style="padding:4px 10px;text-decoration:none;background:#6c757d;color:#fff;border-radius:3px">Powrót</a>
  </div>
  <?php endif; ?>

  <!-- ── Nagłówek organizacji ───────────────────────────────────────── -->
  <div class="org-header">
    <div class="org-logo">
      <?php if ($org_logo_url): ?>
      <img src="<?= e($org_logo_url) ?>" alt="Logo organizacji">
      <?php else: ?>
      <strong style="font-size:13pt"><?= e($org_name) ?></strong>
      <?php endif; ?>
    </div>
    <div class="org-data">
      <?php if ($org_logo_url): ?>
      <strong><?= e($org_name) ?></strong><br>
      <?php endif; ?>
      <?php if ($org_adres): ?><?= e($org_adres) ?><?php if ($org_miasto): ?>, <?= e($org_miasto) ?><?php endif; ?><br><?php endif; ?>
      <?php if ($org_nip):    ?>NIP: <?= e($org_nip) ?><br><?php endif; ?>
      <?php if ($org_krs):    ?>KRS: <?= e($org_krs) ?><br><?php endif; ?>
      <?php if ($org_regon):  ?>REGON: <?= e($org_regon) ?><?php endif; ?>
    </div>
  </div>

  <!-- ── Tytuł dokumentu ────────────────────────────────────────────── -->
  <div class="doc-title">
    <h1>Protokół Migracji Technicznej Umowy</h1>
    <div class="doc-nr">Nr dokumentu: <strong><?= e($row['numer_umowy']) ?></strong></div>
    <div class="doc-date">Data sporządzenia: <?= $data_druku ?></div>
  </div>

  <!-- ── §1 Umowa źródłowa ──────────────────────────────────────────── -->
  <div class="section">
    <div class="section-title">§1 Umowa źródłowa (system webNGO)</div>
    <table class="data">
      <tr>
        <td>Typ umowy:</td>
        <td><?= e($typ_zrodla) ?></td>
      </tr>
      <tr>
        <td>Numer umowy w webNGO:</td>
        <td><strong><?= e($row['webngo_numer_umowy']) ?></strong></td>
      </tr>
      <?php if ($row['webngo_id']): ?>
      <tr>
        <td>Identyfikator webNGO:</td>
        <td><?= e($row['webngo_id']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($row['webngo_data_zawarcia']): ?>
      <tr>
        <td>Data zawarcia umowy:</td>
        <td><?= dat($row['webngo_data_zawarcia']) ?></td>
      </tr>
      <?php endif; ?>
      <tr>
        <td>Strona umowy (imię i nazwisko):</td>
        <td><strong><?= e($row['imie_nazwisko']) ?></strong></td>
      </tr>
      <?php if ($row['pesel']): ?>
      <tr>
        <td>PESEL:</td>
        <td><?= e($row['pesel']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($row['email']): ?>
      <tr>
        <td>Adres e-mail:</td>
        <td><?= e($row['email']) ?></td>
      </tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- ── §2 Nowa umowa w FEER SZO ──────────────────────────────────── -->
  <div class="section">
    <div class="section-title">§2 Umowa w systemie FEER SZO (po migracji)</div>
    <table class="data">
      <tr>
        <td>Typ umowy:</td>
        <td><?php
          if ($row['nowy_typ_umowy'])
            echo e(MIGRACJA_TYPY[$row['nowy_typ_umowy']] ?? $row['nowy_typ_umowy']);
          else
            echo '<em style="color:#888">Nie wskazano — umowa w trakcie tworzenia</em>';
        ?></td>
      </tr>
      <tr>
        <td>Numer umowy:</td>
        <td><?= $row['nowy_numer_umowy'] ? '<strong>' . e($row['nowy_numer_umowy']) . '</strong>' : '<em style="color:#888">—</em>' ?></td>
      </tr>
      <tr>
        <td>System docelowy:</td>
        <td>FEER SZO — System Zarządzania Umowami</td>
      </tr>
      <tr>
        <td>Data migracji:</td>
        <td><?= dat($row['data_migracji']) ?></td>
      </tr>
    </table>
  </div>

  <!-- ── §3 Uzasadnienie migracji ───────────────────────────────────── -->
  <div class="section">
    <div class="section-title">§3 Uzasadnienie migracji</div>
    <div class="uzasadnienie-box">
      <div class="powod-label">Powód: <?= e($powod_label) ?></div>
      <p><?= e($row['opis_powodu']) ?></p>
    </div>
  </div>

  <?php if ($row['uwagi']): ?>
  <!-- ── §4 Uwagi dodatkowe ─────────────────────────────────────────── -->
  <div class="section">
    <div class="section-title">§4 Uwagi dodatkowe</div>
    <p style="font-size:10pt; line-height:1.6; white-space:pre-wrap"><?= e($row['uwagi']) ?></p>
  </div>
  <?php endif; ?>

  <!-- ── Oświadczenie ───────────────────────────────────────────────── -->
  <div class="section">
    <div class="declaration">
      Niniejszy protokół potwierdza, że dane umowy o numerze
      <strong><?= e($row['webngo_numer_umowy']) ?></strong>
      zostały przeniesione z systemu webNGO do systemu FEER SZO
      w dniu <strong><?= $data_migr ?></strong>
      przez osobę upoważnioną:
      <strong><?= e($osoba) ?></strong>.
      Migracja została przeprowadzona zgodnie z procedurami organizacji oraz
      zasadami bezpieczeństwa danych osobowych zgodnie z RODO.
      Dane osobowe przetwarzane są na podstawie art. 6 ust. 1 lit. b lub c RODO
      w związku z realizacją umowy / wypełnieniem obowiązku prawnego.
    </div>
  </div>

  <!-- ── Podpisy ────────────────────────────────────────────────────── -->
  <div class="signatures">
    <div class="sig-block">
      <div class="stamp-area">Pieczęć<br>organizacji</div>
      <div class="sig-line">Reprezentant organizacji</div>
      <div class="sig-hint">(imię, nazwisko, stanowisko)</div>
    </div>
    <div class="sig-block">
      <div class="stamp-area">Pieczęć<br>systemowa</div>
      <div class="sig-line">Osoba wykonująca migrację</div>
      <div class="sig-hint"><?= e($osoba) ?></div>
    </div>
    <div class="sig-block">
      <div class="stamp-area">Do<br>weryfikacji</div>
      <div class="sig-line">Administrator danych</div>
      <div class="sig-hint">(podpis i data)</div>
    </div>
  </div>

  <!-- ── Stopka ─────────────────────────────────────────────────────── -->
  <div class="doc-footer">
    <span>Dokument wygenerowany: <?= $data_druku ?> | FEER SZO v<?= defined('APP_VERSION') ? APP_VERSION : '1.0' ?></span>
    <span>Nr migracji: <?= e($row['numer_umowy']) ?></span>
  </div>

</div><!-- /.page -->
</body>
</html>
