<?php
declare(strict_types=1);

/**
 * modules/claude_billing/logic/LicenceCrm.php — grupa CRM „TI - płatne licencje i oprogramowanie” (polecenie 2026-11-10).
 *
 * W grupie są uczestnicy TI z płatną licencją (Claude, NordVPN: wniosek, aktywny, wstrzymany) albo z przypisanym oprogramowaniem
 * (aktywne przypisanie w module Licencje). Grupę rozpoznajemy po crm_groups.auto_source = 'ti_platne_licencje' (nazwę można zmienić w CRM).
 * Dodawanie: przy wniosku z kreatora i włączeniu przez kadrę (automatycznie), raz dla istniejących (migracja 2026-11_crm_grupa_platne_licencje)
 * i przyciskiem w panelu Płatne licencje › Ustawienia. Z grupy nikogo nie usuwamy (historia).
 *
 * Kontakt CRM uczestnika: potwierdzone powiązanie beneficjenta (crm_beneficiary_links) → PESEL → e-mail + to samo imię i nazwisko
 * (bez tego warunku rodzeństwo z e-mailem rodzica trafiałoby na kontakt rodzica) → nowy kontakt. Nowy kontakt zapisujemy BEZ automatyzacji CRM
 * i bez wysyłki do Outlooka (żadnych e-maili przy masowym przeniesieniu) i wiążemy z uczestnikiem (crm_beneficiary_links, źródło „licencje”).
 * Konta wirtualne (testowe) pomijamy.
 */
require_once __DIR__ . '/claudeBilling.php';

final class LicenceCrm
{
    public const SOURCE = 'ti_platne_licencje';
    public const NAME = 'TI - płatne licencje i oprogramowanie';

    private static function boot(): bool
    {
        try {
            require_once dirname(__DIR__, 3) . '/includes/crm.php';
            if (function_exists('crm_migrate')) crm_migrate();
            require_once dirname(__DIR__, 3) . '/includes/crm_beneficiary.php';
            if (function_exists('crm_beneficiary_migrate')) crm_beneficiary_migrate();
            return (bool)db_one("SELECT name FROM sqlite_master WHERE type='table' AND name='crm_groups'");
        } catch (\Throwable $e) { error_log('licencje crm boot: ' . $e->getMessage()); return false; }
    }

    /** Id grupy (tworzona przy pierwszym użyciu; istniejąca o tej nazwie dostaje znacznik auto_source). */
    public static function groupId(): int
    {
        $g = db_one("SELECT id FROM crm_groups WHERE auto_source=?", [self::SOURCE]);
        if ($g) return (int)$g['id'];
        $byName = db_one("SELECT id FROM crm_groups WHERE name=?", [self::NAME]);
        if ($byName) { db_exec("UPDATE crm_groups SET auto_source=?, updated_at=? WHERE id=?", [self::SOURCE, date('Y-m-d H:i:s'), (int)$byName['id']]); return (int)$byName['id']; }
        $now = date('Y-m-d H:i:s');
        return (int)db_insert('crm_groups', ['name' => self::NAME, 'auto_source' => self::SOURCE, 'color' => '#0f6fa8', 'icon' => 'bi-key-fill', 'sort_order' => 0,
            'description' => 'Uczestnicy TI z płatną licencją (Claude, NordVPN) albo przypisanym oprogramowaniem. Dodawani automatycznie z modułu Płatne licencje.',
            'created_at' => $now, 'updated_at' => $now]);
    }

    /** Kontakt CRM uczestnika (istniejący albo nowy). @return array{id:int,created:bool}|null */
    public static function contactFor(int $cid): ?array
    {
        $c = db_one("SELECT id, name, email, phone, pesel, address FROM k30_clients WHERE id=?", [$cid]);
        if (!$c || trim((string)$c['name']) === '') return null;
        try {
            $l = crm_one("SELECT l.contact_id FROM crm_beneficiary_links l JOIN crm_contacts k ON k.id=l.contact_id AND k.crm_active=1 WHERE l.client_id=? AND l.status='confirmed' ORDER BY l.id DESC LIMIT 1", [$cid]);
            if ($l) return ['id' => (int)$l['contact_id'], 'created' => false];
        } catch (\Throwable $e) {}
        $pesel = preg_replace('/\D/', '', (string)($c['pesel'] ?? ''));
        if (strlen($pesel) === 11 && ($k = db_one("SELECT id FROM crm_contacts WHERE pesel=? AND crm_active=1 LIMIT 1", [$pesel]))) return ['id' => (int)$k['id'], 'created' => false];
        $email = mb_strtolower(trim((string)$c['email']));
        if ($email !== '' && ($k = db_one("SELECT id FROM crm_contacts WHERE LOWER(TRIM(email))=? AND LOWER(TRIM(imie_nazwisko))=? AND crm_active=1 LIMIT 1", [$email, mb_strtolower(trim((string)$c['name']))]))) {
            return ['id' => (int)$k['id'], 'created' => false];
        }
        // nowy kontakt — bez crm_automation_fire i bez Outlooka (CrmManager::createContact je uruchamia)
        $name = trim((string)$c['name']);
        $parts = preg_split('/\s+/u', $name) ?: [];
        $now = date('Y-m-d H:i:s');
        $row = ['type' => 'osoba', 'status' => 'aktywny', 'imie_nazwisko' => $name, 'email' => $email !== '' ? $email : null, 'telefon' => trim((string)$c['phone']) ?: null,
                'imie' => count($parts) > 1 ? implode(' ', array_slice($parts, 0, -1)) : $name, 'nazwisko' => count($parts) > 1 ? (string)end($parts) : null,
                'pesel' => strlen($pesel) === 11 ? $pesel : null, 'adres' => trim((string)$c['address']) ?: null, 'source' => 'ti_licencje',
                'notatka' => 'Uczestnik TI (nr ' . $cid . ') — dodany z modułu Płatne licencje.',
                'avatar_initials' => class_exists('CrmManager') ? CrmManager::makeInitials($name) : mb_strtoupper(mb_substr($name, 0, 2)), 'created_at' => $now, 'updated_at' => $now];
        if (function_exists('crm_strip_null_notnull')) $row = crm_strip_null_notnull($row, 'crm_contacts');
        $id = (int)db_insert('crm_contacts', array_filter($row, fn($v) => $v !== null));
        try { crm_db()->prepare("INSERT OR IGNORE INTO crm_beneficiary_links (contact_id, client_id, status, match_source, created_at) VALUES (?,?,?,?,?)")->execute([$id, $cid, 'confirmed', 'licencje', $now]); } catch (\Throwable $e) {}
        return ['id' => $id, 'created' => true];
    }

    /** Dodaje uczestnika do grupy (bez wyjątków na zewnątrz). @return array{contact:int,created:bool,added:bool}|null */
    public static function addClient(int $cid): ?array
    {
        try {
            if (ClaudeBilling::isVirtual($cid) || !self::boot()) return null;
            $gid = self::groupId();
            $k = self::contactFor($cid);
            if (!$k) return null;
            $st = db()->prepare("INSERT OR IGNORE INTO crm_group_members (group_id, contact_id, added_by, added_at) VALUES (?,?,NULL,?)");
            $st->execute([$gid, $k['id'], date('Y-m-d H:i:s')]);
            return ['contact' => $k['id'], 'created' => $k['created'], 'added' => $st->rowCount() > 0];
        } catch (\Throwable $e) {
            error_log('licencje crm add #' . $cid . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Uczestnicy, którzy powinni być w grupie: płatne licencje (bez zakończonych i testów) + aktywne oprogramowanie z modułu Licencje. */
    public static function candidates(): array
    {
        ClaudeBilling::migrate();
        $ids = array_map('intval', array_column(db_all("SELECT DISTINCT client_id FROM claude_subscriptions WHERE is_test=0 AND status IN ('requested','active','suspended')"), 'client_id'));
        try {
            $ids = array_merge($ids, array_map('intval', array_column(db_all("SELECT DISTINCT client_id FROM k30_ti_client_licenses
                WHERE status='active' AND (expires_at IS NULL OR expires_at='' OR expires_at >= date('now'))"), 'client_id')));
        } catch (\Throwable $e) {}
        return array_values(array_unique(array_filter($ids)));
    }

    /** Przeniesienie wszystkich obecnych użytkowników do grupy. @return array{group:int,total:int,added:int,created:int,skipped:int} */
    public static function syncAll(): array
    {
        if (!self::boot()) throw new RuntimeException('CRM jest niedostępny (brak tabel grup).');
        $gid = self::groupId();
        $rep = ['group' => $gid, 'total' => 0, 'added' => 0, 'created' => 0, 'skipped' => 0];
        foreach (self::candidates() as $cid) {
            $rep['total']++;
            $r = self::addClient($cid);
            if (!$r) { $rep['skipped']++; continue; }
            if ($r['added']) $rep['added']++;
            if ($r['created']) $rep['created']++;
        }
        return $rep;
    }
}
