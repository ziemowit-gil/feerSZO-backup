<?php
/**
 * modules/directory_profile/lib/directoryProfile.php — kompletność profilu w Katalogu
 * współpracowników (/katalog) i wymóg jego uzupełnienia.
 *
 * Funkcjonalność współdzielona: używają jej pulpit SZO (index.php), pulpit Panelu
 * współpracownika (panel/includes/pv_home.php), edycja profilu (directory/profile_edit.php)
 * i ustawienia (admin/profile_fields.php).
 *
 * Ustawienia (tabela settings):
 *   dir_profile_mode  — off | remind | require
 *       off     — nic nie wymuszamy (kafel na pulpicie pokazuje tylko stan),
 *       remind  — na pulpicie stały baner „Uzupełnij profil” do skutku,
 *       require — pulpit przekierowuje do edycji profilu, dopóki brakuje wymaganych pól.
 *   dir_profile_items — JSON lista wymaganych elementów: avatar, bio, phone, skills, field_{id}.
 *
 * Zdjęcie czekające na moderację liczy się jako uzupełnione — nie blokujemy osoby,
 * która zrobiła swoje i czeka na administratora.
 */

const DIR_PROFILE_MODES = ['off' => 'Wyłączony', 'remind' => 'Przypomnienie na pulpicie', 'require' => 'Wymagane (blokuje pulpit)'];
const DIR_PROFILE_BASE_ITEMS = [
    'avatar' => 'Zdjęcie profilowe',
    'bio'    => 'O mnie (opis)',
    'phone'  => 'Numer telefonu',
    'skills' => 'Umiejętności',
];

function directoryProfileMode(): string {
    $mode = function_exists('org_setting') ? org_setting('dir_profile_mode') : '';
    return isset(DIR_PROFILE_MODES[$mode]) ? $mode : 'off';
}

/** Wszystkie elementy, które administrator może oznaczyć jako wymagane (klucz => etykieta). */
function directoryProfileAvailableItems(): array {
    $items = DIR_PROFILE_BASE_ITEMS;
    try {
        foreach (directory_get_field_defs() as $fd) {
            $items['field_' . (int)$fd['id']] = (string)$fd['label'];
        }
    } catch (\Throwable $e) {}
    return $items;
}

/** Wymagane elementy (tylko istniejące/aktywne). Domyślnie: zdjęcie + opis. */
function directoryProfileRequiredItems(): array {
    $raw = function_exists('org_setting') ? org_setting('dir_profile_items') : '';
    $list = $raw !== '' ? json_decode($raw, true) : ['avatar', 'bio'];
    if (!is_array($list)) $list = ['avatar', 'bio'];
    $avail = directoryProfileAvailableItems();
    return array_values(array_filter($list, fn($k) => is_string($k) && isset($avail[$k])));
}

function directoryProfileSaveSettings(string $mode, array $items): void {
    $mode  = isset(DIR_PROFILE_MODES[$mode]) ? $mode : 'off';
    $avail = directoryProfileAvailableItems();
    $items = array_values(array_unique(array_filter($items, fn($k) => is_string($k) && isset($avail[$k]))));
    org_setting_set('dir_profile_mode', $mode);
    org_setting_set('dir_profile_items', json_encode($items));
}

/** Czy element jest uzupełniony w profilu z directory_get_profile(). */
function directoryProfileItemFilled(array $person, string $key): bool {
    switch ($key) {
        case 'avatar': return trim((string)($person['avatar_file'] ?? '')) !== '' || trim((string)($person['avatar_pending_file'] ?? '')) !== '';
        case 'bio':    return trim((string)($person['bio'] ?? '')) !== '';
        case 'phone':  return trim((string)($person['phone_number'] ?? '')) !== '';
        case 'skills': return !empty($person['skills_array']);
    }
    if (str_starts_with($key, 'field_')) {
        return trim((string)($person['field_values'][(int)substr($key, 6)] ?? '')) !== '';
    }
    return true;
}

/**
 * Stan profilu: ['required'=>[k=>label], 'missing'=>[k=>label], 'done'=>int, 'total'=>int,
 * 'pct'=>int, 'complete'=>bool, 'mode'=>string]. Bez wymaganych pól → kompletny.
 */
function directoryProfileStatus(int $userId, ?array $person = null): array {
    $mode  = directoryProfileMode();
    $avail = directoryProfileAvailableItems();
    $req   = [];
    foreach (directoryProfileRequiredItems() as $k) $req[$k] = $avail[$k];
    $out = ['required' => $req, 'missing' => [], 'done' => 0, 'total' => count($req), 'pct' => 100, 'complete' => true, 'mode' => $mode];
    if ($userId <= 0) return $out;
    if ($person === null) {
        try { directory_migrate(); $person = directory_get_profile($userId); } catch (\Throwable $e) { $person = null; }
    }
    if (!$person) return $out;
    foreach ($req as $k => $label) {
        if (directoryProfileItemFilled($person, $k)) $out['done']++;
        else $out['missing'][$k] = $label;
    }
    $out['pct']      = $out['total'] ? (int)round($out['done'] * 100 / $out['total']) : 100;
    $out['complete'] = !$out['missing'];
    return $out;
}

/**
 * Wymóg na pulpicie: w trybie „require” przekierowuje do edycji profilu, gdy czegoś brakuje.
 * Wołać PRZED wysłaniem nagłówków strony. Administratorzy i podgląd roli są zwolnieni
 * (dostają baner), żeby nie zablokować sobie systemu.
 */
function directoryProfileEnforce(array $user): ?array {
    if (directoryProfileMode() === 'off') return null;
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) return null;
    require_once dirname(__DIR__, 3) . '/includes/directory.php';
    $st = directoryProfileStatus($uid);
    if ($st['complete']) return $st;
    $exempt = (function_exists('is_admin') && is_admin()) || !empty($_SESSION['_admin_original']);
    if ($st['mode'] === 'require' && !$exempt && !headers_sent()) {
        header('Location: ' . APP_URL . '/directory/profile_edit.php?wymagane=1');
        exit;
    }
    return $st;
}
