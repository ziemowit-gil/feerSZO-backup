<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

$type   = preg_replace('/[^a-z]/', '', $_GET['type'] ?? 'all');
$format = $_GET['format'] === 'csv' ? 'csv' : 'print';

// Filtry GET (opcjonalne)
$status    = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';

// Definicje kolumn per typ
$col_defs = [
    'zlecenie' => [
        'Numer umowy'       => 'numer_umowy',
        'Status'            => 'status',
        'Zleceniobiorca'    => 'imie_nazwisko',
        'PESEL'             => 'pesel',
        'Przedmiot'         => 'przedmiot_zlecenia',
        'Data zawarcia'     => 'data_zawarcia',
        'Data rozpoczęcia'  => 'data_rozpoczecia',
        'Data zakończenia'  => 'data_zakonczenia',
        'Wynagrodzenie brutto' => 'wynagrodzenie_brutto',
        'Typ stawki'        => 'typ_stawki',
        'Opiekun'           => 'opiekun',
        'Numer projektu'    => 'numer_projektu',
        'Forma podpisania'  => 'forma_podpisania',
        'Konto M365'        => 'm365_login',
    ],
    'uslugi' => [
        'Numer umowy'       => 'numer_umowy',
        'Status'            => 'status',
        'Wykonawca'         => 'nazwa_wykonawcy',
        'NIP/PESEL'         => 'nip_pesel',
        'Przedmiot usługi'  => 'przedmiot_uslugi',
        'Data zawarcia'     => 'data_zawarcia',
        'Data zakończenia'  => 'data_zakonczenia',
        'Wartość netto'     => 'wartosc_netto',
        'Wartość brutto'    => 'wartosc_brutto',
        'Waluta'            => 'waluta',
        'Opiekun'           => 'opiekun',
        'Numer projektu'    => 'numer_projektu',
    ],
    'wolontariat' => [
        'Numer umowy'       => 'numer_umowy',
        'Status'            => 'status',
        'Wolontariusz'      => 'imie_nazwisko',
        'PESEL'             => 'pesel',
        'Email'             => 'email',
        'Telefon'           => 'telefon',
        'Przedmiot'         => 'przedmiot_porozumienia',
        'Miejsce'           => 'miejsce_wolontariatu',
        'Data zawarcia'     => 'data_zawarcia',
        'Data zakończenia'  => 'data_zakonczenia',
        'Godzin/tydz.'      => 'godzin_tygodniowo',
        'Opiekun'           => 'opiekun',
        'Projekt'           => 'projekt_program',
        'Konto M365'        => 'm365_login',
    ],
    'dzielo' => [
        'Numer umowy'       => 'numer_umowy',
        'Status'            => 'status',
        'Wykonawca'         => 'imie_nazwisko',
        'PESEL'             => 'pesel',
        'Opis dzieła'       => 'opis_dziela',
        'Data zawarcia'     => 'data_zawarcia',
        'Termin oddania'    => 'termin_oddania',
        'Wynagrodzenie brutto' => 'wynagrodzenie_brutto',
        'Opiekun'           => 'opiekun',
        'Numer projektu'    => 'numer_projektu',
        'Konto M365'        => 'm365_login',
    ],
    'praca' => [
        'Numer umowy'       => 'numer_umowy',
        'Status'            => 'status',
        'Pracownik'         => 'imie_nazwisko',
        'PESEL'             => 'pesel',
        'Stanowisko'        => 'stanowisko',
        'Dział/Projekt'     => 'dzial_projekt',
        'Wymiar etatu'      => 'wymiar_etatu',
        'Rodzaj umowy'      => 'rodzaj_umowy',
        'Data rozpoczęcia'  => 'data_rozpoczecia',
        'Data zakończenia'  => 'data_zakonczenia',
        'Wynagrodzenie brutto' => 'wynagrodzenie_brutto',
        'Opiekun'           => 'opiekun_przelozony',
    ],
    'inne' => [
        'Numer umowy'       => 'numer_umowy',
        'Status'            => 'status',
        'Typ umowy'         => 'typ_umowy',
        'Strona umowy'      => 'strona_umowy',
        'Przedmiot'         => 'przedmiot_umowy',
        'Data zawarcia'     => 'data_zawarcia',
        'Data zakończenia'  => 'data_zakonczenia',
        'Wartość'           => 'wartosc_umowy',
        'Waluta'            => 'waluta',
        'Opiekun'           => 'opiekun',
    ],
];

function fetch_rows(string $slug, array $cols, string $status, string $df, string $dt): array {
    $table = table_for_type($slug);
    $db_cols = array_values($cols);
    // Pobierz tylko kolumny z tabeli (niektóre jak m365_login mogą nie istnieć)
    try {
        $select = implode(', ', array_map(fn($c) => "`{$c}`", $db_cols));
        $w = '1=1'; $p = [];
        if ($status) { $w .= ' AND status = ?'; $p[] = $status; }
        if ($df)     { $w .= ' AND data_zawarcia >= ?'; $p[] = $df; }
        if ($dt)     { $w .= ' AND data_zawarcia <= ?'; $p[] = $dt; }
        return db_all("SELECT {$select} FROM {$table} WHERE {$w} ORDER BY data_zawarcia DESC, numer_umowy", $p);
    } catch (PDOException $e) {
        // Fallback bez kolumn m365
        $db_cols2 = array_filter($db_cols, fn($c) => !str_starts_with($c, 'm365'));
        $select2 = implode(', ', array_map(fn($c) => "`{$c}`", $db_cols2));
        $w = '1=1'; $p = [];
        if ($status) { $w .= ' AND status = ?'; $p[] = $status; }
        if ($df)     { $w .= ' AND data_zawarcia >= ?'; $p[] = $df; }
        if ($dt)     { $w .= ' AND data_zawarcia <= ?'; $p[] = $dt; }
        return db_all("SELECT {$select2} FROM {$table} WHERE {$w} ORDER BY data_zawarcia DESC, numer_umowy", $p);
    }
}

// Zbierz dane
$datasets = [];
if ($type === 'all') {
    foreach ($col_defs as $slug => $cols) {
        $rows = fetch_rows($slug, $cols, $status, $date_from, $date_to);
        if ($rows) $datasets[$slug] = ['label' => CONTRACT_TYPES[$slug], 'cols' => $cols, 'rows' => $rows];
    }
} else {
    $cols = $col_defs[$type] ?? [];
    $rows = fetch_rows($type, $cols, $status, $date_from, $date_to);
    $datasets[$type] = ['label' => CONTRACT_TYPES[$type] ?? $type, 'cols' => $cols, 'rows' => $rows];
}

// ── CSV ──────────────────────────────────────────────────────────────────────
if ($format === 'csv') {
    $filename = 'zestawienie_' . ($type === 'all' ? 'wszystkie' : $type) . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM dla Excela
    fputs($out, "\xEF\xBB\xBF");

    foreach ($datasets as $slug => $ds) {
        if (count($datasets) > 1) {
            // Separator między typami
            fputcsv($out, ['=== ' . $ds['label'] . ' ==='], ';');
        }
        fputcsv($out, array_keys($ds['cols']), ';');
        foreach ($ds['rows'] as $row) {
            $line = [];
            foreach ($ds['cols'] as $header => $col) {
                $val = $row[$col] ?? '';
                // Formatowanie
                if (in_array($col, ['data_zawarcia','data_rozpoczecia','data_zakonczenia','termin_oddania'], true)) {
                    $val = $val ? date('d.m.Y', strtotime($val)) : '';
                } elseif (in_array($col, ['wynagrodzenie_brutto','wartosc_netto','wartosc_brutto','wartosc_umowy'], true)) {
                    $val = $val !== '' && $val !== null ? number_format((float)$val, 2, ',', ' ') : '';
                }
                $line[] = $val;
            }
            fputcsv($out, $line, ';');
        }
        fputcsv($out, [], ';'); // pusta linia między sekcjami
    }
    fclose($out);
    exit;
}

// ── HTML PRINT ───────────────────────────────────────────────────────────────
$org = defined('ORG_NAME') ? ORG_NAME : '';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Zestawienie umów — <?= h($org) ?></title>
<style>
  body{font-family:Arial,sans-serif;font-size:10pt;margin:20px}
  h1{font-size:14pt;margin:0 0 4px}
  .meta{font-size:9pt;color:#555;margin-bottom:16px}
  h2{font-size:11pt;border-bottom:2px solid #0d6efd;padding-bottom:3px;margin:20px 0 8px}
  table{width:100%;border-collapse:collapse;font-size:8.5pt;margin-bottom:20px}
  th{background:#0d6efd;color:#fff;padding:4px 6px;text-align:left;white-space:nowrap}
  td{padding:3px 6px;border-bottom:1px solid #e0e0e0;vertical-align:top}
  tr:nth-child(even) td{background:#f8f9ff}
  .badge{padding:2px 6px;border-radius:3px;font-size:8pt;white-space:nowrap}
  @media print{
    .no-print{display:none}
    @page{margin:1.5cm;size:A4 landscape}
    h2{break-before:auto}
  }
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px">
  <button onclick="window.print()" style="padding:4px 18px;background:red;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11pt">
     Drukuj / Zapisz jako PDF
  </button>
  <a href="index.php" style="margin-left:10px;font-size:10pt">← Powrót</a>
</div>

<h1><?= h($org) ?> — Zestawienie umów</h1>
<div class="meta">
  Wygenerowano: <?= date('d.m.Y H:i') ?>
  <?php if ($status) echo ' | Status: ' . h($status); ?>
  <?php if ($date_from || $date_to) echo ' | Okres: ' . h($date_from ?: '—') . ' – ' . h($date_to ?: '—'); ?>
</div>

<?php foreach ($datasets as $slug => $ds):
  $total = count($ds['rows']);
  if (!$total) continue;
?>
<h2><?= h($ds['label']) ?> (<?= $total ?>)</h2>
<table>
  <thead>
    <tr><?php foreach ($ds['cols'] as $header => $col) echo '<th>' . h($header) . '</th>'; ?></tr>
  </thead>
  <tbody>
  <?php foreach ($ds['rows'] as $row): ?>
  <tr>
    <?php foreach ($ds['cols'] as $header => $col):
      $val = $row[$col] ?? '';
      if (in_array($col, ['data_zawarcia','data_rozpoczecia','data_zakonczenia','termin_oddania'], true)) {
          $val = $val ? date('d.m.Y', strtotime($val)) : '—';
      } elseif (in_array($col, ['wynagrodzenie_brutto','wartosc_netto','wartosc_brutto','wartosc_umowy'], true)) {
          $val = $val !== '' && $val !== null ? number_format((float)$val, 2, ',', ' ') . ' PLN' : '—';
      } elseif ($col === 'status') {
          $s = STATUS_LABELS[$val] ?? ['label'=>$val,'class'=>'secondary'];
          $val = '<span class="badge" style="background:' . badge_color($s['class']) . ';color:#fff">' . h($s['label']) . '</span>';
      } else {
          $val = htmlspecialchars((string)($val ?: '—'));
      }
    ?>
    <td><?= $val ?></td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; ?>

<script>
// Auto-print jeśli nie wróciłeś przez przycisk
if (location.hash !== '#noPrint') window.onload = () => window.print();
</script>
</body>
</html>
<?php
function badge_color(string $cls): string {
    return match($cls) {
        'success' => '#198754','primary' => '#0d6efd','info' => '#0dcaf0',
        'warning' => '#ffc107','danger' => '#dc3545', default => '#6c757d',
    };
}
exit;
