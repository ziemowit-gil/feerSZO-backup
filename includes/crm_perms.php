<?php
/**
 * includes/crm_perms.php — zaawansowane uprawnienia CRM (per ROLA).
 *
 * Dotąd CRM miał jeden przełącznik na cały moduł: can_read('crm') / can_write('crm').
 * Praktyka jest inna: ktoś ma prowadzić kontakty, ale nie widzieć darowizn; ktoś ma
 * pisać maile, ale nie eksportować bazy; ktoś ma widzieć kartotekę, ale bez PESEL-u.
 *
 * Dwa poziomy, oba per rola:
 *
 *   1. OBSZARY (crm_role_perms) — sekcje modułu: kontakty, sprawy, oferty, skrzynka,
 *      kampanie, darowizny, faktury, import, eksport, ustawienia. Poziomy:
 *      none < read < write < delete. Poziom „delete" zawiera „write", ten „read".
 *
 *   2. POLA KARTOTEKI (crm_field_perms) — podgląd i edycja pojedynczych pól,
 *      z osobnym oznaczeniem pól wrażliwych (PESEL, data urodzenia, notatka).
 *
 * ZASADA WSTECZNEJ ZGODNOŚCI: dopóki dla roli nie zapisano ŻADNEJ reguły, obowiązuje
 * dotychczasowe zachowanie (can_read/can_write('crm')). Włączenie modułu uprawnień
 * niczego nie odbiera samo z siebie — odbiera dopiero świadomy zapis reguł.
 *
 * Administrator systemu (is_admin) omija te reguły. Inaczej dałoby się zablokować
 * dostęp do ekranu, na którym reguły się zmienia.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_role_perms (
            role  TEXT NOT NULL,
            area  TEXT NOT NULL,
            level TEXT NOT NULL DEFAULT 'none',
            PRIMARY KEY (role, area)
        )");
    } catch (\Throwable $e) {}
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_field_perms (
            role  TEXT NOT NULL,
            field TEXT NOT NULL,
            can_view INTEGER NOT NULL DEFAULT 1,
            can_edit INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (role, field)
        )");
    } catch (\Throwable $e) {}
})();

/** Poziomy dostępu do obszaru, od najsłabszego. */
const CRM_PERM_LEVELS = ['none' => 'Brak', 'read' => 'Odczyt', 'write' => 'Zapis', 'delete' => 'Zapis + usuwanie'];

/** Obszary modułu CRM — jedno źródło prawdy dla ekranu uprawnień i strażników. */
function crm_perm_areas(): array {
    return [
        'contacts'  => ['label' => 'Kontakty',      'desc' => 'Kartoteka osób i organizacji'],
        'cases'     => ['label' => 'Sprawy',        'desc' => 'Sprawy, typy, SLA'],
        'offers'    => ['label' => 'Oferty',        'desc' => 'Oferty działalności odpłatnej'],
        'inbox'     => ['label' => 'Skrzynka',      'desc' => 'Poczta przychodząca i wysyłka'],
        'campaigns' => ['label' => 'Kampanie',      'desc' => 'Newslettery, masowe wysyłki, automatyzacje'],
        'donations' => ['label' => 'Darowizny',     'desc' => 'Wpłaty i dokumenty PIT'],
        'invoices'  => ['label' => 'Faktury',       'desc' => 'Wystawianie i rejestr faktur'],
        'import'    => ['label' => 'Import',        'desc' => 'Import kontaktów i podmiotów'],
        'export'    => ['label' => 'Eksport',       'desc' => 'Pobieranie danych do pliku'],
        'settings'  => ['label' => 'Ustawienia CRM','desc' => 'Statusy, szablony, konfiguracja modułu'],
    ];
}

/**
 * Pola kartoteki objęte kontrolą. `sensitive` = pole, które domyślnie warto
 * schować rolom bez potrzeby (RODO: minimalizacja dostępu, nie tylko zakresu).
 */
function crm_perm_fields(): array {
    return [
        'imie_nazwisko'  => ['label' => 'Imię i nazwisko / nazwa', 'sensitive' => false],
        'email'          => ['label' => 'E-mail',                  'sensitive' => false],
        'telefon'        => ['label' => 'Telefon',                 'sensitive' => false],
        'adres'          => ['label' => 'Adres',                   'sensitive' => true],
        'pesel'          => ['label' => 'PESEL',                   'sensitive' => true],
        'data_urodzenia' => ['label' => 'Data urodzenia',          'sensitive' => true],
        'nip'            => ['label' => 'NIP',                     'sensitive' => false],
        'regon'          => ['label' => 'REGON',                   'sensitive' => false],
        'krs'            => ['label' => 'KRS',                     'sensitive' => false],
        'organizacja'    => ['label' => 'Organizacja',             'sensitive' => false],
        'stanowisko'     => ['label' => 'Stanowisko',              'sensitive' => false],
        'notatka'        => ['label' => 'Notatka wewnętrzna',      'sensitive' => true],
        'strona_www'     => ['label' => 'Strona WWW',              'sensitive' => false],
        'branza'         => ['label' => 'Branża',                  'sensitive' => false],
    ];
}

/** Rola bieżącego użytkownika (pusta, gdy nikt nie jest zalogowany). */
function crm_perm_role(?array $user = null): string {
    $u = $user ?? (function_exists('current_user') ? current_user() : null);
    return (string)($u['role'] ?? '');
}

/** Czy dla roli zapisano JAKĄKOLWIEK regułę (jeśli nie — działa stare zachowanie). */
function crm_perms_configured(string $role): bool {
    static $cache = [];
    if (isset($cache[$role])) return $cache[$role];
    try {
        $a = (int)(db_one("SELECT COUNT(*) AS n FROM crm_role_perms WHERE role=?", [$role])['n'] ?? 0);
        $f = (int)(db_one("SELECT COUNT(*) AS n FROM crm_field_perms WHERE role=?", [$role])['n'] ?? 0);
        return $cache[$role] = ($a + $f) > 0;
    } catch (\Throwable $e) { return $cache[$role] = false; }
}

/** Mapa obszar => poziom dla roli. */
function crm_perms_for_role(string $role): array {
    static $cache = [];
    if (isset($cache[$role])) return $cache[$role];
    $out = [];
    try {
        foreach (db_all("SELECT area, level FROM crm_role_perms WHERE role=?", [$role]) as $r) {
            $out[(string)$r['area']] = (string)$r['level'];
        }
    } catch (\Throwable $e) {}
    return $cache[$role] = $out;
}

/** Porządek poziomów — do porównań „czy wystarcza". */
function _crm_perm_rank(string $level): int {
    return ['none' => 0, 'read' => 1, 'write' => 2, 'delete' => 3][$level] ?? 0;
}

/**
 * Czy bieżąca rola ma w obszarze co najmniej wskazany poziom.
 *
 * @param string $area obszar z crm_perm_areas()
 * @param string $op   read | write | delete
 */
function crm_can(string $area, string $op = 'read', ?array $user = null): bool {
    if (function_exists('is_admin') && is_admin()) return true;

    $role = crm_perm_role($user);
    if ($role === '') return false;

    // Bez skonfigurowanych reguł zostaje dotychczasowe zachowanie modułu
    if (!crm_perms_configured($role)) {
        return match ($op) {
            'delete' => function_exists('can_delete') && can_delete('crm'),
            'write'  => function_exists('can_write')  && can_write('crm'),
            default  => (function_exists('can_read') && can_read('crm'))
                        || (function_exists('can_write') && can_write('crm')),
        };
    }

    $map = crm_perms_for_role($role);
    // Obszar bez wpisu przy skonfigurowanej roli = brak dostępu (świadome zamknięcie)
    return _crm_perm_rank($map[$area] ?? 'none') >= _crm_perm_rank($op);
}

/** Strażnik strony: brak dostępu → komunikat i powrót na pulpit CRM. */
function crm_require(string $area, string $op = 'read'): void {
    if (crm_can($area, $op)) return;
    $label = crm_perm_areas()[$area]['label'] ?? $area;
    if (function_exists('flash_set')) {
        flash_set('danger', 'Twoja rola nie ma dostępu do sekcji „' . $label . '" w CRM.');
    }
    header('Location: ' . APP_URL . '/crm/dashboard.php');
    exit;
}

// ── Uprawnienia do pól kartoteki ───────────────────────────────────────────

/** Mapa pole => ['view'=>bool,'edit'=>bool] dla roli. */
function crm_field_perms_for_role(string $role): array {
    static $cache = [];
    if (isset($cache[$role])) return $cache[$role];
    $out = [];
    try {
        foreach (db_all("SELECT field, can_view, can_edit FROM crm_field_perms WHERE role=?", [$role]) as $r) {
            $out[(string)$r['field']] = ['view' => (int)$r['can_view'] === 1, 'edit' => (int)$r['can_edit'] === 1];
        }
    } catch (\Throwable $e) {}
    return $cache[$role] = $out;
}

/** Czy rola widzi pole kartoteki (brak reguły = widzi). */
function crm_field_can_view(string $field, ?array $user = null): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    $role = crm_perm_role($user);
    if ($role === '') return false;
    $p = crm_field_perms_for_role($role);
    return !isset($p[$field]) || $p[$field]['view'];
}

/** Czy rola może edytować pole (brak reguły = może, o ile ma zapis w kontaktach). */
function crm_field_can_edit(string $field, ?array $user = null): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    if (!crm_can('contacts', 'write', $user)) return false;
    $role = crm_perm_role($user);
    $p = crm_field_perms_for_role($role);
    if (!isset($p[$field])) return true;
    return $p[$field]['view'] && $p[$field]['edit'];   // nie da się edytować w ciemno
}

/**
 * Czyści rekord kontaktu z pól niedostępnych dla roli — do eksportu, API i widoków,
 * gdzie łatwiej odsiać dane raz niż pamiętać o warunku przy każdym polu.
 */
function crm_mask_contact(array $contact, ?array $user = null): array {
    if (function_exists('is_admin') && is_admin()) return $contact;
    foreach (crm_perm_fields() as $f => $_) {
        if (array_key_exists($f, $contact) && !crm_field_can_view($f, $user)) {
            $contact[$f] = null;
        }
    }
    return $contact;
}

// ── Zapis konfiguracji ──────────────────────────────────────────────────────

/** Nadpisuje komplet reguł obszarów dla roli. */
function crm_perms_save_areas(string $role, array $levels): bool {
    if ($role === '') return false;
    $areas = crm_perm_areas();
    try {
        db()->prepare("DELETE FROM crm_role_perms WHERE role=?")->execute([$role]);
        $ins = db()->prepare("INSERT INTO crm_role_perms (role, area, level) VALUES (?,?,?)");
        foreach ($levels as $area => $lvl) {
            if (!isset($areas[$area]) || !isset(CRM_PERM_LEVELS[$lvl])) continue;
            $ins->execute([$role, $area, $lvl]);
        }
        return true;
    } catch (\Throwable $e) {
        error_log('[crm_perms_save_areas] ' . $e->getMessage());
        return false;
    }
}

/** Nadpisuje komplet reguł pól dla roli. */
function crm_perms_save_fields(string $role, array $fields): bool {
    if ($role === '') return false;
    $known = crm_perm_fields();
    try {
        db()->prepare("DELETE FROM crm_field_perms WHERE role=?")->execute([$role]);
        $ins = db()->prepare("INSERT INTO crm_field_perms (role, field, can_view, can_edit) VALUES (?,?,?,?)");
        foreach ($fields as $f => $cfg) {
            if (!isset($known[$f])) continue;
            $view = !empty($cfg['view']) ? 1 : 0;
            $edit = (!empty($cfg['edit']) && $view) ? 1 : 0;
            $ins->execute([$role, $f, $view, $edit]);
        }
        return true;
    } catch (\Throwable $e) {
        error_log('[crm_perms_save_fields] ' . $e->getMessage());
        return false;
    }
}

/** Usuwa wszystkie reguły roli — powrót do zachowania sprzed konfiguracji. */
function crm_perms_reset(string $role): void {
    try {
        db()->prepare("DELETE FROM crm_role_perms WHERE role=?")->execute([$role]);
        db()->prepare("DELETE FROM crm_field_perms WHERE role=?")->execute([$role]);
    } catch (\Throwable $e) {}
}

/** Role dostępne do konfiguracji (bez administratora — ten ma zawsze pełny dostęp). */
function crm_perm_roles(): array {
    try {
        return db_all("SELECT name, display_name, description FROM roles WHERE name <> 'admin' ORDER BY sort_order, name");
    } catch (\Throwable $e) {
        return [['name' => 'editor', 'display_name' => 'Operator', 'description' => ''],
                ['name' => 'viewer', 'display_name' => 'Użytkownik', 'description' => '']];
    }
}
