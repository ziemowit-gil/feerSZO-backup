<?php
/**
 * modules/panel_metro/lib/panelMetro.php — wspólny zestaw METRO dla Panelu współpracownika.
 *
 * Ten sam język wizualny co System Tożsamości (tozsamosc/index.php, wzór metroui.org.ua):
 * kafle Start (.mt-grid/.mt-tile), zakładki „pivot” (.mt-pivot), grupy (.mt-group),
 * wiersze usług z paskiem koloru (.mt-svc), zero zaokrągleń i cieni, Segoe UI.
 * Różnice świadome: paleta przyciemniona do WCAG AA (biały tekst ≥ 4,5:1), akcent
 * = per-user --vol-color (personalizacja panelu), tryby jasny/ciemny/wysoki kontrast
 * z tokenów --tz-* (panel/includes/pv_styles.php). Bez Tailwinda i Alpine — czysty CSS
 * + mały skrypt pivota, więc działa w każdej powłoce panelu.
 *
 * Użycie: panelMetroAssets() raz na stronę, potem markup z klasami mt-* w kontenerze .pvm.
 * Pivot: <div class="mt-pivot" data-pvm-pivot role="tablist"> z przyciskami
 * [role=tab][data-tab=klucz] + panele <section data-pvm-panel="klucz">; dowolny element
 * z [data-pvm-go="klucz"] przełącza zakładkę; #klucz w adresie otwiera ją od razu.
 */

/** Paleta kafli (wszystkie z białym tekstem ≥ 4,5:1). */
function panelMetroPalette(): array {
    return [
        'blue'    => '#0f6fa8',
        'green'   => '#007a00',
        'orange'  => '#c25100',
        'red'     => '#a20025',
        'teal'    => '#00807e',
        'violet'  => '#6a00ff',
        'magenta' => '#d80073',
        'indigo'  => '#0050ef',
        'dark'    => '#1d1d1d',
        'grey'    => '#5f5f5f',
    ];
}

/** Kolor z palety po nazwie albo surowy kolor CSS (np. var(--pvm-acc)). */
function panelMetroColor(string $c): string {
    return panelMetroPalette()[$c] ?? $c;
}

/** Kolor tekstu na tle $hex: biały, gdy kontrast ≥ 4,5:1, inaczej prawie czarny. */
function panelMetroOnColor(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) return '#ffffff';
    $lum = 0.0;
    foreach ([0 => .2126, 2 => .7152, 4 => .0722] as $i => $w) {
        $c = hexdec(substr($hex, $i, 2)) / 255;
        $lum += $w * ($c <= .03928 ? $c / 12.92 : (($c + .055) / 1.055) ** 2.4);
    }
    return (1.05 / ($lum + .05)) >= 4.5 ? '#ffffff' : '#111111';
}

/** Emituje CSS + skrypt pivota (raz na żądanie). $accent = kolor panelu użytkownika. */
function panelMetroAssets(string $accent = '#0f6fa8'): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $accentOn = panelMetroOnColor($accent);
    include dirname(__DIR__) . '/view/assets.php';
}

/**
 * Kafel Start. $t: href, icon, label, [sub], [val], [tag], [color], [size: ''|'w'|'l'],
 * [go: klucz zakładki], [external: bool], [aria].
 */
function panelMetroTile(array $t): void {
    $size  = ($t['size'] ?? '') !== '' ? ' mt-tile--' . $t['size'] : '';
    $color = panelMetroColor((string)($t['color'] ?? 'blue'));
    $attrs = '';
    if (!empty($t['go']))       $attrs .= ' data-pvm-go="' . h($t['go']) . '"';
    if (!empty($t['external'])) $attrs .= ' target="_blank" rel="noopener noreferrer"';
    $aria = $t['aria'] ?? trim(($t['label'] ?? '') . (isset($t['val']) && $t['val'] !== '' ? ': ' . $t['val'] : '')
        . (!empty($t['sub']) ? ' — ' . $t['sub'] : '') . (!empty($t['tag']) ? ' (' . $t['tag'] . ')' : '')
        . (!empty($t['external']) ? ' (otwiera się w nowej karcie)' : ''));
    ?>
    <a href="<?= h($t['href'] ?? '#') ?>" class="mt-tile<?= $size ?>" style="--c:<?= h($color) ?>"<?= $attrs ?> aria-label="<?= h($aria) ?>">
      <?php if (!empty($t['tag'])): ?><span class="tag" aria-hidden="true"><?= h((string)$t['tag']) ?></span><?php endif; ?>
      <i class="bi <?= h($t['icon'] ?? 'bi-square') ?> ic" aria-hidden="true"></i>
      <span aria-hidden="true">
        <?php if (isset($t['val']) && $t['val'] !== ''): ?><span class="val"><?= h((string)$t['val']) ?></span><?php endif; ?>
        <span class="lbl"><?= h($t['label'] ?? '') ?></span>
        <?php if (!empty($t['sub'])): ?><span class="sub"><?= h($t['sub']) ?></span><?php endif; ?>
      </span>
    </a>
    <?php
}

/**
 * Czy konto to dydaktyk TI (prowadzący, kierownik, kadra dydaktyki)? Celowo węziej niż
 * kafel launchera: sam odczyt karty30 z roli (np. viewer) nie wystarcza — liczy się
 * admin, flaga k30_consultant, rola panelu (k30_ti_panel_roles), zapis karty30 z roli
 * albo indywidualne uprawnienie karty30 (user_permissions).
 */
function panelMetroIsDydaktyk(array $u): bool {
    $uid = (int)($u['id'] ?? 0);
    if ($uid <= 0) return false;
    if (($u['role'] ?? '') === 'admin') return true;
    try {
        $row = db_one("SELECT k30_consultant FROM users WHERE id=?", [$uid]);
        if (!empty($row['k30_consultant'])) return true;
    } catch (\Throwable $e) {}
    try {
        if (db_one("SELECT 1 AS x FROM k30_ti_panel_roles WHERE user_id=? AND COALESCE(role,'')<>''", [$uid])) return true;
    } catch (\Throwable $e) {}
    if (function_exists('role_permissions') && !empty(role_permissions((string)($u['role'] ?? ''))['karty30']['can_write'])) return true;
    if (function_exists('user_permissions')) {
        $up = user_permissions($uid)['karty30'] ?? [];
        if (!empty($up['can_read']) || !empty($up['can_write'])) return true;
    }
    return false;
}

/**
 * Kafel „Dydaktyka – panel” — tylko dla dydaktyków (panelMetroIsDydaktyk). Wejście przez
 * mostek karty30/ti/dydaktyk/office_enter.php (jak w launcherze) — bez ponownego logowania.
 */
function panelMetroDydaktykTile(): void {
    $u = function_exists('current_user') ? (current_user() ?? []) : [];
    if (!panelMetroIsDydaktyk($u)) return;
    panelMetroTile(['href'=>APP_URL.'/karty30/ti/dydaktyk/office_enter.php','icon'=>'bi-easel2','label'=>'Dydaktyka – panel',
                    'sub'=>'moje zajęcia, grupy i oceny','color'=>'orange','size'=>'w']);
}

/**
 * Siatka kafli z listy pozycji (format kafelków panelu: href, icon, label, sub, badge, count).
 * Kolory przydzielane po kolei z $colors; liczba „badge” > 0 = znacznik „N do uwagi”.
 */
function panelMetroTileGrid(array $items, string $ariaLabel, array $colors = ['teal','blue','green','violet','orange','indigo','magenta','red','dark']): void {
    if (!$items) return;
    echo '<nav class="mt-grid" aria-label="' . h($ariaLabel) . '">';
    foreach (array_values($items) as $i => $it) {
        $badge = (int)($it['badge'] ?? 0);
        panelMetroTile([
            'href'     => $it['href'],
            'icon'     => $it['icon'] ?? 'bi-square',
            'label'    => $it['label'],
            'sub'      => $it['sub'] ?? '',
            'val'      => !empty($it['count']) ? (string)(int)$it['count'] : '',
            'tag'      => $badge ? ($badge > 99 ? '99+' : (string)$badge) . ' do uwagi' : '',
            'color'    => $it['color'] ?? $colors[$i % count($colors)],
            'size'     => $it['size'] ?? '',
            'external' => !empty($it['external']),
        ]);
    }
    echo '</nav>';
}

/** Blok „Ważne informacje” (komunikaty admina w trybie 'important'). Nic nie emituje, gdy brak. */
function panelMetroImportant(int $userId, string $role): void {
    if (!function_exists('ann_panel_important')) {
        require_once dirname(__DIR__, 3) . '/includes/notifications.php';
    }
    try { notif_migrate(); $items = ann_panel_important($userId, $role); } catch (\Throwable $e) { $items = []; }
    if (!$items) return;
    include dirname(__DIR__) . '/view/important.php';
}
