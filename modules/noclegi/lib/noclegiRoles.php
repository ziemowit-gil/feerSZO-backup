<?php
/**
 * modules/noclegi/lib/noclegiRoles.php — role modułu Noclegi (osobne od ról SZO i uprawnień user_permissions).
 *
 *  • Użytkownik — każde aktywne konto: własne zgłoszenia, podgląd rezerwacji, instrukcja zameldowania.
 *  • Koordynator Projektu — akceptacja kosztów zgłoszeń przypisanych do jego budżetów (nocl_user_roles.budget_id; 0 = wszystkie).
 *  • Logistyk / Administrator — obiekty, pokoje, rooming, faktury, role, import z TI. Administrator SZO = Logistyk.
 * Nadawanie ról: ekran „Role” modułu (Logistyk) lub Administrator SZO.
 */
const NOCL_ROLE_RANK = ['uzytkownik' => 1, 'koordynator' => 2, 'logistyk' => 3];

function noclUid(?int $uid = null): int {
    return $uid ?? (int)(current_user()['id'] ?? 0);
}

function noclIsAdminUid(int $uid): bool {
    if ($uid <= 0) return false;
    if ($uid === (int)(current_user()['id'] ?? -1)) return is_admin();
    $u = db_one("SELECT role FROM users WHERE id = ? AND is_active = 1", [$uid]);
    return $u && ($u['role'] ?? '') === 'admin';
}

/** Najwyższa rola modułu użytkownika: logistyk | koordynator | uzytkownik | '' (brak konta). */
function noclRole(?int $uid = null): string {
    $uid = noclUid($uid);
    if ($uid <= 0) return '';
    if (noclIsAdminUid($uid)) return 'logistyk';
    $best = 'uzytkownik';
    try {
        foreach (db_all("SELECT role FROM nocl_user_roles WHERE user_id = ?", [$uid]) as $r) {
            if ((NOCL_ROLE_RANK[$r['role']] ?? 0) > NOCL_ROLE_RANK[$best]) $best = $r['role'];
        }
    } catch (\Throwable $e) {}
    return $best;
}
function noclIsLogistyk(?int $uid = null): bool { return noclRole($uid) === 'logistyk'; }
function noclIsKoordynator(?int $uid = null): bool { return (NOCL_ROLE_RANK[noclRole($uid)] ?? 0) >= 2; }

/** Budżety koordynatora: null = wszystkie, lista id = wskazane (logistyk: wszystkie, użytkownik: []). */
function noclCoordBudgets(?int $uid = null): ?array {
    $uid = noclUid($uid);
    $role = noclRole($uid);
    if ($role === 'logistyk') return null;
    if ($role !== 'koordynator') return [];
    $ids = [];
    foreach (db_all("SELECT budget_id FROM nocl_user_roles WHERE user_id = ? AND role = 'koordynator'", [$uid]) as $r) {
        if ((int)$r['budget_id'] === 0) return null;
        $ids[] = (int)$r['budget_id'];
    }
    return $ids;
}

function noclCoordCovers(int $uid, ?int $budgetId): bool {
    $b = noclCoordBudgets($uid);
    if ($b === null) return true;
    return $budgetId !== null && $budgetId > 0 && in_array($budgetId, $b, true);
}

function noclRoleAssign(int $userId, string $role, int $budgetId, int $by): bool {
    if (!isset(NOCL_ROLE_RANK[$role]) || $role === 'uzytkownik' || $userId <= 0) return false;
    if ($role === 'logistyk') $budgetId = 0;
    db()->prepare("INSERT OR IGNORE INTO nocl_user_roles (user_id, role, budget_id, created_by, created_at) VALUES (?,?,?,?,?)")
        ->execute([$userId, $role, max(0, $budgetId), $by ?: null, date('Y-m-d H:i:s')]);
    return true;
}
function noclRoleRevoke(int $rowId): void { db()->prepare("DELETE FROM nocl_user_roles WHERE id = ?")->execute([$rowId]); }
function noclRolesList(): array {
    return db_all("SELECT r.id, r.user_id, r.role, r.budget_id, u.name, u.email, b.code AS budget_code, b.name AS budget_name
                     FROM nocl_user_roles r LEFT JOIN users u ON u.id = r.user_id LEFT JOIN nocl_budgets b ON b.id = r.budget_id
                    ORDER BY CASE r.role WHEN 'logistyk' THEN 0 ELSE 1 END, u.name");
}

/**
 * Bramka „Dop. noclegi”: moduł działa dla osoby tylko wtedy, gdy ma aktywną umowę (wolontariat / zlecenie) ze znacznikiem
 * „Dop. noclegi” (umowy_*.dop_noclegi = 1), której okres obejmuje dziś. Konto łączymy z umową po users.id
 * (zlecenie: ti_instructor_user_id, m365_user_id) albo po adresie e-mail. Koordynator i Logistyk są wolni od bramki
 * (obsługują cudze zgłoszenia), administrator SZO jest Logistykiem.
 */
function noclEligible(?int $uid = null): bool {
    $uid = noclUid($uid);
    if ($uid <= 0) return false;
    if (noclRole($uid) !== 'uzytkownik') return true;
    return noclContractFor($uid) !== null;
}

/** Umowa dająca dostęp: ['type'=>'wolontariat'|'zlecenie','id'=>…] albo null. */
function noclContractFor(int $uid): ?array {
    $u = db_one("SELECT email FROM users WHERE id = ? AND is_active = 1", [$uid]);
    if (!$u) return null;
    $email = mb_strtolower(trim((string)$u['email']));
    if (!function_exists('contractStatusSqlIn')) require_once dirname(__DIR__, 2) . '/contract_status/lib/contractStatuses.php';
    $st = contractStatusSqlIn(['active']);
    $today = date('Y-m-d');
    $dates = "AND (data_zakonczenia IS NULL OR data_zakonczenia = '' OR data_zakonczenia >= ?) AND (data_rozpoczecia IS NULL OR data_rozpoczecia = '' OR data_rozpoczecia <= ?)";
    try {
        $w = db_one("SELECT id FROM umowy_wolontariat WHERE dop_noclegi = 1 AND $st $dates AND (CAST(m365_user_id AS TEXT) = CAST(? AS TEXT) OR (? != '' AND lower(email) = ?)) ORDER BY id DESC LIMIT 1", [$today, $today, $uid, $email, $email]);
        if ($w) return ['type' => 'wolontariat', 'id' => (int)$w['id']];
        $z = db_one("SELECT id FROM umowy_zlecenie WHERE dop_noclegi = 1 AND $st $dates AND (ti_instructor_user_id = CAST(? AS INTEGER) OR (? != '' AND lower(email) = ?)) ORDER BY id DESC LIMIT 1", [$today, $today, $uid, $email, $email]);
        if ($z) return ['type' => 'zlecenie', 'id' => (int)$z['id']];
    } catch (\Throwable $e) { error_log('noclegi: bramka umowy — ' . $e->getMessage()); }
    return null;
}
