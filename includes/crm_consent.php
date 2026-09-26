<?php
/**
 * includes/crm_consent.php — zgody per cel (RODO).
 *
 * Do tej pory CRM miał jedną flagę `crm_contacts.email_opt_out`: albo wysyłamy
 * wszystko, albo nic. Do zgodnej z prawem wysyłki to nie wystarcza — zgoda jest
 * udzielana NA KONKRETNY CEL, a przy kontroli trzeba wykazać KIEDY i W JAKI
 * SPOSÓB ją zebrano oraz na jaką treść klauzuli. Wycofanie zgody nie może przy
 * tym zacierać śladu, że zgoda kiedyś była.
 *
 * Dlatego `crm_consents` jest rejestrem ZDARZEŃ (append-only), a nie tabelą
 * stanu: każde udzielenie i każde wycofanie to nowy wiersz. Stan bieżący to
 * najnowsze zdarzenie dla pary (kontakt, cel). Dzięki temu historia jest pełna
 * i odtwarzalna, a „wycofałem zgodę, a mimo to dostaję maile" da się rozstrzygnąć
 * z dokładnością do sekundy.
 *
 * ZAKRES: zgoda jest na poziomie KONTAKTU, bo na tym poziomie wysyłamy (adresata
 * wybiera crm_contact_recipient()). Osoba, która zgodę udzieliła lub wycofała,
 * jest zapisywana w person_id jako ślad audytowy — nie tworzy osobnego stanu.
 *
 * ZGODA NIE JEST JEDYNĄ PODSTAWĄ. Do partnerów, kontrahentów i uczestników
 * własnych działań pisze się zwykle w oparciu o umowę albo uzasadniony interes.
 * Ten moduł obsługuje zgody marketingowe i nie blokuje korespondencji
 * operacyjnej — filtr celu włącza się świadomie przy konkretnej wysyłce.
 *
 * Zob. [[project_crm_campaigns_automation]].
 */

require_once __DIR__ . '/crm.php';

/** Kanały, w których cel może być realizowany. */
const CRM_CONSENT_CHANNELS = [
    'email' => 'E-mail',
    'sms'   => 'SMS / telefon',
    'any'   => 'Dowolny kanał',
];

/**
 * Sposoby pozyskania zgody. Wybór jest wymagany przy ręcznym wpisie —
 * „skądś to mamy" nie jest odpowiedzią, którą da się obronić przy kontroli.
 */
const CRM_CONSENT_SOURCES = [
    'formularz' => 'Formularz na stronie',
    'email'     => 'Wiadomość e-mail',
    'papier'    => 'Dokument papierowy / podpis',
    'telefon'   => 'Rozmowa telefoniczna',
    'osobiscie' => 'Osobiście',
    'wydarzenie'=> 'Zapis na wydarzeniu',
    'import'    => 'Import danych',
    'link'      => 'Link w wiadomości',
];

/** Katalog startowy — cele typowe dla organizacji pozarządowej. */
const CRM_CONSENT_SEED = [
    ['newsletter',  'Newsletter i informacje o działalności', 'email',
     'Zgoda na otrzymywanie newslettera i informacji o działalności organizacji na podany adres e-mail.'],
    ['fundraising', 'Prośby o wsparcie i zbiórki',            'email',
     'Zgoda na otrzymywanie informacji o zbiórkach, akcjach pomocowych i możliwościach wsparcia organizacji.'],
    ['wydarzenia',  'Zaproszenia na wydarzenia i szkolenia',  'email',
     'Zgoda na otrzymywanie zaproszeń na wydarzenia, szkolenia i spotkania organizowane przez organizację.'],
    ['oferta',      'Informacje o ofercie odpłatnej',         'email',
     'Zgoda na otrzymywanie informacji handlowych o odpłatnej działalności organizacji.'],
    ['sms',         'Powiadomienia SMS i kontakt telefoniczny','sms',
     'Zgoda na kontakt telefoniczny i otrzymywanie wiadomości SMS w celach marketingowych.'],
];

function crm_consent_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = crm_db();

    // Katalog celów. `klauzula` to treść, którą pokazujemy przy zbieraniu zgody —
    // jej kopia trafia do każdego zdarzenia, bo klauzula z czasem się zmienia,
    // a zgoda dotyczy brzmienia z dnia jej udzielenia.
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_consent_purposes (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        kod        TEXT    NOT NULL UNIQUE,
        nazwa      TEXT    NOT NULL,
        opis       TEXT,
        klauzula   TEXT,
        channel    TEXT    NOT NULL DEFAULT 'email',
        is_active  INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Rejestr zdarzeń. Bez UPDATE i bez DELETE — stan wynika z najnowszego wiersza.
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_consents (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id        INTEGER NOT NULL REFERENCES crm_contacts(id) ON DELETE CASCADE,
        person_id         INTEGER REFERENCES crm_contact_persons(id) ON DELETE SET NULL,
        purpose_id        INTEGER NOT NULL REFERENCES crm_consent_purposes(id) ON DELETE CASCADE,
        granted           INTEGER NOT NULL DEFAULT 1,
        event_at          DATETIME NOT NULL,
        source            TEXT    NOT NULL DEFAULT 'import',
        source_detail     TEXT,
        klauzula_snapshot TEXT,
        ip                TEXT,
        note              TEXT,
        created_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Indeks pod zapytanie o stan: (kontakt, cel, id DESC).
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_consents_state   ON crm_consents(contact_id, purpose_id, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_crm_consents_purpose ON crm_consents(purpose_id, granted)");

    // Ważność zgody. 0 = bezterminowa, czyli dotychczasowe zachowanie — zgoda raz
    // udzielona obowiązuje aż do wycofania. Wartość dodatnia mówi, po ilu miesiącach
    // zgodę trzeba odnowić; po tym czasie przestaje uprawniać do wysyłki.
    try { $pdo->exec("ALTER TABLE crm_consent_purposes ADD COLUMN valid_months INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}

    // Klauzula informacyjna (art. 13) z modułu Klauzule RODO dla celu — `klauzula`
    // wyżej to treść samej zgody; tu wskazujemy pełną informację o przetwarzaniu,
    // a jej akceptacja trafia do rejestru akceptacji modułu (gdpr_clause_acceptances).
    try { $pdo->exec("ALTER TABLE crm_consent_purposes ADD COLUMN gdpr_clause_slug TEXT"); } catch (\Throwable $e) {}

    // Cel wysyłki kampanii — bez niego kampania działa jak dotąd (patrz niżej).
    try { $pdo->exec("ALTER TABLE crm_campaigns ADD COLUMN purpose_id INTEGER REFERENCES crm_consent_purposes(id) ON DELETE SET NULL"); } catch (\Throwable $e) {}

    // Katalog startowy tylko dla pustej tabeli — organizacja, która sobie cele
    // poukładała po swojemu (także usuwając nasze), nie dostaje ich z powrotem.
    $cnt = (int)($pdo->query("SELECT COUNT(*) AS c FROM crm_consent_purposes")->fetch()['c'] ?? 0);
    if ($cnt === 0) {
        $ins = $pdo->prepare(
            "INSERT INTO crm_consent_purposes (kod, nazwa, klauzula, channel, is_active, sort_order, created_at)
             VALUES (?, ?, ?, ?, 1, ?, datetime('now'))"
        );
        foreach (CRM_CONSENT_SEED as $i => [$kod, $nazwa, $channel, $klauzula]) {
            try { $ins->execute([$kod, $nazwa, $klauzula, $channel, $i]); } catch (\Throwable $e) {}
        }
    }
}

/** @return array<int,array> Cele z katalogu, w kolejności do wyświetlania. */
function crm_consent_purposes(bool $only_active = true): array
{
    crm_consent_migrate();
    $sql = "SELECT * FROM crm_consent_purposes";
    if ($only_active) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY sort_order, nazwa";
    return crm_all($sql);
}

/** Cel po ID (liczba) albo po kodzie (tekst). */
function crm_consent_purpose(int|string $ref): ?array
{
    crm_consent_migrate();
    if (is_int($ref) || ctype_digit((string)$ref)) {
        return crm_one("SELECT * FROM crm_consent_purposes WHERE id = ?", [(int)$ref]);
    }
    return crm_one("SELECT * FROM crm_consent_purposes WHERE kod = ?", [(string)$ref]);
}

/**
 * Stan bieżący zgody — najnowsze zdarzenie dla pary (kontakt, cel).
 *
 * @return array|null Wiersz zdarzenia albo null, gdy nic nie zapisano.
 *                    NULL to „nie wiemy", a nie „zgody nie ma".
 */
function crm_consent_state(int $contact_id, int $purpose_id): ?array
{
    crm_consent_migrate();
    return crm_one(
        "SELECT * FROM crm_consents
          WHERE contact_id = ? AND purpose_id = ?
       ORDER BY id DESC LIMIT 1",
        [$contact_id, $purpose_id]
    );
}

/** @return array<int,array> Stany wszystkich celów kontaktu, kluczowane purpose_id. */
function crm_consent_states(int $contact_id): array
{
    crm_consent_migrate();
    $out = [];
    // Jeden przelot po historii kontaktu — najnowsze zdarzenie wygrywa.
    foreach (crm_all(
        "SELECT * FROM crm_consents WHERE contact_id = ? ORDER BY id ASC", [$contact_id]
    ) as $r) {
        $out[(int)$r['purpose_id']] = $r;
    }
    return $out;
}

/**
 * Zapisuje zdarzenie zgody. Nie modyfikuje wcześniejszych wpisów.
 *
 * @param array $meta source, source_detail, note, person_id, ip, event_at, user_id
 * @return int ID zdarzenia
 */
function crm_consent_record(int $contact_id, int $purpose_id, bool $granted, array $meta = []): int
{
    crm_consent_migrate();

    $purpose = crm_one("SELECT * FROM crm_consent_purposes WHERE id = ?", [$purpose_id]);
    if (!$purpose) return 0;

    $source = (string)($meta['source'] ?? 'import');
    if (!array_key_exists($source, CRM_CONSENT_SOURCES)) $source = 'import';

    return crm_insert('crm_consents', [
        'contact_id'    => $contact_id,
        'person_id'     => ((int)($meta['person_id'] ?? 0)) ?: null,
        'purpose_id'    => $purpose_id,
        'granted'       => $granted ? 1 : 0,
        'event_at'      => (string)($meta['event_at'] ?? date('Y-m-d H:i:s')),
        'source'        => $source,
        'source_detail' => ($meta['source_detail'] ?? null) ?: null,
        // Snapshot klauzuli tylko przy udzieleniu — wycofanie nie dotyczy treści.
        'klauzula_snapshot' => $granted ? (($meta['klauzula'] ?? null) ?: ($purpose['klauzula'] ?? null)) : null,
        'ip'            => ($meta['ip'] ?? null) ?: null,
        'note'          => ($meta['note'] ?? null) ?: null,
        'created_by'    => ((int)($meta['user_id'] ?? 0)) ?: null,
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
}

/** Wycofanie zgody — skrót na crm_consent_record() z granted=false. */
function crm_consent_withdraw(int $contact_id, int $purpose_id, array $meta = []): int
{
    return crm_consent_record($contact_id, $purpose_id, false, $meta);
}

/** @return array<int,array> Historia zdarzeń kontaktu, najnowsze pierwsze. */
function crm_consent_history(int $contact_id, ?int $purpose_id = null, int $limit = 100): array
{
    crm_consent_migrate();
    $sql    = "SELECT c.*, p.nazwa AS purpose_nazwa, p.kod AS purpose_kod, u.name AS user_name
                 FROM crm_consents c
                 JOIN crm_consent_purposes p ON p.id = c.purpose_id
            LEFT JOIN users u ON u.id = c.created_by
                WHERE c.contact_id = ?";
    $params = [$contact_id];
    if ($purpose_id !== null) { $sql .= " AND c.purpose_id = ?"; $params[] = $purpose_id; }
    $sql .= " ORDER BY c.id DESC LIMIT " . max(1, $limit);
    return crm_all($sql, $params);
}

/**
 * Kiedy zgoda przestaje obowiązywać. NULL = bezterminowa albo brak zgody.
 *
 * Liczone od zdarzenia udzielenia (event_at), nie od wpisu do bazy — zgoda
 * zebrana na papierze pół roku temu jest o pół roku starsza niż jej import.
 */
function crm_consent_expires_at(?array $state, ?array $purpose): ?string
{
    if ($state === null || (int)$state['granted'] !== 1) return null;
    $months = (int)($purpose['valid_months'] ?? 0);
    if ($months <= 0) return null;
    $from = strtotime((string)($state['event_at'] ?: $state['created_at']));
    if (!$from) return null;
    return date('Y-m-d H:i:s', strtotime('+' . $months . ' months', $from));
}

/** Czy zgoda wygasła (ważność celu minęła). */
function crm_consent_is_expired(?array $state, ?array $purpose): bool
{
    $exp = crm_consent_expires_at($state, $purpose);
    return $exp !== null && strtotime($exp) < time();
}

/**
 * Zgody wygasłe albo wygasające w najbliższych $days dniach.
 *
 * Zwraca po jednym wierszu na parę kontakt+cel, z danymi kontaktu i opiekuna —
 * cron robi z tego zestawienie do odnowienia.
 *
 * @return array<int,array<string,mixed>>
 */
function crm_consent_expiring(int $days = 30): array
{
    crm_consent_migrate();
    $out = [];
    foreach (crm_consent_purposes(false) as $p) {
        $months = (int)($p['valid_months'] ?? 0);
        if ($months <= 0) continue;                 // bezterminowa — nie ma czego pilnować

        // Najnowsze zdarzenie na parę kontakt+cel, tylko udzielenia
        $rows = crm_all(
            "SELECT c.contact_id, c.event_at, c.created_at
               FROM crm_consents c
              WHERE c.purpose_id = ? AND c.granted = 1
                AND c.id = (SELECT MAX(x.id) FROM crm_consents x
                             WHERE x.contact_id = c.contact_id AND x.purpose_id = c.purpose_id)",
            [(int)$p['id']]
        );
        $limit = time() + $days * 86400;
        foreach ($rows as $r) {
            $exp = crm_consent_expires_at(['granted' => 1, 'event_at' => $r['event_at'],
                                           'created_at' => $r['created_at']], $p);
            if ($exp === null || strtotime($exp) > $limit) continue;
            $out[] = [
                'contact_id' => (int)$r['contact_id'],
                'purpose_id' => (int)$p['id'],
                'purpose'    => (string)$p['nazwa'],
                'expires_at' => $exp,
                'expired'    => strtotime($exp) < time(),
            ];
        }
    }
    usort($out, static fn($a, $b) => strcmp((string)$a['expires_at'], (string)$b['expires_at']));
    return $out;
}

/** @return array<int> ID kontaktów z aktualnie udzieloną zgodą na dany cel. */
function crm_consent_granted_ids(int $purpose_id): array
{
    crm_consent_migrate();
    $rows = crm_all(
        "SELECT c.contact_id, c.event_at, c.created_at
           FROM crm_consents c
          WHERE c.purpose_id = ?
            AND c.granted = 1
            AND c.id = (SELECT MAX(x.id) FROM crm_consents x
                         WHERE x.contact_id = c.contact_id AND x.purpose_id = c.purpose_id)",
        [$purpose_id]
    );

    // Zgoda z ograniczoną ważnością po terminie nie uprawnia do wysyłki. Filtrujemy
    // TU, a nie dopiero w interfejsie — inaczej wygasła zgoda dalej przepuszczałaby
    // kampanię, a to jest dokładnie ten błąd, przed którym ma chronić termin.
    $purpose = crm_one("SELECT * FROM crm_consent_purposes WHERE id = ?", [$purpose_id]);
    $out = [];
    foreach ($rows as $r) {
        if (crm_consent_is_expired(['granted' => 1, 'event_at' => $r['event_at'],
                                    'created_at' => $r['created_at']], $purpose)) continue;
        $out[] = (int)$r['contact_id'];
    }
    return $out;
}

/**
 * Zawęża listę kontaktów do tych, które mają zgodę na dany cel.
 * purpose_id = 0 zwraca listę bez zmian (wysyłka bez zadeklarowanego celu).
 */
function crm_consent_filter(array $contact_ids, int $purpose_id): array
{
    if ($purpose_id <= 0 || !$contact_ids) return array_values($contact_ids);
    $ok = crm_consent_granted_ids($purpose_id);
    return array_values(array_intersect(array_map('intval', $contact_ids), $ok));
}

/** Czy kontakt ma aktualnie zgodę na dany cel. */
function crm_consent_has(int $contact_id, int $purpose_id): bool
{
    $st = crm_consent_state($contact_id, $purpose_id);
    if ($st === null || (int)$st['granted'] !== 1) return false;
    return !crm_consent_is_expired($st, crm_one("SELECT * FROM crm_consent_purposes WHERE id = ?", [$purpose_id]));
}

/** @return array<int,int> Liczba kontaktów ze zgodą, kluczowana purpose_id. */
function crm_consent_counts(): array
{
    crm_consent_migrate();
    $out = [];
    foreach (crm_consent_purposes(false) as $p) {
        $out[(int)$p['id']] = count(crm_consent_granted_ids((int)$p['id']));
    }
    return $out;
}

/** Liczba zdarzeń dla celu — czy pozycję katalogu wolno usunąć. */
function crm_consent_purpose_usage(int $purpose_id): int
{
    crm_consent_migrate();
    return (int)(crm_one("SELECT COUNT(*) AS c FROM crm_consents WHERE purpose_id = ?", [$purpose_id])['c'] ?? 0);
}

/** Etykieta stanu do interfejsu: [tekst, klasa CSS, ikona]. */
function crm_consent_state_label(?array $state, ?array $purpose = null): array
{
    if ($state === null)              return ['Brak zapisu',    'secondary', 'bi-dash-circle'];
    if ((int)$state['granted'] !== 1) return ['Zgoda wycofana', 'danger',    'bi-x-circle-fill'];
    if ($purpose !== null && crm_consent_is_expired($state, $purpose)) {
        return ['Zgoda wygasła', 'warning', 'bi-hourglass-bottom'];
    }
    return ['Zgoda udzielona', 'success', 'bi-check-circle-fill'];
}

/** Czytelna nazwa sposobu pozyskania. */
function crm_consent_source_label(?string $source): string
{
    return CRM_CONSENT_SOURCES[(string)$source] ?? (string)$source;
}

/**
 * Slug klauzuli informacyjnej z rejestru dla celu zgody: wskazana przy celu,
 * a gdy brak — domyślna dla formularzy CRM (ustawienia modułu klauzul). '' = brak.
 */
function crm_consent_gdpr_slug(?array $purpose): string
{
    $f = dirname(__DIR__) . '/modules/gdpr_clauses/logic/gdpr_clauses.php';
    if (!$purpose || !is_file($f)) return '';
    require_once $f;
    $slug = trim((string)($purpose['gdpr_clause_slug'] ?? ''));
    return $slug !== '' ? $slug : gdpr_clauses_default_slug('crm_form');
}
