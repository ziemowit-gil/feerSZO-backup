<?php
/**
 * helpers.php — funkcje pomocnicze: escaping, CSRF, flash, slugi, upload plików, vCard.
 */
declare(strict_types=1);

// ── Wyjście / wejście ─────────────────────────────────────────────────

/** Escape HTML (domyślne wyjście dla wszystkich danych z bazy). */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Wartość z POST jako przycięty string. */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function post_int(string $key, int $default = 0): int
{
    return isset($_POST[$key]) && is_scalar($_POST[$key]) ? (int)$_POST[$key] : $default;
}

function post_bool(string $key): bool
{
    return !empty($_POST[$key]);
}

function get_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) && is_scalar($_GET[$key]) ? (int)$_GET[$key] : $default;
}

/** Przekierowanie wewnętrzne i koniec żądania. */
function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)), true, 302);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────────────

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Ukryte pole formularza z tokenem. */
function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/** Weryfikacja tokenu; przy błędzie 419 i koniec. */
function csrf_check(): void
{
    $sent = (string)($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Nieprawidłowy token bezpieczeństwa (CSRF). Odśwież stronę i spróbuj ponownie.');
    }
}

// ── Komunikaty flash ──────────────────────────────────────────────────

/** Dodaj komunikat: type = success | error | info. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

/** Pobierz i wyczyść komunikaty. */
function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ── Slugi ─────────────────────────────────────────────────────────────

/** Bezpieczny slug z polskimi znakami → ascii. */
function slugify(string $text, string $fallback = 'strona'): string
{
    $map = [
        'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
        'Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z',
        'ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'ss','é'=>'e','è'=>'e','ê'=>'e','á'=>'a','í'=>'i','ú'=>'u','ç'=>'c',
    ];
    $text = strtr($text, $map);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('~[^a-z0-9]+~', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? mb_substr($text, 0, 80) : $fallback;
}

/** Slug unikalny w tabeli (dokleja -2, -3 ...). */
function unique_slug(string $table, string $slug, ?int $ignoreId = null): string
{
    $base = $slug;
    $i    = 1;
    while (true) {
        $sql    = "SELECT id FROM {$table} WHERE slug = :s" . ($ignoreId ? ' AND id <> :id' : '');
        $params = $ignoreId ? ['s' => $slug, 'id' => $ignoreId] : ['s' => $slug];
        if (!q_one($sql, $params)) return $slug;
        $slug = $base . '-' . (++$i);
    }
}

// ── Upload plików ─────────────────────────────────────────────────────

const ALLOWED_IMAGE_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
    'image/svg+xml' => 'svg',
];

/** Maksymalny rozmiar pliku (bajty) — 8 MB. */
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

/**
 * Zapisz przesłany obraz w podkatalogu uploads/.
 * Zwraca ['ok'=>bool,'file'=>nazwa,'width','height','size','error'=>tekst].
 */
function save_uploaded_image(array $file, string $subdir): array
{
    $fail = fn(string $msg) => ['ok' => false, 'error' => $msg];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $fail(match ($file['error'] ?? -1) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Plik jest zbyt duży.',
            UPLOAD_ERR_NO_FILE                        => 'Nie wybrano pliku.',
            default                                   => 'Błąd przesyłania pliku.',
        });
    }
    if (!is_uploaded_file($file['tmp_name'])) return $fail('Nieprawidłowe źródło pliku.');
    if ((int)$file['size'] > MAX_UPLOAD_BYTES) {
        return $fail('Plik przekracza limit ' . round(MAX_UPLOAD_BYTES / 1048576) . ' MB.');
    }

    // Typ MIME ustalany z zawartości pliku, nie z nagłówka przeglądarki.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);
    if (!isset(ALLOWED_IMAGE_MIME[$mime])) {
        return $fail('Niedozwolony typ pliku (' . $mime . '). Dozwolone: JPG, PNG, GIF, WEBP, AVIF, SVG.');
    }
    $ext = ALLOWED_IMAGE_MIME[$mime];

    // Rastry muszą dać się odczytać jako obraz (odsiewa pliki "przebrane" za obraz).
    $w = $h = 0;
    if ($mime !== 'image/svg+xml') {
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) return $fail('Plik nie jest prawidłowym obrazem.');
        [$w, $h] = $info;
    }

    $dir = UPLOAD_ROOT . '/' . trim($subdir, '/');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return $fail('Nie można utworzyć katalogu uploads.');

    // Unikalna, nieodgadywalna nazwa — nigdy nie ufamy nazwie od użytkownika.
    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return $fail('Nie udało się zapisać pliku (uprawnienia do katalogu uploads?).');
    }
    @chmod($dir . '/' . $name, 0644);

    return [
        'ok'     => true,
        'file'   => $name,
        'width'  => (int)$w,
        'height' => (int)$h,
        'size'   => (int)$file['size'],
        'error'  => '',
    ];
}

/** Rozbij tablicę $_FILES[...] z multi-upload na listę pojedynczych plików. */
function normalize_files_array(array $files): array
{
    if (!isset($files['name'])) return [];
    if (!is_array($files['name'])) return [$files];
    $out = [];
    foreach (array_keys($files['name']) as $i) {
        $out[] = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
    }
    return $out;
}

/** Usuń plik z uploads (bezpiecznie, tylko wewnątrz katalogu uploads). */
function delete_upload(string $relative): void
{
    $relative = ltrim(str_replace(['..', "\0"], '', $relative), '/');
    if ($relative === '') return;
    $path = realpath(UPLOAD_ROOT . '/' . $relative);
    if ($path && str_starts_with($path, realpath(UPLOAD_ROOT) ?: UPLOAD_ROOT) && is_file($path)) {
        @unlink($path);
    }
}

/** URL do pliku w uploads. */
function upload_url(string $relative): string
{
    return url('/uploads/' . ltrim($relative, '/'));
}

function human_size(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024) . ' kB';
    return $bytes . ' B';
}

// ── vCard ─────────────────────────────────────────────────────────────

/** Zbuduj treść pliku .vcf z ustawień strony. */
function build_vcard(): string
{
    // Nazwa właściciela = część zwykła + część pogrubiona z nagłówka
    $name  = trim(setting('owner_name') . ' ' . setting('owner_name_bold'));
    if ($name === '') $name = setting('site_name', 'Wizytówka');
    $parts = preg_split('~\s+~', trim($name)) ?: [];
    $first = $parts[0] ?? $name;
    $last  = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

    $esc = fn(string $v): string => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\;'], trim($v));

    $lines   = [];
    $lines[] = 'BEGIN:VCARD';
    $lines[] = 'VERSION:3.0';
    $lines[] = 'N:' . $esc($last) . ';' . $esc($first) . ';;;';
    $lines[] = 'FN:' . $esc($name);
    if (setting('job_title'))  $lines[] = 'TITLE:' . $esc(setting('job_title'));
    if (setting('company'))    $lines[] = 'ORG:' . $esc(setting('company'));
    if (setting('email'))      $lines[] = 'EMAIL;type=INTERNET;type=WORK:' . $esc(setting('email'));
    if (setting('phone'))      $lines[] = 'TEL;type=CELL:' . $esc(setting('phone'));
    if (setting('website'))    $lines[] = 'URL:' . $esc(setting('website'));
    else                       $lines[] = 'URL:' . $esc(abs_url('/'));
    if (setting('location'))   $lines[] = 'ADR;type=WORK:;;' . $esc(setting('location')) . ';;;;';
    if (setting('bio')) {
        // NOTE bez znaczników Markdown
        $note    = preg_replace('~[*_#`>]+~', '', strip_tags(setting('bio'))) ?? '';
        $lines[] = 'NOTE:' . $esc(mb_substr(trim($note), 0, 300));
    }
    $lines[] = 'REV:' . gmdate('Ymd\THis\Z');
    $lines[] = 'END:VCARD';

    return implode("\r\n", $lines) . "\r\n";
}

// ── Dekoracyjne tło (animowane kwadraty w kolorze akcentu) ────────────

/**
 * Zwraca data-URI z animowanym wzorem SVG (delikatne, powolne kwadraty).
 * Pozycje są stałe (deterministyczne), więc plik dobrze się cachuje.
 */
function bg_pattern_svg(string $color): string
{
    $rgb = sscanf(ltrim($color, '#'), '%2x%2x%2x') ?: [255, 114, 79];
    $fill = sprintf('rgba(%d,%d,%d,0.20)', $rgb[0], $rgb[1], $rgb[2]);

    // [x, y, czas trwania ms, opóźnienie ms]
    $cells = [
        [50, 122, 9000, 0],    [218, 26, 7800, 600],  [290, 362, 6100, 200],
        [362, 74, 9600, 400],  [434, 386, 5400, 800], [458, 146, 5100, 300],
        [482, 50, 3300, 0],    [554, 314, 8400, 500], [578, 218, 10000, 100],
        [602, 74, 6300, 900],  [674, 338, 9000, 700], [698, 2, 6000, 250],
        [2, 362, 5700, 650],   [146, 242, 8700, 150], [314, 218, 4800, 350],
        [530, 410, 4200, 880], [698, 194, 6900, 450], [74, 2, 9300, 550],
    ];

    $rects = '';
    foreach ($cells as [$x, $y, $dur, $begin]) {
        $rects .= '<rect x="' . $x . '" y="' . $y . '" width="20" height="20" fill="' . $fill . '" fill-opacity="0">'
                . '<animate attributeName="fill-opacity" dur="' . $dur . 'ms" begin="' . $begin . 'ms"'
                . ' repeatCount="indefinite" values="0;0.9;1;0.9;0;0;0;0.4;0.9;0;0;0;0.3;0" />'
                . '</rect>';
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="768" height="432" viewBox="0 0 768 432">'
         . $rects . '</svg>';

    return 'data:image/svg+xml;charset=utf8,' . str_replace(
        ['%', '#', '<', '>', '"', ' ', "\n"],
        ['%25', '%23', '%3C', '%3E', '%22', '%20', ''],
        $svg
    );
}
