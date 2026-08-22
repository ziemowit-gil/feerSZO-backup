<?php
/**
 * crm/mobile/api/search.php — dane dla mobilnego dialera CRM.
 *
 * GET ?mode=snapshot            → wszystkie kontakty z numerem (cap 1000) do lokalnego szukania
 * GET ?mode=recent              → ostatnio dzwonione przeze mnie
 * GET ?q=...                    → wyszukiwanie po stronie serwera (nazwa / firma / numer / e-mail)
 *
 * Odpowiedź: { ok, items: [ { key, cid, pid, name, sub, initials, type, phones:[{label,tel}] } ] }
 * Zwracane są tylko wpisy, z których da się zadzwonić — bez numeru nie ma po co ich pokazywać.
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/mobile.php';

crm_mobile_guard(true);

$mode  = (string)($_GET['mode'] ?? '');
$q     = trim((string)($_GET['q'] ?? ''));
$limit = min(1000, max(1, (int)($_GET['limit'] ?? 40)));

/** Wiersz kontaktu głównego → element listy. */
function crm_mobile_item_contact(array $r): ?array
{
    $phones = crm_mobile_phones($r['telefon'] ?? null);
    if (!$phones) return null;

    $sub = array_filter([trim((string)($r['stanowisko'] ?? '')), trim((string)($r['organizacja'] ?? ''))]);
    return [
        'key'      => 'c' . (int)$r['id'],
        'cid'      => (int)$r['id'],
        'pid'      => 0,
        'name'     => (string)$r['imie_nazwisko'],
        'sub'      => implode(' · ', $sub),
        'initials' => crm_mobile_initials((string)$r['imie_nazwisko']),
        'type'     => (string)($r['type'] ?? 'osoba'),
        'phones'   => $phones,
    ];
}

/** Wiersz osoby kontaktowej (pracownik firmy) → element listy. */
function crm_mobile_item_person(array $r): ?array
{
    $phones = crm_mobile_phones($r['telefon'] ?? null);
    if (!$phones) return null;

    $sub = array_filter([trim((string)($r['stanowisko'] ?? '')), trim((string)($r['org_name'] ?? ''))]);
    return [
        'key'      => 'p' . (int)$r['id'],
        'cid'      => (int)$r['contact_id'],
        'pid'      => (int)$r['id'],
        'name'     => (string)$r['imie_nazwisko'],
        'sub'      => implode(' · ', $sub),
        'initials' => crm_mobile_initials((string)$r['imie_nazwisko']),
        'type'     => 'osoba_kontaktowa',
        'phones'   => $phones,
    ];
}

$items = [];

if ($mode === 'recent') {
    // Ostatnie rozmowy tego użytkownika — jeden wpis na kontakt, najnowsze pierwsze.
    $rows = db_all(
        "SELECT c.id, c.imie_nazwisko, c.telefon, c.organizacja, c.stanowisko, c.type,
                MAX(a.created_at) AS last_call
           FROM crm_activities a
           JOIN crm_contacts   c ON c.id = a.contact_id
          WHERE a.type='call' AND a.created_by = ? AND c.crm_active = 1
       GROUP BY c.id
       ORDER BY last_call DESC
          LIMIT ?",
        [(int)(current_user()['id'] ?? 0), $limit]
    );
    foreach ($rows as $r) {
        if ($it = crm_mobile_item_contact($r)) $items[] = $it;
    }

} elseif ($mode === 'snapshot') {
    // Pełna lista „dzwonialnych" na potrzeby błyskawicznego szukania w telefonie.
    $rows = db_all(
        "SELECT id, imie_nazwisko, telefon, organizacja, stanowisko, type
           FROM crm_contacts
          WHERE crm_active = 1 AND telefon IS NOT NULL AND TRIM(telefon) <> ''
       ORDER BY imie_nazwisko
          LIMIT ?",
        [$limit]
    );
    foreach ($rows as $r) {
        if ($it = crm_mobile_item_contact($r)) $items[] = $it;
    }

    $prows = db_all(
        "SELECT p.id, p.contact_id, p.imie_nazwisko, p.telefon, p.stanowisko,
                c.imie_nazwisko AS org_name
           FROM crm_contact_persons p
           JOIN crm_contacts       c ON c.id = p.contact_id
          WHERE c.crm_active = 1 AND p.telefon IS NOT NULL AND TRIM(p.telefon) <> ''
       ORDER BY p.imie_nazwisko
          LIMIT ?",
        [$limit]
    );
    foreach ($prows as $r) {
        if ($it = crm_mobile_item_person($r)) $items[] = $it;
    }

} else {
    if ($q === '') {
        echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Numer wpisany z odstępami („600 111 222") musi trafić w zapis bez odstępów i odwrotnie.
    $like  = '%' . $q . '%';
    $digits = preg_replace('/\D+/', '', $q) ?? '';
    $dlike  = $digits !== '' ? '%' . $digits . '%' : null;

    $rows = db_all(
        "SELECT id, imie_nazwisko, telefon, organizacja, stanowisko, type
           FROM crm_contacts
          WHERE crm_active = 1 AND telefon IS NOT NULL AND TRIM(telefon) <> ''
            AND (imie_nazwisko LIKE ? OR organizacja LIKE ? OR email LIKE ? OR telefon LIKE ?
                 OR (? IS NOT NULL AND REPLACE(REPLACE(REPLACE(telefon,' ',''),'-',''),'+','') LIKE ?))
       ORDER BY imie_nazwisko
          LIMIT ?",
        [$like, $like, $like, $like, $dlike, (string)$dlike, $limit]
    );
    foreach ($rows as $r) {
        if ($it = crm_mobile_item_contact($r)) $items[] = $it;
    }

    $prows = db_all(
        "SELECT p.id, p.contact_id, p.imie_nazwisko, p.telefon, p.stanowisko,
                c.imie_nazwisko AS org_name
           FROM crm_contact_persons p
           JOIN crm_contacts       c ON c.id = p.contact_id
          WHERE c.crm_active = 1 AND p.telefon IS NOT NULL AND TRIM(p.telefon) <> ''
            AND (p.imie_nazwisko LIKE ? OR p.stanowisko LIKE ? OR c.imie_nazwisko LIKE ? OR p.telefon LIKE ?
                 OR (? IS NOT NULL AND REPLACE(REPLACE(REPLACE(p.telefon,' ',''),'-',''),'+','') LIKE ?))
       ORDER BY p.imie_nazwisko
          LIMIT ?",
        [$like, $like, $like, $like, $dlike, (string)$dlike, $limit]
    );
    foreach ($prows as $r) {
        if ($it = crm_mobile_item_person($r)) $items[] = $it;
    }
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
