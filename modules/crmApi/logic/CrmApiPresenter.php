<?php
/**
 * modules/crmApi/logic/CrmApiPresenter.php — publiczny kształt rekordów API CRM (JSON).
 * Kontakty używają nazw kolumn jak api/v1/crm.php (imie_nazwisko, telefon…), żeby oba API SZO mówiły tym samym językiem.
 * Pola zamaskowane uprawnieniami (crm_mask_contact) przychodzą jako null; które to są — mówi perms.fieldView.
 */
final class CrmApiPresenter
{
    /** Kolumny kartoteki wydawane na zewnątrz (pełny widok / formularz edycji). */
    public const CONTACT_FIELDS = [
        'type', 'status', 'imie_nazwisko', 'imie', 'nazwisko', 'email', 'telefon', 'organizacja', 'stanowisko',
        'nip', 'krs', 'regon', 'forma_prawna', 'branza', 'strona_www', 'osoba_kontaktowa', 'adres',
        'addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city', 'addr_country',
        'wojewodztwo', 'powiat', 'gmina', 'data_urodzenia', 'notatka', 'source', 'owner_id', 'is_critical',
        'critical_note', 'avatar_initials', 'email_opt_out', 'created_at', 'updated_at',
    ];

    /** Kolumny, które API przyjmuje przy tworzeniu / zmianie (CrmManager i tak filtruje whitelistą i prawami do pól). */
    public const CONTACT_WRITABLE = [
        'type', 'status', 'imie_nazwisko', 'imie', 'nazwisko', 'email', 'telefon', 'organizacja', 'stanowisko',
        'nip', 'krs', 'regon', 'forma_prawna', 'branza', 'strona_www', 'osoba_kontaktowa', 'adres',
        'addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city', 'addr_country',
        'wojewodztwo', 'powiat', 'gmina', 'data_urodzenia', 'pesel', 'notatka', 'owner_id', 'is_critical', 'critical_note',
    ];

    public static function contactRow(array $r, ?array $primary = null): array
    {
        return [
            'id'               => (int)$r['id'],
            'type'             => $r['type'] ?? 'osoba',
            'status'           => $r['status'] ?? null,
            'imie_nazwisko'    => $r['imie_nazwisko'] ?? null,
            'email'            => $r['email'] ?? null,
            'telefon'          => $r['telefon'] ?? null,
            'organizacja'      => $r['organizacja'] ?? null,
            'stanowisko'       => $r['stanowisko'] ?? null,
            'addr_city'        => $r['addr_city'] ?? null,
            'wojewodztwo'      => $r['wojewodztwo'] ?? null,
            'owner_id'         => isset($r['owner_id']) && (int)$r['owner_id'] > 0 ? (int)$r['owner_id'] : null,
            'avatar_initials'  => $r['avatar_initials'] ?? null,
            'is_critical'      => !empty($r['is_critical']),
            'tags'             => !empty($r['tags_csv']) ? array_values(array_filter(explode(',', (string)$r['tags_csv']))) : [],
            'last_comm_at'     => $r['last_comm_at'] ?? null,
            'updated_at'       => $r['updated_at'] ?? null,
            'primary_category' => $primary,
        ];
    }

    public static function contactFull(array $c): array
    {
        $out = ['id' => (int)$c['id']];
        foreach (self::CONTACT_FIELDS as $f) $out[$f] = $c[$f] ?? null;
        $out['owner_id']      = isset($c['owner_id']) && (int)$c['owner_id'] > 0 ? (int)$c['owner_id'] : null;
        $out['is_critical']   = !empty($c['is_critical']);
        $out['email_opt_out'] = !empty($c['email_opt_out']);
        $out['tags']   = array_map(fn($t) => (string)$t['tag'], $c['tags'] ?? []);
        $out['groups'] = array_map(fn($g) => ['id' => (int)$g['id'], 'name' => (string)$g['name'], 'color' => $g['color'] ?? null], $c['groups'] ?? []);
        $out['persons'] = array_map(fn($p) => [
            'id'   => (int)$p['id'],
            'name' => (string)($p['imie_nazwisko'] ?? $p['linked_name'] ?? trim(($p['imie'] ?? '') . ' ' . ($p['nazwisko'] ?? ''))),
            'role' => $p['stanowisko'] ?? ($p['rola'] ?? null),
            'email' => $p['email'] ?? null,
            'telefon' => $p['telefon'] ?? null,
        ], $c['persons'] ?? []);
        return $out;
    }

    public static function note(array $n): array
    {
        return [
            'id'         => (int)$n['id'],
            'body'       => (string)$n['body'],
            'is_pinned'  => !empty($n['is_pinned']),
            'author'     => $n['author_name'] ?? null,
            'created_by' => isset($n['created_by']) ? (int)$n['created_by'] : null,
            'created_at' => $n['created_at'] ?? null,
        ];
    }

    public static function communication(array $m): array
    {
        $body = (string)($m['body'] ?? '');
        if ($body === '' && !empty($m['body_html'])) $body = (string)$m['body_html'];
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        return [
            'id'          => (int)$m['id'],
            'channel'     => $m['channel'] ?? null,
            'direction'   => ($m['direction'] ?? '') === 'in' ? 'in' : 'out',
            'subject'     => $m['subject'] ?? null,
            'excerpt'     => mb_substr($text, 0, 400),
            'status'      => $m['status'] ?? null,
            'sent_at'     => $m['sent_at'] ?? null,
            'sender_name' => $m['sender_name'] ?? null,
            'from_name'   => $m['from_name'] ?? null,
            'from_email'  => $m['from_email'] ?? null,
        ];
    }

    public static function activity(array $a): array
    {
        $who = trim(($a['assigned_fn'] ?? '') . ' ' . ($a['assigned_ln'] ?? '')) ?: ($a['assigned_name'] ?? null);
        return [
            'id'           => (int)$a['id'],
            'type'         => $a['type'] ?? 'other',
            'title'        => $a['title'] ?? '',
            'description'  => $a['description'] ?? null,
            'scheduled_at' => $a['scheduled_at'] ?? null,
            'status'       => $a['status'] ?? 'planned',
            'outcome'      => $a['outcome'] ?? null,
            'assigned_to'  => isset($a['assigned_to']) ? (int)$a['assigned_to'] : null,
            'assigned_name' => $who,
            'completed_at' => $a['completed_at'] ?? null,
            'contact_id'   => isset($a['contact_id']) ? (int)$a['contact_id'] : null,
            'contact_name' => $a['imie_nazwisko'] ?? null,
        ];
    }

    public static function task(array $t): array
    {
        return [
            'id'         => (int)$t['id'],
            'title'      => (string)($t['title'] ?? ''),
            'due_date'   => $t['due_date'] ?? null,
            'priority'   => $t['priority'] ?? null,
            'status'     => $t['status'] ?? 'open',
            'owner_name' => $t['owner_name'] ?? null,
            'case_id'    => isset($t['case_id']) && $t['case_id'] ? (int)$t['case_id'] : null,
            'case_title' => $t['case_title'] ?? null,
        ];
    }

    public static function caseRow(array $c): array
    {
        $cfg = crm_case_status_cfg($c['status'] ?? '');
        $active = !crm_case_is_closed($c['status'] ?? '');
        return [
            'id'           => (int)$c['id'],
            'case_number'  => $c['case_number'] ?? null,
            'title'        => (string)($c['title'] ?? ''),
            'status'       => (string)($c['status'] ?? ''),
            'status_label' => (string)($cfg['label'] ?? $c['status'] ?? ''),
            'status_color' => $cfg['color'] ?? null,
            'is_closed'    => !$active,
            'priority'     => $c['priority'] ?? 'medium',
            'contact_id'   => isset($c['contact_id']) ? (int)$c['contact_id'] : null,
            'contact_name' => $c['contact_name'] ?? null,
            'owner_id'     => isset($c['owner_id']) && (int)$c['owner_id'] > 0 ? (int)$c['owner_id'] : null,
            'owner_name'   => $c['owner_name'] ?? null,
            'type_id'      => isset($c['type_id']) && $c['type_id'] ? (int)$c['type_id'] : null,
            'due_date'     => $c['due_date'] ?? null,
            'is_overdue'   => $active && !empty($c['due_date']) && substr((string)$c['due_date'], 0, 10) < date('Y-m-d'),
            'notes_count'  => isset($c['notes_count']) ? (int)$c['notes_count'] : null,
            'created_at'   => $c['created_at'] ?? null,
            'updated_at'   => $c['updated_at'] ?? null,
            'closed_at'    => $c['closed_at'] ?? null,
        ];
    }
}
