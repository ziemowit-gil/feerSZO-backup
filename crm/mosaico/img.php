<?php
/**
 * crm/mosaico/img.php — odpowiednik `imgProcessorBackend` z kontraktu Mosaico.
 *
 * GET ?src=&method=placeholder|placeholder2|resize|resizex|cover|coverx|aspect&params=W,H[&text=]
 * Zwraca surowe bajty obrazu PNG.
 *
 * KRYTYCZNE: `src` musi wskazywać wyłącznie na nasz katalog uploadów Mosaico —
 * inaczej to otwarty proxy SSRF/path traversal. Wymiary z `params` są dodatkowo
 * ograniczane serwerowo (DoS przez wyczerpanie pamięci w GD).
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');

const MOSAICO_MAX_DIM = 2000; // px — twardy limit niezależny od żądania klienta

function mosaico_send_png(\GdImage $im): void {
    header('Content-Type: image/png');
    imagepng($im);
    imagedestroy($im);
    exit;
}

function mosaico_clamp_dim(?string $v, int $fallback = 300): int {
    if ($v === null || $v === 'null' || $v === '') return $fallback;
    $n = (int)$v;
    if ($n <= 0) $n = $fallback;
    return min($n, MOSAICO_MAX_DIM);
}

/** Rozwiązuje `src` do lokalnej ścieżki wewnątrz uploads/crm_mosaico/ — albo null jeśli poza whitelistą. */
function mosaico_resolve_src(string $src): ?string {
    $path = parse_url($src, PHP_URL_PATH) ?: $src;
    $path = rawurldecode($path);
    $base = realpath(UPLOAD_DIR . 'crm_mosaico');
    if ($base === false) return null;
    $candidate = realpath($base . '/' . basename($path));
    if ($candidate === false) return null;
    if (strpos($candidate, $base . DIRECTORY_SEPARATOR) !== 0) return null;
    return $candidate;
}

$method = $_GET['method'] ?? '';
$paramsRaw = explode(',', (string)($_GET['params'] ?? ''));
$w = mosaico_clamp_dim($paramsRaw[0] ?? null);
$h = mosaico_clamp_dim($paramsRaw[1] ?? null);

if ($method === 'placeholder' || $method === 'placeholder2') {
    $text = (string)($_GET['text'] ?? ($w . ' x ' . $h));
    $im = imagecreatetruecolor($w, $h);
    $gray  = imagecolorallocate($im, 0x80, 0x80, 0x80);
    $gray2 = imagecolorallocate($im, 0x70, 0x70, 0x70);
    $white = imagecolorallocate($im, 0xFF, 0xFF, 0xFF);
    imagefill($im, 0, 0, $gray);
    // Proste ukośne paski jako tło placeholdera
    $stripe = max(20, (int)($method === 'placeholder2' ? 40 : 20));
    for ($x = -$h; $x < $w; $x += $stripe * 2) {
        imagefilledpolygon($im, [
            $x, 0, $x + $stripe, 0, $x + $stripe + $h, $h, $x + $h, $h,
        ], $gray2);
    }
    $font = 5;
    $tw = imagefontwidth($font) * strlen($text);
    $th = imagefontheight($font);
    imagestring($im, $font, (int)(($w - $tw) / 2), (int)(($h - $th) / 2), $text, $white);
    mosaico_send_png($im);
}

if (in_array($method, ['resize', 'resizex', 'cover', 'coverx', 'aspect'], true)) {
    $srcPath = mosaico_resolve_src((string)($_GET['src'] ?? ''));
    if (!$srcPath) { http_response_code(404); exit; }

    $info = @getimagesize($srcPath);
    if (!$info) { http_response_code(404); exit; }
    [$origW, $origH] = $info;

    $src = match ($info[2]) {
        IMAGETYPE_PNG  => imagecreatefrompng($srcPath),
        IMAGETYPE_JPEG => imagecreatefromjpeg($srcPath),
        IMAGETYPE_GIF  => imagecreatefromgif($srcPath),
        default => null,
    };
    if (!$src) { http_response_code(415); exit; }

    $wRaw = $paramsRaw[0] ?? null;
    $hRaw = $paramsRaw[1] ?? null;

    if ($method === 'resize' || $method === 'resizex' || $method === 'aspect') {
        if ($wRaw === 'null' || $wRaw === null || $wRaw === '') {
            // Tylko wysokość podana — skaluj wg wysokości
            if ($method === 'resizex' && $origH <= $h) { mosaico_send_png($src); }
            $newH = $h; $newW = (int)round($origW * ($newH / $origH));
        } elseif ($hRaw === 'null' || $hRaw === null || $hRaw === '') {
            if ($method === 'resizex' && $origW <= $w) { mosaico_send_png($src); }
            $newW = $w; $newH = (int)round($origH * ($newW / $origW));
        } else {
            if ($method === 'resizex' && $origW <= $w && $origH <= $h) { mosaico_send_png($src); }
            // "contain" — dopasuj zachowując proporcje wewnątrz w×h, dopełnij przezroczystością
            $scale = min($w / $origW, $h / $origH);
            $newW = (int)round($origW * $scale);
            $newH = (int)round($origH * $scale);
            $canvas = imagecreatetruecolor($w, $h);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
            imagecopyresampled($canvas, $src, (int)(($w - $newW) / 2), (int)(($h - $newH) / 2), 0, 0, $newW, $newH, $origW, $origH);
            mosaico_send_png($canvas);
        }
        $resized = imagecreatetruecolor($newW, $newH);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        mosaico_send_png($resized);
    }

    if ($method === 'cover' || $method === 'coverx') {
        $ar = $w / $h;
        $origAr = $origW / $origH;
        if ($ar > $origAr) {
            $cropH = (int)round($origW / $ar);
            $cropY = (int)round(($origH - $cropH) / 2);
            $cropped = imagecrop($src, ['x' => 0, 'y' => $cropY, 'width' => $origW, 'height' => $cropH]);
            $noEnlarge = $cropH <= $h;
        } else {
            $cropW = (int)round($origH * $ar);
            $cropX = (int)round(($origW - $cropW) / 2);
            $cropped = imagecrop($src, ['x' => $cropX, 'y' => 0, 'width' => $cropW, 'height' => $origH]);
            $noEnlarge = $cropW <= $w;
        }
        if ($method === 'coverx' && $noEnlarge) { mosaico_send_png($cropped); }
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $cropped, 0, 0, 0, 0, $w, $h, imagesx($cropped), imagesy($cropped));
        mosaico_send_png($out);
    }
}

http_response_code(400);
