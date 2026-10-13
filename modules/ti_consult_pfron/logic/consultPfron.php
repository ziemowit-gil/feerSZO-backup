<?php
/**
 * modules/ti_consult_pfron/logic/consultPfron.php — umowy PFRON (Aktywny Samorząd) w trybie Konsultacje.
 *
 * Przeniesione z karty30/clients/view.php#pfron (zapis umowy, plan godzin zdalnie/stacjonarnie, status rozliczenia
 * terminu). Tabela k30_pfron_contracts bez zmian; zapis przez k30_pfron_contract_save() z jawnym created_by
 * (w panelu nie ma sesji SZO — current_user() jest puste). Podpis, dokumenty PDF i portal beneficjenta zostają
 * w karty30/pfron/* (linki stąd). Terminy PFRON = k30_schedules z billing_type='pfron' (TiBookings).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/includes/pfron.php';
require_once dirname(__DIR__, 3) . '/modules/ti_test_accounts/logic/testFilter.php';

final class TiConsultPfron
{
    /** Lista umów: q (beneficjent / numer), status, kończące się (days). */
    public static function list(array $f): array
    {
        $w = [ti_test_client_sql('p.client_id')]; $par = [];
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(c.name LIKE ? OR p.contract_number LIKE ?)'; array_push($par, "%$q%", "%$q%"); }
        if (isset(K30_PFRON_CONTRACT_STATUSES[$f['status'] ?? ''])) { $w[] = 'p.status = ?'; $par[] = $f['status']; }
        if ((int)($f['days'] ?? 0) > 0) { $w[] = "p.status = 'active' AND p.valid_to <> '' AND p.valid_to IS NOT NULL AND DATE(p.valid_to) <= DATE('now', ?)"; $par[] = '+' . (int)$f['days'] . ' days'; }
        return db_all("SELECT p.*, c.name AS client_name, c.phone AS client_phone,
                              (SELECT COUNT(*) FROM k30_schedules s WHERE s.pfron_contract_id = p.id AND s.billing_type = 'pfron' AND s.status NOT IN ('cancelled','rejected')) AS bookings,
                              (SELECT COUNT(*) FROM k30_schedules s WHERE s.pfron_contract_id = p.id AND s.billing_type = 'pfron' AND s.pfron_status = 'pending' AND s.status = 'attended') AS to_submit
                         FROM k30_pfron_contracts p JOIN k30_clients c ON c.id = p.client_id
                        WHERE " . implode(' AND ', $w) . " ORDER BY p.status = 'active' DESC, p.valid_to, c.name COLLATE NOCASE LIMIT 300", $par);
    }

    public static function get(int $id): ?array
    {
        return db_one("SELECT p.*, c.name AS client_name, c.pesel AS client_pesel, c.phone AS client_phone FROM k30_pfron_contracts p JOIN k30_clients c ON c.id = p.client_id WHERE p.id = ?", [$id]) ?: null;
    }

    /** Terminy rozliczane z tej umowy (ze statusem rozliczenia PFRON). */
    public static function bookings(int $id): array
    {
        return db_all("SELECT s.id, s.start_time, s.duration_minutes, s.status, s.pfron_status, s.free_hours, s.resource_id, u.name AS consultant
                         FROM k30_schedules s LEFT JOIN users u ON u.id = s.assigned_to
                        WHERE s.pfron_contract_id = ? AND s.billing_type = 'pfron' ORDER BY s.start_time DESC", [$id]);
    }

    /** Zapis umowy z formularza. @return array{ok:bool, id:int, errors:string[]} */
    public static function save(array $p, ?int $id, int $uid): array
    {
        $e = [];
        $client = (int)($p['client_id'] ?? 0);
        if (!$client || !db_one("SELECT 1 FROM k30_clients WHERE id=?", [$client])) $e[] = 'Wybierz beneficjenta.';
        $cn = trim((string)($p['contract_number'] ?? ''));
        if ($cn === '') $e[] = 'Numer umowy PFRON jest wymagany.';
        $limit = max(0, (float)str_replace(',', '.', (string)($p['hours_limit'] ?? '0')));
        $planOn = !empty($p['hours_plan_enabled']);
        $pr = max(0, (float)str_replace(',', '.', (string)($p['planned_hours_remote'] ?? '0')));
        $po = max(0, (float)str_replace(',', '.', (string)($p['planned_hours_onsite'] ?? '0')));
        if ($planOn && $limit > 0 && $pr + $po > $limit) $e[] = sprintf('Suma godzin planu (%s) przekracza limit umowy (%s h).', number_format($pr + $po, 2, ',', ''), number_format($limit, 2, ',', ''));
        foreach (['valid_from', 'valid_to'] as $k) if (($p[$k] ?? '') !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$p[$k])) $e[] = 'Nieprawidłowa data ważności.';
        if ($e) return ['ok' => false, 'id' => (int)$id, 'errors' => $e];
        $data = ['client_id' => $client, 'contract_number' => mb_substr($cn, 0, 100), 'hours_limit' => $limit,
                 'valid_from' => ($p['valid_from'] ?? '') ?: null, 'valid_to' => ($p['valid_to'] ?? '') ?: null,
                 'status' => isset(K30_PFRON_CONTRACT_STATUSES[$p['status'] ?? '']) ? (string)$p['status'] : 'active',
                 'notes' => trim((string)($p['notes'] ?? '')), 'hours_plan_enabled' => $planOn ? 1 : 0,
                 'planned_hours_remote' => $planOn ? $pr : 0, 'planned_hours_onsite' => $planOn ? $po : 0];
        if ($id) { k30_pfron_contract_save($data, $id); return ['ok' => true, 'id' => $id, 'errors' => []]; }
        $newId = (int)db_insert('k30_pfron_contracts', $data + ['created_by' => $uid ?: null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true, 'id' => $newId, 'errors' => []];
    }

    /** Status rozliczenia PFRON terminu (do złożenia / złożone / zatwierdzone / odrzucone / do ponownego złożenia). */
    public static function setBookingStatus(int $scheduleId, string $status): bool
    {
        if (!isset(K30_PFRON_STATUSES[$status])) return false;
        db()->prepare("UPDATE k30_schedules SET pfron_status=?, updated_at=datetime('now') WHERE id=? AND billing_type='pfron'")->execute([$status, $scheduleId]);
        return true;
    }

    /** Usunięcie umowy — tylko bez terminów. @return string '' = usunięto, inaczej powód */
    public static function delete(int $id): string
    {
        if (db_one("SELECT 1 FROM k30_schedules WHERE pfron_contract_id=? LIMIT 1", [$id])) return 'Umowa ma rozliczone terminy — zmień jej status na „Zamknięta” zamiast usuwać.';
        db()->prepare("DELETE FROM k30_pfron_contracts WHERE id=?")->execute([$id]);
        return '';
    }

    /** Podsumowanie godzin umowy: limit, wykorzystane (zdalnie/stacjonarnie), zostało. */
    public static function hours(array $c): array
    {
        $m = k30_pfron_hours_used_by_mode((int)$c['id']);
        $used = (float)$c['hours_used'];
        return ['limit' => (float)$c['hours_limit'], 'used' => $used, 'remote' => $m['remote'], 'onsite' => $m['onsite'], 'left' => max(0, (float)$c['hours_limit'] - $used),
                'plan_remote' => (float)($c['planned_hours_remote'] ?? 0), 'plan_onsite' => (float)($c['planned_hours_onsite'] ?? 0), 'plan' => !empty($c['hours_plan_enabled'])];
    }
}
