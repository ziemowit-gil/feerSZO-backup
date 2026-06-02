<?php
const CONTRACT_TYPES = [
    'zlecenie'    => 'Umowa zlecenie',
    'uslugi'      => 'Umowa o świadczenie usług',
    'wolontariat' => 'Umowa wolontariacka',
    'dzielo'      => 'Umowa o dzieło',
    'praca'       => 'Umowa o pracę',
    'inne'        => 'Inna umowa',
];

const STATUS_LABELS = [
    'projekt'      => ['label' => 'Projekt',      'class' => 'secondary'],
    'podpisana'    => ['label' => 'Podpisana',     'class' => 'primary'],
    'w realizacji' => ['label' => 'W trakcie',  'class' => 'info'],
    'zakończona'   => ['label' => 'Zakończona',    'class' => 'success'],
    'rozwiązana'   => ['label' => 'Rozwiązana',    'class' => 'warning'],
    'anulowana'    => ['label' => 'Anulowana',     'class' => 'danger'],
    'obowiązująca' => ['label' => 'Obowiązująca',  'class' => 'success'],
    'wygasła'      => ['label' => 'Wygasła',       'class' => 'secondary'],
];

function status_badge(string $status): string {
    $s = STATUS_LABELS[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
}

function date_pl(?string $d): string {
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('d.m.Y', $t) : $d;
}

function money(?float $v, string $currency = 'PLN'): string {
    if ($v === null || $v === '') return '—';
    return number_format($v, 2, ',', ' ') . ' ' . $currency;
}

function yn(mixed $v): string {
    return $v ? '<span class="text-success">Tak</span>' : '<span class="text-muted">Nie</span>';
}

/**
 * Obsługa uploadu pliku, zwraca względną ścieżkę lub null przy błędzie.
 */
function handle_upload(string $field, string $subfolder = ''): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return null;

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'docx'], true)) return null;
    if ($f['size'] > 20 * 1024 * 1024) return null;

    $dir = UPLOAD_DIR . ($subfolder ? $subfolder . '/' : '');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) return null;

    return ($subfolder ? $subfolder . '/' : '') . $name;
}

function upload_link(?string $path): string {
    if (!$path) return '—';
    $url = APP_URL . '/uploads/' . $path;
    $name = basename($path);
    return '<a href="' . h($url) . '" target="_blank" class="btn btn-sm btn-outline-secondary">'
         . '<i class="bi bi-file-earmark-pdf"></i> ' . h($name) . '</a>';
}

function paginate(int $total, int $per_page, int $page, string $url_base): array {
    $pages = max(1, (int) ceil($total / $per_page));
    $page  = max(1, min($page, $pages));
    return [
        'total'    => $total,
        'pages'    => $pages,
        'page'     => $page,
        'per_page' => $per_page,
        'offset'   => ($page - 1) * $per_page,
        'url_base' => $url_base,
    ];
}

function pagination_html(array $p): string {
    if ($p['pages'] <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $p['pages']; $i++) {
        $active = $i == $p['page'] ? ' active' : '';
        $sep = strpos($p['url_base'], '?') !== false ? '&' : '?';
        $html .= '<li class="page-item' . $active . '"><a class="page-link" href="'
               . h($p['url_base'] . $sep . 'page=' . $i) . '">' . $i . '</a></li>';
    }
    $html .= '</ul></nav>';
    return $html;
}

function flash_set(string $type, string $msg): void {
    auth_start();
    $_SESSION['flash'] = compact('type', 'msg');
}

function flash_get(): ?array {
    auth_start();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function flash_html(): string {
    $f = flash_get();
    if (!$f) return '';
    // Zamień typ Bootstrap na dane toastu
    $type = $f['type'];
    $cfg = match($type) {
        'success' => ['icon' => 'bi-check-circle-fill', 'color' => '#16A34A', 'bg' => '#F0FDF4', 'border' => '#86EFAC', 'delay' => 4000],
        'danger',
        'error'   => ['icon' => 'bi-x-circle-fill',     'color' => '#DC2626', 'bg' => '#FEF2F2', 'border' => '#FCA5A5', 'delay' => 7000],
        'warning' => ['icon' => 'bi-exclamation-triangle-fill', 'color' => '#D97706', 'bg' => '#FFFBEB', 'border' => '#FCD34D', 'delay' => 6000],
        default   => ['icon' => 'bi-info-circle-fill',  'color' => '#0284C7', 'bg' => '#F0F9FF', 'border' => '#7DD3FC', 'delay' => 4500],
    };
    $msg   = h($f['msg']);
    $icon  = $cfg['icon'];
    $color = $cfg['color'];
    $bg    = $cfg['bg'];
    $brd   = $cfg['border'];
    $delay = $cfg['delay'];
    return <<<HTML
<div id="_flash_toast_wrap" aria-live="polite" aria-atomic="true"
     style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;min-width:300px;max-width:440px">
  <div id="_flash_toast"
       role="alert" aria-live="assertive"
       style="background:{$bg};border:1px solid {$brd};border-radius:12px;
              box-shadow:0 4px 24px rgba(0,0,0,.13);padding:.85rem 1.1rem;
              display:flex;align-items:flex-start;gap:.7rem;
              animation:_toastIn .25s cubic-bezier(.34,1.56,.64,1) both">
    <i class="bi {$icon}" style="color:{$color};font-size:1.15rem;flex-shrink:0;margin-top:.05rem"></i>
    <div style="flex:1;font-size:.88rem;color:#1E293B;line-height:1.4">{$msg}</div>
    <button type="button" onclick="document.getElementById('_flash_toast_wrap').remove()"
            style="background:none;border:none;padding:0;color:#94A3B8;cursor:pointer;font-size:1rem;line-height:1;flex-shrink:0;margin-top:.1rem">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
</div>
<style>
@keyframes _toastIn  { from { opacity:0; transform:translateY(12px) scale(.96); } to { opacity:1; transform:none; } }
@keyframes _toastOut { from { opacity:1; transform:none; } to { opacity:0; transform:translateY(8px) scale(.97); } }
</style>
<script>
(function() {
  var delay = {$delay};
  var wrap  = document.getElementById('_flash_toast_wrap');
  if (!wrap) return;
  setTimeout(function() {
    var t = document.getElementById('_flash_toast');
    if (t) t.style.animation = '_toastOut .2s ease forwards';
    setTimeout(function() { if (wrap && wrap.parentNode) wrap.remove(); }, 220);
  }, delay);
})();
</script>
HTML;
}

function table_for_type(string $type): string {
    return 'umowy_' . $type;
}

function contract_url(string $type, int $id, string $action = 'view'): string {
    return APP_URL . "/contracts/{$type}/{$action}.php?id={$id}";
}

function org_setting(string $key): string {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key] = $r['value'] ?? '';
    }
    return $cache[$key];
}

/**
 * Sprawdza czy moduł/funkcja jest włączona.
 * Domyślnie zwraca true (włączone), jeśli klucz nie istnieje w bazie.
 */
function module_enabled(string $key): bool {
    $v = org_setting($key);
    return $v === '' || $v === '1';
}

/**
 * Przerywa wykonanie i przekierowuje na stronę główną, jeśli moduł jest wyłączony.
 */
function require_module_enabled(string $key, string $module_name = 'Ten moduł'): void {
    if (!module_enabled($key)) {
        flash_set('error', $module_name . ' jest wyłączony przez administratora.');
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

/**
 * Odczytuje datę urodzenia z numeru PESEL.
 * Obsługuje urodzonych w latach 1800–2299 (standardowe kodowanie miesiąca).
 * Zwraca string 'YYYY-MM-DD' lub null gdy PESEL jest nieprawidłowy.
 */
function pesel_to_birthdate(string $pesel): ?string {
    $pesel = preg_replace('/\D/', '', $pesel);
    if (strlen($pesel) !== 11) return null;
    $y = (int)substr($pesel, 0, 2);
    $m = (int)substr($pesel, 2, 2);
    $d = (int)substr($pesel, 4, 2);
    if ($m >= 81)      { $y += 1800; $m -= 80; }
    elseif ($m >= 61)  { $y += 2200; $m -= 60; }
    elseif ($m >= 41)  { $y += 2100; $m -= 40; }
    elseif ($m >= 21)  { $y += 2000; $m -= 20; }
    else               { $y += 1900; }
    if ($m < 1 || $m > 12 || $d < 1 || $d > 31) return null;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function get_opiekun_initials(string $opiekun): string {
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z'];
    $clean = strtr($opiekun, $map);
    $words = preg_split('/\s+/', trim($clean));
    $initials = implode('', array_map(
        fn($w) => mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8')),
        array_filter($words)
    ));
    return $initials ?: 'XX';
}

function suggest_nr_rejestru(string $opiekun = ''): string {
    $year = date('Y');
    // Policz istniejące nr_rejestru we wszystkich tabelach
    $n = 0;
    foreach (array_keys(CONTRACT_TYPES) as $slug) {
        try {
            $row = db_one("SELECT COUNT(*) AS c FROM " . table_for_type($slug)
                        . " WHERE nr_rejestru IS NOT NULL AND nr_rejestru != ''");
            $n += (int)($row['c'] ?? 0);
        } catch (\Exception $e) {}
    }
    $n++;
    return sprintf('RU/%04d/%s/%s', $n, $year, get_opiekun_initials($opiekun));
}

function assign_nr_rejestru(array &$data): void {
    if (empty($data['nr_rejestru'])) {
        $data['nr_rejestru'] = suggest_nr_rejestru($data['opiekun'] ?? $data['opiekun_przelozony'] ?? '');
    }
}

/**
 * Generuje przycisk usuwania dla uniwersalnego handlera /delete.php.
 *
 * @param string $table     Nazwa tabeli (musi być w konfiguracji delete.php)
 * @param int    $id        ID rekordu
 * @param string $confirm   Etykieta w oknie confirm (np. nazwa rekordu)
 * @param string $redirect  URL powrotu (opcjonalnie; domyślnie konfiguracja handlera)
 */
function delete_btn(string $table, int $id, string $confirm = '', string $redirect = ''): string {
    $csrf = csrf_token();
    $app  = APP_URL;
    $lbl  = $confirm ? addslashes($confirm) : 'ten rekord';
    $red  = htmlspecialchars($redirect, ENT_QUOTES);
    return <<<HTML
<form method="post" action="{$app}/delete.php" class="d-inline"
      onsubmit="return confirm('Usunąć {$lbl}?')">
  <input type="hidden" name="_csrf"    value="{$csrf}">
  <input type="hidden" name="table"    value="{$table}">
  <input type="hidden" name="id"       value="{$id}">
  <input type="hidden" name="redirect" value="{$red}">
  <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń">
    <i class="bi bi-trash"></i>
  </button>
</form>
HTML;
}

/**
 * Wymaga weryfikacji kodu IKA przed wyświetleniem strony.
 *
 * Jeśli użytkownik ma rolę admin/editor i nie zweryfikował IKA w tej sesji
 * (lub weryfikacja wygasła), przekierowuje na stronę bramy IKA.
 *
 * Wywołaj ją po require_login()/require_role() i PRZED include header.php.
 *
 * @param string $return_url URL powrotu po weryfikacji (domyślnie bieżący URL)
 * @param int    $ttl        Czas ważności weryfikacji w sekundach (domyślnie 1800 = 30 min)
 */
function ika_require(string $return_url = '', int $ttl = 1800): void {
    if (!function_exists('current_user')) return;
    $user = current_user();
    if (!$user) return;
    $role = $user['role'] ?? '';

    // Weryfikacja IKA wymagana dla:
    //  - admin, editor, crm_user — dostęp do operacji krytycznych / CRM
    //  - k30_consultant (dowolna rola) — przetwarza dane osobowe w Kartach 30
    $requires_ika = in_array($role, ['admin', 'editor', 'crm_user'], true);

    if (!$requires_ika) {
        // Sprawdź czy doradca K30 (przetwarza dane osobowe — wymaga IKA)
        try {
            $row = db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)$user['id']]);
            $requires_ika = !empty($row['k30_consultant']);
        } catch (\Throwable $e) {
            // Kolumna nie istnieje jeszcze — nie blokuj
        }
    }

    if (!$requires_ika) return;

    // Sprawdź token IKA w sesji
    if (!isset($_SESSION)) session_start();
    $ts = (int)($_SESSION['_ika_ts'] ?? 0);

    if ($ts > 0 && (time() - $ts) < $ttl) {
        // Sesja jeszcze ważna — sprawdź czy admin jej nie unieważnił w DB
        try {
            $rev = db_one("SELECT ika_revoked_at FROM users WHERE id = ?", [(int)$user['id']]);
            $revoked_at = $rev['ika_revoked_at'] ?? null;
            if ($revoked_at && strtotime($revoked_at) > $ts) {
                // Admin unieważnił sesję po jej utworzeniu — wymuś ponowną weryfikację
                unset($_SESSION['_ika_ts']);
                // przepuść poniżej do redirect
            } else {
                return; // sesja ważna i nie unieważniona
            }
        } catch (\Throwable $e) {
            return; // kolumna jeszcze nie istnieje lub brak DB — przepuszczamy
        }
    }

    // Usuń wygasły/unieważniony token
    unset($_SESSION['_ika_ts']);

    // Ustal URL powrotu (bieżący URL jeśli nie podano)
    if ($return_url === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $return_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    header('Location: ' . APP_URL . '/contracts/ika_gate.php?to=' . urlencode($return_url));
    exit;
}

/**
 * Ustawia token IKA w sesji (wywoływane po poprawnej weryfikacji na bramie).
 */
function ika_set_verified(): void {
    if (!isset($_SESSION)) session_start();
    $_SESSION['_ika_ts'] = time();
}

/**
 * Czyści token IKA z sesji (wywoływane po wylogowaniu lub żądaniu ponownej weryfikacji).
 */
function ika_clear(): void {
    unset($_SESSION['_ika_ts']);
}

function next_contract_number(string $type): string {
    $table = table_for_type($type);
    $year  = date('Y');
    $row   = db_one("SELECT COUNT(*) AS cnt FROM {$table} WHERE numer_umowy LIKE ?", ["%/{$year}%"]);
    $n     = ($row['cnt'] ?? 0) + 1;
    $prefix = strtoupper(substr($type, 0, 3));
    return sprintf('%s/%04d/%s', $prefix, $n, $year);
}

// Umowy techniczne (wspolpraca_przed_2026) nie mają numeru — nie trafiają do RU.
