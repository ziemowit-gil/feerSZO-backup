<?php
/**
 * export_insurance.php — Eksport XLS na potrzeby ubezpieczenia.
 *
 * Generuje plik Excel (SpreadsheetML) z listą osób z umów
 * krótszych niż 30 dni: PESEL, Imię, Nazwisko, dane umowy.
 *
 * Parametry GET:
 *   type      = wolontariat|zlecenie|dzielo|praca|inne|all (domyślnie: all)
 *   date_from = YYYY-MM-DD (domyślnie: bieżący miesiąc)
 *   date_to   = YYYY-MM-DD
 *   max_days  = liczba dni (domyślnie: 30)
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
if (!can_edit()) {
    http_response_code(403);
    die('Brak dostępu.');
}

$type     = preg_replace('/[^a-z]/', '', $_GET['type'] ?? 'all');
$max_days = max(1, (int)($_GET['max_days'] ?? 30));
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-t');

// ── Źródła danych per typ ─────────────────────────────────────────────────────
// Każda tabela: kolumna startowa, końcowa, czy ma pesel, czy split imię/nazwisko
$sources = [
    'wolontariat' => [
        'table'      => 'umowy_wolontariat',
        'label'      => 'Wolontariat',
        'col_start'  => 'data_rozpoczecia',
        'col_end'    => 'data_zakonczenia',
        'col_person' => 'imie_nazwisko',
        'col_pesel'  => 'pesel',
    ],
    'zlecenie' => [
        'table'      => 'umowy_zlecenie',
        'label'      => 'Zlecenie',
        'col_start'  => 'data_rozpoczecia',
        'col_end'    => 'data_zakonczenia',
        'col_person' => 'imie_nazwisko',
        'col_pesel'  => 'pesel',
    ],
    'dzielo' => [
        'table'      => 'umowy_dzielo',
        'label'      => 'Dzieło',
        'col_start'  => 'data_zawarcia',
        'col_end'    => 'termin_oddania',
        'col_person' => 'imie_nazwisko',
        'col_pesel'  => 'pesel',
    ],
    'praca' => [
        'table'      => 'umowy_praca',
        'label'      => 'Praca',
        'col_start'  => 'data_rozpoczecia',
        'col_end'    => 'data_zakonczenia',
        'col_person' => 'imie_nazwisko',
        'col_pesel'  => 'pesel',
    ],
    'inne' => [
        'table'      => 'umowy_inne',
        'label'      => 'Inne',
        'col_start'  => 'data_zawarcia',
        'col_end'    => 'data_zakonczenia',
        'col_person' => 'imie_nazwisko',
        'col_pesel'  => 'pesel',
    ],
];

if ($type !== 'all' && isset($sources[$type])) {
    $sources = [$type => $sources[$type]];
}

// ── Pobierz rekordy ───────────────────────────────────────────────────────────
$rows = [];
foreach ($sources as $slug => $src) {
    $table = $src['table'];
    $cs    = $src['col_start'];
    $ce    = $src['col_end'];
    $cp    = $src['col_person'];
    $cpsel = $src['col_pesel'];

    try {
        $results = db_all(
            "SELECT
                id,
                numer_umowy,
                {$cp}    AS imie_nazwisko,
                {$cpsel} AS pesel,
                {$cs}    AS data_start,
                {$ce}    AS data_end,
                status,
                '{$slug}' AS typ
             FROM {$table}
             WHERE {$cpsel} IS NOT NULL AND {$cpsel} != ''
               AND {$cs} IS NOT NULL AND {$cs} != ''
               AND {$ce} IS NOT NULL AND {$ce} != ''
               AND {$cs} <= ?
               AND {$ce} >= ?
               AND status NOT IN ('anulowana','rozwiązana')
               AND (
                 CAST(julianday({$ce}) - julianday({$cs}) AS INTEGER) < ?
               )
             ORDER BY {$cs}, {$cp}",
            [$date_to, $date_from, $max_days]
        );
    } catch (\Throwable $e) {
        continue;
    }

    foreach ($results as $r) {
        // Oblicz rzeczywistą długość umowy
        $days = 0;
        if ($r['data_start'] && $r['data_end']) {
            $days = max(0, (int)round(
                (strtotime($r['data_end']) - strtotime($r['data_start'])) / 86400
            ));
        }
        if ($days >= $max_days) continue;

        // Rozdziel imię i nazwisko
        $parts    = explode(' ', trim($r['imie_nazwisko'] ?? ''), 2);
        $imie     = $parts[0] ?? '';
        $nazwisko = $parts[1] ?? '';

        $rows[] = [
            'typ'        => $src['label'],
            'numer'      => $r['numer_umowy'] ?? '',
            'pesel'      => $r['pesel'] ?? '',
            'imie'       => $imie,
            'nazwisko'   => $nazwisko,
            'data_start' => $r['data_start'] ?? '',
            'data_end'   => $r['data_end']   ?? '',
            'dni'        => $days,
            'status'     => $r['status'] ?? '',
            'id'         => $r['id'],
        ];
    }
}

$org        = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$filename   = 'ubezpieczenie_' . date('Ymd') . '_do' . $max_days . 'dni.xls';
$gen_date   = date('d.m.Y H:i');

// ── SpreadsheetML XML ─────────────────────────────────────────────────────────
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:o="urn:schemas-microsoft-com:office:office"
          xmlns:x="urn:schemas-microsoft-com:office:excel"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:html="http://www.w3.org/TR/REC-html40">

<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>Wykaz do ubezpieczenia — <?= htmlspecialchars($org) ?></Title>
  <Author><?= htmlspecialchars($org) ?></Author>
  <Created><?= date('Y-m-d') ?>T00:00:00Z</Created>
</DocumentProperties>

<Styles>
  <Style ss:ID="Default" ss:Name="Normal">
    <Alignment ss:Vertical="Center"/>
    <Font ss:FontName="Calibri" ss:Size="11"/>
  </Style>
  <Style ss:ID="title">
    <Font ss:FontName="Calibri" ss:Size="14" ss:Bold="1" ss:Color="#1E293B"/>
    <Alignment ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="meta">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Color="#64748B"/>
    <Alignment ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="hdr">
    <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
    <Interior ss:Color="#1E6DFF" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center" ss:WrapText="1"/>
    <Borders>
      <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#1554CC"/>
    </Borders>
  </Style>
  <Style ss:ID="pesel">
    <Font ss:FontName="Courier New" ss:Size="11"/>
    <Alignment ss:Vertical="Center" ss:Horizontal="Left"/>
    <NumberFormat ss:Format="@"/>
  </Style>
  <Style ss:ID="data">
    <Font ss:FontName="Calibri" ss:Size="11"/>
    <Alignment ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="warn">
    <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#DC2626"/>
    <Alignment ss:Vertical="Center" ss:Horizontal="Center"/>
  </Style>
  <Style ss:ID="even">
    <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center"/>
    <Font ss:FontName="Calibri" ss:Size="11"/>
  </Style>
  <Style ss:ID="odd">
    <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
    <Alignment ss:Vertical="Center"/>
    <Font ss:FontName="Calibri" ss:Size="11"/>
  </Style>
  <Style ss:ID="total">
    <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/>
    <Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/>
    <Borders>
      <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#BFDBFE"/>
    </Borders>
    <Alignment ss:Vertical="Center"/>
  </Style>
</Styles>

<Worksheet ss:Name="Wykaz do ubezpieczenia">
<Table ss:DefaultRowHeight="18">

  <!-- Szerokości kolumn -->
  <Column ss:Width="90"/>  <!-- Typ -->
  <Column ss:Width="120"/> <!-- Numer umowy -->
  <Column ss:Width="115"/> <!-- PESEL -->
  <Column ss:Width="110"/> <!-- Imię -->
  <Column ss:Width="130"/> <!-- Nazwisko -->
  <Column ss:Width="90"/>  <!-- Data od -->
  <Column ss:Width="90"/>  <!-- Data do -->
  <Column ss:Width="60"/>  <!-- Dni -->
  <Column ss:Width="90"/>  <!-- Status -->

  <!-- Nagłówek dokumentu -->
  <Row ss:Height="28">
    <Cell ss:StyleID="title" ss:MergeAcross="8">
      <Data ss:Type="String">Wykaz osób do ubezpieczenia — <?= htmlspecialchars($org) ?></Data>
    </Cell>
  </Row>
  <Row ss:Height="18">
    <Cell ss:StyleID="meta" ss:MergeAcross="8">
      <Data ss:Type="String">Umowy krótsze niż <?= $max_days ?> dni | Zakres dat: <?= $date_from ?> – <?= $date_to ?> | Wygenerowano: <?= $gen_date ?></Data>
    </Cell>
  </Row>
  <Row ss:Height="6"/>

  <!-- Nagłówki kolumn -->
  <Row ss:Height="24">
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Typ umowy</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Numer umowy</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">PESEL</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Imię</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Nazwisko</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Data od</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Data do</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Dni</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Status</Data></Cell>
  </Row>

  <!-- Dane -->
  <?php if (empty($rows)): ?>
  <Row ss:Height="22">
    <Cell ss:StyleID="meta" ss:MergeAcross="8">
      <Data ss:Type="String">Brak danych spełniających kryteria.</Data>
    </Cell>
  </Row>
  <?php else: ?>
  <?php foreach ($rows as $i => $r):
    $style = ($i % 2 === 0) ? 'even' : 'odd';
    $ds    = $style; // bazowy styl wiersza
  ?>
  <Row ss:Height="20">
    <Cell ss:StyleID="<?= $ds ?>"><Data ss:Type="String"><?= htmlspecialchars($r['typ']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $ds ?>"><Data ss:Type="String"><?= htmlspecialchars($r['numer']) ?></Data></Cell>
    <Cell ss:StyleID="pesel"><Data ss:Type="String"><?= htmlspecialchars($r['pesel']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $ds ?>"><Data ss:Type="String"><?= htmlspecialchars($r['imie']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $ds ?>"><Data ss:Type="String"><?= htmlspecialchars($r['nazwisko']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $ds ?>"><Data ss:Type="String"><?= htmlspecialchars($r['data_start']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $ds ?>"><Data ss:Type="String"><?= htmlspecialchars($r['data_end']) ?></Data></Cell>
    <Cell ss:StyleID="<?= $r['dni'] <= 7 ? 'warn' : $ds ?>"><Data ss:Type="Number"><?= $r['dni'] ?></Data></Cell>
    <Cell ss:StyleID="<?= $ds ?>"><Data ss:Type="String"><?= htmlspecialchars($r['status']) ?></Data></Cell>
  </Row>
  <?php endforeach; ?>

  <!-- Podsumowanie -->
  <Row ss:Height="6"/>
  <Row ss:Height="20">
    <Cell ss:StyleID="total" ss:MergeAcross="1">
      <Data ss:Type="String">Łącznie osób:</Data>
    </Cell>
    <Cell ss:StyleID="total">
      <Data ss:Type="Number"><?= count($rows) ?></Data>
    </Cell>
    <Cell ss:StyleID="total" ss:MergeAcross="5"/>
  </Row>
  <?php endif; ?>

</Table>

<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
  <PageSetup>
    <Layout x:Orientation="Landscape"/>
    <PageMargins x:Bottom="0.75" x:Left="0.7" x:Right="0.7" x:Top="0.75"/>
  </PageSetup>
  <Print>
    <FitWidth>1</FitWidth>
    <ValidPrinterInfo/>
    <PaperSizeIndex>9</PaperSizeIndex><!-- A4 -->
    <HorizontalResolution>600</HorizontalResolution>
    <VerticalResolution>600</VerticalResolution>
  </Print>
  <Selected/>
  <FreezePanes/>
  <FrozenNoSplit/>
  <SplitHorizontal>4</SplitHorizontal>
  <TopRowBottomPane>4</TopRowBottomPane>
  <ActivePane>2</ActivePane>
  <Panes>
    <Pane>
      <Number>2</Number>
      <ActiveRow>4</ActiveRow>
    </Pane>
  </Panes>
  <ProtectObjects>False</ProtectObjects>
  <ProtectScenarios>False</ProtectScenarios>
</WorksheetOptions>
</Worksheet>
</Workbook>
<?php
