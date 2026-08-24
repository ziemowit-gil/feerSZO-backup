<?php
/**
 * includes/crm_relations.php — powiązania między kartotekami CRM.
 *
 * Tabela `crm_relations` istniała od dawna i nic do niej nie pisało: dotykało jej
 * wyłącznie scalanie kartotek, żeby przepiąć wiersze, których nigdy nie było.
 * Skutek: „ta osoba jest w zarządzie tej fundacji", „ten podmiot jest partnerem
 * tamtego", „to jest opiekun tego dziecka" wisiało w notatkach albo w niczyjej
 * pamięci — i przy pytaniu „kto tu za co odpowiada" trzeba było czytać historię.
 *
 * NIE MYLIĆ z osobami kontaktowymi (crm_contact_persons): tam osoba NIE ma własnej
 * kartoteki, jest danymi przy podmiocie. Tu obie strony są pełnymi kartotekami
 * i każda widzi powiązanie u siebie.
 *
 * Powiązanie jest jedno, a czyta się je z dwóch stron — dlatego typ ma dwie
 * etykiety: patrząc od A („członek zarządu") i od B („ma w zarządzie").
 */

require_once __DIR__ . '/db.php';

/**
 * Katalog typów powiązań.
 *
 * `a` opisuje rolę kartoteki A względem B, `b` odwrotnie. `sym` = powiązanie
 * symetryczne (obie strony czyta się tak samo).
 */
function crm_relation_types(): array
{
    return [
        'zarzad'      => ['a' => 'członek zarządu',      'b' => 'ma w zarządzie',        'icon' => 'bi-briefcase',      'sym' => false],
        'pracownik'   => ['a' => 'pracuje w',            'b' => 'zatrudnia',             'icon' => 'bi-person-badge',   'sym' => false],
        'wolontariusz'=> ['a' => 'wolontariusz przy',    'b' => 'ma wolontariusza',      'icon' => 'bi-heart',          'sym' => false],
        'opiekun'     => ['a' => 'opiekun prawny',       'b' => 'pod opieką',            'icon' => 'bi-people',         'sym' => false],
        'rodzina'     => ['a' => 'rodzina',              'b' => 'rodzina',               'icon' => 'bi-house-heart',    'sym' => true],
        'partner'     => ['a' => 'partner',              'b' => 'partner',               'icon' => 'bi-handshake',      'sym' => true],
        'podwykonawca'=> ['a' => 'podwykonawca',         'b' => 'zleca podwykonawstwo',  'icon' => 'bi-tools',          'sym' => false],
        'oddzial'     => ['a' => 'oddział / filia',      'b' => 'jednostka nadrzędna',   'icon' => 'bi-diagram-3',      'sym' => false],
        'polecil'     => ['a' => 'polecił',              'b' => 'polecony przez',        'icon' => 'bi-megaphone',      'sym' => false],
        'powiazany'   => ['a' => 'powiązany',            'b' => 'powiązany',             'icon' => 'bi-link-45deg',     'sym' => true],
    ];
}

/** Etykieta typu widziana od strony wskazanej kartoteki. */
function crm_relation_label(string $type, bool $from_a = true): string
{
    $t = crm_relation_types()[$type] ?? crm_relation_types()['powiazany'];
    return $from_a ? $t['a'] : $t['b'];
}

/**
 * Dodaje powiązanie. Kolejność stron ma znaczenie: A jest tą, o której mówi
 * pierwsza etykieta typu („A jest członkiem zarządu B").
 *
 * @return array{ok:bool,error:string,id:int}
 */
function crm_relation_add(int $a_id, int $b_id, string $type, string $notes = ''): array
{
    if ($a_id <= 0 || $b_id <= 0)  return ['ok' => false, 'error' => 'Wskaż obie kartoteki.', 'id' => 0];
    if ($a_id === $b_id)           return ['ok' => false, 'error' => 'Kartoteki nie da się powiązać z samą sobą.', 'id' => 0];
    if (!isset(crm_relation_types()[$type])) $type = 'powiazany';

    try {
        $b = db_one("SELECT id FROM crm_contacts WHERE id=? AND crm_active=1", [$b_id]);
        if (!$b) return ['ok' => false, 'error' => 'Druga kartoteka nie istnieje albo jest wygaszona.', 'id' => 0];

        // Powiązanie tego samego typu w drugą stronę to ta sama informacja — przy
        // typach symetrycznych nie chcemy dwóch wierszy mówiących to samo
        if (crm_relation_types()[$type]['sym']) {
            $dup = db_one("SELECT id FROM crm_relations
                            WHERE relation_type=? AND contact_a_id=? AND contact_b_id=?",
                          [$type, $b_id, $a_id]);
            if ($dup) return ['ok' => true, 'error' => '', 'id' => (int)$dup['id']];
        }

        $id = db_insert('crm_relations', [
            'contact_a_id'  => $a_id,
            'contact_b_id'  => $b_id,
            'relation_type' => $type,
            'notes'         => trim($notes) ?: null,
            'created_by'    => (int)(current_user()['id'] ?? 0) ?: null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'error' => '', 'id' => (int)$id];
    } catch (\Throwable $e) {
        // UNIQUE(a,b,typ) — takie powiązanie już jest, i dobrze
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            return ['ok' => false, 'error' => 'Takie powiązanie już istnieje.', 'id' => 0];
        }
        error_log('[crm_relation_add] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Nie udało się zapisać powiązania.', 'id' => 0];
    }
}

function crm_relation_delete(int $id): bool
{
    try { db()->prepare("DELETE FROM crm_relations WHERE id=?")->execute([$id]); return true; }
    catch (\Throwable $e) { return false; }
}

/**
 * Powiązania kartoteki — z OBU stron, sprowadzone do jednego kształtu.
 *
 * Wiersz opisuje zawsze „ta kartoteka JEST … dla tamtej", niezależnie od tego,
 * po której stronie została zapisana. Inaczej połowa powiązań czytałaby się
 * odwrotnie i nikt by ich nie rozumiał.
 *
 * @return array<int,array{id:int,other_id:int,other_name:string,other_type:string,label:string,icon:string,notes:string}>
 */
function crm_relations_for(int $contact_id): array
{
    if ($contact_id <= 0) return [];
    $types = crm_relation_types();
    $out   = [];

    try {
        $rows = db_all(
            "SELECT r.*, 'a' AS side, c.id AS other_id, c.imie_nazwisko AS other_name,
                    c.type AS other_type, c.organizacja AS other_org
               FROM crm_relations r
               JOIN crm_contacts c ON c.id = r.contact_b_id AND c.crm_active = 1
              WHERE r.contact_a_id = ?
              UNION ALL
             SELECT r.*, 'b' AS side, c.id AS other_id, c.imie_nazwisko AS other_name,
                    c.type AS other_type, c.organizacja AS other_org
               FROM crm_relations r
               JOIN crm_contacts c ON c.id = r.contact_a_id AND c.crm_active = 1
              WHERE r.contact_b_id = ?
           ORDER BY relation_type, other_name",
            [$contact_id, $contact_id]
        );
    } catch (\Throwable $e) { return []; }

    foreach ($rows as $r) {
        $t = $types[$r['relation_type']] ?? $types['powiazany'];
        $out[] = [
            'id'         => (int)$r['id'],
            'type'       => (string)$r['relation_type'],
            'other_id'   => (int)$r['other_id'],
            'other_name' => (string)$r['other_name'],
            'other_org'  => (string)($r['other_org'] ?? ''),
            'other_type' => (string)($r['other_type'] ?? ''),
            'label'      => $r['side'] === 'a' ? $t['a'] : $t['b'],
            'icon'       => $t['icon'],
            'notes'      => (string)($r['notes'] ?? ''),
        ];
    }
    return $out;
}

/** Ile powiązań ma kartoteka — do plakietki przy zakładce. */
function crm_relations_count(int $contact_id): int
{
    try {
        return (int)(db_one(
            "SELECT COUNT(*) AS n FROM crm_relations r
               JOIN crm_contacts c ON c.crm_active = 1
                AND c.id = CASE WHEN r.contact_a_id = ? THEN r.contact_b_id ELSE r.contact_a_id END
              WHERE r.contact_a_id = ? OR r.contact_b_id = ?",
            [$contact_id, $contact_id, $contact_id]
        )['n'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}
