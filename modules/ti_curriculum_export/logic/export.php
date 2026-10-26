<?php
/**
 * modules/ti_curriculum_export/logic/export.php — eksport planu nauczania kursu (TI)
 * do Worda (DOCX) i Excela (XLSX). Używany przez panel dydaktyka i panel kursanta.
 *
 * Dostępność (czytniki ekranu): tabela ma wiersz nagłówkowy (w Wordzie powtarzany na
 * każdej stronie), dział jest osobną kolumną (nie wierszem scalonym), a status to tekst.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

/** Wiersze planu kursu + status realizacji (z powiązanych lekcji). */
function ti_curr_export_rows(int $course_id, bool $only_active): array {
    $items = k30_ti_curriculum_list($course_id, $only_active);
    $cov = [];
    foreach (db_all(
        "SELECT sc.curriculum_id AS cid, s.status AS st
         FROM k30_ti_session_curriculum sc JOIN k30_ti_sessions s ON s.id=sc.session_id
         WHERE s.course_id=?", [$course_id]) as $r) {
        $k = (int)$r['cid'];
        $rank = $r['st'] === 'held' ? 2 : ($r['st'] === 'planned' ? 1 : 0);
        if (!isset($cov[$k]) || $rank > $cov[$k]) $cov[$k] = $rank;
    }
    $labels = [2 => 'Zrealizowane', 1 => 'Zaplanowane', 0 => 'W planie'];
    $rows = [];
    $n = 0;
    foreach ($items as $it) {
        $n++;
        $rows[] = [
            'lp'      => $n,
            'section' => (string)$it['section'],
            'title'   => (string)$it['title'],
            'desc'    => trim((string)$it['description']),
            'minutes' => (int)$it['est_minutes'],
            'status'  => $labels[$cov[(int)$it['id']] ?? 0],
            'active'  => (int)$it['is_active'] === 1,
        ];
    }
    return $rows;
}

function ti_curr_export_filename(string $course_name, string $ext): string {
    $slug = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $course_name) ?: 'kurs');
    return 'plan_nauczania_' . trim($slug, '_') . '_' . date('Ymd') . '.' . $ext;
}

/** @param bool $show_active dodatkowa kolumna „Widoczna dla kursanta” (widok dydaktyka) */
function ti_curr_export_docx(string $course_name, array $rows, bool $show_active, string $author): void {
    require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';
    error_reporting(error_reporting() & ~E_DEPRECATED); // PhpWord na PHP 8.x sypie ostrzeżeniami, a one psują ZIP
    $pw = new \PhpOffice\PhpWord\PhpWord();
    $pw->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
    $pw->getDocInfo()->setTitle('Plan nauczania — ' . $course_name);
    $pw->addTitleStyle(1, ['bold' => true, 'size' => 16]);
    $sec = $pw->addSection(['orientation' => 'landscape']);
    $footer = $sec->addFooter();
    $footer->addText('Wygenerowano: ' . date('d.m.Y H:i') . ($author !== '' ? ' przez ' . $author : ''), ['size' => 8]);
    // Stopka z kodami Code128 + QR (rejestr dokumentów) — pliki tymczasowe z PNG usuwane po zapisie; błąd kodów nie blokuje DOCX
    $tmp_imgs = [];
    try {
        require_once dirname(dirname(dirname(__DIR__))) . '/modules/doc_registry/lib/DocRegistry.php';
        if ($fc = DocRegistry::printCodes('ti_plan_nauczania', md5($course_name) . '|' . (int)$show_active, 'Plan nauczania — ' . $course_name)) {
            $tmp_png = function (?string $png) use (&$tmp_imgs) {
                if (!$png || !($p = tempnam(sys_get_temp_dir(), 'dcimg')) || file_put_contents($p, $png) === false) return null;
                return $tmp_imgs[] = $p;
            };
            $run = $footer->addTextRun(['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            if ($p = $tmp_png($fc['bar'])) $run->addImage($p, ['width' => 130, 'height' => 17, 'wrappingStyle' => 'inline']);
            if ($p = $tmp_png($fc['qr'])) $run->addImage($p, ['width' => 26, 'height' => 26, 'wrappingStyle' => 'inline']);
            $footer->addText($fc['code'] . ' · ' . $fc['doc_number'] . ' · weryfikacja: ' . $fc['url'], ['size' => 6.5, 'name' => 'Consolas'], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
        }
    } catch (\Throwable $e) { error_log('[docx kody w stopce] ' . $e->getMessage()); }
    $sec->addTitle('Plan nauczania — ' . $course_name, 1);

    $total = array_sum(array_column($rows, 'minutes'));
    $done  = count(array_filter($rows, fn($r) => $r['status'] === 'Zrealizowane'));
    $sec->addText('Liczba pozycji: ' . count($rows) . '; zrealizowane: ' . $done
        . ($total > 0 ? '; łączny czas: ' . round($total / 60, 1) . ' h' : '') . '.', ['size' => 10]);

    if (!$rows) { $sec->addText('Brak pozycji planu.'); }
    else {
        $cols = [['Lp.', 700], ['Dział', 2600], ['Temat', 3600], ['Opis', 4200], ['Czas (min)', 1000], ['Status', 1500]];
        if ($show_active) $cols[] = ['Widoczna dla kursanta', 1500];
        $t = $sec->addTable(['borderSize' => 6, 'borderColor' => '666666', 'cellMargin' => 60]);
        $t->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($cols as [$h, $w]) $t->addCell($w, ['bgColor' => 'E2E8F0'])->addText($h, ['bold' => true, 'size' => 10]);
        foreach ($rows as $r) {
            $t->addRow(null, ['cantSplit' => true]);
            $vals = [(string)$r['lp'], $r['section'] !== '' ? $r['section'] : 'Program', $r['title'],
                     $r['desc'] !== '' ? $r['desc'] : '—', $r['minutes'] > 0 ? (string)$r['minutes'] : '—', $r['status']];
            if ($show_active) $vals[] = $r['active'] ? 'Tak' : 'Nie';
            foreach ($vals as $i => $v) {
                $cell = $t->addCell($cols[$i][1]);
                foreach (explode("\n", (string)$v) as $line) $cell->addText(htmlspecialchars($line, ENT_XML1), ['size' => 10]);
            }
        }
    }
    $fn = ti_curr_export_filename($course_name, 'docx');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Cache-Control: no-cache');
    $pw->save('php://output', 'Word2007');
    foreach ($tmp_imgs as $p) @unlink($p);
    exit;
}

function ti_curr_export_xlsx(string $course_name, array $rows, bool $show_active, string $author): void {
    require_once dirname(dirname(dirname(__DIR__))) . '/includes/xlsx.php';
    $x = new XlsxWriter();
    $x->addSheet('Plan nauczania');
    // Nagłówek tabeli w pierwszym wierszu — czytnik ekranu rozpoznaje kolumny od A1.
    $head = ['Lp.', 'Dział', 'Temat', 'Opis', 'Czas (min)', 'Status'];
    if ($show_active) $head[] = 'Widoczna dla kursanta';
    $x->writeRow($head, ['header']);
    foreach ($rows as $r) {
        $line = [$r['lp'], $r['section'] !== '' ? $r['section'] : 'Program', $r['title'], $r['desc'], $r['minutes'], $r['status']];
        if ($show_active) $line[] = $r['active'] ? 'Tak' : 'Nie';
        $x->writeRow($line);
    }
    $x->addSheet('Informacje');
    $x->writeRow(['Kurs', 'Wygenerowano', 'Przez'], ['header']);
    $x->writeRow([$course_name, date('d.m.Y H:i'), $author]);
    $x->output(ti_curr_export_filename($course_name, 'xlsx'));
}
