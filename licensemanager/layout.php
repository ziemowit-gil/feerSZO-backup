<?php
/**
 * licensemanager/layout.php — helper renderowania layoutu.
 */

function lm_head(string $title, string $active = ''): void {
    $base = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $css  = rtrim($base, '/') . '/assets/style.css';
    echo '<!DOCTYPE html><html lang="pl"><head>';
    echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . lm_h($title) . ' — License Manager</title>';
    echo '<link rel="stylesheet" href="' . lm_h($css) . '">';
    echo '</head><body>';
    echo '<div class="lm-shell">';
    // Sidebar
    $nav = [
        'index.php'    => ['icon' => '⚡', 'label' => 'Dashboard'],
        'licenses.php' => ['icon' => '🔑', 'label' => 'Licencje'],
        'new.php'      => ['icon' => '➕', 'label' => 'Nowa licencja'],
        'log.php'      => ['icon' => '📋', 'label' => 'Log zdarzeń'],
    ];
    echo '<aside class="lm-sidebar">';
    echo '<div class="lm-brand"><div class="lm-brand-title">🛡 License Manager</div><div class="lm-brand-sub">Platforma NGO</div></div>';
    echo '<ul class="lm-nav">';
    foreach ($nav as $file => $item) {
        $href    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . $file;
        $current = basename($_SERVER['SCRIPT_NAME']) === $file;
        $cls     = ($active === $file || $current) ? ' active' : '';
        echo '<li><a href="' . lm_h($href) . '" class="' . trim($cls) . '">' . $item['icon'] . ' ' . lm_h($item['label']) . '</a></li>';
    }
    echo '<li><div class="lm-nav-sep"></div></li>';
    echo '<li><a href="' . lm_h(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/index.php?logout=1') . '">🚪 Wyloguj</a></li>';
    echo '</ul>';
    echo '<div class="lm-footer">v1.0 · LicenseManager</div>';
    echo '</aside>';
    echo '<main class="lm-content">';
}

function lm_foot(): void {
    echo '</main></div>';
    echo '<script>
    function copyText(text, btn) {
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.textContent;
            btn.textContent = "✓ Skopiowano";
            setTimeout(function() { btn.textContent = orig; }, 2000);
        });
    }
    </script>';
    echo '</body></html>';
}

function lm_flash_set(string $type, string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['lm_flash'] = ['type' => $type, 'msg' => $msg];
}

function lm_flash_html(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['lm_flash'])) return '';
    $f = $_SESSION['lm_flash'];
    unset($_SESSION['lm_flash']);
    $cls = $f['type'] === 'success' ? 'lm-alert-success' : ($f['type'] === 'info' ? 'lm-alert-info' : 'lm-alert-danger');
    return '<div class="lm-alert ' . $cls . '">' . lm_h($f['msg']) . '</div>';
}

function lm_status_badge(string $status): string {
    $map = [
        'active'  => ['badge-active',  'Aktywna'],
        'trial'   => ['badge-trial',   'Trial'],
        'expired' => ['badge-expired', 'Wygasła'],
        'revoked' => ['badge-revoked', 'Odwołana'],
        'pending' => ['badge-pending', 'Oczekuje'],
    ];
    [$cls, $label] = $map[$status] ?? ['badge-pending', $status];
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
}

function lm_days_left(string $expires_at): int {
    return (int)ceil((strtotime($expires_at) - time()) / 86400);
}
