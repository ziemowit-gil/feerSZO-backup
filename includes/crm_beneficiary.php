<?php
/**
 * includes/crm_beneficiary.php — beneficjenci programów w kartotece CRM.
 *
 * Osoba, z którą korespondujemy w CRM, bywa jednocześnie uczestnikiem naszych
 * działań: kursantem Dydaktyki 3 (k30_clients + zapisy TI), osobą korzystającą
 * z konsultacji, wsparcia sprzętowego. Dotąd te dwa światy się nie widziały —
 * prowadzący sprawę w CRM nie miał pojęcia, że pisze do kogoś, kto od pół roku
 * jest u nas na zajęciach.
 *
 * DOPASOWANIE JEST OSTROŻNE, I TO CELOWO. Kartoteka beneficjenta zawiera dane
 * wrażliwe, więc pomyłka nie kosztuje tu „dziwnego wpisu na ekranie", ale
 * pokazanie danych jednej osoby na karcie drugiej. Dlatego:
 *
 *   • automatycznie łączymy TYLKO po numerze PESEL — jest unikalny;
 *   • e-mail i imię z nazwiskiem dają PROPOZYCJĘ do potwierdzenia przez
 *     człowieka, nigdy gotowe powiązanie. Rodzeństwo zapisane na zajęcia
 *     regularnie ma ten sam adres rodzica, a „Anna Kowalska" bywa więcej niż
 *     jedna — sam adres albo samo nazwisko to nie tożsamość;
 *   • gdy oba rekordy mają PESEL i są to RÓŻNE numery, propozycji nie ma —
 *     wiemy wtedy, że to inne osoby.
 *
 * Potwierdzenia i odrzucenia trafiają do `crm_beneficiary_links`, więc raz
 * odrzucona propozycja nie wraca przy każdym wejściu na kartotekę.
 *
 * ZAKRES DANYCH. Panel pokazuje udział w programach (grupy, godziny, liczbę
 * konsultacji) i link do kartoteki w Dydaktyce 3. NIE pokazuje opisu problemu,
 * sprzętu, uwag, adresu ani danych wrażliwych — kto ma je widzieć, otwiera
 * kartotekę w module, gdzie obowiązują jego uprawnienia i audyt dostępu.
 *
 * Zob. [[project_karty30_api_standalone]], [[project_ti_rozliczenia_kombinowane]].
 */

require_once __DIR__ . '/crm.php';

function crm_beneficiary_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // Tabela mieszka w bazie CRM (jak reszta powiązań kartoteki), a beneficjenci
    // w bazie głównej — dlatego nie ma tu FK na k30_clients: przy rozdzielonych
    // bazach byłby nieegzekwowalny, a przy jednej i tak nie ratuje przed
    // usunięciem kartoteki w module. Martwe powiązania odsiewamy przy odczycie.
    crm_db()->exec("CREATE TABLE IF NOT EXISTS crm_beneficiary_links (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id   INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        client_id    INTEGER NOT NULL,
        status       TEXT    NOT NULL DEFAULT 'confirmed',
        match_source TEXT    NOT NULL DEFAULT 'manual',
        created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(contact_id, client_id)
    )");
    crm_db()->exec("CREATE INDEX IF NOT EXISTS idx_crm_benef_contact ON crm_beneficiary_links(contact_id)");
}

/**
 * Czy zalogowany może widzieć powiązania z beneficjentami.
 *
 * Ta sama zasada, co wejście do Dydaktyki 3 (k30_require_access): rola z prawem
 * odczytu, administrator albo doradca. CRM nie może być obejściem uprawnień
 * modułu, w którym te dane mieszkają.
 */
function crm_beneficiary_can_view(): bool
{
    if (function_exists('is_admin') && is_admin()) return true;
    if (function_exists('can_read') && can_read('karty30')) return true;
    try {
        $u = function_exists('current_user') ? current_user() : null;
        if ($u) {
            $row = db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)($u['id'] ?? 0)]);
            if (!empty($row['k30_consultant'])) return true;
        }
    } catch (\Throwable $e) {}
    return false;
}

/** Cyfry z numeru PESEL — porównania po samych cyfrach, bo bywa ze spacjami. */
function _crm_benef_pesel(?string $v): string
{
    $d = preg_replace('/\D+/', '', (string)$v) ?? '';
    return strlen($d) === 11 ? $d : '';
}

/** Nazwa do porównań: bez ogonków, wielkości liter i podwójnych spacji. */
function _crm_benef_name_key(?string $v): string
{
    $s = trim((string)$v);
    if ($s === '') return '';
    $s = str_replace(
        ['ą','ć','ę','ł','ń','ó','ś','ź','ż','Ą','Ć','Ę','Ł','Ń','Ó','Ś','Ź','Ż'],
        ['a','c','e','l','n','o','s','z','z','a','c','e','l','n','o','s','z','z'],
        $s
    );
    $s = preg_replace('/\s+/', ' ', mb_strtolower($s, 'UTF-8')) ?? '';
    // Znaczniki seedów/testów w nawiasach nie są częścią imienia.
    $s = trim(preg_replace('/\s*[\[\(][^\]\)]*[\]\)]\s*/u', ' ', $s) ?? '');
    return trim($s);
}

/**
 * Streszczenie udziału beneficjenta w programach — wyłącznie dane nieskromne:
 * grupy, godziny, liczniki. Bez opisu problemu, sprzętu i uwag.
 */
function crm_beneficiary_summary(int $client_id): ?array
{
    $c = db_one(
        "SELECT id, name, status, available_hours, used, used_paid, created_at
           FROM k30_clients WHERE id = ?", [$client_id]
    );
    if (!$c) return null;

    $groups = [];
    try {
        foreach (db_all(
            "SELECT e.status AS enr_status, k.name, k.group_code, k.status AS course_status
               FROM k30_ti_enrollments e
          LEFT JOIN k30_ti_courses k ON k.id = e.course_id
              WHERE e.client_id = ?
           ORDER BY e.id DESC", [$client_id]
        ) as $g) {
            $groups[] = [
                'name'   => (string)($g['name'] ?? ''),
                'code'   => (string)($g['group_code'] ?? ''),
                'status' => (string)($g['enr_status'] ?? ''),
            ];
        }
    } catch (\Throwable $e) { /* moduł TI może nie być wdrożony */ }

    $consultations = 0; $last = null;
    try {
        $r = db_one("SELECT COUNT(*) AS c, MAX(consultation_datetime) AS m FROM k30_consultations WHERE client_id = ?", [$client_id]);
        $consultations = (int)($r['c'] ?? 0);
        $last = $r['m'] ?? null;
    } catch (\Throwable $e) {}
    try {
        $r = db_one("SELECT MAX(start_time) AS m FROM k30_schedules WHERE client_id = ?", [$client_id]);
        if (!empty($r['m']) && (string)$r['m'] > (string)$last) $last = $r['m'];
    } catch (\Throwable $e) {}

    return [
        'id'            => (int)$c['id'],
        'name'          => (string)$c['name'],
        'status'        => (string)($c['status'] ?? ''),
        'hours_total'   => (float)($c['available_hours'] ?? 0),
        'hours_used'    => (float)($c['used'] ?? 0),
        'hours_paid'    => (float)($c['used_paid'] ?? 0),
        'groups'        => $groups,
        'consultations' => $consultations,
        'last_activity' => $last,
        'since'         => $c['created_at'] ?? null,
    ];
}

/**
 * Beneficjenci powiązani z kontaktem oraz propozycje do potwierdzenia.
 *
 * @return array{linked:array<int,array>,suggested:array<int,array>}
 */
function crm_contact_beneficiaries(array $contact): array
{
    crm_beneficiary_migrate();

    $contact_id = (int)($contact['id'] ?? 0);
    $out = ['linked' => [], 'suggested' => []];
    if ($contact_id <= 0) return $out;

    // Decyzje operatora — potwierdzone i odrzucone. Odrzucone muszą być znane,
    // inaczej ta sama zła propozycja wracałaby po każdym odświeżeniu.
    $decided = [];
    foreach (crm_all("SELECT client_id, status, match_source FROM crm_beneficiary_links WHERE contact_id = ?", [$contact_id]) as $r) {
        $decided[(int)$r['client_id']] = $r;
    }

    $pesel = _crm_benef_pesel($contact['pesel'] ?? null);
    $email = mb_strtolower(trim((string)($contact['email'] ?? '')), 'UTF-8');
    $nkey  = _crm_benef_name_key($contact['imie_nazwisko'] ?? null);

    // ── Powiązania automatyczne: PESEL ──
    $auto = [];
    if ($pesel !== '') {
        try {
            foreach (db_all(
                "SELECT id, pesel FROM k30_clients WHERE COALESCE(pesel,'') <> ''"
            ) as $r) {
                if (_crm_benef_pesel($r['pesel']) === $pesel) $auto[(int)$r['id']] = 'pesel';
            }
        } catch (\Throwable $e) { return $out; }
    }

    // Ręczne potwierdzenia dokładamy do automatycznych; odrzucenie PESEL-u też
    // szanujemy — operator mógł zobaczyć, że numer wpisano komuś błędnie.
    foreach ($decided as $cid => $d) {
        if ($d['status'] === 'confirmed') $auto[$cid] = 'manual';
        if ($d['status'] === 'rejected')  unset($auto[$cid]);
    }

    foreach ($auto as $cid => $src) {
        $sum = crm_beneficiary_summary((int)$cid);
        if (!$sum) continue;                      // kartoteka usunięta w module
        $sum['match'] = $src;
        $out['linked'][] = $sum;
    }

    // ── Propozycje: e-mail albo imię i nazwisko ──
    if ($email !== '' || $nkey !== '') {
        try {
            $rows = db_all("SELECT id, name, email, pesel FROM k30_clients");
        } catch (\Throwable $e) { $rows = []; }

        foreach ($rows as $r) {
            $cid = (int)$r['id'];
            if (isset($auto[$cid]) || isset($decided[$cid])) continue;

            // Dwa różne PESEL-e to rozstrzygający dowód, że to inne osoby.
            $rp = _crm_benef_pesel($r['pesel'] ?? null);
            if ($pesel !== '' && $rp !== '' && $rp !== $pesel) continue;

            $why = '';
            if ($email !== '' && mb_strtolower(trim((string)($r['email'] ?? '')), 'UTF-8') === $email) {
                $why = 'e-mail';
            }
            if ($nkey !== '' && _crm_benef_name_key($r['name']) === $nkey) {
                $why = $why !== '' ? 'e-mail i nazwa' : 'imię i nazwisko';
            }
            if ($why === '') continue;

            $sum = crm_beneficiary_summary($cid);
            if (!$sum) continue;
            $sum['match'] = $why;
            $out['suggested'][] = $sum;
        }
    }

    return $out;
}

/**
 * Zapisuje decyzję operatora o propozycji.
 *
 * @param string $status 'confirmed' albo 'rejected'
 */
function crm_beneficiary_decide(int $contact_id, int $client_id, string $status, int $user_id = 0): bool
{
    crm_beneficiary_migrate();
    if (!in_array($status, ['confirmed', 'rejected'], true)) return false;
    if ($contact_id <= 0 || $client_id <= 0) return false;

    // Kartoteka musi istnieć — bez tego dałoby się „potwierdzić" cokolwiek.
    try {
        if (!db_one("SELECT id FROM k30_clients WHERE id = ?", [$client_id])) return false;
    } catch (\Throwable $e) { return false; }

    $existing = crm_one("SELECT id FROM crm_beneficiary_links WHERE contact_id=? AND client_id=?", [$contact_id, $client_id]);
    if ($existing) {
        crm_db()->prepare("UPDATE crm_beneficiary_links SET status=?, created_by=?, created_at=? WHERE id=?")
            ->execute([$status, $user_id ?: null, date('Y-m-d H:i:s'), (int)$existing['id']]);
        return true;
    }
    crm_insert('crm_beneficiary_links', [
        'contact_id'   => $contact_id,
        'client_id'    => $client_id,
        'status'       => $status,
        'match_source' => 'manual',
        'created_by'   => $user_id ?: null,
        'created_at'   => date('Y-m-d H:i:s'),
    ]);
    return true;
}

/** Usuwa decyzję — propozycja wróci przy następnym wejściu na kartotekę. */
function crm_beneficiary_unlink(int $contact_id, int $client_id): void
{
    crm_beneficiary_migrate();
    crm_db()->prepare("DELETE FROM crm_beneficiary_links WHERE contact_id=? AND client_id=?")
        ->execute([$contact_id, $client_id]);
}

/** Etykiety statusów kartoteki beneficjenta. */
function crm_beneficiary_status_label(string $status): string
{
    return [
        'enrolled'  => 'zapisany',
        'active'    => 'aktywny',
        'waiting'   => 'lista oczekujących',
        'finished'  => 'zakończony',
        'resigned'  => 'rezygnacja',
        'inactive'  => 'nieaktywny',
    ][$status] ?? $status;
}
