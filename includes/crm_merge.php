<?php
/**
 * includes/crm_merge.php — przepinanie i scalanie kartotek CRM.
 *
 * Jedna warstwa pod dwie operacje:
 *   • przypięcie osoby do podmiotu (osoba znika z listy kontaktów, zostaje jako
 *     osoba kontaktowa firmy) — używane przez grupowanie po domenach,
 *   • scalenie dwóch kartotek tego samego bytu (duplikaty).
 *
 * Obie sprowadzają się do tego samego: PRZEPIĄĆ wszystko, co wisi na kartotece
 * źródłowej, na kartotekę docelową, a źródłową wygasić. Kartoteki nie kasujemy
 * NIGDY — `crm_active=0` zostawia ślad i pozwala cofnąć pomyłkę ręcznie.
 *
 * Mapa kluczy obcych czytana jest z `sqlite_master`, a nie z listy w kodzie:
 * moduły dokładają tabele wskazujące na crm_contacts (oferty, faktury,
 * darowizny, zgody, beneficjenci, wydarzenia, konsultacje) i lista pisana ręcznie
 * rozjechałaby się przy pierwszym nowym module — a przeoczona tabela to
 * osierocone dane.
 */

declare(strict_types=1);

require_once __DIR__ . '/crm.php';

/** Tabele pominięte przy przepinaniu — ich sens jest przywiązany do ORYGINAŁU. */
const CRM_MERGE_SKIP_TABLES = [
    'crm_contact_audit',   // historia zmian zostaje przy kartotece, której dotyczy
];

/** Kolumny pominięte przy przepinaniu — „tabela.kolumna”. */
const CRM_MERGE_SKIP_COLUMNS = [
    // Wskazuje na kartotekę osoby, z której powstała osoba kontaktowa. Przepięcie
    // na podmiot zrobiłoby z niej wskaźnik na samą siebie.
    'crm_contact_persons.linked_contact_id',
];

/**
 * Mapa: tabela → kolumny wskazujące na crm_contacts(id).
 *
 * @return array<string,string[]>
 */
function crm_merge_fk_map(): array
{
    static $map = null;
    if ($map !== null) return $map;

    $map    = [];
    $tables = db_all("SELECT name, sql FROM sqlite_master WHERE type='table' AND sql IS NOT NULL");

    foreach ($tables as $t) {
        $name = (string)$t['name'];
        if ($name === 'crm_contacts') continue;
        if (in_array($name, CRM_MERGE_SKIP_TABLES, true)) continue;
        if (!preg_match('~^[a-z_][a-z0-9_]*$~', $name)) continue;

        $sql  = (string)$t['sql'];
        $cols = [];

        // Forma inline: `contact_id INTEGER ... REFERENCES crm_contacts(id)`
        if (preg_match_all('~([a-z_][a-z0-9_]*)\s+INTEGER[^,()]*REFERENCES\s+crm_contacts\s*\(~i', $sql, $m)) {
            $cols = array_merge($cols, $m[1]);
        }
        // Forma tabelaryczna: `FOREIGN KEY(contact_id) REFERENCES crm_contacts(id)`
        if (preg_match_all('~FOREIGN\s+KEY\s*\(\s*([a-z_][a-z0-9_]*)\s*\)\s*REFERENCES\s+crm_contacts\s*\(~i', $sql, $m2)) {
            $cols = array_merge($cols, $m2[1]);
        }

        $cols = array_values(array_unique(array_map('strtolower', $cols)));
        $cols = array_filter($cols, fn($c) => !in_array("$name.$c", CRM_MERGE_SKIP_COLUMNS, true));
        if ($cols) $map[$name] = array_values($cols);
    }
    return $map;
}

/**
 * Co wisi na kartotece — liczby wierszy per tabela.
 *
 * Służy do podglądu PRZED operacją: użytkownik ma zobaczyć, co dokładnie
 * przepnie, zanim kliknie.
 *
 * @return array<string,int>
 */
function crm_merge_preview(int $contact_id): array
{
    $out = [];
    foreach (crm_merge_fk_map() as $table => $cols) {
        $n = 0;
        foreach ($cols as $col) {
            try {
                $r = db_one("SELECT COUNT(*) AS n FROM {$table} WHERE {$col}=?", [$contact_id]);
                $n += (int)($r['n'] ?? 0);
            } catch (\Throwable $e) { /* tabela z innego modułu, nieaktywna */ }
        }
        if ($n) $out[$table] = $n;
    }
    return $out;
}

/** Ludzkie nazwy tabel w podglądzie skutków. */
function crm_merge_table_label(string $table): string
{
    static $map = [
        'crm_communications'        => 'wiadomości w historii',
        'crm_cases'                 => 'sprawy',
        'crm_notes'                 => 'notatki',
        'crm_activities'            => 'działania',
        'crm_tags'                  => 'tagi',
        'crm_group_members'         => 'przypisania do grup',
        'crm_relations'             => 'powiązania',
        'crm_events'                => 'zdarzenia kalendarza',
        'crm_event_participants'    => 'udziały w wydarzeniach',
        'crm_consents'              => 'wpisy w rejestrze zgód',
        'crm_offers'                => 'oferty',
        'crm_contact_persons'       => 'osoby kontaktowe',
        'crm_contact_services'      => 'rodzaje usług',
        'crm_contact_field_values'  => 'wartości pól dodatkowych',
        'crm_campaign_recipients'   => 'wysyłki kampanii',
        'crm_beneficiary_links'     => 'powiązania z beneficjentami',
        'invoices'                  => 'faktury',
        'donations'                 => 'darowizny',
        'consultations'             => 'konsultacje',
        'events'                    => 'wydarzenia',
    ];
    return $map[$table] ?? $table;
}

/**
 * Przepina wszystkie wiersze z kartoteki $from na $to.
 *
 * `UPDATE OR IGNORE` zamiast zwykłego UPDATE, bo część tabel ma UNIQUE na
 * (contact_id, coś) — tag, członkostwo w grupie, wartość pola dodatkowego.
 * Przy kolizji wiersz zostaje przy kartotece źródłowej, która i tak jest
 * wygaszana; alternatywą byłby błąd i przerwana operacja w połowie.
 *
 * @return array<string,int> liczba przepiętych wierszy per tabela
 */
function crm_relink_contact_rows(int $from, int $to): array
{
    if ($from === $to || $from <= 0 || $to <= 0) return [];
    $moved = [];
    foreach (crm_merge_fk_map() as $table => $cols) {
        foreach ($cols as $col) {
            try {
                $st = db()->prepare("UPDATE OR IGNORE {$table} SET {$col}=? WHERE {$col}=?");
                $st->execute([$to, $from]);
                $n = $st->rowCount();
                if ($n) $moved[$table] = ($moved[$table] ?? 0) + $n;
            } catch (\Throwable $e) { /* moduł nieaktywny w tej instalacji */ }
        }
    }
    // Przepięcie mogło zrobić z powiązania pętlę na samego siebie. Nazwy kolumn
    // bierzemy z mapy, bo crm_relations trzyma strony jako contact_a_id/contact_b_id.
    $rel = crm_merge_fk_map()['crm_relations'] ?? [];
    if (count($rel) === 2) {
        try { db()->exec("DELETE FROM crm_relations WHERE {$rel[0]} = {$rel[1]}"); } catch (\Throwable $e) {}
    }
    return $moved;
}

/**
 * Przypina kartotekę osoby do podmiotu jako jego osobę kontaktową.
 *
 * @param array{move_history?:bool,deactivate?:bool,stanowisko?:string} $opts
 * @return array{ok:bool,error?:string,person_id?:int,moved?:array}
 */
function crm_attach_contact_to_org(int $org_id, int $contact_id, array $opts = []): array
{
    crm_migrate();

    $move_history = $opts['move_history'] ?? true;
    $deactivate   = $opts['deactivate']   ?? true;

    if ($org_id === $contact_id) return ['ok' => false, 'error' => 'Kontakt nie może być osobą kontaktową samego siebie.'];

    $org = db_one("SELECT * FROM crm_contacts WHERE id=?", [$org_id]);
    $src = db_one("SELECT * FROM crm_contacts WHERE id=?", [$contact_id]);
    if (!$org || !$src)  return ['ok' => false, 'error' => 'Kontakt nie istnieje.'];
    if (empty(CRM_CONTACT_TYPES[$org['type']]['org_like'])) {
        return ['ok' => false, 'error' => 'Celem musi być podmiot (organizacja, kontrahent lub partner).'];
    }
    if (!empty(CRM_CONTACT_TYPES[$src['type']]['org_like'])) {
        return ['ok' => false, 'error' => 'Do podmiotu przypinamy osoby, nie inne podmioty — do tego służy scalanie.'];
    }
    if (!crm_can_access_contact($org_id) || !crm_can_access_contact($contact_id)) {
        return ['ok' => false, 'error' => 'Brak dostępu do jednego z kontaktów.'];
    }

    $uid = (int)(current_user()['id'] ?? 0);

    // 1. Osoba kontaktowa podmiotu — ze śladem, z której kartoteki powstała.
    $person_id = CrmManager::addContactPerson($org_id, [
        'imie_nazwisko'     => $src['imie_nazwisko'],
        'stanowisko'        => trim((string)($opts['stanowisko'] ?? ($src['stanowisko'] ?? ''))) ?: null,
        'email'             => $src['email'],
        'telefon'           => $src['telefon'],
        'notatka'           => $src['notatka'],
        'linked_contact_id' => $contact_id,
    ], $uid ?: null);

    if (!$person_id) return ['ok' => false, 'error' => 'Nie udało się utworzyć osoby kontaktowej.'];

    // 2. Historia i powiązania.
    $moved = $move_history ? crm_relink_contact_rows($contact_id, $org_id) : [];

    // 3. Wygaszenie kartoteki źródłowej + czytelny ślad po obu stronach.
    $when = date('Y-m-d H:i');
    $who  = (string)(current_user()['full_name'] ?? current_user()['username'] ?? 'system');
    CrmManager::addNote($org_id, sprintf(
        'Przypięto osobę kontaktową „%s” z kartoteki #%d (%s). Przeniesiono: %s. %s, %s.',
        $src['imie_nazwisko'], $contact_id, $src['email'] ?: 'brak e-maila',
        $moved ? implode(', ', array_map(
            fn($t, $n) => crm_merge_table_label($t) . ": $n", array_keys($moved), $moved)) : 'nic',
        $who, $when
    ), $uid ?: null);

    if ($deactivate) {
        CrmManager::addNote($contact_id, sprintf(
            'Kartoteka wygaszona — osoba przypięta do podmiotu „%s” (#%d). %s, %s.',
            $org['imie_nazwisko'], $org_id, $who, $when
        ), $uid ?: null);
        db()->prepare("UPDATE crm_contacts SET crm_active=0, updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $contact_id]);
    } else {
        // Kartoteka zostaje — niech przynajmniej wskazuje pracodawcę.
        CrmManager::updateContact($contact_id, ['organizacja' => $org['imie_nazwisko']]);
    }

    return ['ok' => true, 'person_id' => $person_id, 'moved' => $moved];
}

/**
 * Scala dwie kartoteki tego samego bytu (duplikaty).
 *
 * Kartoteka $keep_id zostaje, $drop_id jest wygaszana. Pola puste w rekordzie
 * zachowanym uzupełniamy z porzucanego — scalenie ma DODAWAĆ informację, nigdy
 * jej nie ujmować; wypełnione pola pozostają nietknięte.
 *
 * @return array{ok:bool,error?:string,moved?:array,filled?:string[]}
 */
function crm_merge_contacts(int $keep_id, int $drop_id): array
{
    crm_migrate();

    if ($keep_id === $drop_id) return ['ok' => false, 'error' => 'To ta sama kartoteka.'];

    $keep = db_one("SELECT * FROM crm_contacts WHERE id=?", [$keep_id]);
    $drop = db_one("SELECT * FROM crm_contacts WHERE id=?", [$drop_id]);
    if (!$keep || !$drop) return ['ok' => false, 'error' => 'Kontakt nie istnieje.'];
    if (!crm_can_access_contact($keep_id) || !crm_can_access_contact($drop_id)) {
        return ['ok' => false, 'error' => 'Brak dostępu do jednego z kontaktów.'];
    }

    $keep_org = !empty(CRM_CONTACT_TYPES[$keep['type']]['org_like']);
    $drop_org = !empty(CRM_CONTACT_TYPES[$drop['type']]['org_like']);
    if ($keep_org !== $drop_org) {
        return ['ok' => false, 'error' => 'Scalać można kartoteki tego samego rodzaju — osobę z osobą, podmiot z podmiotem.'];
    }

    // Uzupełnienie pustych pól. Świadomie pomijamy pola techniczne i takie,
    // które opisują konkretną kartotekę, a nie opisywany byt.
    $skip = ['id','created_at','updated_at','synced_at','crm_active','avatar_initials','source','created_by'];
    $fill = [];
    foreach ($drop as $col => $val) {
        if (in_array($col, $skip, true))          continue;
        if ($val === null || $val === '')          continue;
        $cur = $keep[$col] ?? null;
        if ($cur !== null && $cur !== '' && $cur !== 0 && $cur !== '0') continue;
        $fill[$col] = $val;
    }
    if ($fill) CrmManager::updateContact($keep_id, $fill);

    $moved = crm_relink_contact_rows($drop_id, $keep_id);

    $uid  = (int)(current_user()['id'] ?? 0);
    $who  = (string)(current_user()['full_name'] ?? current_user()['username'] ?? 'system');
    $when = date('Y-m-d H:i');

    CrmManager::addNote($keep_id, sprintf(
        'Scalono z kartoteką „%s” (#%d). Przeniesiono: %s. Uzupełnione pola: %s. %s, %s.',
        $drop['imie_nazwisko'], $drop_id,
        $moved ? implode(', ', array_map(
            fn($t, $n) => crm_merge_table_label($t) . ": $n", array_keys($moved), $moved)) : 'nic',
        $fill ? implode(', ', array_map('crm_audit_field_label', array_keys($fill))) : 'brak',
        $who, $when
    ), $uid ?: null);
    CrmManager::addNote($drop_id, sprintf(
        'Kartoteka wygaszona — scalona z „%s” (#%d). %s, %s.',
        $keep['imie_nazwisko'], $keep_id, $who, $when
    ), $uid ?: null);

    db()->prepare("UPDATE crm_contacts SET crm_active=0, updated_at=? WHERE id=?")
        ->execute([date('Y-m-d H:i:s'), $drop_id]);

    return ['ok' => true, 'moved' => $moved, 'filled' => array_keys($fill)];
}
