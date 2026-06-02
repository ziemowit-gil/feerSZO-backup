<?php
/**
 * contracts/teczka.php — Ozdobnik na teczkę (etykieta do wydruku).
 *
 * GET: type, id  (opcjonalnie — bez id renderuje pusty wzorzec)
 *
 * Format: A4 portrait — drukuje się i wkleja / wsuwa na teczkę.
 */
if (!defined('APP_INSTALLED')) require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

$type_map = [
    'wolontariat' => ['label' => 'WOLONTARIAT',                'color' => '#1d4ed8', 'bg' => '#dbeafe', 'icon' => '★'],
    'zlecenie'    => ['label' => 'UMOWA ZLECENIE',             'color' => '#065f46', 'bg' => '#d1fae5', 'icon' => '◆'],
    'uslugi'      => ['label' => 'UMOWA O USŁUGI',             'color' => '#7c2d12', 'bg' => '#fef3c7', 'icon' => '◆'],
    'dzielo'      => ['label' => 'UMOWA O DZIEŁO',             'color' => '#4c1d95', 'bg' => '#ede9fe', 'icon' => '◈'],
    'praca'       => ['label' => 'UMOWA O PRACĘ',              'color' => '#831843', 'bg' => '#fce7f3', 'icon' => '◉'],
    'inne'        => ['label' => 'UMOWA INNA',                 'color' => '#374151', 'bg' => '#f3f4f6', 'icon' => '●'],
];

$type  = $_GET['type'] ?? 'inne';
$id    = (int)($_GET['id'] ?? 0);
$cfg   = $type_map[$type] ?? $type_map['inne'];
$row   = null;

if ($id > 0 && isset($type_map[$type])) {
    $tables = [
        'wolontariat' => 'umowy_wolontariat', 'zlecenie' => 'umowy_zlecenie',
        'uslugi' => 'umowy_uslugi',           'dzielo'   => 'umowy_dzielo',
        'praca'  => 'umowy_praca',            'inne'     => 'umowy_inne',
    ];
    $row = db_one("SELECT * FROM {$tables[$type]} WHERE id = ?", [$id]);
}

$org_name   = org_setting('org_name')        ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_krs    = org_setting('org_krs')         ?: '';
$org_nip    = org_setting('org_nip')         ?: '';
$org_adres  = org_setting('org_adres')       ?: '';
$org_miasto = org_setting('org_miejscowosc') ?: '';
$org_logo   = org_setting('org_logo')        ?: '';
$logo_url   = $org_logo ? APP_URL . '/uploads/' . $org_logo : '';

$numer     = $row['numer_umowy']    ?? '';
$osoba     = $row['imie_nazwisko']  ?? ($row['nazwa_wykonawcy'] ?? '');
$data_od   = $row['data_rozpoczecia'] ?? ($row['data_zawarcia'] ?? '');
$data_do   = $row['data_zakonczenia'] ?? '';
$bezterm   = !empty($row['bezterminowa']);
$projekt   = $row['projekt_program'] ?? ($row['projekt'] ?? '');
$opiekun   = $row['opiekun'] ?? '';
$status    = $row['status'] ?? '';

function tpl_date(?string $d): string {
    if (!$d) return '';
    $t = strtotime($d);
    return $t ? date('d.m.Y', $t) : $d;
}

$rok_od = $data_od ? date('Y', strtotime($data_od)) : date('Y');

?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Teczka — <?= h($osoba ?: $numer ?: 'Wzorzec') ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',serif;background:#e5e7eb;display:flex;
     flex-direction:column;align-items:center;padding:16px;min-height:100vh}

/* ── Pasek akcji ────────────────────────────────── */
.action-bar{background:#1e293b;color:#fff;padding:6px 16px;
            display:flex;align-items:center;gap:10px;border-radius:6px;
            margin-bottom:14px;font-family:system-ui,sans-serif;font-size:.83rem;
            width:210mm;max-width:100%}
.action-bar button,.action-bar a{padding:4px 14px;border-radius:4px;
  font-size:.82rem;cursor:pointer;border:none;text-decoration:none}
.btn-print{background:#3b82f6;color:#fff}
.btn-wydruk{background:#059669;color:#fff}
.btn-close{background:#475569;color:#fff;margin-left:auto}
@media print{.action-bar,.no-print{display:none!important}}

/* ── Karta teczki ───────────────────────────────── */
.teczka{width:210mm;min-height:297mm;background:#fff;position:relative;
        box-shadow:0 4px 32px rgba(0,0,0,.2);overflow:hidden}

/* Dekoracyjna obwódka zewnętrzna */
.outer-border{position:absolute;top:8mm;left:8mm;right:8mm;bottom:8mm;
              border:2.5pt solid <?= $cfg['color'] ?>;pointer-events:none;z-index:10}
.inner-border{position:absolute;top:10.5mm;left:10.5mm;right:10.5mm;bottom:10.5mm;
              border:.8pt solid <?= $cfg['color'] ?>;pointer-events:none;z-index:10;
              opacity:.45}

/* Ozdobne narożniki */
.corner{position:absolute;width:14mm;height:14mm;z-index:11}
.corner::before,.corner::after{content:'';position:absolute;background:<?= $cfg['color'] ?>}
.corner-tl{top:6mm;left:6mm}
.corner-tl::before{top:0;left:0;width:7mm;height:2.5pt}
.corner-tl::after{top:0;left:0;width:2.5pt;height:7mm}
.corner-tr{top:6mm;right:6mm}
.corner-tr::before{top:0;right:0;width:7mm;height:2.5pt}
.corner-tr::after{top:0;right:0;width:2.5pt;height:7mm}
.corner-bl{bottom:6mm;left:6mm}
.corner-bl::before{bottom:0;left:0;width:7mm;height:2.5pt}
.corner-bl::after{bottom:0;left:0;width:2.5pt;height:7mm}
.corner-br{bottom:6mm;right:6mm}
.corner-br::before{bottom:0;right:0;width:7mm;height:2.5pt}
.corner-br::after{bottom:0;right:0;width:2.5pt;height:7mm}

/* Zawartość */
.inner{padding:16mm 16mm 14mm;position:relative;z-index:5}

/* Nagłówek org */
.org-head{display:flex;align-items:center;gap:12pt;
          border-bottom:1.5pt solid <?= $cfg['color'] ?>;padding-bottom:10pt;margin-bottom:14pt}
.org-logo img{height:44pt;max-width:88pt}
.org-logo .initials{font-size:28pt;font-weight:700;color:<?= $cfg['color'] ?>;
                    line-height:1;letter-spacing:-2px}
.org-info .name{font-size:11.5pt;font-weight:700;color:#111;line-height:1.3}
.org-info .meta{font-size:7.5pt;color:#666;margin-top:3pt;line-height:1.7}

/* Baner typu umowy */
.type-banner{background:<?= $cfg['color'] ?>;color:#fff;text-align:center;
             padding:9pt 0;margin:0 -2pt;letter-spacing:3px;font-size:13pt;
             font-weight:700;position:relative}
.type-banner .icon{font-size:18pt;margin-right:8pt;opacity:.7}

/* Rok / identyfikator */
.year-strip{background:<?= $cfg['bg'] ?>;text-align:center;padding:5pt 0;
            font-size:22pt;font-weight:700;color:<?= $cfg['color'] ?>;
            letter-spacing:6px;border-bottom:1pt solid <?= $cfg['color'] ?>42}

/* Główna sekcja — osoba */
.person-section{text-align:center;padding:16pt 0 10pt}
.person-label{font-size:7.5pt;letter-spacing:2px;text-transform:uppercase;
              color:#888;margin-bottom:5pt}
.person-name{font-size:18pt;font-weight:700;color:#111;line-height:1.25;
             border-bottom:1.5pt solid <?= $cfg['color'] ?>;
             display:inline-block;padding-bottom:3pt;min-width:120mm}

/* Numer umowy */
.numer-section{text-align:center;margin:8pt 0}
.numer-label{font-size:7pt;letter-spacing:2px;text-transform:uppercase;color:#999}
.numer-val{font-size:12pt;font-weight:700;color:<?= $cfg['color'] ?>;
           border:1.5pt solid <?= $cfg['color'] ?>;display:inline-block;
           padding:3pt 16pt;letter-spacing:.5px;margin-top:3pt}

/* Daty */
.dates-section{text-align:center;margin:10pt 0;font-size:9.5pt}
.dates-row{display:flex;justify-content:center;align-items:center;gap:12pt;margin-top:4pt}
.date-block{text-align:center}
.date-block .lbl{font-size:7pt;text-transform:uppercase;letter-spacing:1.5px;color:#888}
.date-block .val{font-size:11.5pt;font-weight:700;color:#111;margin-top:1pt}
.dates-arrow{font-size:16pt;color:<?= $cfg['color'] ?>;opacity:.6}

/* Dodatkowe pola */
.fields-grid{display:grid;grid-template-columns:1fr 1fr;gap:6pt;
             margin:12pt 0;font-size:8.5pt}
.field-item{padding:4pt 7pt;background:<?= $cfg['bg'] ?>;
            border-left:2.5pt solid <?= $cfg['color'] ?>}
.field-item .lbl{font-size:7pt;text-transform:uppercase;letter-spacing:1px;
                 color:<?= $cfg['color'] ?>;font-weight:700;margin-bottom:1pt}
.field-item .val{color:#222;font-weight:600}

/* Ozdobny separator */
.ornament{text-align:center;color:<?= $cfg['color'] ?>;font-size:11pt;
          letter-spacing:8px;margin:8pt 0;opacity:.5}

/* Stopka */
.footer-strip{border-top:1pt solid <?= $cfg['color'] ?>42;padding-top:8pt;
              margin-top:10pt;display:flex;justify-content:space-between;
              align-items:flex-end;font-size:7pt;color:#aaa}
.footer-strip .seal-area{border:1.2pt dashed #ccc;width:64pt;height:50pt;
                          display:flex;align-items:center;justify-content:center;
                          font-size:6.5pt;color:#ccc;text-align:center;line-height:1.4}

/* Boczny pasek dekoru */
.side-bar{position:absolute;left:0;top:0;bottom:0;width:5mm;background:<?= $cfg['color'] ?>;opacity:.12}
.side-bar-r{position:absolute;right:0;top:0;bottom:0;width:5mm;background:<?= $cfg['color'] ?>;opacity:.12}

@media print{
  body{background:none;padding:0;display:block}
  .teczka{box-shadow:none;width:100%;min-height:100vh}
  @page{size:A4;margin:0}
}
</style>
</head>
<body>

<!-- ── Pasek akcji ────────────────────────────────────────────── -->
<div class="action-bar no-print">
  <button class="btn-print" onclick="window.print()">⎙ Drukuj teczkę</button>
  <?php if ($id > 0): ?>
  <a class="btn-wydruk"
     href="<?= APP_URL ?>/contracts/print.php?type=<?= h($type) ?>&id=<?= $id ?>"
     target="_blank">📄 Wydruk umowy</a>
  <?php endif; ?>
  <span style="color:#94a3b8"><?= h($cfg['label']) ?><?= $osoba ? ' · ' . h($osoba) : '' ?></span>
  <a class="btn-close" href="javascript:window.close()" style="text-decoration:none">✕ Zamknij</a>
</div>

<!-- ══ KARTA TECZKI ══════════════════════════════════════════════ -->
<div class="teczka">

  <!-- Dekoracje -->
  <div class="side-bar"></div>
  <div class="side-bar-r"></div>
  <div class="outer-border"></div>
  <div class="inner-border"></div>
  <div class="corner corner-tl"></div>
  <div class="corner corner-tr"></div>
  <div class="corner corner-bl"></div>
  <div class="corner corner-br"></div>

  <div class="inner">

    <!-- Nagłówek organizacji -->
    <div class="org-head">
      <div class="org-logo">
        <?php if ($logo_url): ?>
        <img src="<?= h($logo_url) ?>" alt="">
        <?php else: ?>
        <span class="initials"><?= h(mb_strtoupper(mb_substr($org_name, 0, 2))) ?></span>
        <?php endif; ?>
      </div>
      <div class="org-info">
        <div class="name"><?= h($org_name) ?></div>
        <div class="meta">
          <?php if ($org_krs): ?> KRS <?= h($org_krs) ?><?php endif; ?>
          <?php if ($org_nip): ?> · NIP <?= h($org_nip) ?><?php endif; ?>
          <?php if ($org_adres || $org_miasto): ?><br><?= h($org_adres) ?><?= $org_miasto ? ' · ' . h($org_miasto) : '' ?><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Baner typu -->
    <div class="type-banner">
      <span class="icon"><?= $cfg['icon'] ?></span>
      TECZKA — <?= h($cfg['label']) ?>
      <span class="icon"><?= $cfg['icon'] ?></span>
    </div>

    <!-- Rok -->
    <div class="year-strip"><?= h($rok_od) ?></div>

    <!-- Osoba -->
    <div class="person-section">
      <div class="person-label">Imię i nazwisko / Nazwa</div>
      <div class="person-name"><?= $osoba ? h($osoba) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' ?></div>
    </div>

    <!-- Numer umowy -->
    <div class="numer-section">
      <div class="numer-label">Numer dokumentu</div>
      <div class="numer-val"><?= $numer ? h($numer) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' ?></div>
    </div>

    <!-- Ozdobnik -->
    <div class="ornament">· · · ✦ · · ·</div>

    <!-- Daty -->
    <div class="dates-section">
      <div class="dates-row">
        <div class="date-block">
          <div class="lbl">Data zawarcia</div>
          <div class="val"><?= $data_od ? h(tpl_date($data_od)) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' ?></div>
        </div>
        <div class="dates-arrow">→</div>
        <div class="date-block">
          <div class="lbl">Data zakończenia</div>
          <div class="val"><?= $bezterm ? 'bezterminowo' : ($data_do ? h(tpl_date($data_do)) : '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;') ?></div>
        </div>
      </div>
    </div>

    <!-- Dodatkowe pola -->
    <?php if ($projekt || $opiekun || $status || $org_krs): ?>
    <div class="fields-grid">
      <?php if ($projekt): ?><div class="field-item"><div class="lbl">Projekt / Program</div><div class="val"><?= h($projekt) ?></div></div><?php endif; ?>
      <?php if ($opiekun): ?><div class="field-item"><div class="lbl">Opiekun umowy</div><div class="val"><?= h($opiekun) ?></div></div><?php endif; ?>
      <?php if ($status):  ?><div class="field-item"><div class="lbl">Status</div><div class="val"><?= h($status) ?></div></div><?php endif; ?>
      <?php if ($org_nip): ?><div class="field-item"><div class="lbl">NIP organizacji</div><div class="val"><?= h($org_nip) ?></div></div><?php endif; ?>
    </div>
    <?php else: ?>
    <div style="margin:14pt 0;height:28pt;border-bottom:1pt dashed #ddd;
         border-top:1pt dashed #ddd;display:flex;align-items:center;
         justify-content:center;font-size:8pt;color:#ccc;letter-spacing:1px">
      DODATKOWE INFORMACJE
    </div>
    <?php endif; ?>

    <!-- Ozdobnik -->
    <div class="ornament" style="margin-top:14pt">· · · ✦ · · ·</div>

    <!-- Stopka -->
    <div class="footer-strip">
      <div>
        <div style="font-size:8pt;color:#888;margin-bottom:3pt">Wygenerowano <?= date('d.m.Y') ?></div>
        <div style="color:#bbb">Rejestr Umów · <?= h($org_name) ?></div>
      </div>
      <div class="seal-area">Pieczęć<br>organizacji</div>
    </div>

  </div><!-- /inner -->
</div><!-- /teczka -->

</body>
</html>
