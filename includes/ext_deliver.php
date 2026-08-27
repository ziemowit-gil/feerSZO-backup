<?php
/**
 * includes/ext_deliver.php — Materiały zewnętrzne: znak wodny i strumień pliku.
 *
 * Znak wodny nakładamy na kopię, nie na oryginał, i trzymamy ją w cache pod
 * kluczem zawierającym WERSJĘ POLITYKI — zmiana reguł unieważnia stare pliki
 * bez ręcznego czyszczenia katalogu.
 *
 * Czego to nie robi: nie jest to szczelny DRM. Wszystko, co dociera do
 * przeglądarki, da się sfotografować i wydrukować do PDF-a. Znak wodny mówi,
 * CZYJ to był egzemplarz — i to jest jego cała rola.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ext_materials.php';

/** Kolejka stemplowania dużych plików — obsługuje ją cron/ext_agent.php. */
function ext_deliver_migrate(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ext_wm_queue (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        resource_id INTEGER NOT NULL,
        ticket_id   TEXT    NOT NULL,
        dest        TEXT    NOT NULL,
        status      TEXT    NOT NULL DEFAULT 'pending',
        error       TEXT    NOT NULL DEFAULT '',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        done_at     DATETIME
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS ix_ext_wmq ON k30_ext_wm_queue(status, created_at)");
}

/** Wersja polityki — zmiana napisu unieważnia cache wszystkich stempli. */
function ext_policy_version(string $policy): string
{
    return substr(sha1($policy . '|v1'), 0, 8);
}

/** Ścieżka kopii ze znakiem wodnym dla tej osoby i tej polityki. */
function ext_wm_path(array $resource, array $ticket): string
{
    return sprintf('%s/ext/wm/%d/%s-%d/%s.pdf',
        rtrim(UPLOAD_DIR, '/'), (int)$resource['id'],
        preg_replace('/[^a-z]/', '', (string)$ticket['subject_type']),
        (int)$ticket['subject_id'], ext_policy_version((string)$ticket['watermark']));
}

/** Napis stempla: kto, kiedy, jakim biletem. */
function ext_watermark_text(array $ticket): string
{
    $who = trim((string)$ticket['subject_name']) !== ''
        ? (string)$ticket['subject_name']
        : $ticket['subject_type'] . ' #' . (int)$ticket['subject_id'];
    return sprintf('%s · %s · bilet %s', $who, date('Y-m-d H:i'), substr((string)$ticket['id'], 0, 8));
}

/**
 * Zwraca ścieżkę pliku do wysłania — oryginał albo kopię ze stemplem.
 * Duże pliki idą do kolejki: strona nie może wisieć pół minuty na mPDF.
 * Zwraca ['path'=>string] albo ['queued'=>true].
 */
function ext_prepare_file(array $resource, array $ticket): array
{
    $src = ext_storage_path((string)$resource['checksum']);
    if ($src === '' || !is_file($src)) return ['error' => 'Plik nie istnieje w magazynie.'];

    $policy = (string)$ticket['watermark'];
    if ($policy === 'none' || $resource['mime'] !== 'application/pdf') {
        return ['path' => $src];
    }

    $dst = ext_wm_path($resource, $ticket);
    if (is_file($dst)) return ['path' => $dst];

    if ((int)$resource['size_bytes'] > 20 * 1024 * 1024) {
        ext_queue_watermark((int)$resource['id'], (string)$ticket['id'], $dst);
        return ['queued' => true];
    }

    @mkdir(dirname($dst), 0770, true);
    if (!ext_watermark_pdf($src, $dst, ext_watermark_text($ticket), $policy)) {
        // Stempel się nie udał — NIE wysyłamy oryginału zamiast niego.
        // Materiał chroniony bez znaku wodnego to złamanie warunków licencji.
        return ['error' => 'Nie udało się przygotować dokumentu. Zgłoś to administratorowi.'];
    }
    return ['path' => $dst];
}

function ext_queue_watermark(int $resourceId, string $ticketId, string $dest): void
{
    $dup = db_one("SELECT id FROM k30_ext_wm_queue WHERE dest=? AND status='pending'", [$dest]);
    if ($dup) return;
    db_insert('k30_ext_wm_queue', ['resource_id' => $resourceId, 'ticket_id' => $ticketId, 'dest' => $dest]);
}

/**
 * Nakłada znak wodny na PDF. Zwraca true przy powodzeniu.
 * mPDF 8 nie wymaga już SetImportUse() — import stron działa od razu.
 */
function ext_watermark_pdf(string $src, string $dst, string $mark, string $policy): bool
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) { error_log('[ext_watermark_pdf] brak vendor/autoload.php'); return false; }
    require_once $autoload;

    try {
        $mpdf  = new \Mpdf\Mpdf([
            'tempDir' => sys_get_temp_dir() . '/mpdf',
            'margin_top' => 0, 'margin_bottom' => 0, 'margin_left' => 0, 'margin_right' => 0,
        ]);
        $pages = (int)$mpdf->SetSourceFile($src);
        if ($pages < 1) return false;

        $mpdf->SetCreator('SZO/ext:' . hash_hmac('sha256', $mark, APP_KEY));
        $esc = htmlspecialchars($mark, ENT_QUOTES, 'UTF-8');

        for ($p = 1; $p <= $pages; $p++) {
            $tpl  = $mpdf->ImportPage($p);
            $size = $mpdf->getTemplateSize($tpl);
            $w    = (float)($size['width']  ?? 210);
            $hgt  = (float)($size['height'] ?? 297);

            $mpdf->AddPageByArray([
                'orientation' => $w > $hgt ? 'L' : 'P',
                'sheet-size'  => [$w, $hgt],
            ]);
            $mpdf->UseTemplate($tpl);

            // Stempel rysujemy PO nałożeniu strony źródłowej i własną ręką.
            // Wbudowany SetWatermarkText() mPDF-a rysuje pod treścią, a książki
            // od wydawnictw mają zwykle nieprzezroczyste tło strony — znak wodny
            // znikałby pod nim i nie byłoby tego jak zauważyć.
            if ($policy === 'overlay' || $policy === 'both') {
                $step = max(60.0, $hgt / 4);
                for ($y = $step / 2; $y < $hgt; $y += $step) {
                    $mpdf->WriteFixedPosHTML(
                        '<div style="font-size:11pt;color:#c9c9c9;text-align:center">' . $esc . '</div>',
                        6, $y, $w - 12, 10, 'hidden'
                    );
                }
            }
            if ($policy === 'footer' || $policy === 'both') {
                $mpdf->WriteFixedPosHTML(
                    '<div style="font-size:6.5pt;color:#777;text-align:right">' . $esc . '</div>',
                    6, max(4.0, $hgt - 8), $w - 12, 6, 'hidden'
                );
            }
        }

        $mpdf->Output($dst, \Mpdf\Output\Destination::FILE);
        return is_file($dst) && filesize($dst) > 0;
    } catch (\Throwable $e) {
        error_log('[ext_watermark_pdf] ' . $e->getMessage());
        @unlink($dst);
        return false;
    }
}
/**
 * Wysyła plik strumieniem, z obsługą nagłówka Range.
 * readfile() na 300-megabajtowej książce zjadłoby pamięć i nie pozwoliło
 * czytnikowi PDF skakać po stronach — a czytniki proszą właśnie o fragmenty.
 */
function ext_stream_file(string $path, string $mime, string $downloadName = '', bool $inline = true): void
{
    $size  = (int)filesize($path);
    $start = 0;
    $end   = $size - 1;

    if (preg_match('/bytes=(\d+)-(\d*)/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
        $start = min((int)$m[1], max(0, $size - 1));
        if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }

    while (ob_get_level()) ob_end_clean();     // bufor PHP nie ma trzymać całego pliku

    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
    header('Content-Length: ' . max(0, $end - $start + 1));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment; filename="'
        . preg_replace('/[\r\n"]+/', '', $downloadName ?: basename($path)) . '"'));

    $fh = fopen($path, 'rb');
    if (!$fh) { http_response_code(500); exit; }
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = fread($fh, (int)min(8192, $left));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fh);
}
