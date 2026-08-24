<?php
/**
 * includes/crm_contact_analyzer.php — analiza kartotek powstałych z poczty.
 *
 * Automatyczne zakładanie kartotek dla nieznanych nadawców (poczta_autocreate_contacts)
 * daje dwa skutki naraz: wśród nowych wpisów są WARTOŚCIOWE kontakty firmowe, ale też
 * śmieci — powiadomienia, newslettery, adresy noreply i jednorazowi nadawcy.
 *
 * Ten moduł ocenia jedno i drugie na podstawie ŚLADÓW W DANYCH, nie zgadywania:
 * czy odpisaliśmy, ile było wiadomości, czy domena jest firmowa, czy z tej domeny
 * pisze więcej osób, czy kontakt ma sprawy albo oferty. Każda ocena zwraca powody,
 * bo operator ma zobaczyć DLACZEGO coś zostało wskazane, a nie samą liczbę.
 *
 * Filtr nadawców (crm_sender_blocklist) działa u źródła — w skanerze poczty, więc
 * odfiltrowany adres nie zakłada kartoteki ponownie po każdym skanowaniu.
 *
 * Wymaga: db.php, functions.php, crm.php
 */

declare(strict_types=1);

// Lista domen darmowej poczty i wyciąganie domeny z adresu mieszkają w
// crm_domains.php — dwie kopie tej wiedzy rozjeżdżają się przy pierwszej
// dopisanej domenie, a `crm_email_domain()` istniało tu i tam pod tą samą nazwą
// (załadowanie obu plików = błąd krytyczny „Cannot redeclare”).
require_once __DIR__ . '/crm_domains.php';

/** Wzorce lokalnej części adresu, które oznaczają nadawcę automatycznego. */
const CRM_ROBOT_LOCALPARTS = [
    'noreply', 'no-reply', 'no_reply', 'donotreply', 'do-not-reply', 'do_not_reply',
    'newsletter', 'newsletters', 'mailer-daemon', 'mailerdaemon', 'postmaster',
    'bounce', 'bounces', 'notification', 'notifications', 'notify', 'alerts',
    'automat', 'automated', 'system', 'daemon', 'robot', 'mailing', 'marketing',
    'info-noreply', 'support-noreply',
];

// ── Filtr nadawców ───────────────────────────────────────────────────────────

function crm_sender_blocklist_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        db()->exec("CREATE TABLE IF NOT EXISTS crm_sender_blocklist (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            pattern    TEXT    NOT NULL,
            kind       TEXT    NOT NULL DEFAULT 'email',
            reason     TEXT    NOT NULL DEFAULT '',
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        // Ten sam wzorzec nie ma sensu dwa razy.
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_crm_blocklist_pat ON crm_sender_blocklist(pattern)");
    } catch (\Throwable $e) { /* tabela istnieje */ }
}

/** Normalizuje wzorzec: pełny adres albo domena z wiodącym „@". */
function crm_sender_pattern(string $raw): array
{
    $v = strtolower(trim($raw));
    if ($v === '') return ['', ''];
    if (str_starts_with($v, '@')) return [$v, 'domain'];
    if (filter_var($v, FILTER_VALIDATE_EMAIL)) return [$v, 'email'];
    // Sama domena bez „@" — traktujemy jako domenę.
    if (str_contains($v, '.') && !str_contains($v, '@')) return ['@' . $v, 'domain'];
    return ['', ''];
}

/** Czy adres jest odfiltrowany (dokładnie albo przez domenę). */
function crm_sender_blocked(string $email): bool
{
    crm_sender_blocklist_migrate();
    $e = strtolower(trim($email));
    if ($e === '') return false;
    $domain = '@' . substr(strrchr($e, '@') ?: '', 1);

    try {
        $r = db_one("SELECT 1 AS x FROM crm_sender_blocklist WHERE pattern IN (?, ?) LIMIT 1", [$e, $domain]);
        return (bool)$r;
    } catch (\Throwable $e2) {
        return false;   // brak tabeli nie może blokować skanowania poczty
    }
}

/** Dodaje wzorzec do filtra. Zwraca komunikat błędu albo null. */
function crm_sender_block_add(string $raw, string $reason = '', ?int $uid = null): ?string
{
    crm_sender_blocklist_migrate();
    [$pattern, $kind] = crm_sender_pattern($raw);
    if ($pattern === '') return 'Podaj adres e-mail albo domenę.';

    try {
        db()->prepare(
            "INSERT OR IGNORE INTO crm_sender_blocklist (pattern, kind, reason, created_by, created_at)
             VALUES (?,?,?,?,?)"
        )->execute([$pattern, $kind, mb_substr(trim($reason), 0, 255), $uid, date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        return 'Nie udało się zapisać filtra: ' . $e->getMessage();
    }
    return null;
}

// ── Ocena kontaktów ──────────────────────────────────────────────────────────

/** Czy domena jest darmową pocztą. Alias na wspólną listę z crm_domains.php. */
function crm_is_free_mail(string $domain): bool
{
    return $domain !== '' && crm_domain_is_public($domain);
}

/** Czy lokalna część adresu wskazuje na nadawcę automatycznego. */
function crm_is_robot_sender(?string $email): bool
{
    $local = strtolower((string)strtok(strtolower(trim((string)$email)), '@'));
    if ($local === '') return false;
    foreach (CRM_ROBOT_LOCALPARTS as $needle) {
        if ($local === $needle || str_contains($local, $needle)) return true;
    }
    return false;
}

/**
 * Zbiera statystyki potrzebne do oceny — jednym zapytaniem na wszystkie kontakty,
 * bo per kontakt byłoby to N+1 na kilku tysiącach wpisów.
 *
 * @return array{msg:array<int,array{in:int,out:int,last:?string}>,domains:array<string,int>,links:array<int,int>}
 */
function crm_analyzer_stats(): array
{
    $msg = [];
    try {
        foreach (db_all(
            "SELECT contact_id,
                    SUM(CASE WHEN direction='in'  THEN 1 ELSE 0 END) AS n_in,
                    SUM(CASE WHEN direction='out' THEN 1 ELSE 0 END) AS n_out,
                    MAX(sent_at) AS last_at
               FROM crm_communications WHERE contact_id IS NOT NULL GROUP BY contact_id"
        ) as $r) {
            $msg[(int)$r['contact_id']] = [
                'in'   => (int)$r['n_in'],
                'out'  => (int)$r['n_out'],
                'last' => $r['last_at'] ?? null,
            ];
        }
    } catch (\Throwable $e) {}

    // Ile RÓŻNYCH kontaktów ma adres w danej domenie — kilka osób z jednej domeny
    // to mocny sygnał, że mamy do czynienia z firmą, nie z przypadkowym mailem.
    $domains = [];
    try {
        foreach (db_all(
            "SELECT LOWER(SUBSTR(email, INSTR(email,'@')+1)) AS dom, COUNT(*) AS c
               FROM crm_contacts
              WHERE crm_active=1 AND email IS NOT NULL AND INSTR(email,'@')>0
           GROUP BY dom"
        ) as $r) {
            $domains[(string)$r['dom']] = (int)$r['c'];
        }
    } catch (\Throwable $e) {}

    // Powiązania merytoryczne: sprawy i oferty. Kontakt, na którym coś się dzieje,
    // nie jest śmieciem — niezależnie od tego, jak wygląda jego adres.
    $links = [];
    foreach ([
        "SELECT contact_id, COUNT(*) AS c FROM crm_cases  WHERE contact_id IS NOT NULL GROUP BY contact_id",
        "SELECT contact_id, COUNT(*) AS c FROM crm_offers WHERE contact_id IS NOT NULL AND deleted_at IS NULL GROUP BY contact_id",
    ] as $sql) {
        try {
            foreach (db_all($sql) as $r) {
                $cid = (int)$r['contact_id'];
                $links[$cid] = ($links[$cid] ?? 0) + (int)$r['c'];
            }
        } catch (\Throwable $e) {}
    }

    return ['msg' => $msg, 'domains' => $domains, 'links' => $links];
}

/**
 * Ocena kontaktu jako KANDYDATA NA KONTAKT FIRMOWY.
 *
 * Punktujemy ślady realnej relacji, nie wygląd adresu:
 *  • odpisaliśmy (wiadomość wychodząca) — najmocniejszy sygnał, ktoś podjął rozmowę,
 *  • domena firmowa (nie darmowa poczta),
 *  • kilka osób z tej samej domeny — to znaczy, że mamy do czynienia z organizacją,
 *  • wypełniona nazwa organizacji albo NIP,
 *  • powiązane sprawy albo oferty,
 *  • więcej niż jedna wiadomość przychodząca.
 *
 * @return array{score:int,reasons:list<string>}
 */
function crm_contact_company_score(array $c, array $stats): array
{
    $score   = 0;
    $reasons = [];

    $cid    = (int)($c['id'] ?? 0);
    $domain = crm_email_domain($c['email'] ?? null);
    $m      = $stats['msg'][$cid] ?? ['in' => 0, 'out' => 0, 'last' => null];

    if ($m['out'] > 0) {
        $score += 4;
        $reasons[] = 'odpisaliśmy (' . $m['out'] . ' wysł.)';
    }
    if ($domain !== '' && !crm_is_free_mail($domain)) {
        $score += 3;
        $reasons[] = 'domena firmowa (' . $domain . ')';
    }
    $same_domain = $domain !== '' ? (int)($stats['domains'][$domain] ?? 0) : 0;
    if ($same_domain > 1 && !crm_is_free_mail($domain)) {
        $score += min(3, $same_domain - 1);
        $reasons[] = $same_domain . ' kontaktów z tej domeny';
    }
    if (trim((string)($c['organizacja'] ?? '')) !== '') {
        $score += 2;
        $reasons[] = 'ma nazwę organizacji';
    }
    if (preg_match('/^\d{10}$/', preg_replace('/\D+/', '', (string)($c['nip'] ?? '')) ?? '')) {
        $score += 3;
        $reasons[] = 'ma NIP';
    }
    if (!empty($stats['links'][$cid])) {
        $score += 2;
        $reasons[] = 'powiązane sprawy/oferty (' . (int)$stats['links'][$cid] . ')';
    }
    if ($m['in'] > 1) {
        $score += min(3, $m['in'] - 1);
        $reasons[] = $m['in'] . ' wiadomości przychodzących';
    }

    // Nadawca automatyczny nie jest kontaktem firmowym, choćby domena była firmowa.
    if (crm_is_robot_sender($c['email'] ?? null)) {
        $score = 0;
        $reasons = ['adres automatyczny — nie kwalifikuje się'];
    }

    return ['score' => $score, 'reasons' => $reasons];
}

/**
 * Ocena kontaktu jako KANDYDATA DO USUNIĘCIA lub odfiltrowania.
 *
 * Zwracamy powody tylko wtedy, gdy naprawdę nic nie wskazuje na wartość kontaktu —
 * jedna wiadomość bez odpowiedzi, brak powiązań, adres automatyczny. Kontakt,
 * na którym cokolwiek się dzieje, NIE trafia na tę listę, choćby wyglądał na śmieć.
 *
 * @return array{junk:bool,reasons:list<string>,hard:bool} hard = adres automatyczny (kandydat na filtr)
 */
function crm_contact_junk_score(array $c, array $stats): array
{
    $cid = (int)($c['id'] ?? 0);
    $m   = $stats['msg'][$cid] ?? ['in' => 0, 'out' => 0, 'last' => null];

    // Cokolwiek merytorycznego = kontakt zostaje. Ten warunek jest pierwszy,
    // żeby żaden dalszy sygnał nie mógł go przesłonić.
    if (!empty($stats['links'][$cid]))                       return ['junk' => false, 'reasons' => [], 'hard' => false];
    if ($m['out'] > 0)                                       return ['junk' => false, 'reasons' => [], 'hard' => false];
    if (trim((string)($c['nip'] ?? '')) !== '')               return ['junk' => false, 'reasons' => [], 'hard' => false];
    if (trim((string)($c['telefon'] ?? '')) !== '')           return ['junk' => false, 'reasons' => [], 'hard' => false];

    // Kolejna osoba z domeny, w której mamy już inne kontakty, NIE jest śmieciem —
    // to zwykle współpracownik w firmie, z którą korespondujemy. Proponowanie
    // usunięcia takiej kartoteki byłoby szkodliwą podpowiedzią. Wyjątek: adresy
    // automatyczne, bo tych w firmowej domenie też nie chcemy trzymać.
    $domain = crm_email_domain($c['email'] ?? null);
    if ($domain !== '' && !crm_is_free_mail($domain)
        && (int)($stats['domains'][$domain] ?? 0) > 1
        && !crm_is_robot_sender($c['email'] ?? null)) {
        return ['junk' => false, 'reasons' => [], 'hard' => false];
    }

    $reasons = [];
    $hard    = false;

    if (crm_is_robot_sender($c['email'] ?? null)) {
        $reasons[] = 'adres automatyczny (noreply/newsletter itp.)';
        $hard      = true;
    }

    // Kartoteka założona automatycznie, jedna wiadomość, nikt nie odpisał.
    if ((string)($c['source'] ?? '') === 'skrzynka' && $m['in'] <= 1) {
        $reasons[] = 'z automatu, ' . ($m['in'] === 1 ? '1 wiadomość' : 'brak wiadomości') . ', bez odpowiedzi';
    }

    // Nazwa wygląda na wygenerowaną z adresu (brak prawdziwego imienia i nazwiska).
    $name  = trim((string)($c['imie_nazwisko'] ?? ''));
    $local = (string)strtok(strtolower((string)($c['email'] ?? '')), '@');
    if ($name !== '' && $local !== '' && strtolower($name) === strtolower($local)) {
        $reasons[] = 'nazwa wzięta z adresu, brak danych osoby';
    }

    // Cisza od dawna — dopiero jako sygnał dodatkowy, nie samodzielny powód.
    if ($reasons && !empty($m['last']) && strtotime((string)$m['last']) < strtotime('-180 days')) {
        $reasons[] = 'brak kontaktu od ponad pół roku';
    }

    return ['junk' => (bool)$reasons, 'reasons' => $reasons, 'hard' => $hard];
}

/**
 * Analizuje kartoteki i zwraca dwie listy: kandydatów firmowych i do usunięcia.
 *
 * @param int $min_score Próg dla listy firmowej.
 * @return array{company:list<array>,junk:list<array>,checked:int}
 */
function crm_analyze_contacts(int $min_score = 6, int $limit = 300): array
{
    $stats = crm_analyzer_stats();

    try {
        $rows = db_all(
            "SELECT id, imie_nazwisko, email, telefon, organizacja, nip, type, status, source, created_at
               FROM crm_contacts WHERE crm_active = 1 AND email IS NOT NULL AND email <> ''"
        );
    } catch (\Throwable $e) {
        return ['company' => [], 'junk' => [], 'checked' => 0];
    }

    $company = [];
    $junk    = [];

    foreach ($rows as $c) {
        $cs = crm_contact_company_score($c, $stats);
        $js = crm_contact_junk_score($c, $stats);

        // Kontakt już oznaczony jako podmiot nie jest „kandydatem" — nie ma co proponować.
        $is_org = !empty(CRM_CONTACT_TYPES[$c['type']]['org_like']);

        if ($cs['score'] >= $min_score && !$is_org) {
            $company[] = $c + ['score' => $cs['score'], 'reasons' => $cs['reasons'],
                               'domain' => crm_email_domain($c['email'])];
        }
        if ($js['junk']) {
            $junk[] = $c + ['reasons' => $js['reasons'], 'hard' => $js['hard'],
                            'domain' => crm_email_domain($c['email'])];
        }
    }

    usort($company, fn($a, $b) => $b['score'] <=> $a['score']);
    // Adresy automatyczne na górze listy do usunięcia — te są najpewniejsze.
    usort($junk, fn($a, $b) => ($b['hard'] <=> $a['hard']) ?: (count($b['reasons']) <=> count($a['reasons'])));

    return [
        'company' => array_slice($company, 0, $limit),
        'junk'    => array_slice($junk, 0, $limit),
        'checked' => count($rows),
    ];
}
