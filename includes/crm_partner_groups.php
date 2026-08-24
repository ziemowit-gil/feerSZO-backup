<?php
/**
 * includes/crm_partner_groups.php — systemowa grupa „Współpracownicy" z podziałem
 * na typy umów.
 *
 * Wolontariusze mają swoją grupę od dawna, a reszta osób i podmiotów, z którymi
 * mamy podpisane umowy (zlecenie, dzieło, usługi, praca, powierzenie, inne),
 * siedziała w kartotece bez żadnego przekroju. Przy wysyłce „do wszystkich
 * zleceniobiorców" trzeba było wyklikiwać kontakty ręcznie.
 *
 * Struktura, którą utrzymuje ten moduł:
 *
 *   Współpracownicy                (auto_source='wspolpracownicy')
 *   ├── Umowa zlecenie             (auto_source='wspolpracownicy_zlecenie')
 *   ├── Umowa o dzieło             (auto_source='wspolpracownicy_dzielo')
 *   ├── Umowa o świadczenie usług  (auto_source='wspolpracownicy_uslugi')
 *   └── …                          (po jednej podgrupie na typ umowy)
 *
 * Grupa nadrzędna zbiera wszystkich, podgrupy dzielą wg typu — ta sama osoba
 * z dwiema umowami trafia do dwóch podgrup i raz do nadrzędnej.
 *
 * Członkostwo wynika z AKTYWNYCH umów. „Aktywna" = status z listy aktywnych
 * i (jeśli jest data zakończenia) termin jeszcze nie minął, z karencją — dokładnie
 * tak jak przy wolontariuszach, żeby nie wyrzucać ludzi w dniu końca umowy.
 *
 * Dopasowanie kontaktu: e-mail, PESEL (osoby) albo NIP (podmioty). Sam e-mail
 * gubi tych, którzy zmienili adres.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const CRM_PARTNER_ACTIVE_STATUSES = ['projekt', 'podpisana', 'w realizacji', 'obowiązująca'];
const CRM_PARTNER_GRACE_DAYS      = 30;

/**
 * Typy umów objęte grupą „Współpracownicy".
 *
 * Umowa o świadczenie usług jest POZA grupą: to relacja z dostawcą (podmiot
 * wystawiający fakturę), a nie współpraca osobista — mieszanie jej z ludźmi
 * psuło wysyłki „do współpracowników".
 * Wolontariat jest W grupie: wolontariusze to współpracownicy, tylko na innej
 * podstawie. Mają dodatkowo własną grupę „Wolontariusze" i to się nie zmienia —
 * jedna osoba może być w obu, bo służą do czego innego.
 */
function crm_partner_types(): array {
    $skip = ['uslugi'];
    $out  = [];
    foreach (CONTRACT_TYPES as $slug => $label) {
        if (in_array($slug, $skip, true)) continue;
        $out[$slug] = $label;
    }
    return $out;
}

/** Zakłada (albo znajduje) grupę o danym `auto_source`. */
function crm_partner_group(string $auto_source, string $name, array $opt = []): int {
    try {
        $g = db_one("SELECT id FROM crm_groups WHERE auto_source=?", [$auto_source]);
        if ($g) return (int)$g['id'];

        return (int)db_insert('crm_groups', [
            'name'        => $name,
            'description' => (string)($opt['description'] ?? 'Grupa systemowa — zarządzana automatycznie'),
            'color'       => (string)($opt['color'] ?? '#0176D3'),
            'icon'        => (string)($opt['icon'] ?? 'bi-briefcase-fill'),
            'auto_source' => $auto_source,
            'parent_id'   => (int)($opt['parent_id'] ?? 0) ?: null,
            'sort_order'  => (int)($opt['sort_order'] ?? 0),
            // NULL, nie 0 — kolumna ma klucz obcy do users(id), a użytkownika „0" nie ma
            'created_by'  => null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log('[crm_partner_group] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Kontakty z aktywną umową danego typu.
 *
 * @return array<int> ID kartotek CRM
 */
function crm_partner_contacts_for_type(string $slug): array {
    $table = 'umowy_' . $slug;
    try {
        $cols = array_column(db_all("PRAGMA table_info({$table})"), 'name');
    } catch (\Throwable $e) { return []; }
    if (!$cols) return [];

    $sel = ['status'];
    foreach (['email', 'pesel', 'nip_pesel', 'pesel_nip_krs', 'nip', 'data_zakonczenia'] as $c) {
        if (in_array($c, $cols, true)) $sel[] = $c;
    }

    try {
        $rows = db_all("SELECT " . implode(',', $sel) . " FROM {$table}");
    } catch (\Throwable $e) { return []; }

    $cutoff = date('Y-m-d', strtotime('-' . CRM_PARTNER_GRACE_DAYS . ' days'));
    $ids    = [];

    foreach ($rows as $r) {
        if (!in_array((string)($r['status'] ?? ''), CRM_PARTNER_ACTIVE_STATUSES, true)) continue;

        // Umowa z datą końca w przeszłości (po karencji) nie robi już ze mnie współpracownika
        $end = trim((string)($r['data_zakonczenia'] ?? ''));
        if ($end !== '' && $end < $cutoff) continue;

        $email = strtolower(trim((string)($r['email'] ?? '')));
        $ident = preg_replace('/\D/', '', (string)(
            $r['pesel'] ?? $r['nip_pesel'] ?? $r['pesel_nip_krs'] ?? $r['nip'] ?? ''
        ));

        $where  = [];
        $params = [];
        if ($email !== '')            { $where[] = 'LOWER(email)=?';                              $params[] = $email; }
        if (strlen($ident) === 11)    { $where[] = "REPLACE(COALESCE(pesel,''),' ','')=?";        $params[] = $ident; }
        if (strlen($ident) === 10)    { $where[] = "REPLACE(REPLACE(COALESCE(nip,''),'-',''),' ','')=?"; $params[] = $ident; }
        if (!$where) continue;

        try {
            foreach (db_all("SELECT id FROM crm_contacts WHERE crm_active=1 AND (" . implode(' OR ', $where) . ")",
                     $params) as $c) {
                $ids[(int)$c['id']] = true;
            }
        } catch (\Throwable $e) {}
    }
    return array_keys($ids);
}

/**
 * Pełna synchronizacja grupy „Współpracownicy" i podgrup per typ umowy.
 *
 * @param bool $apply false = tylko policz, nic nie zapisuj (tryb podglądu)
 * @return array podsumowanie per grupa
 */
function crm_partner_groups_sync(bool $apply = true): array {
    $now    = date('Y-m-d H:i:s');
    $report = [];

    $root = crm_partner_group('wspolpracownicy', 'Współpracownicy', [
        'description' => 'Osoby i podmioty z aktywną umową (bez wolontariatu) — grupa systemowa',
        'color'       => '#0176D3',
        'icon'        => 'bi-briefcase-fill',
        'sort_order'  => 5,
    ]);
    if (!$root) return $report;

    $all_ids = [];
    $order   = 1;

    foreach (crm_partner_types() as $slug => $label) {
        $gid = crm_partner_group('wspolpracownicy_' . $slug, $label, [
            'description' => 'Aktywne umowy typu: ' . $label . ' — grupa systemowa',
            'color'       => '#64748B',
            'icon'        => 'bi-file-earmark-text',
            'parent_id'   => $root,
            'sort_order'  => $order++,
        ]);
        if (!$gid) continue;

        $ids = crm_partner_contacts_for_type($slug);
        foreach ($ids as $id) $all_ids[$id] = true;

        $report[$slug] = ['group_id' => $gid, 'label' => $label, 'should' => count($ids), 'added' => 0, 'removed' => 0];
        if (!$apply) continue;

        $report[$slug] = array_merge($report[$slug], _crm_partner_apply($gid, $ids, $now));
    }

    // Podgrupy po typach, które wypadły z zakresu (np. usługi), zostawiłyby po sobie
    // grupę z nieaktualnym składem — kasujemy je razem z członkostwami.
    if ($apply) {
        $keep = array_map(static fn($slug) => 'wspolpracownicy_' . $slug, array_keys(crm_partner_types()));
        try {
            foreach (db_all("SELECT id, name, auto_source FROM crm_groups
                              WHERE auto_source LIKE 'wspolpracownicy\_%' ESCAPE '\\'") as $g) {
                if (in_array((string)$g['auto_source'], $keep, true)) continue;
                db()->prepare("DELETE FROM crm_group_members WHERE group_id=?")->execute([(int)$g['id']]);
                db()->prepare("DELETE FROM crm_groups WHERE id=?")->execute([(int)$g['id']]);
                $report['_removed'][] = (string)$g['name'];
            }
        } catch (\Throwable $e) {
            error_log('[crm_partner_groups_sync] cleanup: ' . $e->getMessage());
        }
    }

    $ids_root = array_keys($all_ids);
    $report['_root'] = ['group_id' => $root, 'label' => 'Współpracownicy', 'should' => count($ids_root),
                        'added' => 0, 'removed' => 0];
    if ($apply) {
        $report['_root'] = array_merge($report['_root'], _crm_partner_apply($root, $ids_root, $now));
    }

    return $report;
}

/** Doprowadza skład grupy do listy $ids (dodaje brakujących, usuwa nadmiarowych). */
function _crm_partner_apply(int $gid, array $ids, string $now): array {
    $added = $removed = 0;
    try {
        $current = array_map('intval', array_column(
            db_all("SELECT contact_id FROM crm_group_members WHERE group_id=?", [$gid]), 'contact_id'
        ));

        foreach (array_diff($ids, $current) as $id) {
            db()->prepare("INSERT OR IGNORE INTO crm_group_members (group_id, contact_id, added_by, added_at)
                           VALUES (?,?,NULL,?)")->execute([$gid, (int)$id, $now]);
            $added++;
        }
        foreach (array_diff($current, $ids) as $id) {
            db()->prepare("DELETE FROM crm_group_members WHERE group_id=? AND contact_id=?")
                ->execute([$gid, (int)$id]);
            $removed++;
        }
    } catch (\Throwable $e) {
        error_log('[_crm_partner_apply] ' . $e->getMessage());
    }
    return ['should' => count($ids), 'added' => $added, 'removed' => $removed];
}
