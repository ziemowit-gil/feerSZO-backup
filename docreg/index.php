<?php
/**
 * docreg/index.php — rejestr numerów i kodów plików (modules/doc_registry):
 *   ?view=KOD   — strona z QR, numerem i kodem (działa bez JS i bez okna modalnego),
 *   ?qr=KOD     — PNG z kodem QR (adres weryfikacji /espis/?c=KOD),
 *   ?label=KOD  — etykieta do druku (PDF 70×38 mm): Code128 + QR + numer; do naklejenia na papierowy odpowiednik,
 *   ?o=KOD      — „Otwórz dokument online”: po zalogowaniu przekierowanie do pliku w systemie źródłowym (EZD / CRM / Zadania),
 *                 który sam sprawdza uprawnienia (ten adres niczego nie omija).
 * Wszystko wymaga logowania (publiczna jest tylko weryfikacja kodu w /espis/).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/doc_registry/lib/DocRegistry.php';

require_login();
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

function docreg_fail(int $code, string $msg): never { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; }

if (isset($_GET['qr'])) {
    $row = DocRegistry::find((string)$_GET['qr']) ?: docreg_fail(404, 'Nie znaleziono kodu.');
    $png = DocRegistry::qrPng((string)$row['code']) ?: docreg_fail(503, 'Generator QR jest niedostępny.');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: image/png'); header('Content-Length: ' . strlen($png)); header('Cache-Control: private, max-age=86400');
    echo $png; exit;
}

// Decyzja o odpowiedniku papierowym (POST: paper=KOD, mode=none|label|manual | done=1) — JSON dla okna, przekierowanie dla formularza.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['paper'])) {
    csrf_check();
    $code = (string)$_GET['paper'];
    $json = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $err = '';
    if (!empty($_POST['done'])) DocRegistry::paperDone($code);
    else $err = DocRegistry::setPaper($code, (string)($_POST['mode'] ?? ''), (int)(current_user()['id'] ?? 0));
    if ($json) { header('Content-Type: application/json; charset=utf-8'); $r = DocRegistry::find($code); echo json_encode(['ok' => $err === '', 'error' => $err, 'mode' => (string)($r['paper_mode'] ?? '')]); exit; }
    header('Location: ?view=' . rawurlencode($code) . ($err !== '' ? '&err=' . rawurlencode($err) : '')); exit;
}

// Etykieta (?label=KOD) albo arkusz A4 3×8 (?sheet=KOD1,KOD2…&start=N — numer pierwszego wolnego pola) — PDF.
if (isset($_GET['label']) || isset($_GET['sheet'])) {
    $codes = isset($_GET['sheet']) ? array_values(array_filter(array_map('trim', is_array($_GET['sheet']) ? $_GET['sheet'] : explode(',', (string)$_GET['sheet'])))) : [(string)$_GET['label']];
    $rows = [];
    foreach (array_slice($codes, 0, 120) as $c) {
        $r = DocRegistry::find($c);
        if ($r && !DocRegistry::isSeq((string)$r['source_type'])) $rows[] = $r;
    }
    if (!$rows) docreg_fail(404, 'Nie znaleziono dokumentów z etykietą (numery kolejne HD/KOR/REK mają tylko numer i QR).');
    $pdf = DocRegistry::labelsPdf($rows, isset($_GET['sheet']), (int)($_GET['start'] ?? 1)) ?? docreg_fail(503, 'Generator PDF jest niedostępny.');
    foreach ($rows as $r) if (($r['paper_mode'] ?? '') === 'label' && empty($r['paper_done_at'])) DocRegistry::paperDone((string)$r['code']);   // etykieta wydrukowana
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . (isset($_GET['sheet']) ? 'arkusz-etykiet' : 'etykieta-' . preg_replace('~[^A-Z0-9]+~', '-', (string)$rows[0]['code'])) . '.pdf"');
    header('Content-Length: ' . strlen($pdf)); header('Cache-Control: private, no-store');
    echo $pdf; exit;
}

if (isset($_GET['view'])) {
    $row = DocRegistry::find((string)$_GET['view']) ?: docreg_fail(404, 'Nie znaleziono kodu.');
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $def = DocRegistry::SOURCES[$row['source_type']] ?? ['', 'Plik w systemie'];
    $seq = ($def[4] ?? 'rand') === 'seq';
    $code = (string)$row['code'];
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Identyfikator dokumentu ' . $e($row['doc_number']) . '</title>'
        . '<style>body{font-family:"Segoe UI",system-ui,sans-serif;margin:0;background:#f4f4f4;color:#1d1d1d;text-align:center;padding:1.5rem}main{max-width:22rem;margin:0 auto;background:#fff;border:1px solid #c9c9c9;padding:1.2rem}'
        . 'img{width:220px;height:220px}code{font-size:1.05rem}.s{font-size:.85rem;color:#4b5b6b}a.b{display:inline-block;margin:.3rem .2rem;padding:.45rem .9rem;background:#0050ef;color:#fff;text-decoration:none;font-weight:600}a.o{background:#4b5b6b}:focus-visible{outline:3px solid #ff9800;outline-offset:2px}'
        . '@media (prefers-color-scheme:dark){body{background:#1b1b1b;color:#f2f2f2}main{background:#262626;border-color:#4a4a4a}.s{color:#aebccb}}@media print{a.b{display:none}}</style></head><body><main id="tresc">'
        . '<h1 style="font-size:1.1rem;font-weight:600;margin:.2rem 0 .8rem">' . $e($def[1]) . '</h1>'
        . '<img src="?qr=' . $e(rawurlencode($code)) . '" alt="Kod QR do weryfikacji i otwarcia dokumentu online">'
        . '<p class="s" style="margin:.6rem 0 0">Numer</p><p style="margin:0 0 .5rem;font-weight:600">' . $e($row['doc_number']) . '</p>'
        . ($seq ? '' : '<p class="s" style="margin:0">Kod (Code128)</p><p style="margin:0 0 .5rem"><code>' . $e($code) . '</code></p>')
        . '<p class="s">Kod QR otwiera stronę weryfikacji; po zalogowaniu — dokument online.</p>'
        . '<a class="b" href="' . $e(DocRegistry::url($code)) . '">Strona weryfikacji</a>'
        . ($seq ? '' : '<a class="b o" href="?label=' . $e(rawurlencode($code)) . '">Etykieta do druku (PDF)</a>')
        . (function () use ($row, $code, $seq, $e) {
            if ($seq || str_starts_with((string)$row['source_type'], 'contract_') || in_array($row['source_type'], ['print_doc', 'gen_doc'], true)) return '';
            $mode = (string)($row['paper_mode'] ?? '');
            $h = '<hr style="border:0;border-top:1px solid #c9c9c9;margin:1rem 0"><h2 style="font-size:1rem;margin:.2rem 0 .5rem">Odpowiednik papierowy</h2>';
            if (!empty($_GET['err'])) $h .= '<p role="alert" style="color:#a20025">' . $e($_GET['err']) . '</p>';
            $h .= '<p class="s" style="margin:0 0 .5rem">Czy ten dokument ma odpowiednik papierowy?</p>'
                . '<form method="post" action="?paper=' . $e(rawurlencode($code)) . '" style="text-align:left"><input type="hidden" name="_csrf" value="' . $e(csrf_token()) . '">';
            foreach (['none' => 'Nie — dokument tylko elektroniczny', 'label' => 'Tak — wydrukuję etykietę (naklejka)', 'manual' => 'Tak — numer naniosę ręcznie'] as $k => $l)
                $h .= '<label style="display:block;margin:.3rem 0"><input type="radio" name="mode" value="' . $k . '"' . ($mode === $k ? ' checked' : '') . '> ' . $e($l) . '</label>';
            $h .= '<button class="b" style="border:0;cursor:pointer;font:inherit" type="submit">Zapisz</button></form>';
            if ($mode === 'label') {
                $h .= '<p style="margin:.6rem 0 0"><a class="b o" href="?label=' . $e(rawurlencode($code)) . '">Drukuj etykietę (PDF 70×37 mm)</a></p>'
                    . '<p class="s" style="margin:.3rem 0 0">' . (!empty($row['paper_done_at']) ? 'Etykieta wydrukowana ' . $e(date('d.m.Y H:i', strtotime((string)$row['paper_done_at']))) . '.' : 'Etykieta jeszcze nie była drukowana — po otwarciu PDF zostanie oznaczona jako wydrukowana.') . '</p>';
            } elseif ($mode === 'manual') {
                $h .= '<div style="margin:.7rem 0 0;padding:.7rem;border:2px solid #0050ef;text-align:center"><div class="s">Nanieś na dokument ręcznie (czytelnie, w prawym górnym rogu):</div>'
                    . '<div style="font-size:1.25rem;font-weight:700;margin-top:.2rem">' . $e($row['doc_number']) . '</div><div style="font-family:ui-monospace,monospace;font-size:1.05rem">' . $e($code) . '</div></div>'
                    . (!empty($row['paper_done_at']) ? '<p class="s" style="margin:.3rem 0 0">Numer naniesiono ' . $e(date('d.m.Y H:i', strtotime((string)$row['paper_done_at']))) . '.</p>'
                       : '<form method="post" action="?paper=' . $e(rawurlencode($code)) . '"><input type="hidden" name="_csrf" value="' . $e(csrf_token()) . '"><input type="hidden" name="done" value="1"><button class="b o" style="border:0;cursor:pointer;font:inherit" type="submit">Numer naniesiony na papier</button></form>');
            } elseif ($mode === '') {
                $h .= '<p class="s" style="margin:.5rem 0 0">Nie rozstrzygnięto — wybierz jedną z opcji.</p>';
            }
            return $h;
        })()
        . '</main></body></html>';
    exit;
}

if (isset($_GET['o'])) {
    $row = DocRegistry::find((string)$_GET['o']) ?: docreg_fail(404, 'Nie znaleziono dokumentu o tym kodzie.');
    $st = DocRegistry::state($row);
    if ($st['src'] === null) docreg_fail(410, $st['note']);
    header('Location: ' . DocRegistry::openUrl((string)$row['source_type'], (int)$row['source_id'])); exit;
}

docreg_fail(400, 'Brak parametru (qr, view, label albo o).');
