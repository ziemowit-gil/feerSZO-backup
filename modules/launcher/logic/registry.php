<?php
/**
 * modules/launcher/logic/registry.php — jedno źródło prawdy o modułach w launcherze.
 *
 * Do 2026-09-29 były DWA rozjeżdżające się launchery: inline w includes/header.php
 * ($_sw_items, 3 układy) i includes/module_switcher.php (dropdown Bootstrapa,
 * uprawnienia per rola z admin/module_perms.php) używany przez nagłówki CRM,
 * Zadań, Dydaktyki, Poczty, Katalogu, Wydarzeń i Strategii. Ten rejestr zasila
 * oba miejsca (i portal/admina), więc lista, kolejność, kolory i widoczność są
 * wszędzie te same.
 *
 * Klucze (`key`) są trwałe — tabela msw_role_perms trzyma je per rola. Nowy moduł
 * = jedna pozycja w launcherCatalog(); klucz dopisz raz i nie zmieniaj.
 */

if (!function_exists('current_user')) require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';

/** Widoczność modułu dla roli bieżącego użytkownika (tabela msw_role_perms). */
function launcherRoleVisible(string $key): bool {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $u = current_user();
        if (($u['role'] ?? '') === 'admin') { $cache = ['*' => true]; }
        else {
            try {
                foreach (db_all("SELECT module_key, visible FROM msw_role_perms WHERE role=?", [$u['role'] ?? '']) as $r)
                    $cache[$r['module_key']] = (bool)$r['visible'];
            } catch (\Throwable $e) {}
        }
    }
    if (isset($cache['*'])) return true;
    return $cache[$key] ?? true;          // brak wpisu = widoczny
}

/**
 * Pełny katalog modułów (bez filtrowania) — także dla admin/module_perms.php.
 * `check` = warunek dostępu (null = zawsze), `match` = fragmenty URI aktywności,
 * `unmatch` = fragmenty wykluczające, `desc` = podpis na dużych kaflach.
 */
function launcherCatalog(): array {
    $app = APP_URL;
    return [
        // ── Praca i umowy ────────────────────────────────────────────────────
        ['key'=>'szo',        'sec'=>'Praca i umowy',      'label'=>'SZO',            'desc'=>'Umowy, rejestry, biuro',
         'icon'=>'bi-building',           'mc'=>'#2563eb','mb'=>'#eff6ff','url'=>"$app/index.php",
         'check'=>fn()=>can_edit(), 'match'=>[], 'fallback'=>true],
        ['key'=>'wol',        'sec'=>'Praca i umowy',      'label'=>'Wolontariusze',  'desc'=>'Porozumienia i zgłoszenia',
         'icon'=>'bi-heart-fill',         'mc'=>'#e11d48','mb'=>'#fff1f2','url'=>"$app/contracts/wolontariat/list.php",
         'check'=>fn()=>can_edit() && module_enabled('contract_wolontariat'), 'match'=>['/contracts/wolontariat/']],
        ['key'=>'actions',    'sec'=>'Praca i umowy',      'label'=>'Działania',      'desc'=>'Projekty i wydarzenia organizacji',
         'icon'=>'bi-calendar-event',     'mc'=>'#0891b2','mb'=>'#ecfeff','url'=>"$app/strategy/actions/index.php",
         'check'=>fn()=>can_edit(), 'match'=>['/strategy/actions/','/actions/']],
        ['key'=>'grants',     'sec'=>'Praca i umowy',      'label'=>'Granty',         'desc'=>'Dotacje i sprawozdania',
         'icon'=>'bi-cash-coin',          'mc'=>'#15803d','mb'=>'#f0fdf4','url'=>"$app/grants/index.php",
         'check'=>fn()=>can_edit(), 'match'=>['/grants/']],
        ['key'=>'strategy',   'sec'=>'Praca i umowy',      'label'=>'Strategia',      'desc'=>'Cele i sfery pożytku',
         'icon'=>'bi-bullseye',           'mc'=>'#7c3aed','mb'=>'#f5f3ff','url'=>"$app/strategy/index.php",
         'check'=>fn()=>can_read('umowy') || is_admin(), 'match'=>['/strategy/'], 'unmatch'=>['/strategy/actions/']],
        ['key'=>'reports',    'sec'=>'Praca i umowy',      'label'=>'Raporty',        'desc'=>'Statystyki i zestawienia',
         'icon'=>'bi-bar-chart-line',     'mc'=>'#0284c7','mb'=>'#f0f9ff','url'=>"$app/reports/index.php",
         'check'=>fn()=>can_edit() && module_enabled('reports_enabled'), 'match'=>['/reports/']],
        ['key'=>'events',     'sec'=>'Praca i umowy',      'label'=>'Wydarzenia',     'desc'=>'Kalendarz i zapisy',
         'icon'=>'bi-calendar-event-fill','mc'=>'#7c3aed','mb'=>'#f5f3ff','url'=>"$app/events/dashboard.php",
         'check'=>fn()=>can_edit() && module_enabled('events_enabled'), 'match'=>['/events/']],
        ['key'=>'poczta',     'sec'=>'Praca i umowy',      'label'=>'Poczta',         'desc'=>'Skanowanie korespondencji',
         'icon'=>'bi-envelope-fill',      'mc'=>'#1d4ed8','mb'=>'#eff6ff','url'=>"$app/poczta/dashboard.php",
         'check'=>fn()=>can_edit() && module_enabled('poczta_enabled'), 'match'=>['/poczta/']],
        ['key'=>'ezd',        'sec'=>'Praca i umowy',      'label'=>'Wirtualne biurko','desc'=>'EZD — koszulki, pisma, RPW',
         'icon'=>'bi-building-gear',      'mc'=>'#dc2626','mb'=>'#fef2f2','url'=>"$app/ezd/index.php",
         'check'=>fn()=>module_enabled('ezd_enabled') && (can_read('ezd') || can_write('ezd')), 'match'=>['/ezd/']],
        // ── Relacje i ludzie ─────────────────────────────────────────────────
        ['key'=>'crm',        'sec'=>'Relacje i ludzie',   'label'=>'CRM',            'desc'=>'Kontakty, sprawy, kampanie',
         'icon'=>'bi-diagram-2-fill',     'mc'=>'#16a34a','mb'=>'#f0fdf4','url'=>"$app/crm/dashboard.php",
         'check'=>fn()=>module_enabled('crm_enabled') && can_read('crm'), 'match'=>['/crm/','/mobilna/']],
        ['key'=>'directory',  'sec'=>'Relacje i ludzie',   'label'=>'Katalog',        'desc'=>'Książka telefoniczna',
         'icon'=>'bi-person-lines-fill',  'mc'=>'#4338ca','mb'=>'#eef2ff','url'=>"$app/directory/",
         'check'=>null, 'match'=>['/directory/']],
        ['key'=>'panel',      'sec'=>'Relacje i ludzie',   'label'=>'Panel wolontariusza','desc'=>'Moje umowy, godziny, dokumenty',
         'icon'=>'bi-person-heart',       'mc'=>'#be185d','mb'=>'#fdf2f8','url'=>"$app/panel/index.php",
         'check'=>fn()=>function_exists('user_has_volunteer_panel') && user_has_volunteer_panel(), 'match'=>['/panel/']],
        // ── Dydaktyka ────────────────────────────────────────────────────────
        ['key'=>'k30',        'sec'=>'Dydaktyka',          'label'=>'Dydaktyka',      'desc'=>'Zajęcia TI, beneficjenci, wizyty',
         'icon'=>'bi-card-checklist',     'mc'=>'#c2410c','mb'=>'#fff7ed','url'=>"$app/karty30/index.php",
         'check'=>fn()=>can_read('karty30'), 'match'=>['/karty30/'], 'unmatch'=>['/karty30/ti/dydaktyk']],
        ['key'=>'rozliczenia','sec'=>'Dydaktyka',          'label'=>'Rozliczenia',    'desc'=>'Saldo grup i wynagrodzenia',
         'icon'=>'bi-cash-stack',         'mc'=>'#0f766e','mb'=>'#f0fdfa','url'=>"$app/rozliczenia/index.php",
         'check'=>fn()=>can_read('karty30'), 'match'=>['/rozliczenia/']],
        ['key'=>'dydaktyk',   'sec'=>'Dydaktyka',          'label'=>'Panel dydaktyka','desc'=>'Moje zajęcia i oceny',
         'icon'=>'bi-easel2',             'mc'=>'#2563eb','mb'=>'#eff6ff','url'=>"$app/karty30/ti/dydaktyk/index.php",
         'check'=>function () {
             if (can_read('karty30')) return false;
             try { return !empty(db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)(current_user()['id'] ?? 0)])['k30_consultant']); }
             catch (\Throwable $e) { return false; }
         }, 'match'=>['/karty30/ti/dydaktyk']],
        ['key'=>'szkolenia',  'sec'=>'Dydaktyka',          'label'=>'Szkolenia',      'desc'=>'Rezerwacja terminu szkolenia',
         'icon'=>'bi-calendar2-check',    'mc'=>'#7c3aed','mb'=>'#f5f3ff','url'=>"$app/szkolenia/index.php",
         'check'=>function () {
             if (!function_exists('tidycal_enabled')) { @require_once dirname(__DIR__, 3) . '/includes/tidycal.php'; }
             return function_exists('tidycal_enabled') && tidycal_enabled() && (can_read('szkolenia') || is_admin());
         }, 'match'=>['/szkolenia/']],
        // ── Obsługa i zgłoszenia ─────────────────────────────────────────────
        ['key'=>'tasks',      'sec'=>'Obsługa i zgłoszenia','label'=>'Zadania',       'desc'=>'Tablice, listy, obszary',
         'icon'=>'bi-kanban',             'mc'=>'#ea580c','mb'=>'#fff7ed','url'=>"$app/tasks/dashboard.php",
         'check'=>fn()=>module_enabled('tasks_enabled'), 'match'=>['/tasks/'], 'unmatch'=>['/admin/']],
        ['key'=>'helpdesk',   'sec'=>'Obsługa i zgłoszenia','label'=>'Helpdesk',      'desc'=>'Zgłoszenia IT i wsparcie',
         'icon'=>'bi-ticket-perforated',  'mc'=>'#b45309','mb'=>'#fffbeb','url'=>"$app/helpdesk/index.php",
         'check'=>fn()=>module_enabled('helpdesk_enabled'), 'match'=>['/helpdesk/']],
        ['key'=>'obiegi',     'sec'=>'Obsługa i zgłoszenia','label'=>'Obiegi',        'desc'=>'Procesy i akceptacje',
         'icon'=>'bi-diagram-2',          'mc'=>'#0369a1','mb'=>'#f0f9ff','url'=>"$app/obiegi/index.php",
         'check'=>fn()=>can_edit() && module_enabled('obiegi_enabled'), 'match'=>['/obiegi/']],
        ['key'=>'rodo',       'sec'=>'Obsługa i zgłoszenia','label'=>'RODO',          'desc'=>'Upoważnienia i rejestry',
         'icon'=>'bi-shield-lock',        'mc'=>'#475569','mb'=>'#f8fafc','url'=>"$app/rodo/index.php",
         'check'=>fn()=>can_edit(), 'match'=>['/rodo/']],
        // ── Administracja ────────────────────────────────────────────────────
        ['key'=>'tozsamosc',  'sec'=>'Administracja',      'label'=>'Tożsamość',      'desc'=>'Konto, hasło, dostępy',
         'icon'=>'bi-person-vcard-fill',  'mc'=>'#4f46e5','mb'=>'#eef2ff','url'=>"$app/tozsamosc/index.php",
         'check'=>null, 'match'=>['/tozsamosc/']],
        ['key'=>'admin',      'sec'=>'Administracja',      'label'=>'Admin',          'desc'=>'Ustawienia, użytkownicy, moduły',
         'icon'=>'bi-gear-fill',          'mc'=>'#1e293b','mb'=>'#f1f5f9','url'=>"$app/admin/index.php",
         'check'=>fn()=>is_admin(), 'match'=>['/admin/'], 'unmatch'=>['/admin/certificates','/admin/timesheets','/admin/messages','/admin/terminations','/admin/shipments','/admin/onboarding']],
    ];
}

/** Plakietki (liczniki) przy modułach — z rejestru menu, każde w try/catch. */
function launcherBadges(): array {
    static $b = null;
    if ($b !== null) return $b;
    $b = [];
    try {
        if (!function_exists('_menu_counts')) require_once dirname(__DIR__, 3) . '/includes/menu.php';
        $c = _menu_counts();
        if (!empty($c['hd']))    $b['helpdesk'] = (int)$c['hd'];
        if (!empty($c['obieg'])) $b['obiegi']   = (int)$c['obieg'];
        if (!empty($c['rek']))   $b['wol']      = (int)$c['rek'];
        if (!empty($c['adm']))   $b['admin']    = (int)$c['adm'];
    } catch (\Throwable $e) {}
    return $b;
}

/** Który klucz jest aktywny dla bieżącego URI (najdłuższe dopasowanie wygrywa). */
function launcherActiveKey(array $catalog, string $uri): string {
    $best = ''; $bestLen = -1;
    foreach ($catalog as $m) {
        foreach ($m['match'] ?? [] as $frag) {
            if ($frag === '' || !str_contains($uri, $frag)) continue;
            $ok = true;
            foreach ($m['unmatch'] ?? [] as $u) if (str_contains($uri, $u)) { $ok = false; break; }
            if ($ok && strlen($frag) > $bestLen) { $best = $m['key']; $bestLen = strlen($frag); }
        }
    }
    return $best;
}

/**
 * Pozycje launchera dla bieżącego użytkownika (widoczne, w kolejności katalogu).
 * Zwraca listę ['key','sec','label','desc','icon','mc','mb','url','on','badge'].
 * `$activeKey` wymusza aktywny moduł (nagłówki modułów podają go jawnie).
 */
function launcherItems(string $activeKey = ''): array {
    if (!current_user()) return [];
    $catalog = launcherCatalog();
    $uri     = $_SERVER['REQUEST_URI'] ?? '';
    if ($activeKey === '') $activeKey = launcherActiveKey($catalog, $uri);
    // Strony SZO bez własnego modułu (umowy, rejestry, biuro) → aktywny „SZO”.
    if ($activeKey === '' && can_edit()) $activeKey = 'szo';
    $badges  = launcherBadges();
    $out = [];
    foreach ($catalog as $m) {
        if (!launcherRoleVisible($m['key'])) continue;
        if ($m['check'] !== null && !($m['check'])()) continue;
        $out[] = [
            'key'=>$m['key'], 'sec'=>$m['sec'], 'label'=>$m['label'], 'desc'=>$m['desc'] ?? '',
            'icon'=>$m['icon'], 'mc'=>$m['mc'], 'mb'=>$m['mb'], 'url'=>$m['url'],
            'on'=>($m['key'] === $activeKey), 'badge'=>(int)($badges[$m['key']] ?? 0),
        ];
    }
    return $out;
}
