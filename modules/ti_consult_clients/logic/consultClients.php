<?php
/**
 * modules/ti_consult_clients/logic/consultClients.php — zarządzanie klientami (beneficjentami) w trybie Konsultacje.
 *
 * Kartoteka to k30_clients (ta sama co kursantów szkoleń). Ekran w Konsultacjach obejmuje osoby, które mają cokolwiek
 * w konsultacjach (rezerwacja, karta, kolejka, konto beneficjenta konsultacji) albo zostały tu dodane — bez aktywnego
 * zapisu do grupy nie pojawiają się w panelowym „Podgląd klientów” (klienci.php), stąd osobny ekran.
 * Dostęp: osoby z uprawnieniem do Konsultacji; edycja danych — tak samo (dane kontaktowe są potrzebne do rezerwacji),
 * usuwanie — tylko kadra. Każdy odczyt i edycja w rejestrze dostępu RODO (k30_access_log).
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/modules/ti_test_accounts/logic/testFilter.php';

final class TiConsultClients
{
    public const CONTACT = ['email' => 'E-mail', 'phone' => 'Telefon', 'sms' => 'SMS'];
    public const GENDER  = ['' => '—', 'K' => 'Kobieta', 'M' => 'Mężczyzna', 'N' => 'Inna / nie podano'];

    /** Statusy dostępne w formularzu (bez „_legacy”). */
    public static function statuses(): array
    {
        return array_filter(K30_CLIENT_STATUSES, fn($s) => empty($s['_legacy']));
    }

    /** Warunek SQL „osoba ma coś w konsultacjach” (alias tabeli k30_clients: c). */
    private const IN_KONS = "(EXISTS (SELECT 1 FROM k30_schedules s WHERE s.client_id = c.id)
        OR EXISTS (SELECT 1 FROM k30_consultations k WHERE k.client_id = c.id)
        OR EXISTS (SELECT 1 FROM k30_waiting_list w WHERE w.client_id = c.id)
        OR EXISTS (SELECT 1 FROM k30_ti_student_accounts a WHERE a.client_id = c.id AND COALESCE(a.simple_mode,0) = 4))";

    /** Lista: q (nazwisko / e-mail / telefon), status, zakres ('kons' = tylko z konsultacji, 'all' = cała kartoteka), test (bool — pokaż testowe). */
    public static function list(array $f, int $limit = 200): array
    {
        $w = ['1=1']; $p = [];
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = "(c.name LIKE ? OR COALESCE(c.email,'') LIKE ? OR COALESCE(c.phone,'') LIKE ?)"; array_push($p, "%$q%", "%$q%", "%$q%"); }
        if (isset(K30_CLIENT_STATUSES[$f['status'] ?? ''])) { $w[] = 'c.status = ?'; $p[] = $f['status']; }
        if (($f['zakres'] ?? 'kons') !== 'all') $w[] = self::IN_KONS;
        if (empty($f['test'])) $w[] = ti_test_client_sql('c.id');
        return db_all(
            "SELECT c.id, c.name, c.email, c.phone, c.status, c.date_of_birth,
                    (SELECT COUNT(*) FROM k30_schedules s WHERE s.client_id = c.id AND s.status IN ('preliminary','confirmed') AND s.start_time >= datetime('now')) AS future,
                    (SELECT MAX(s.start_time) FROM k30_schedules s WHERE s.client_id = c.id) AS last_booking,
                    (SELECT COUNT(*) FROM k30_consultations k WHERE k.client_id = c.id AND k.status <> 'cancelled') AS cards,
                    (SELECT 1 FROM k30_waiting_list w WHERE w.client_id = c.id AND w.status IN ('waiting','contacted') LIMIT 1) AS queued,
                    (SELECT a.login FROM k30_ti_student_accounts a WHERE a.client_id = c.id LIMIT 1) AS login,
                    (SELECT COUNT(*) FROM k30_ti_enrollments e WHERE e.client_id = c.id AND e.status = 'active') AS groups_n
               FROM k30_clients c WHERE " . implode(' AND ', $w) . " ORDER BY c.name COLLATE NOCASE LIMIT " . (int)$limit, $p);
    }

    public static function get(int $id): ?array
    {
        return db_one("SELECT * FROM k30_clients WHERE id=?", [$id]) ?: null;
    }

    /** Wszystko, co o osobie jest w konsultacjach — do karty klienta. */
    public static function profile(int $id): array
    {
        return [
            'bookings' => db_all("SELECT s.id, s.start_time, s.duration_minutes, s.status, s.billing_type, s.amount_due, u.name AS consultant FROM k30_schedules s LEFT JOIN users u ON u.id = s.assigned_to WHERE s.client_id = ? ORDER BY s.start_time DESC LIMIT 50", [$id]),
            'cards'    => db_all("SELECT co.id, co.card_no, co.consultation_datetime, co.status, co.mgr_approved_at, u.name AS consultant FROM k30_consultations co LEFT JOIN users u ON u.id = co.consultant_id WHERE co.client_id = ? AND co.status <> 'cancelled' ORDER BY co.consultation_datetime DESC LIMIT 50", [$id]),
            'queue'    => db_all("SELECT id, priority, status, reason, created_at FROM k30_waiting_list WHERE client_id = ? ORDER BY id DESC LIMIT 10", [$id]),
            'account'  => db_one("SELECT id, login, is_active, simple_mode, must_change_password, last_login, created_at FROM k30_ti_student_accounts WHERE client_id = ? LIMIT 1", [$id]) ?: null,
            'groups'   => db_all("SELECT k.name, k.simple_mode FROM k30_ti_enrollments e JOIN k30_ti_courses k ON k.id = e.course_id WHERE e.client_id = ? AND e.status = 'active' ORDER BY k.name", [$id]),
            'pfron'    => (function () use ($id) { try { return db_all("SELECT id, contract_number, hours_limit, hours_used, status, valid_to FROM k30_pfron_contracts WHERE client_id = ? ORDER BY id DESC", [$id]); } catch (\Throwable $e) { return []; } })(),
        ];
    }

    /** Dane z formularza → wiersz k30_clients (walidacja). @return array{row:array, errors:string[]} */
    public static function fromPost(array $p): array
    {
        $e = [];
        $name = trim((string)($p['name'] ?? '')); $email = trim((string)($p['email'] ?? '')); $pesel = preg_replace('/\D/', '', (string)($p['pesel'] ?? ''));
        if ($name === '') $e[] = 'Imię i nazwisko jest wymagane.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $e[] = 'Nieprawidłowy adres e-mail.';
        if ($pesel !== '' && function_exists('pesel_valid') && !pesel_valid($pesel)) $e[] = 'Nieprawidłowy PESEL.';
        $dob = trim((string)($p['date_of_birth'] ?? ''));
        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $e[] = 'Nieprawidłowa data urodzenia.';
        $status = isset(self::statuses()[$p['status'] ?? '']) ? (string)$p['status'] : 'enrolled';
        $row = [
            'name' => mb_substr($name, 0, 200), 'email' => $email ?: null, 'phone' => trim((string)($p['phone'] ?? '')) ?: null,
            'date_of_birth' => $dob ?: null, 'gender' => isset(self::GENDER[$p['gender'] ?? '']) && ($p['gender'] ?? '') !== '' ? (string)$p['gender'] : null,
            'status' => $status, 'address' => trim((string)($p['address'] ?? '')) ?: null,
            'problem' => trim((string)($p['problem'] ?? '')) ?: null, 'equipment' => trim((string)($p['equipment'] ?? '')) ?: null,
            'notes' => trim((string)($p['notes'] ?? '')) ?: null, 'consent' => !empty($p['consent']) ? 1 : 0,
            'preferred_contact_method' => isset(self::CONTACT[$p['preferred_contact_method'] ?? '']) ? (string)$p['preferred_contact_method'] : 'email',
            'available_hours' => max(0, (float)str_replace(',', '.', (string)($p['available_hours'] ?? 0))), 'pesel' => $pesel,
        ];
        return ['row' => $row, 'errors' => $e];
    }

    public static function create(array $row, int $uid): int
    {
        $id = (int)db_insert('k30_clients', $row + ['created_by' => $uid ?: null]);
        try { k30_sync_to_crm(['name' => $row['name'], 'email' => (string)$row['email'], 'phone' => (string)$row['phone']], $uid); } catch (\Throwable $e) {}
        return $id;
    }

    public static function update(int $id, array $row): void
    {
        db_update('k30_clients', $row + ['updated_at' => date('Y-m-d H:i:s')], $id);
    }

    /**
     * Usunięcie — tylko kadra i tylko osoba bez zapisów do grup, rozliczeń i zatwierdzonych kart.
     * Rezerwacje, karty robocze i wpisy kolejki kasują się kaskadowo (FK ON DELETE CASCADE w k30_clients).
     * @return string '' = usunięto, inaczej powód odmowy
     */
    public static function delete(int $id): string
    {
        if (db_one("SELECT 1 FROM k30_ti_enrollments WHERE client_id=? LIMIT 1", [$id])) return 'Osoba ma zapisy do grup — usuwa się ją z kartoteki kursantów, nie stąd.';
        try { if (db_one("SELECT 1 FROM k30_ti_billing WHERE client_id=? LIMIT 1", [$id])) return 'Osoba ma rozliczenia — nie można usunąć.'; } catch (\Throwable $e) {}
        if (db_one("SELECT 1 FROM k30_consultations WHERE client_id=? AND status='completed' LIMIT 1", [$id])) return 'Osoba ma podpisane karty konsultacji — można ją tylko oznaczyć jako „Rezygnacja” lub „Zakończył”.';
        db()->prepare("DELETE FROM k30_clients WHERE id=?")->execute([$id]);
        return '';
    }
}
