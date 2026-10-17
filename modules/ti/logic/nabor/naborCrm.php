<?php
/**
 * modules/ti/logic/nabor/naborCrm.php — zgłoszenia z naboru do grup TI → CRM.
 *
 * Kontakt CRM = rodzic/opiekun (to on jest stroną relacji): upsert po e-mailu, potem po telefonie,
 * tag „ti-nabor”, grupa auto „TI — Nabór do grup (opiekunowie)”, a przy zgodzie
 * marketingowej także tag „zgoda-marketing”. Każde zdarzenie (potwierdzenie, zmiana statusu)
 * trafia do crm_activities jako typ „zajecia_zapis”. Najlepszy wysiłek — gate crm_enabled, try/catch.
 * Kształt upsertu jak rk_crm_sync() w includes/ti_rekrutacja.php.
 */
require_once __DIR__ . '/nabor.php';

function naborCrmGroupId(): int {
    static $gid = null;
    if ($gid !== null) return $gid;
    try {
        $g = db_one("SELECT id FROM crm_groups WHERE auto_source='ti_nabor'");
        $gid = $g ? (int)$g['id'] : db_insert('crm_groups', [
            'name'        => 'TI — Nabór do grup (opiekunowie)',
            'description' => 'Rodzice i opiekunowie ze zgłoszeń z naboru do grup TI — dodawani automatycznie',
            'auto_source' => 'ti_nabor',
        ]);
    } catch (\Throwable $e) { $gid = 0; }
    return $gid;
}

/** Upsert kontaktu opiekuna + aktywność. Zwraca crm_contacts.id albo 0. */
function naborCrmSync(int $sid, string $event): int {
    if (!module_enabled('crm_enabled')) return 0;
    try {
        require_once dirname(__DIR__, 4) . '/includes/crm.php';
        crm_migrate();
        $s = naborSubmissionGet($sid);
        if (!$s) return 0;

        $cid = (int)($s['crm_contact_id'] ?? 0);
        if ($cid && !db_one("SELECT id FROM crm_contacts WHERE id=? AND crm_active=1", [$cid])) $cid = 0;
        if (!$cid) $cid = (int)(db_one("SELECT id FROM crm_contacts WHERE LOWER(email)=LOWER(?) AND crm_active=1 ORDER BY id DESC LIMIT 1", [$s['guardian_email']])['id'] ?? 0);
        if (!$cid) $cid = (int)(db_one("SELECT id FROM crm_contacts WHERE REPLACE(REPLACE(telefon,' ',''),'+','') IN (?,?) AND crm_active=1 ORDER BY id DESC LIMIT 1",
                                       [$s['guardian_phone'], substr($s['guardian_phone'], 2)])['id'] ?? 0);
        $adres = $s['guardian_street'] . ', ' . $s['guardian_postal_code'] . ' ' . $s['guardian_city'];
        if ($cid) {
            CrmManager::updateContact($cid, ['email' => $s['guardian_email'], 'telefon' => '+' . $s['guardian_phone']]);
        } else {
            $cid = CrmManager::createContact([
                'type'          => 'osoba',
                'imie_nazwisko' => $s['guardian_name'],
                'email'         => $s['guardian_email'],
                'telefon'       => '+' . $s['guardian_phone'],
                'adres'         => $adres,
                'status'        => 'aktywny',
                'source'        => 'ti_nabor',
            ]);
        }
        if (!$cid) return 0;
        if (!$s['crm_contact_id']) db_exec("UPDATE ti_nabor_submissions SET crm_contact_id=? WHERE id=?", [$cid, $sid]);

        $tags = ['ti-nabor'];
        if (!empty(naborConsents($sid)['marketing_consent'])) $tags[] = 'zgoda-marketing';
        foreach ($tags as $tag) {
            try { db_exec("INSERT OR IGNORE INTO crm_tags (contact_id, tag) VALUES (?,?)", [$cid, $tag]); } catch (\Throwable $e) {}
        }
        if ($gid = naborCrmGroupId()) CrmManager::addToGroup($gid, $cid, 0);

        $now = date('Y-m-d H:i:s');
        db_insert('crm_activities', [
            'contact_id'   => $cid,
            'type'         => 'zajecia_zapis',
            'title'        => 'Nabór TI ' . $s['registration_number'] . ' — ' . $s['participant_name'],
            'description'  => $event . "\nZajęcia: " . ($s['is_custom_schedule'] ? 'termin do ustalenia' : (string)$s['course_name'])
                            . ($s['ezd_case_id'] ? "\nSprawa EZD #" . $s['ezd_case_id'] : ''),
            'status'       => 'done',
            'created_at'   => $now,
            'updated_at'   => $now,
            'completed_at' => $now,
        ]);
        return $cid;
    } catch (\Throwable $e) {
        error_log('[ti_nabor_crm] #' . $sid . ': ' . $e->getMessage());
        return 0;
    }
}
