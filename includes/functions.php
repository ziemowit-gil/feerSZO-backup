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
    'projekt'        => ['label' => 'Projekt',        'class' => 'secondary'],
    'do podpisu'     => ['label' => 'Do podpisu',     'class' => 'teal'],
    'podpisana'      => ['label' => 'Podpisana',      'class' => 'primary'],
    'w realizacji'   => ['label' => 'W realizacji',   'class' => 'info'],
    'zawieszona'     => ['label' => 'Zawieszona',     'class' => 'orange'],
    'do rozliczenia' => ['label' => 'Do rozliczenia', 'class' => 'indigo'],
    'zakończona'     => ['label' => 'Zakończona',     'class' => 'success'],
    'rozwiązana'     => ['label' => 'Rozwiązana',     'class' => 'warning'],
    'anulowana'      => ['label' => 'Anulowana',      'class' => 'danger'],
    'obowiązująca'   => ['label' => 'Obowiązująca',   'class' => 'success'],
    'wygasła'        => ['label' => 'Wygasła',        'class' => 'secondary'],
    'aneks'          => ['label' => 'Aneks',          'class' => 'purple'],
];

/** Niestandardowe kolory badge'y dla klas spoza palety kontekstowej Bootstrap. */
const STATUS_CUSTOM_COLORS = [
    'purple' => '#7c3aed',
    'teal'   => '#0d9488',
    'orange' => '#ea580c',
    'indigo' => '#6366f1',
];

/**
 * Dozwolone przejścia między statusami dla zwykłych edytorów.
 * Admini mogą ustawić dowolny status (oprócz 'aneks').
 */
const STATUS_TRANSITIONS = [
    'projekt'        => ['do podpisu', 'podpisana', 'anulowana'],
    'do podpisu'     => ['podpisana', 'anulowana'],
    'podpisana'      => ['w realizacji', 'zakończona', 'rozwiązana', 'anulowana'],
    'w realizacji'   => ['zawieszona', 'do rozliczenia', 'zakończona', 'rozwiązana'],
    'zawieszona'     => ['w realizacji', 'do rozliczenia', 'zakończona', 'rozwiązana'],
    'do rozliczenia' => ['zakończona', 'rozwiązana'],
    'obowiązująca'   => ['zakończona', 'rozwiązana'],
    'zakończona'     => [],
    'rozwiązana'     => [],
    'anulowana'      => [],
    'wygasła'        => [],
    'aneks'          => [],
];

/**
 * Zwraca listę statusów, na które można przejść z podanego statusu.
 * Admini widzą wszystkie statusy (bez 'aneks').
 */
function status_allowed_next(string $current, bool $is_admin = false): array {
    if ($is_admin) {
        return array_keys(array_filter(STATUS_LABELS, static fn($_, $k) => $k !== 'aneks', ARRAY_FILTER_USE_BOTH));
    }
    return STATUS_TRANSITIONS[$current] ?? [];
}

/** Zwraca true jeśli umowa jest zablokowana (status aneks). */
function contract_is_locked(array $row): bool {
    return ($row['status'] ?? '') === 'aneks';
}

function status_badge(string $status): string {
    $s = STATUS_LABELS[$status] ?? ['label' => $status, 'class' => 'secondary'];
    if (isset(STATUS_CUSTOM_COLORS[$s['class']])) {
        return '<span class="badge" style="background:' . STATUS_CUSTOM_COLORS[$s['class']] . '">' . htmlspecialchars($s['label']) . '</span>';
    }
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

    $result = ($subfolder ? $subfolder . '/' : '') . $name;

    // SharePoint sync — fire-and-forget; błędy nie blokują uploadu
    if (!function_exists('sp_sync_upload')) {
        @require_once __DIR__ . '/m365.php';
    }
    if (function_exists('sp_sync_upload')) {
        try { sp_sync_upload($result); } catch (\Throwable $e) {
            error_log('[SP sync] ' . $e->getMessage());
        }
    }

    return $result;
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
    // Virtual types — mapuj na prawdziwą tabelę
    if ($type === 'canva_request') return 'umowy_wolontariat';
    if ($type === 'crm_case')      return 'crm_cases';
    return 'umowy_' . $type;
}

function contract_url(string $type, int $id, string $action = 'view'): string {
    // Virtual types — mapuj na właściwy URL
    if ($type === 'canva_request') {
        return APP_URL . "/contracts/wolontariat/view.php?id={$id}#tab-m365-anchor";
    }
    if ($type === 'crm_case') {
        return APP_URL . "/crm/cases/view.php?id={$id}";
    }
    return APP_URL . "/contracts/{$type}/{$action}.php?id={$id}";
}

/**
 * Zwraca listę aktywnych przedstawicieli organizacji.
 */
function org_representatives(): array {
    try {
        return db_all("SELECT * FROM org_representatives WHERE is_active=1 ORDER BY sort_order, name");
    } catch (\Throwable $e) { return []; }
}

/**
 * Zwraca select HTML z przedstawicielami do użycia w formularzach umów.
 */
function org_representative_select(string $name = 'representative_id', ?int $selected = null, string $class = 'form-select form-select-sm'): string {
    $reps = org_representatives();
    if (!$reps) return '<input type="text" name="representative_name" class="' . h($class) . '" placeholder="Imię i nazwisko podpisującego">';
    $html = '<select name="' . h($name) . '" class="' . h($class) . '">';
    $html .= '<option value="">— wybierz podpisującego —</option>';
    foreach ($reps as $r) {
        $sel  = ($selected === (int)$r['id']) ? ' selected' : '';
        $html .= '<option value="' . (int)$r['id'] . '"' . $sel . '>'
               . h($r['name']) . ($r['title'] ? ' (' . h($r['title']) . ')' : '')
               . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/** Select HTML z przedstawicielami — wartość to name (do pola podpisujacy_fundacja). */
function org_representative_select_by_name(string $field_name, string $selected_name = '', string $class = 'form-select form-select-sm'): string {
    $reps = org_representatives();
    if (!$reps) {
        return '<input type="text" name="' . h($field_name) . '" class="' . h($class) . '" value="' . h($selected_name) . '" placeholder="Imię i nazwisko podpisującego">';
    }
    $html = '<select name="' . h($field_name) . '" class="' . h($class) . '">';
    $html .= '<option value="">— wybierz podpisującego —</option>';
    foreach ($reps as $r) {
        $sel  = ($selected_name === $r['name']) ? ' selected' : '';
        $html .= '<option value="' . h($r['name']) . '"' . $sel . '>'
               . h($r['name']) . ($r['title'] ? ' (' . h($r['title']) . ')' : '')
               . '</option>';
    }
    $html .= '</select>';
    return $html;
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

// ── System widoczności menu ───────────────────────────────────────────────────

/** Ładuje konfigurację menu z bazy (raz na request, cached statycznie). */
function _menu_config_load(): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    global $_menu_hidden, $_panel_disabled;
    $_menu_hidden    = $_menu_hidden    ?? [];
    $_panel_disabled = $_panel_disabled ?? [];
    try {
        $rows = db_all("SELECT key_, value FROM settings WHERE key_ LIKE 'menu_hide_%' OR key_ LIKE 'panel_disable_%'");
        foreach ($rows as $r) {
            if ($r['value'] !== '1') continue;
            if (str_starts_with($r['key_'], 'menu_hide_')) {
                $_menu_hidden[substr($r['key_'], 10)] = true;
            } elseif (str_starts_with($r['key_'], 'panel_disable_')) {
                $_panel_disabled[substr($r['key_'], 14)] = true;
            }
        }
    } catch (\Throwable $e) {}
}

/** Zwraca true jeśli pozycja menu admina/edytora jest widoczna. */
function menu_visible(string $key): bool {
    _menu_config_load();
    global $_menu_hidden;
    return empty($_menu_hidden[$key]);
}

/** Zwraca true jeśli pozycja panelu wolontariusza jest widoczna (nie ukryta). */
function panel_visible(string $key): bool {
    _menu_config_load();
    global $_panel_disabled;
    return empty($_panel_disabled[$key]);
}

/**
 * Wywołaj na początku strony panelu wolontariusza — redirect z komunikatem
 * jeśli moduł jest wyłączony przez admina dla wolontariuszy.
 */
function panel_require_enabled(string $key, string $module_name = 'Ta sekcja'): void {
    if (!panel_visible($key)) {
        flash_set('warning', $module_name . ' jest niedostępna dla wolontariuszy.');
        header('Location: ' . APP_URL . '/panel/index.php');
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

    // Jeśli sesja uwierzytelniona kluczem WebAuthn (FIDO2) — admin/editor są zwolnieni z IKA
    if (!empty($_SESSION['_webauthn_auth']) && in_array($role, ['admin', 'editor'], true)) {
        return;
    }

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

    // Nadpisanie per-user dla kontekstu CRM (crm_ika_required: NULL=domyślnie, 0=zwolniony, 1=wymuś)
    $uri_check = $return_url ?: ($_SERVER['REQUEST_URI'] ?? '');
    $in_crm = str_contains($uri_check, '/crm/') || str_contains($uri_check, '/crm?');
    if ($in_crm) {
        try {
            $ov = db_one("SELECT crm_ika_required FROM users WHERE id=?", [(int)$user['id']]);
            $flag = isset($ov['crm_ika_required']) && $ov['crm_ika_required'] !== null
                ? (int)$ov['crm_ika_required'] : null;
            if ($flag === 0) return;         // admin zwolnił z IKA w CRM
            if ($flag === 1) $requires_ika = true; // admin wymusił IKA w CRM
        } catch (\Throwable $e) {}
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
