<?php
/**
 * modules/org_moves/lib/orgMoves.php — przenoszenie w strukturze organizacyjnej:
 * osób między jednostkami/działami (pojedynczo i masowo) oraz działów między jednostkami.
 *
 * Dostęp: administrator i zarząd (settings.zarzad_user_ids — jak includes/byli.php).
 * Przeniesienie osoby = historyczne zamknięcie przypisania (org_member_remove) + nowe
 * przypisanie w jednostce docelowej z tym samym stanowiskiem, telefonami i „główne”;
 * funkcja kierownika NIE przechodzi. Każda zmiana trafia do historii (org_log).
 */
require_once dirname(__DIR__, 3) . '/includes/org.php';
require_once dirname(__DIR__, 2) . '/org_levels/lib/orgLevels.php';

function orgMovesCan(): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u) return false;
    $ids = array_filter(array_map('intval', explode(',', (string)org_setting('zarzad_user_ids'))));
    return in_array((int)$u['id'], $ids, true);
}

/** Aktywne jednostki wewnętrzne (id => wiersz), z poziomem. */
function orgMovesUnits(): array {
    orgLevelsMigrate();
    $out = [];
    foreach (org_units_all('active') as $u) {
        if (($u['kind'] ?? 'internal') === 'external') continue;
        $out[(int)$u['id']] = $u;
    }
    return $out;
}

/** Etykieta jednostki z jej Jednostką nadrzędną: „Dział X (Jednostka Y)”. */
function orgMovesUnitLabel(array $u, array $byId): string {
    $l = orgLevelLabel($u);
    $p = orgLevelOf($u) === 'dzial' ? orgLevelParentUnit($u, $byId) : null;
    return trim(($l ? $l . ': ' : '') . $u['name'] . ($p ? ' — ' . $p['name'] : ''));
}

/** Aktywne przypisania osób (opcjonalnie z jednej jednostki i wg frazy). */
function orgMovesMembers(int $unitId = 0, string $q = ''): array {
    $where = ["om.status='active'", "(om.valid_to IS NULL OR om.valid_to >= date('now'))", 'u.is_active=1'];
    $params = [];
    if ($unitId > 0) { $where[] = 'om.unit_id = ?'; $params[] = $unitId; }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR om.position_name LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    try {
        return db_all("SELECT om.id, om.user_id, om.unit_id, om.position_name, om.is_head, om.is_primary,
                              u.first_name, u.last_name, u.name AS user_name, u.email, ou.name AS unit_name, ou.code AS unit_code
                       FROM org_members om
                       JOIN users u ON u.id = om.user_id
                       JOIN org_units ou ON ou.id = om.unit_id
                       WHERE " . implode(' AND ', $where) . "
                       ORDER BY ou.name, u.last_name, u.first_name", $params);
    } catch (\Throwable $e) { return []; }
}

/**
 * Przenosi przypisania (org_members.id) do jednostki $target.
 * Zwraca ['moved'=>n, 'merged'=>n (osoba już była w docelowej — zamknięto tylko stare), 'skipped'=>n].
 */
function orgMovesMoveMembers(array $memberIds, int $target, int $by): array {
    $res = ['moved' => 0, 'merged' => 0, 'skipped' => 0];
    $units = orgMovesUnits();
    if (!isset($units[$target])) throw new \RuntimeException('Wybierz jednostkę lub dział docelowy.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (array_unique(array_map('intval', $memberIds)) as $mid) {
            $m = org_member_get($mid);
            if (!$m || ($m['status'] ?? '') !== 'active' || (int)$m['unit_id'] === $target
                || (!empty($m['valid_to']) && $m['valid_to'] < date('Y-m-d'))) { $res['skipped']++; continue; }
            // Ten sam warunek co w org_member_add(): przypisanie zamknięte DZIŚ (valid_to = dziś) nadal „istnieje”
            $already = db_one("SELECT id, status FROM org_members WHERE unit_id=? AND user_id=?
                               AND (valid_to IS NULL OR valid_to >= date('now')) ORDER BY id DESC LIMIT 1", [$target, (int)$m['user_id']]);
            org_member_remove($mid, $by);
            if ($already && $already['status'] === 'active') { $res['merged']++; continue; }
            if ($already) { // powrót tego samego dnia — przywróć zamknięte przypisanie
                db()->prepare("UPDATE org_members SET status='active', valid_to=NULL, is_head=0, updated_at=datetime('now') WHERE id=?")
                    ->execute([(int)$already['id']]);
                org_log('member', (int)$already['id'], 'restore', [], ['unit_id' => $target], $by, 'Przywrócono przypisanie (przeniesienie z powrotem)');
                $res['moved']++;
                continue;
            }
            org_member_add([
                'unit_id' => $target, 'user_id' => (int)$m['user_id'], 'position_id' => $m['position_id'] ?? null,
                'position_name' => $m['position_name'] ?? '', 'is_head' => 0, 'is_primary' => (int)($m['is_primary'] ?? 0),
                'email_service' => $m['email_service'] ?? '', 'phone_direct' => $m['phone_direct'] ?? '',
                'phone_mobile' => $m['phone_mobile'] ?? '', 'availability' => $m['availability'] ?? '',
                'status' => 'active', 'substitute_id' => null, 'valid_from' => date('Y-m-d'), 'valid_to' => null,
                'notes' => trim(($m['notes'] ?? '') . "\nPrzeniesiono z: " . ($units[(int)$m['unit_id']]['name'] ?? '#' . $m['unit_id']) . ' (' . date('d.m.Y') . ')'),
            ], $by);
            $res['moved']++;
        }
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    return $res;
}

/** Przenosi działy (org_units.id) pod Jednostkę $target. Zwraca ['moved'=>n, 'skipped'=>n]. */
function orgMovesMoveUnits(array $unitIds, int $target, int $by): array {
    $units = orgMovesUnits();
    if (!isset($units[$target]) || orgLevelOf($units[$target]) !== 'jednostka') throw new \RuntimeException('Wybierz Jednostkę docelową.');
    $res = ['moved' => 0, 'skipped' => 0];
    foreach (array_unique(array_map('intval', $unitIds)) as $id) {
        if (!isset($units[$id]) || $id === $target || orgLevelOf($units[$id]) !== 'dzial' || (int)$units[$id]['parent_id'] === $target) { $res['skipped']++; continue; }
        // Ochrona przed cyklem: cel nie może leżeć pod przenoszonym działem
        $p = (int)($units[$target]['parent_id'] ?? 0); $cycle = false; $seen = [];
        while ($p && !isset($seen[$p])) { $seen[$p] = 1; if ($p === $id) { $cycle = true; break; } $p = (int)($units[$p]['parent_id'] ?? 0); }
        if ($cycle) { $res['skipped']++; continue; }
        db()->prepare("UPDATE org_units SET parent_id=?, updated_at=datetime('now') WHERE id=?")->execute([$target, $id]);
        org_log('unit', $id, 'move', ['parent_id' => $units[$id]['parent_id']], ['parent_id' => $target], $by,
            'Przeniesiono dział „' . $units[$id]['name'] . '” do jednostki „' . $units[$target]['name'] . '”');
        $res['moved']++;
    }
    return $res;
}
