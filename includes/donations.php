<?php
/**
 * includes/donations.php — rejestr darowizn.
 *
 * Powstał pod konkretną potrzebę: darczyńcy pytają w lutym o potwierdzenie
 * przekazanej darowizny do rozliczenia PIT. Bez rejestru nie ma czego
 * potwierdzać, a odtwarzanie wpłat z wyciągów raz w roku jest i pracochłonne,
 * i zawodne.
 *
 * DWA RODZAJE DAROWIZN, DWA RÓŻNE DOKUMENTY — i to nie jest kosmetyka.
 * Art. 26 ust. 7 ustawy o PIT określa, czym podatnik dokumentuje odliczenie:
 *
 *   pkt 1 — darowizna PIENIĘŻNA: dowodem jest wpłata na rachunek płatniczy
 *           obdarowanego. Nasze potwierdzenie jest wtedy dokumentem
 *           POMOCNICZYM (rocznym zestawieniem), a nie podstawą odliczenia —
 *           i dokument musi to mówić wprost, żeby nikt nie składał go
 *           w urzędzie zamiast potwierdzenia przelewu;
 *   pkt 2 — darowizna INNA NIŻ PIENIĘŻNA: dowodem jest dokument z danymi
 *           darczyńcy i wartością darowizny WRAZ Z OŚWIADCZENIEM OBDAROWANEGO
 *           O JEJ PRZYJĘCIU. Tu dokument wystawiany przez nas jest właśnie tym
 *           wymaganym dowodem — i musi zawierać oświadczenie o przyjęciu.
 *
 * Stąd dwa generatory w includes/donation_pdf.php, a nie jeden „uniwersalny".
 *
 * GOTÓWKA. Darowizna pieniężna wpłacona gotówką nie jest wpłatą na rachunek
 * płatniczy, więc nie ma dowodu wymaganego przez pkt 1. Rejestr ją przyjmuje
 * (bo wpłaty się zdarzają i muszą być zaksięgowane), ale dokument stawia przy
 * niej jawne zastrzeżenie. Milczenie byłoby tu gorsze niż odmowa zapisu.
 *
 * Zob. [[project_faktury]] (mPDF i szablon), [[project_crm_campaigns_automation]].
 */

declare(strict_types=1);

require_once __DIR__ . '/crm.php';
// address_format() mieszka w address.php i NIE jest wciągane przez crm.php —
// bez tego adres darczyńcy cicho wypadał z dokumentu (function_exists() był
// po prostu fałszywy).
require_once __DIR__ . '/address.php';

/** Rodzaje darowizn — decydują o wymaganym dokumencie. */
const DONATION_KINDS = [
    'pieniezna' => 'Pieniężna',
    'rzeczowa'  => 'Rzeczowa (niepieniężna)',
];

/** Kanały wpłaty. `gotowka` jest tu świadomie — patrz nagłówek pliku. */
const DONATION_CHANNELS = [
    'przelew' => 'Przelew na rachunek',
    'stripe'  => 'Płatność kartą (Stripe)',
    'payu'    => 'Płatność online (PayU)',
    'p24'     => 'Płatność online (Przelewy24)',
    'zbiorka' => 'Zbiórka / wpłata za pośrednictwem',
    'gotowka' => 'Gotówka',
    'inne'    => 'Inne',
];

function donations_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = crm_db();

    // Dane darczyńcy są zapisane NA DAROWIŹNIE, nie tylko przez contact_id:
    // wpłata bywa od kogoś, kogo nie ma jeszcze w kartotece, a dokument musi
    // dać się wystawić bez zakładania rekordu CRM „na siłę".
    $pdo->exec("CREATE TABLE IF NOT EXISTS donations (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id    INTEGER REFERENCES crm_contacts(id) ON DELETE SET NULL,
        donor_name    TEXT    NOT NULL,
        donor_address TEXT,
        donor_pesel   TEXT,
        donor_nip     TEXT,
        kind          TEXT    NOT NULL DEFAULT 'pieniezna',
        amount        REAL    NOT NULL DEFAULT 0,
        currency      TEXT    NOT NULL DEFAULT 'PLN',
        donation_date DATE    NOT NULL,
        channel       TEXT    NOT NULL DEFAULT 'przelew',
        purpose       TEXT,
        description   TEXT,
        bank_account  TEXT,
        bank_ref      TEXT,
        is_anonymous  INTEGER NOT NULL DEFAULT 0,
        note          TEXT,
        ext_source    TEXT,
        accepted_at   DATETIME,
        created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME,
        deleted_at    DATETIME
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_donations_contact ON donations(contact_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_donations_date    ON donations(donation_date)");
    // Wpłata zaciągnięta z bramki płatniczej nie może się zdublować przy
    // ponownym imporcie — indeks częściowy, bo większość wpisów jest ręczna.
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_donations_ext
                    ON donations(ext_source) WHERE ext_source IS NOT NULL AND deleted_at IS NULL");
    } catch (\Throwable $e) {}
}

function donation_get(int $id): ?array
{
    donations_migrate();
    return crm_one("SELECT * FROM donations WHERE id = ? AND deleted_at IS NULL", [$id]);
}

/**
 * Dopisuje darowiznę. Wymagane: donor_name, amount, donation_date.
 *
 * @return int ID darowizny albo 0, gdy dane niekompletne.
 */
function donation_add(array $d, int $user_id = 0): int
{
    donations_migrate();

    $name = trim((string)($d['donor_name'] ?? ''));
    $amt  = (float)($d['amount'] ?? 0);
    $date = trim((string)($d['donation_date'] ?? ''));
    if ($name === '' || $amt <= 0 || $date === '') return 0;

    $kind    = array_key_exists((string)($d['kind'] ?? ''), DONATION_KINDS)      ? (string)$d['kind']    : 'pieniezna';
    $channel = array_key_exists((string)($d['channel'] ?? ''), DONATION_CHANNELS) ? (string)$d['channel'] : 'przelew';

    return crm_insert('donations', [
        'contact_id'    => ((int)($d['contact_id'] ?? 0)) ?: null,
        'donor_name'    => $name,
        'donor_address' => trim((string)($d['donor_address'] ?? '')) ?: null,
        'donor_pesel'   => preg_replace('/\D+/', '', (string)($d['donor_pesel'] ?? '')) ?: null,
        'donor_nip'     => preg_replace('/\D+/', '', (string)($d['donor_nip'] ?? '')) ?: null,
        'kind'          => $kind,
        'amount'        => round($amt, 2),
        'currency'      => strtoupper(trim((string)($d['currency'] ?? 'PLN'))) ?: 'PLN',
        'donation_date' => $date,
        'channel'       => $kind === 'rzeczowa' ? 'inne' : $channel,
        'purpose'       => trim((string)($d['purpose'] ?? '')) ?: null,
        'description'   => trim((string)($d['description'] ?? '')) ?: null,
        'bank_account'  => trim((string)($d['bank_account'] ?? '')) ?: null,
        'bank_ref'      => trim((string)($d['bank_ref'] ?? '')) ?: null,
        'is_anonymous'  => !empty($d['is_anonymous']) ? 1 : 0,
        'note'          => trim((string)($d['note'] ?? '')) ?: null,
        'ext_source'    => trim((string)($d['ext_source'] ?? '')) ?: null,
        // Darowizna rzeczowa: przyjęcie stwierdzamy z chwilą wpisu do rejestru —
        // to ta data trafia na oświadczenie o przyjęciu.
        'accepted_at'   => $kind === 'rzeczowa' ? date('Y-m-d H:i:s') : null,
        'created_by'    => $user_id ?: null,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
}

/** Aktualizuje darowiznę — tylko pola przekazane w tablicy. */
function donation_update(int $id, array $d): void
{
    donations_migrate();
    $allowed = ['contact_id','donor_name','donor_address','donor_pesel','donor_nip','kind','amount',
                'currency','donation_date','channel','purpose','description','bank_account','bank_ref',
                'is_anonymous','note'];
    $set = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $d)) continue;
        $set[$k] = $d[$k];
    }
    if (!$set) return;
    if (isset($set['amount']))     $set['amount']     = round((float)$set['amount'], 2);
    if (isset($set['contact_id'])) $set['contact_id'] = ((int)$set['contact_id']) ?: null;

    // Zmiana rodzaju na rzeczową domyka datę przyjęcia — bez niej oświadczenie
    // o przyjęciu wyszłoby bez daty, a to jego istotny element.
    if (($set['kind'] ?? '') === 'rzeczowa') {
        $cur = crm_one("SELECT accepted_at FROM donations WHERE id = ?", [$id]);
        if ($cur && empty($cur['accepted_at'])) $set['accepted_at'] = date('Y-m-d H:i:s');
        // Ta sama normalizacja co przy dopisywaniu: darowizna rzeczowa nie ma
        // sposobu wpłaty, a zostawiony „przelew" fałszowałby filtry rejestru.
        $set['channel'] = 'inne';
    }

    $set['updated_at'] = date('Y-m-d H:i:s');
    crm_update('donations', $set, $id);
}

/**
 * Usuwa darowiznę miękko — rejestr wpłat jest podstawą wystawionych
 * potwierdzeń, więc twarde DELETE zabierałoby dokumentom pokrycie.
 */
function donation_delete(int $id): void
{
    donations_migrate();
    crm_db()->prepare("UPDATE donations SET deleted_at = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $id]);
}

/**
 * Lista darowizn.
 *
 * @param array $f year, contact_id, kind, channel, q, limit
 */
function donations_list(array $f = []): array
{
    donations_migrate();

    $w = ['d.deleted_at IS NULL'];
    $p = [];
    if (!empty($f['year']))       { $w[] = "strftime('%Y', d.donation_date) = ?"; $p[] = (string)(int)$f['year']; }
    if (!empty($f['contact_id'])) { $w[] = 'd.contact_id = ?';                    $p[] = (int)$f['contact_id']; }
    if (!empty($f['kind']))       { $w[] = 'd.kind = ?';                          $p[] = (string)$f['kind']; }
    if (!empty($f['channel']))    { $w[] = 'd.channel = ?';                       $p[] = (string)$f['channel']; }
    if (!empty($f['q'])) {
        // Opis jest szukany razem z celem: przy darowiźnie rzeczowej to on nosi
        // całą treść („5 laptopów…"), więc pominięcie go czyniłoby wyszukiwanie
        // bezużytecznym dokładnie tam, gdzie jest najbardziej potrzebne.
        $w[] = "(LOWER(d.donor_name) LIKE ? OR LOWER(COALESCE(d.purpose,'')) LIKE ?"
             . " OR LOWER(COALESCE(d.description,'')) LIKE ? OR LOWER(COALESCE(d.bank_ref,'')) LIKE ?)";
        $like = '%' . mb_strtolower(trim((string)$f['q']), 'UTF-8') . '%';
        $p[] = $like; $p[] = $like; $p[] = $like; $p[] = $like;
    }
    $limit = max(1, min(2000, (int)($f['limit'] ?? 500)));

    return crm_all(
        "SELECT d.*, c.imie_nazwisko AS contact_name
           FROM donations d
      LEFT JOIN crm_contacts c ON c.id = d.contact_id
          WHERE " . implode(' AND ', $w) . "
       ORDER BY d.donation_date DESC, d.id DESC
          LIMIT {$limit}",
        $p
    );
}

/** Darowizny kontaktu, najnowsze pierwsze. */
function donations_for_contact(int $contact_id, int $limit = 200): array
{
    return donations_list(['contact_id' => $contact_id, 'limit' => $limit]);
}

/** Lata, w których są jakiekolwiek darowizny — do przełącznika roku. */
function donation_years(): array
{
    donations_migrate();
    $out = [];
    foreach (crm_all(
        "SELECT DISTINCT strftime('%Y', donation_date) AS y FROM donations
          WHERE deleted_at IS NULL ORDER BY y DESC"
    ) as $r) {
        if (!empty($r['y'])) $out[] = (int)$r['y'];
    }
    if (!$out) $out[] = (int)date('Y');
    return $out;
}

/** Podsumowanie roku: liczba wpłat, suma, liczba darczyńców, rozbicie na rodzaje. */
function donations_stats(int $year): array
{
    donations_migrate();
    $r = crm_one(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(amount), 0) AS total,
                COUNT(DISTINCT COALESCE(contact_id, LOWER(donor_name))) AS donors,
                COALESCE(SUM(CASE WHEN kind = 'rzeczowa'  THEN amount ELSE 0 END), 0) AS total_rzeczowa,
                COALESCE(SUM(CASE WHEN channel = 'gotowka' THEN amount ELSE 0 END), 0) AS total_gotowka
           FROM donations
          WHERE deleted_at IS NULL AND strftime('%Y', donation_date) = ?",
        [(string)$year]
    ) ?: [];
    return [
        'count'          => (int)($r['cnt'] ?? 0),
        'total'          => (float)($r['total'] ?? 0),
        'donors'         => (int)($r['donors'] ?? 0),
        'total_rzeczowa' => (float)($r['total_rzeczowa'] ?? 0),
        'total_gotowka'  => (float)($r['total_gotowka'] ?? 0),
    ];
}

/**
 * Darowizny jednego darczyńcy w danym roku — zestaw pod potwierdzenie roczne.
 *
 * Grupujemy po kartotece CRM, nie po nazwisku: „Anna Kowalska" bywa więcej niż
 * jedna, a zestawienie musi obejmować dokładnie jedną osobę.
 */
function donations_annual_set(int $contact_id, int $year): array
{
    donations_migrate();
    return crm_all(
        "SELECT * FROM donations
          WHERE deleted_at IS NULL AND contact_id = ? AND strftime('%Y', donation_date) = ?
       ORDER BY donation_date ASC, id ASC",
        [$contact_id, (string)$year]
    );
}

/**
 * Dane darczyńcy do dokumentu.
 *
 * Gdy darowizna jest przypisana do kartoteki, adres bierzemy z niej — kartoteka
 * jest utrzymywana i to tam trafiają aktualizacje. Snapshot z darowizny jest
 * zapasem dla wpłat bez kartoteki.
 */
function donation_donor(array $d): array
{
    $name    = (string)($d['donor_name'] ?? '');
    $address = (string)($d['donor_address'] ?? '');
    $pesel   = (string)($d['donor_pesel'] ?? '');
    $nip     = (string)($d['donor_nip'] ?? '');

    if (!empty($d['contact_id'])) {
        $c = crm_one("SELECT * FROM crm_contacts WHERE id = ?", [(int)$d['contact_id']]);
        if ($c) {
            $name = (string)($c['imie_nazwisko'] ?? $name);
            $addr = function_exists('address_format') ? (string)address_format($c) : '';
            if ($addr !== '') $address = $addr;
            if (!empty($c['pesel'])) $pesel = (string)$c['pesel'];
            if (!empty($c['nip']))   $nip   = (string)$c['nip'];
        }
    }

    return ['name' => $name, 'address' => $address, 'pesel' => $pesel, 'nip' => $nip];
}

/**
 * Zastrzeżenia do dokumentu — czego darowizna NIE dowodzi.
 *
 * @return array<int,string> Lista uwag; pusta, gdy dokumentacja jest bez zarzutu.
 */
function donation_doc_warnings(array $donations): array
{
    $out = [];
    $cash = array_filter($donations, fn($d) => ($d['kind'] ?? '') === 'pieniezna' && ($d['channel'] ?? '') === 'gotowka');
    if ($cash) {
        $out[] = 'Wpłaty gotówkowe ujęte w zestawieniu nie są wpłatami na rachunek płatniczy, '
               . 'więc nie mają dowodu, o którym mówi art. 26 ust. 7 pkt 1 ustawy o podatku '
               . 'dochodowym od osób fizycznych.';
    }
    return $out;
}

/** Etykieta rodzaju darowizny. */
function donation_kind_label(string $kind): string
{
    return DONATION_KINDS[$kind] ?? $kind;
}

/** Etykieta kanału wpłaty. */
function donation_channel_label(string $channel): string
{
    return DONATION_CHANNELS[$channel] ?? $channel;
}
