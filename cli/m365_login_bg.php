<?php
/**
 * cli/m365_login_bg.php — eksport tła ekranu logowania do pliku JPG dla
 * strony logowania Microsoft 365 (Entra ID → Company branding → Background image).
 *
 * Rysuje dokładnie to samo tło co powłoka wejścia (.ks-hero z
 * includes/auth_screen.php): kolor marki organizacji + geometria (kwadraty,
 * koło, strzałki) w czerni 5,5% + ukośne prążki w czerni 4,5%. Dzięki temu
 * ekran Microsoftu i ekran logowania SZO wyglądają jak jedna całość.
 *
 * Entra ID wymaga: 1920×1080 px, JPG/PNG, poniżej 300 kB.
 *
 * Użycie:
 *   php cli/m365_login_bg.php                        # kolor marki z ustawień
 *   php cli/m365_login_bg.php --color=#1e3a5f
 *   php cli/m365_login_bg.php --size=1920x1080 --out=output/m365-login-bg.jpg
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/branding.php';

// ── Argumenty ─────────────────────────────────────────────────────────────────
$opt = getopt('', ['color::', 'size::', 'out::', 'quality::']);

$color = $opt['color'] ?? '';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
    $color = branding_load()['primary'];               // to samo źródło co --c w CSS
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#DC2626';
}

[$W, $H] = array_map('intval', explode('x', ($opt['size'] ?? '1920x1080') . 'x0'));
if ($W < 200 || $H < 200) { fwrite(STDERR, "Zły --size (oczekiwane np. 1920x1080)\n"); exit(1); }

$out = $opt['out'] ?? ($root . '/output/m365-login-bg.jpg');
if ($out[0] !== '/') $out = $root . '/' . $out;
@mkdir(dirname($out), 0775, true);
$quality = max(50, min(100, (int)($opt['quality'] ?? 88)));

// ── Płótno w kolorze marki ────────────────────────────────────────────────────
$r = hexdec(substr($color, 1, 2)); $g = hexdec(substr($color, 3, 2)); $b = hexdec(substr($color, 5, 2));

$im = imagecreatetruecolor($W, $H);
imagealphablending($im, true);
imagefilledrectangle($im, 0, 0, $W, $H, imagecolorallocate($im, $r, $g, $b));

// Figury rysujemy kolorem NIEPRZEZROCZYSTYM (kolor marki przyciemniony o 5,5%),
// bo nakładające się fragmenty (rogi zaokrąglonych prostokątów) podwoiłyby alfę.
$m = 1 - 0.055;
$shape = imagecolorallocate($im, (int)round($r * $m), (int)round($g * $m), (int)round($b * $m));

/** Prostokąt z zaokrąglonymi rogami (rx w SVG). */
$rrect = function ($x, $y, $w, $h, $rad, $col) use ($im) {
    imagefilledrectangle($im, $x + $rad, $y, $x + $w - $rad, $y + $h, $col);
    imagefilledrectangle($im, $x, $y + $rad, $x + $w, $y + $h - $rad, $col);
    $d = $rad * 2;
    imagefilledarc($im, $x + $rad,      $y + $rad,      $d, $d, 180, 270, $col, IMG_ARC_PIE);
    imagefilledarc($im, $x + $w - $rad, $y + $rad,      $d, $d, 270, 360, $col, IMG_ARC_PIE);
    imagefilledarc($im, $x + $rad,      $y + $h - $rad, $d, $d,  90, 180, $col, IMG_ARC_PIE);
    imagefilledarc($im, $x + $w - $rad, $y + $h - $rad, $d, $d,   0,  90, $col, IMG_ARC_PIE);
};

// Kafel 420×420 — 1:1 z <svg> w .ks-hero::before (background-size:420px 420px).
for ($ty = 0; $ty < $H; $ty += 420) {
    for ($tx = 0; $tx < $W; $tx += 420) {
        $rrect($tx + 24,  $ty + 40,  120, 120, 8, $shape);          // rect
        $rrect($tx + 210, $ty + 250, 150, 150, 8, $shape);          // rect
        imagefilledellipse($im, $tx + 330, $ty + 96, 116, 116, $shape);   // circle r=58
        imagefilledpolygon($im, [                                    // M0 210l70-70v46l-24 24z
            $tx + 0, $ty + 210,  $tx + 70, $ty + 140,  $tx + 70, $ty + 186,  $tx + 46, $ty + 210,
        ], $shape);
        imagefilledpolygon($im, [                                    // m52 132l96-96v46l-50 50z
            $tx + 52, $ty + 342, $tx + 148, $ty + 246, $tx + 148, $ty + 292, $tx + 98, $ty + 342,
        ], $shape);
        imagefilledpolygon($im, [                                    // M300 0l60 60-24 24-60-60z
            $tx + 300, $ty + 0,  $tx + 360, $ty + 60,  $tx + 336, $ty + 84, $tx + 276, $ty + 24,
        ], $shape);
    }
}

// ── Prążki: repeating-linear-gradient(135deg, rgba(0,0,0,.045) 0 3px, transparent 3px 26px)
// Pasma biegną w kierunku (1,-1); 3 px grubości i 26 px odstępu liczone prostopadle,
// czyli w poziomie ×√2. Alfa GD: 0 = krycie, 127 = pełna przezroczystość.
$stripe = imagecolorallocatealpha($im, 0, 0, 0, (int)round(127 * (1 - 0.045)));
$thick  = 3 * M_SQRT2;
$step   = 26 * M_SQRT2;
for ($x0 = -$H; $x0 < $W + $step; $x0 += $step) {
    imagefilledpolygon($im, [
        (int)round($x0),                  $H,
        (int)round($x0 + $thick),         $H,
        (int)round($x0 + $thick + $H),    0,
        (int)round($x0 + $H),             0,
    ], $stripe);
}

imagejpeg($im, $out, $quality);

printf("%s — %d×%d, kolor %s, %.0f kB%s\n", $out, $W, $H, $color, filesize($out) / 1024,
    filesize($out) > 300 * 1024 ? '  ⚠ powyżej limitu Entra ID (300 kB) — zmniejsz --quality' : '');
