<?php
/**
 * includes/branding.php — Branding helpers for standalone pages (login, IKA gate, etc.)
 *
 * Provides color utilities and a CSS-variables block based on org settings.
 * Safe to include before auth (no require_login dependency).
 */

// ── Color utilities ─────────────────────────────────────────────────────────

function _hex_to_rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    return [
        (int)hexdec(substr($hex, 0, 2)),
        (int)hexdec(substr($hex, 2, 2)),
        (int)hexdec(substr($hex, 4, 2)),
    ];
}

function _rgb_to_hex(int $r, int $g, int $b): string {
    return sprintf('#%02x%02x%02x',
        max(0, min(255, $r)),
        max(0, min(255, $g)),
        max(0, min(255, $b))
    );
}

/** Ściemnia kolor o $amount (0–255). */
function color_darken(string $hex, int $amount = 30): string {
    [$r, $g, $b] = _hex_to_rgb($hex);
    return _rgb_to_hex($r - $amount, $g - $amount, $b - $amount);
}

/** Rozjaśnia kolor o $amount (0–255). */
function color_lighten(string $hex, int $amount = 30): string {
    [$r, $g, $b] = _hex_to_rgb($hex);
    return _rgb_to_hex($r + $amount, $g + $amount, $b + $amount);
}

/** Zwraca '#fff' lub '#000' zależnie od jasności tła (kontrast WCAG). */
function color_contrast_text(string $hex): string {
    [$r, $g, $b] = _hex_to_rgb($hex);
    // Luminancja wg WCAG
    $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $lum > 0.55 ? '#111827' : '#ffffff';
}

/** Kolor z przezroczystością jako rgba(). */
function color_rgba(string $hex, float $alpha = 1.0): string {
    [$r, $g, $b] = _hex_to_rgb($hex);
    return "rgba($r,$g,$b,$alpha)";
}

// ── Load org branding ───────────────────────────────────────────────────────

function branding_load(): array {
    $sidebar  = org_setting('sidebar_color')   ?: '#1e293b';
    $primary  = org_setting('volunteer_color') ?: '#2563eb';
    $logo     = org_setting('org_logo');
    $org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

    // Validate hex (fallback to safe defaults)
    if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $sidebar)) $sidebar = '#1e293b';
    if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $primary))  $primary = '#2563eb';

    $logo_path = $logo ? (dirname(__DIR__) . '/assets/logo/' . $logo) : '';
    $logo_url  = ($logo && file_exists($logo_path))
        ? APP_URL . '/assets/logo/' . $logo
        : '';

    return compact('sidebar', 'primary', 'logo_url', 'org_name');
}

/**
 * Emituje blok <style> z CSS custom properties wyliczonymi z ustawień org.
 * Wywołaj wewnątrz <head>.
 */
function branding_css(array $b): void {
    $s  = $b['sidebar'];
    $p  = $b['primary'];
    echo '<style>:root{';
    // Panel (lewa strona)
    echo '--bp:'         . $s . ';';
    echo '--bp-dark:'    . color_darken($s, 25) . ';';
    echo '--bp-darker:'  . color_darken($s, 50) . ';';
    echo '--bp-light:'   . color_lighten($s, 15) . ';';
    echo '--bp-text:'    . color_contrast_text($s) . ';';
    echo '--bp-muted:'   . color_rgba($b['sidebar'], 0.55) . ';';
    echo '--bp-subtle:'  . color_rgba($b['sidebar'], 0.12) . ';';
    // Primary (przycisk, focus, akcent)
    echo '--c:'          . $p . ';';
    echo '--c-dark:'     . color_darken($p, 20) . ';';
    echo '--c-darker:'   . color_darken($p, 40) . ';';
    echo '--c-light:'    . color_lighten($p, 40) . ';';
    echo '--c-text:'     . color_contrast_text($p) . ';';
    echo '--c-ring:'     . color_rgba($p, 0.18) . ';';
    echo '--c-bg:'       . color_rgba($p, 0.07) . ';';
    echo '}';

    // Klasy pomocnicze używane w stronach logowania
    echo '
.btn-brand{background:var(--c);color:var(--c-text);border:none;border-radius:.5rem;
  padding:.72rem 1.25rem;font-size:.94rem;font-weight:600;width:100%;
  transition:background .15s,box-shadow .15s,transform .1s;}
.btn-brand:hover:not(:disabled){background:var(--c-dark);box-shadow:0 2px 10px var(--c-ring);transform:translateY(-1px);}
.btn-brand:disabled{opacity:.5;cursor:not-allowed;}
.form-control:focus,.code-input:focus{border-color:var(--c)!important;box-shadow:0 0 0 3px var(--c-ring)!important;}
.login-left{background:linear-gradient(155deg,var(--c-darker) 0%,var(--c) 55%,var(--c-light) 100%);color:var(--c-text,#fff);}
</style>';
}
