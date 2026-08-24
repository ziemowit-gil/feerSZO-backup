<?php
/**
 * includes/crm_macros.php — szybkie akcje („makra") w pasku CRM.
 *
 * Katalog jest wspólny dla całego systemu, ale KAŻDY UŻYTKOWNIK sam wybiera,
 * które akcje ma przypięte jako przyciski w pasku — reszta zostaje w rozwijanym
 * menu „Nowe". Wybór trzyma tabela `user_macros` (użytkownik + klucz + kolejność).
 *
 * Akcja opisana jest przez:
 *   key    — identyfikator zapisywany w bazie
 *   label  — etykieta przycisku
 *   title  — podpowiedź: co dokładnie zrobi kliknięcie
 *   icon   — ikona Bootstrap Icons
 *   color  — kolor ikony
 *   kind   — 'quick' (otwiera okno szybkiego tworzenia) albo 'link' (przejście)
 *   href   — dla 'link'
 *   type   — dla 'quick': typ przekazywany do crm/api/quick_create.php
 *   can    — callback uprawnień
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS user_macros (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            macro_key TEXT    NOT NULL,
            position  INTEGER NOT NULL DEFAULT 0,
            UNIQUE(user_id, macro_key)
        )");
    } catch (\Throwable $e) {}
})();

/** Domyślny zestaw dla kogoś, kto niczego nie wybrał. */
const CRM_MACROS_DEFAULT = ['new_contact', 'new_case', 'new_task'];

/** Pełny katalog dostępnych akcji (już przefiltrowany po uprawnieniach). */
function crm_macros_catalog(): array {
    $crm_w   = function_exists('can_write') && (can_write('crm') || is_admin());
    $tasks_w = function_exists('can_write') && (can_write('tasks') || is_admin());

    $all = [
        'new_contact' => [
            'key' => 'new_contact', 'label' => 'Nowy kontakt', 'icon' => 'bi-person-plus', 'color' => '#0176D3',
            'title' => 'Dodaj kartotekę kontaktu — wystarczy nazwa, resztę uzupełnisz później',
            'kind' => 'quick', 'type' => 'contact', 'can' => $crm_w,
        ],
        'new_note' => [
            'key' => 'new_note', 'label' => 'Nowa notatka', 'icon' => 'bi-sticky', 'color' => '#B45309',
            'title' => 'Zapisz notatkę przy kontakcie — np. ustalenia z rozmowy telefonicznej',
            'kind' => 'quick', 'type' => 'note', 'can' => $crm_w,
        ],
        'new_case' => [
            'key' => 'new_case', 'label' => 'Nowa sprawa', 'icon' => 'bi-briefcase', 'color' => '#1D4ED8',
            'title' => 'Załóż sprawę CRM dla kontaktu — do prowadzenia tematu od zapytania do finału',
            'kind' => 'quick', 'type' => 'case', 'can' => $crm_w,
        ],
        'new_task' => [
            'key' => 'new_task', 'label' => 'Nowe zadanie', 'icon' => 'bi-check2-square', 'color' => '#2E844A',
            'title' => 'Dorzuć zadanie do swojego obszaru w module Zadań',
            'kind' => 'quick', 'type' => 'task', 'can' => $tasks_w,
        ],
        'new_offer' => [
            'key' => 'new_offer', 'label' => 'Nowa oferta', 'icon' => 'bi-file-earmark-text', 'color' => '#7C3AED',
            'title' => 'Otwórz kreator oferty (działalność odpłatna)',
            'kind' => 'link', 'href' => '/crm/offers/form.php', 'can' => $crm_w,
        ],
        'inbox' => [
            'key' => 'inbox', 'label' => 'Skrzynka', 'icon' => 'bi-inbox', 'color' => '#0F766E',
            'title' => 'Przejdź do Skrzynki CRM — nowe wiadomości',
            'kind' => 'link', 'href' => '/crm/inbox.php', 'can' => true,
        ],
        'communicate' => [
            'key' => 'communicate', 'label' => 'Wyślij wiadomość', 'icon' => 'bi-send', 'color' => '#0176D3',
            'title' => 'Otwórz moduł Komunikacji — e-mail lub SMS do wybranych kontaktów',
            'kind' => 'link', 'href' => '/crm/communicate.php', 'can' => $crm_w,
        ],
    ];

    return array_filter($all, static fn($m) => !empty($m['can']));
}

/** Klucze akcji przypiętych przez użytkownika (z zachowaniem kolejności). */
function crm_macros_user(?int $user_id = null): array {
    $uid = $user_id ?? (int)(current_user()['id'] ?? 0);
    if ($uid <= 0) return [];

    try {
        $rows = db_all("SELECT macro_key FROM user_macros WHERE user_id=? ORDER BY position, id", [$uid]);
    } catch (\Throwable $e) { $rows = []; }

    $keys = array_column($rows, 'macro_key');
    if (!$keys) $keys = CRM_MACROS_DEFAULT;          // pierwszy raz — sensowny zestaw startowy

    $catalog = crm_macros_catalog();
    return array_values(array_filter($keys, static fn($k) => isset($catalog[$k])));
}

/**
 * Zapisuje wybór użytkownika. Pusta lista = brak przypiętych przycisków
 * (menu „Nowe" zostaje), dlatego zapisujemy wtedy znacznik pustki.
 */
function crm_macros_save(array $keys, ?int $user_id = null): bool {
    $uid = $user_id ?? (int)(current_user()['id'] ?? 0);
    if ($uid <= 0) return false;

    $catalog = crm_macros_catalog();
    $keys    = array_values(array_unique(array_filter(
        array_map('strval', $keys),
        static fn($k) => isset($catalog[$k])
    )));

    try {
        db()->prepare("DELETE FROM user_macros WHERE user_id=?")->execute([$uid]);
        if (!$keys) {
            // '-' nie istnieje w katalogu, więc odczyt da pustą listę zamiast domyślnej
            db()->prepare("INSERT INTO user_macros (user_id, macro_key, position) VALUES (?, '-', 0)")->execute([$uid]);
            return true;
        }
        $ins = db()->prepare("INSERT INTO user_macros (user_id, macro_key, position) VALUES (?, ?, ?)");
        foreach ($keys as $i => $k) $ins->execute([$uid, $k, $i]);
        return true;
    } catch (\Throwable $e) {
        error_log('[crm_macros_save] ' . $e->getMessage());
        return false;
    }
}
