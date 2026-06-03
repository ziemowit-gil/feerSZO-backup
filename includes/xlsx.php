<?php
/**
 * includes/xlsx.php — Minimalna implementacja XLSX writer (ZipArchive, brak Composera).
 *
 * Użycie:
 *   $x = new XlsxWriter();
 *   $x->addSheet('Kontakty');
 *   $x->writeRow(['ID','Imię','E-mail'], ['header']);
 *   $x->writeRow([1,'Jan Kowalski','jan@example.com']);
 *   $x->output('kontakty.xlsx'); // wysyła do przeglądarki
 */
class XlsxWriter {
    private array $sheets   = [];
    private int   $current  = -1;
    private array $strings  = [];  // shared strings
    private array $str_idx  = [];

    public function addSheet(string $name = 'Arkusz1'): void {
        $this->sheets[] = ['name' => $name, 'rows' => []];
        $this->current  = count($this->sheets) - 1;
    }

    /** Dodaj wiersz. $styles: ['header'] lub [] */
    public function writeRow(array $cells, array $styles = []): void {
        if ($this->current < 0) $this->addSheet();
        $is_header = in_array('header', $styles);
        $row = [];
        foreach ($cells as $c) {
            if ($c === null || $c === '') {
                $row[] = ['t'=>'s','v'=>'','h'=>$is_header];
            } elseif (is_int($c) || (is_string($c) && ctype_digit($c))) {
                $row[] = ['t'=>'n','v'=>(int)$c,'h'=>$is_header];
            } elseif (is_float($c) || (is_string($c) && is_numeric($c) && str_contains($c,'.'))) {
                $row[] = ['t'=>'n','v'=>(float)$c,'h'=>$is_header];
            } else {
                $s = (string)$c;
                if (!isset($this->str_idx[$s])) {
                    $this->str_idx[$s] = count($this->strings);
                    $this->strings[] = $s;
                }
                $row[] = ['t'=>'s','v'=>$this->str_idx[$s],'h'=>$is_header];
            }
        }
        $this->sheets[$this->current]['rows'][] = $row;
    }

    /** Wyślij plik do przeglądarki */
    public function output(string $filename): void {
        $content = $this->build();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Cache-Control: no-cache');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    /** Zapisz do pliku */
    public function save(string $path): bool {
        return file_put_contents($path, $this->build()) !== false;
    }

    private function build(): string {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        // [Content_Types].xml
        $ct_sheets = '';
        foreach ($this->sheets as $i => $_) {
            $si = $i + 1;
            $ct_sheets .= "<Override PartName=\"/xl/worksheets/sheet{$si}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml"  ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml"      ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
<Override PartName="/xl/styles.xml"        ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
' . $ct_sheets . '
</Types>');

        // _rels/.rels
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

        // xl/_rels/workbook.xml.rels
        $wb_rels = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId0" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($this->sheets as $i => $_) {
            $si = $i + 1;
            $ri = $si + 1;
            $wb_rels .= "<Relationship Id=\"rId{$ri}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$si}.xml\"/>";
        }
        $wb_rels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);

        // xl/workbook.xml
        $wb_sheets = '';
        foreach ($this->sheets as $i => $sh) {
            $si = $i + 1; $ri = $si + 1;
            $wb_sheets .= "<sheet name=\"" . htmlspecialchars($sh['name'], ENT_XML1) . "\" sheetId=\"{$si}\" r:id=\"rId{$ri}\"/>";
        }
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets>' . $wb_sheets . '</sheets>
</workbook>');

        // xl/styles.xml — dwa style: 0=normalny, 1=header (pogrubiony)
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="2">
  <font><sz val="11"/><name val="Calibri"/></font>
  <font><b/><sz val="11"/><name val="Calibri"/></font>
</fonts>
<fills count="2">
  <fill><patternFill patternType="none"/></fill>
  <fill><patternFill patternType="gray125"/></fill>
</fills>
<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="2">
  <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
  <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>
</cellXfs>
</styleSheet>');

        // xl/sharedStrings.xml
        $ss = '<?xml version="1.0" encoding="UTF-8"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($this->strings) . '" uniqueCount="' . count($this->strings) . '">';
        foreach ($this->strings as $s) {
            $ss .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
        }
        $ss .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ss);

        // xl/worksheets/sheet*.xml
        foreach ($this->sheets as $i => $sheet) {
            $ws  = '<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetData>';
            foreach ($sheet['rows'] as $ri => $row) {
                $rn  = $ri + 1;
                $ws .= "<row r=\"{$rn}\">";
                foreach ($row as $ci => $cell) {
                    $col  = $this->colName($ci + 1);
                    $addr = $col . $rn;
                    $sty  = $cell['h'] ? ' s="1"' : '';
                    if ($cell['t'] === 'n') {
                        $ws .= "<c r=\"{$addr}\"{$sty}><v>{$cell['v']}</v></c>";
                    } else {
                        $ws .= "<c r=\"{$addr}\" t=\"s\"{$sty}><v>{$cell['v']}</v></c>";
                    }
                }
                $ws .= '</row>';
            }
            $ws .= '</sheetData></worksheet>';
            $zip->addFromString("xl/worksheets/sheet" . ($i + 1) . ".xml", $ws);
        }

        $zip->close();
        $data = file_get_contents($tmp);
        unlink($tmp);
        return $data;
    }

    private function colName(int $n): string {
        $r = '';
        while ($n > 0) {
            $n--; $r = chr(65 + ($n % 26)) . $r; $n = (int)($n / 26);
        }
        return $r;
    }
}
