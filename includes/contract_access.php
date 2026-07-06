<?php
/**
 * includes/contract_access.php — Dostęp do pojedynczej umowy (dowolny typ z contracts/).
 *
 * Domyślnie (dla umów bez zdefiniowanej listy) dostęp mają wszyscy edytorzy/admini
 * z uprawnieniem can_edit() do modułu 'umowy' — dokładnie jak dotychczas. Dopiero
 * jawne dodanie wpisów do contract_access dla danej umowy zawęża dostęp WYŁĄCZNIE
 * do wskazanych osób (+ admin, który ma zawsze pełny dostęp).
 *
 * Typy ($type): 'wolontariat', 'powierzenie', 'uslugi', 'praca', 'zlecenie', 'inne', 'dzielo'.
 */

(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS contract_access (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_type TEXT    NOT NULL,
            contract_id   INTEGER NOT NULL,
            user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            added_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
            added_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(contract_type, contract_id, user_id)
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_contract_access_lookup ON contract_access(contract_type, contract_id)");
    } catch (\Throwable $e) {}
})();

/** Czy dana umowa ma jawnie zdefiniowaną (zawężoną) listę dostępu. */
function contract_has_restriction(string $type, int $contract_id): bool {
    try {
        return (bool)db_one(
            "SELECT 1 FROM contract_access WHERE contract_type=? AND contract_id=? LIMIT 1",
            [$type, $contract_id]
        );
    } catch (\Throwable $e) { return false; }
}

/**
 * Czy $user_id (domyślnie zalogowany) ma dostęp do tej konkretnej umowy.
 * Admin — zawsze tak. Umowa bez zdefiniowanej listy — dostępna dla każdego
 * z can_edit() (zachowanie sprzed tej funkcji, dotyczy istniejących umów).
 * Umowa z listą — tylko wskazane osoby.
 */
function contract_can_access(string $type, array $contract, ?int $user_id = null): bool {
    if (is_admin()) return true;
    $user_id = $user_id ?? (int)(current_user()['id'] ?? 0);
    if (!$user_id) return false;
    $cid = (int)($contract['id'] ?? 0);
    if (!$cid || !contract_has_restriction($type, $cid)) return true;
    try {
        return (bool)db_one(
            "SELECT 1 FROM contract_access WHERE contract_type=? AND contract_id=? AND user_id=?",
            [$type, $cid, $user_id]
        );
    } catch (\Throwable $e) { return true; }
}

/** Lista user_id z jawnie nadanym dostępem do umowy (puste = brak zawężenia). */
function contract_access_user_ids(string $type, int $contract_id): array {
    try {
        return array_map('intval', array_column(
            db_all("SELECT user_id FROM contract_access WHERE contract_type=? AND contract_id=?", [$type, $contract_id]),
            'user_id'
        ));
    } catch (\Throwable $e) { return []; }
}

/**
 * Ustawia listę dostępu dla umowy (nadpisuje poprzednią). Pusta tablica
 * = usuwa zawężenie (umowa znów dostępna dla wszystkich z can_edit()).
 */
function contract_access_set(string $type, int $contract_id, array $user_ids, int $by): void {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    // Odfiltruj id, które nie istnieją w users — zapobiega naruszeniu FK w pętli insertów
    // niżej (jeden nieudany execute() na współdzielonym $stmt psuje kolejne w PDO).
    if ($user_ids) {
        try {
            $ph  = implode(',', array_fill(0, count($user_ids), '?'));
            $ok  = db_all("SELECT id FROM users WHERE id IN ($ph)", $user_ids);
            $user_ids = array_map('intval', array_column($ok, 'id'));
        } catch (\Throwable $e) { $user_ids = []; }
    }
    try {
        db()->prepare("DELETE FROM contract_access WHERE contract_type=? AND contract_id=?")
            ->execute([$type, $contract_id]);
        foreach ($user_ids as $uid) {
            try {
                db()->prepare("INSERT INTO contract_access (contract_type, contract_id, user_id, added_by) VALUES (?,?,?,?)")
                    ->execute([$type, $contract_id, $uid, $by ?: null]);
            } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {}
}

/**
 * Fragment SQL do dołączenia do WHERE listy umów (AND contract_access_where(...)).
 * Admin — bez ograniczeń. Reszta — widzi umowy bez zawężenia LUB te, gdzie jest na liście.
 */
function contract_access_where(string $type, string $id_col = 'id'): string {
    if (is_admin()) return '1=1';
    $uid = (int)(current_user()['id'] ?? 0);
    $t   = db()->quote($type);
    return "($id_col NOT IN (SELECT contract_id FROM contract_access WHERE contract_type = $t)
             OR $id_col IN (SELECT contract_id FROM contract_access WHERE contract_type = $t AND user_id = $uid))";
}

/** Lista edytorów/adminów do wyboru w polu dostępu (i polu "opiekun"). */
function contract_editors_list(): array {
    try {
        return db_all(
            "SELECT id,
                    CASE WHEN first_name != '' AND last_name != '' THEN first_name || ' ' || last_name ELSE name END AS display_name
             FROM users
             WHERE role IN ('editor','admin') AND is_active = 1
             ORDER BY display_name"
        );
    } catch (\Throwable $e) { return []; }
}

/**
 * Renderuje pole formularza "Kto ma dostęp do tej umowy" — multi-select osób.
 * $name — atrybut name selecta (np. "access_users[]"), $selected — zaznaczone user_id.
 */
function contract_access_field_html(array $selected = [], string $name = 'access_users[]', string $id = 'access_users'): string {
    $editors = contract_editors_list();
    if (!$editors) return '';
    $opts = '';
    foreach ($editors as $e) {
        $sel = in_array((int)$e['id'], $selected, true) ? ' selected' : '';
        $opts .= '<option value="' . (int)$e['id'] . '"' . $sel . '>' . h($e['display_name']) . '</option>';
    }
    return '
    <div class="mb-3">
      <label class="form-label fw-semibold small" for="' . h($id) . '">Kto ma dostęp do tej umowy</label>
      <select name="' . h($name) . '" id="' . h($id) . '" class="form-select form-select-sm" multiple size="5">' . $opts . '</select>
      <div class="form-text">Zostaw puste, aby umowa była widoczna dla wszystkich uprawnionych (jak dotychczas).
        Zaznacz konkretne osoby (Ctrl/Cmd + klik), aby zawęzić dostęp tylko do nich. Administrator zawsze ma pełny dostęp.</div>
    </div>';
}
